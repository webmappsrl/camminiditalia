<?php

namespace Tests\Feature;

use App\Jobs\RecalculateLayerAttributesJob;
use App\Nova\Layer;
use App\Services\LayerAttributesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Resources\MergeValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\TaxonomyTheme;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerThemeAttributesTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    /**
     * Un tema senza nessun nome utilizzabile non deve produrre una voce con
     * `name` vuoto: una mappa PHP vuota viene serializzata come lista `[]`
     * invece che come oggetto `{}`, e un consumer tipizzato (dove `name` è una
     * mappa) va in errore sull'intero elemento.
     */
    /**
     * Regressione del 500 osservato cancellando un tema dal cammino 131.
     *
     * Nova esegue le closure di fillUsing DENTRO la sua transazione. Il
     * ricalcolo è già accodato da LayerObserver::saved() nello stesso
     * salvataggio: un secondo dispatch dello stesso job unico nella stessa
     * transazione tentava un insert sulla chiave di lock già presente (con
     * CACHE_STORE=database il lock è una riga su cache_locks), Postgres
     * abortiva la transazione, e l'update di fallback in
     * DatabaseLock::acquire() esplodeva con SQLSTATE 25P02.
     *
     * Il campo dei Temi non deve quindi accodare nulla di suo.
     */
    public function test_saving_themes_does_not_dispatch_its_own_recalculation(): void
    {
        $layer = $this->createLayer();
        $theme = new TaxonomyTheme(['name' => ['it' => 'Storico']]);
        $theme->save();

        $field = $this->themesField();

        $request = NovaRequest::create('/', 'POST', [
            'taxonomyThemes' => json_encode([$theme->id]),
        ]);

        $queue = Queue::fake();

        // fillInto restituisce la closure che Nova esegue a fine transazione.
        $callback = $field->fillInto($request, $layer, 'taxonomyThemes', 'taxonomyThemes');
        if (is_callable($callback)) {
            $callback();
        }

        $this->assertSame(
            [$theme->id],
            $layer->fresh()->taxonomyThemes->pluck('id')->all(),
            'Il campo deve comunque sincronizzare i temi.'
        );

        $queue->assertNotPushed(RecalculateLayerAttributesJob::class);
    }

    private function themesField(): object
    {
        $resource = new Layer(new \Wm\WmPackage\Models\Layer);

        $found = null;
        $walk = function ($items) use (&$walk, &$found) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $walk($item);

                    continue;
                }
                if ($item instanceof MergeValue) {
                    $walk($item->data);

                    continue;
                }
                if (is_object($item) && ($item->attribute ?? null) === 'taxonomyThemes') {
                    $found = $item;
                }
            }
        };
        $walk($resource->fields(NovaRequest::create('/')));

        $this->assertNotNull($found, 'Campo Temi non trovato nella risorsa Layer.');

        return $found;
    }

    public function test_theme_without_any_usable_name_is_excluded(): void
    {
        $layer = $this->createLayer();

        $theme = new TaxonomyTheme(['name' => ['it' => '', 'en' => '']]);
        $theme->save();
        $layer->taxonomyThemes()->attach($theme->id);

        $this->assertNull(
            app(LayerAttributesService::class)->themes($layer->fresh()),
            'Un tema senza nome non deve comparire negli attributi.'
        );
    }

    /**
     * Verifica la forma a livello di jsonb, non in PHP: json_decode
     * normalizza `{}` e `[]` nello stesso array, quindi un'asserzione
     * PHP-side non distinguerebbe i due casi.
     */
    public function test_persisted_theme_names_are_json_objects_not_lists(): void
    {
        $layer = $this->createLayer();
        $service = app(LayerAttributesService::class);

        $named = new TaxonomyTheme(['name' => ['it' => 'Cammini storici']]);
        $named->save();
        $nameless = new TaxonomyTheme(['name' => ['it' => '']]);
        $nameless->save();

        $layer->taxonomyThemes()->attach([$named->id, $nameless->id]);

        $service->persistCalculatedValues($layer, $service->computeCalculatedValues($layer->fresh()));

        $row = DB::selectOne(
            "SELECT jsonb_array_length(properties->'attributes'->'themes') AS voci,
                    jsonb_typeof(properties->'attributes'->'themes'->0->'name') AS tipo_nome
             FROM layers WHERE id = ?",
            [$layer->id]
        );

        $this->assertSame(1, (int) $row->voci, 'Il tema senza nome non deve essere persistito.');
        $this->assertSame('object', $row->tipo_nome, 'name deve essere un oggetto JSON, non una lista.');
    }

    public function test_themes_are_exposed_as_value_and_translated_names(): void
    {
        $layer = $this->createLayer();

        $theme = new TaxonomyTheme(['name' => ['it' => 'Cammini spirituali', 'en' => 'Spiritual routes']]);
        $theme->save();
        $layer->taxonomyThemes()->attach($theme->id);

        $themes = app(LayerAttributesService::class)->themes($layer->fresh());

        $this->assertSame([
            [
                'value' => 'spiritual-routes',
                'name' => ['it' => 'Cammini spirituali', 'en' => 'Spiritual routes'],
            ],
        ], $themes);
    }

    public function test_theme_value_falls_back_to_italian_when_english_is_missing(): void
    {
        $layer = $this->createLayer();

        $theme = new TaxonomyTheme(['name' => ['it' => 'Alta Via']]);
        $theme->save();
        $layer->taxonomyThemes()->attach($theme->id);

        $themes = app(LayerAttributesService::class)->themes($layer->fresh());

        $this->assertSame('alta-via', $themes[0]['value']);
    }

    public function test_themes_are_null_without_associations(): void
    {
        $layer = $this->createLayer();

        $this->assertNull(app(LayerAttributesService::class)->themes($layer));
    }
}

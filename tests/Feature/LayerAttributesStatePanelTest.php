<?php

namespace Tests\Feature;

use App\Models\User;
use App\Nova\Layer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer as LayerModel;
use Wm\WmPackage\Models\TaxonomyTheme;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerAttributesStatePanelTest extends TestCase
{
    // Nota: i test girano con APP_LOCALE=en (.env.testing), quindi le
    // asserzioni sono sull'output inglese. Prima le chiavi di traduzione
    // erano scritte in italiano e questi test passavano restituendo la
    // chiave non tradotta: verificavano la chiave, non la traduzione.

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // oc:8180: LayerObserver::saved() accoda RecalculateLayerAttributesJob
        // su OGNI save() (auto-riparazione dei filtri calcolati). Con la
        // coda reale (redis, non fake) un worker in ascolto eseguirebbe
        // subito il job e sovrascriverebbe i valori di properties->attributes
        // creati "a mano" da questi test, che non riflettono un ricalcolo
        // vero (nessuna tappa reale associata). Queue::fake() impedisce che
        // il dispatch implicito interferisca con i fixture dei test.
        Queue::fake();

        RolesAndPermissionsService::seedDatabase();
        App::factory()->create();
    }

    /**
     * Costruisce l'HTML del pannello "Stato filtri" per un dato Layer,
     * invocando il metodo privato tramite reflection (nessuna route Nova
     * necessaria per testare la logica di rendering).
     */
    private function buildHtmlFor(LayerModel $layer): string
    {
        $resource = new Layer($layer);

        $method = new \ReflectionMethod(Layer::class, 'buildAttributesStateHtml');
        $method->setAccessible(true);

        return $method->invoke($resource);
    }

    public function test_layer_with_all_filters_populated_shows_formatted_values(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Administrator');

        $theme = new TaxonomyTheme(['name' => ['it' => 'Storico']]);
        $theme->save();

        $layer = LayerModel::factory()->create([
            'user_id' => $owner->id,
            'properties' => [
                'attributes' => [
                    'distance' => 842,
                    'stage_count' => 12,
                    'shape' => [
                        'value' => 'roundtrip',
                        'name' => ['it' => 'Anello', 'en' => 'Roundtrip'],
                    ],
                    'taxonomy_where' => [
                        [
                            'value' => 'tuscany',
                            'name' => ['it' => 'Toscana', 'en' => 'Tuscany'],
                        ],
                    ],
                    'walking_network' => [
                        'value' => 'nwn',
                        'name' => ['it' => 'Rete nazionale', 'en' => 'National'],
                    ],
                    'season' => [
                        ['value' => 'spring', 'name' => ['it' => 'Primavera', 'en' => 'Spring']],
                        ['value' => 'summer', 'name' => ['it' => 'Estate', 'en' => 'Summer']],
                    ],
                ],
            ],
        ]);

        $layer->taxonomyThemes()->attach($theme->id);
        $layer->refresh();

        $html = $this->buildHtmlFor($layer);

        $this->assertStringContainsString('842 km', $html);
        $this->assertStringContainsString('12 stages', $html);
        $this->assertStringContainsString('Roundtrip', $html);
        $this->assertStringContainsString('Toscana', $html);
        $this->assertStringContainsString('National', $html);
        $this->assertStringContainsString('Spring', $html);
        $this->assertStringContainsString('Summer', $html);
        $this->assertStringContainsString('Storico', $html);
    }

    public function test_layer_without_filters_shows_absence_markers_and_no_invented_values(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Administrator');

        $layer = LayerModel::factory()->create([
            'user_id' => $owner->id,
            'properties' => [],
        ]);

        $html = $this->buildHtmlFor($layer);

        $this->assertStringContainsString('not computable', $html);
        $this->assertStringContainsString('not set', $html);
        $this->assertStringNotContainsString('0 km', $html);
        $this->assertStringNotContainsString('0 tappe', $html);
    }

    /**
     * "Discontinuo" è un valore vero della tipologia (tratti non collegati
     * fra loro), non un'assenza di dato: deve comparire come tale, con una
     * spiegazione breve del significato.
     */
    public function test_layer_with_discontinuous_type_shows_value_with_explanation(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Administrator');

        $layer = LayerModel::factory()->create([
            'user_id' => $owner->id,
            'properties' => [
                'attributes' => [
                    'shape' => [
                        'value' => 'discontinuous',
                        'name' => ['it' => 'Discontinuo', 'en' => 'Discontinuous'],
                    ],
                ],
            ],
        ]);

        $html = $this->buildHtmlFor($layer);

        $this->assertStringContainsString('Discontinuous', $html);
        $this->assertStringContainsString('the route segments are not connected to each other', $html);
        // "Discontinuo" è un valore vero, non un'assenza: non deve comparire
        // vicino al badge/testo di "non calcolabile" usato dagli altri
        // filtri (distanza/durata/regioni) quando manca il dato.
        $this->assertStringNotContainsString('Tipologia</span></div><div style="font-size:14px;color:#0f172a;line-height:1.5;word-break:break-word;"><span style="color:#92400e;font-weight:600;">non calcolabile', $html);
    }

    public function test_layer_with_unrecognized_network_value_does_not_throw(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Administrator');

        $layer = LayerModel::factory()->create([
            'user_id' => $owner->id,
            'properties' => [
                'attributes' => [
                    'walking_network' => 'xxx',
                ],
            ],
        ]);

        $html = $this->buildHtmlFor($layer);

        $this->assertStringContainsString('xxx', $html);
        $this->assertStringContainsString('unrecognized value', $html);
    }

    public function test_region_name_with_html_characters_is_escaped(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Administrator');

        $layer = LayerModel::factory()->create([
            'user_id' => $owner->id,
            'properties' => [
                'attributes' => [
                    'taxonomy_where' => [
                        [
                            'value' => 'xss-probe',
                            'name' => ['it' => '<script>alert(1)</script>'],
                        ],
                    ],
                ],
            ],
        ]);

        $html = $this->buildHtmlFor($layer);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Il Multiselect "Themes" nel Panel "Filtri" seleziona solo Temi già
     * esistenti (sync della relazione belongsToMany): selezionandone due
     * esistenti, la relazione deve contenere esattamente quei due.
     */
    public function test_selecting_two_existing_themes_syncs_exactly_those(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Administrator');

        $layer = LayerModel::factory()->create([
            'user_id' => $owner->id,
            'properties' => [],
        ]);

        $themeA = new TaxonomyTheme(['name' => ['it' => 'Tema A']]);
        $themeA->save();
        $themeB = new TaxonomyTheme(['name' => ['it' => 'Tema B']]);
        $themeB->save();

        $this->fillThemesField($layer, [(string) $themeA->id, (string) $themeB->id]);

        $this->assertSame(2, $layer->taxonomyThemes()->count());
        $this->assertEqualsCanonicalizing(
            [$themeA->id, $themeB->id],
            $layer->taxonomyThemes()->pluck('taxonomy_themes.id')->all()
        );
    }

    /**
     * Guardia contro la reintroduzione della creazione al volo: un valore
     * che non corrisponde a nessun Tema esistente non deve creare alcun
     * nuovo record in taxonomy_themes.
     */
    public function test_unknown_theme_value_does_not_create_a_new_theme(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Administrator');

        $layer = LayerModel::factory()->create([
            'user_id' => $owner->id,
            'properties' => [],
        ]);

        $themesBefore = TaxonomyTheme::count();

        $this->fillThemesField($layer, ['Tema Inesistente']);

        $this->assertSame($themesBefore, TaxonomyTheme::count());
    }

    /**
     * La sezione Nova TaxonomyTheme deve restare raggiungibile: è l'unico
     * punto dove i nuovi Temi possono essere creati, ora che il campo
     * Multiselect su Layer non li crea più al volo.
     */
    public function test_taxonomy_theme_nova_resource_is_reachable(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $response = $this->actingAs($admin)->getJson('/nova-api/taxonomy-themes');

        $response->assertStatus(200);
    }

    /**
     * Simula la sottomissione del campo Multiselect "Themes" invocando
     * fillInto sul field risolto dalla risorsa, come farebbe Nova su una
     * richiesta di update.
     *
     * @param  array<int, string>  $values
     */
    private function fillThemesField(LayerModel $layer, array $values): void
    {
        $resource = new Layer($layer);
        $request = NovaRequest::create('/', 'POST', [
            'taxonomyThemes' => $values,
        ]);

        /** @var array<int, mixed> $fields */
        $fields = $resource->fields($request);

        foreach ($fields as $field) {
            if (! $field instanceof Panel) {
                continue;
            }

            foreach ($field->data as $sub) {
                if ($sub->attribute === 'taxonomyThemes') {
                    $callback = $sub->fillInto($request, $layer, 'taxonomyThemes');

                    if (is_callable($callback)) {
                        $callback();
                    }
                }
            }
        }

        $layer->refresh();
    }
}

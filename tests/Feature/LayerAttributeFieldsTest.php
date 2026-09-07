<?php

namespace Tests\Feature;

use App\Models\User;
use App\Nova\Layer as NovaLayer;
use App\Services\LayerAttributesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Resources\MergeValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Enums\OsmWalkingNetwork;
use Wm\WmPackage\Enums\Season;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerAttributeFieldsTest extends TestCase
{
    use DatabaseTransactions;

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

    private function fieldAttributes(): array
    {
        $resource = new NovaLayer(new Layer);
        $attributes = [];

        $walk = function ($items) use (&$walk, &$attributes) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $walk($item);

                    continue;
                }
                // Panel e Tab estendono MergeValue: i campi contenuti stanno in ->data,
                // e array_walk_recursive non entrerebbe negli oggetti.
                if ($item instanceof MergeValue) {
                    $walk($item->data);

                    continue;
                }
                if (is_object($item) && property_exists($item, 'attribute')) {
                    $attributes[] = $item->attribute;
                }
            }
        };

        $walk($resource->fields(NovaRequest::create('/')));

        return $attributes;
    }

    public function test_network_and_season_filter_fields_exist(): void
    {
        $attributes = $this->fieldAttributes();

        $this->assertContains('properties->attributes->walking_network', $attributes);
        $this->assertContains('properties->attributes->season', $attributes);
        // Verifica che l'helper trova anche campi di primo livello (non dentro Panel)
        $this->assertContains('layerOwner', $attributes);
    }

    public function test_network_options_come_from_the_osm_enum(): void
    {
        $this->assertSame(
            ['lwn', 'rwn', 'nwn', 'iwn'],
            array_keys(OsmWalkingNetwork::toArray())
        );
    }

    public function test_season_options_come_from_the_season_enum(): void
    {
        $this->assertSame(
            ['spring', 'summer', 'autumn', 'winter'],
            array_keys(Season::toArray())
        );
    }

    public function test_season_is_persisted_as_a_jsonb_array_not_a_string(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $layer = Layer::factory()->create(['user_id' => $admin->id]);

        $response = $this->actingAs($admin)
            ->putJson('/nova-api/layers/'.$layer->id, [
                'name' => $layer->name,
                'properties->attributes->season' => json_encode(['spring', 'summer']),
            ]);

        $response->assertStatus(200);

        $type = DB::selectOne(
            "SELECT jsonb_typeof(properties->'attributes'->'season') AS t FROM layers WHERE id = ?",
            [$layer->id]
        )->t;

        $this->assertSame('array', $type);
    }

    /**
     * Difetto Critical: una scheda Nova aperta a T0 tiene in memoria il
     * blob `properties` com'era a T0. Se fra T0 e il salvataggio un
     * ricalcolo automatico scrive le CALCULATED_KEYS, un salvataggio del
     * campo manuale basato su un save() Eloquent dell'intero blob lo
     * sovrascrive e le perde silenziosamente.
     *
     * NOTA su come è stato verificato empiricamente (non dedotto): un
     * primo tentativo di questo test passava per una vera
     * `PUT /nova-api/layers/{id}` agganciando un listener sull'evento
     * "saving" del modello Layer per iniettare lì il ricalcolo concorrente.
     * È emerso che QUALSIASI save() di Layer — anche senza toccare il
     * campo "name" e anche dopo aver spostato Network/Season su
     * `persistManualValue()` — marca comunque `properties` dirty e lo
     * riscrive per intero dal blob in memoria: il trait Spatie
     * `HasTranslations` (usato da `Wm\WmPackage\Models\Layer` per
     * name/title/subtitle/description) normalizza al save i path JSON
     * traducibili riserializzando tutto `properties`, indipendentemente
     * dai campi effettivamente inviati nella request (verificato con un
     * dump di `$model->getDirty()` nell'evento "saving": conteneva
     * `properties` anche a payload vuoto di "name"). È un difetto
     * preesistente e distinto in wm-package (fuori scope: nessuna modifica
     * a wm-package consentita in questo ciclo) che rende impossibile una
     * dimostrazione end-to-end via HTTP senza toccare quel trait: QUALSIASI
     * PUT su un Layer, non solo quello dei campi qui corretti, riscrive
     * comunque l'intero blob a valle. Il test è quindi scritto al livello
     * del codice che abbiamo effettivamente cambiato — il metodo del
     * service, non il giro HTTP completo — cosa che resta comunque una
     * dimostrazione valida e deterministica del difetto e della sua
     * correzione, sullo stesso identico punto (properties->attributes).
     */
    public function test_manual_value_writer_does_not_erase_concurrently_written_calculated_values(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $layer = Layer::factory()->create([
            'user_id' => $admin->id,
            'properties' => ['attributes' => ['season' => ['summer']]],
        ]);

        $service = app(LayerAttributesService::class);

        // --- Riproduzione del difetto: il vecchio meccanismo (attributo
        // Eloquent + save() dell'intero blob) perde le chiavi calcolate
        // scritte concorrentemente fra il caricamento del modello (T0) e
        // il flush del save() (T1). Se questa asserzione NON fallisse, il
        // test non staerebbe esercitando davvero la finestra di
        // concorrenza.
        $staleModel = Layer::find($layer->id); // T0: modello caricato prima del ricalcolo concorrente
        $service->persistCalculatedValues($layer, ['distance' => 12.5, 'stage_count' => 3]); // ricalcolo concorrente, T0.5
        $staleModel->setAttribute('properties->attributes->walking_network', 'lwn'); // T1: salvataggio "vecchio stile"
        $staleModel->save();

        $rowOld = DB::selectOne("SELECT properties->'attributes' AS attributes FROM layers WHERE id = ?", [$layer->id]);
        $attributesOld = json_decode($rowOld->attributes, true);

        $this->assertSame('lwn', $attributesOld['walking_network'] ?? null);
        $this->assertNull($attributesOld['distance'] ?? null, 'Il vecchio meccanismo (save() Eloquent dell\'intero blob) deve perdere la chiave calcolata scritta concorrentemente: se questa asserzione fallisce, il test non riproduce più il difetto.');
        $this->assertNull($attributesOld['stage_count'] ?? null);

        // --- Correzione: persistManualValue() scrive via jsonb_set solo
        // la propria chiave, senza mai leggere/riscrivere l'intero blob in
        // PHP — nessuna finestra di concorrenza con un ricalcolo che scriva
        // le CALCULATED_KEYS nel frattempo.
        $layer2 = Layer::factory()->create([
            'user_id' => $admin->id,
            'properties' => ['attributes' => ['season' => ['summer']]],
        ]);

        Layer::find($layer2->id); // T0: modello "aperto in Nova", non più usato per il salvataggio
        $service->persistCalculatedValues($layer2, ['distance' => 12.5, 'stage_count' => 3]); // ricalcolo concorrente, T0.5
        $service->persistManualValue($layer2, 'walking_network', 'lwn'); // T1: salvataggio del campo manuale, via il nuovo writer

        $rowNew = DB::selectOne("SELECT properties->'attributes' AS attributes FROM layers WHERE id = ?", [$layer2->id]);
        $attributesNew = json_decode($rowNew->attributes, true);

        $this->assertSame('lwn', $attributesNew['walking_network'] ?? null, 'Il valore manuale appena scritto deve essere presente.');
        $this->assertSame(12.5, $attributesNew['distance'] ?? null, 'La chiave calcolata scritta durante la finestra di concorrenza non deve essere persa dal nuovo writer manuale.');
        $this->assertSame(3, $attributesNew['stage_count'] ?? null, 'La chiave calcolata scritta durante la finestra di concorrenza non deve essere persa dal nuovo writer manuale.');

        // Ordine inverso (scrittura manuale prima del ricalcolo): stesso
        // risultato, perché entrambe le scritture sono jsonb_set atomici su
        // path disgiunti, non un read-modify-write dell'intero blob.
        $layer3 = Layer::factory()->create(['user_id' => $admin->id, 'properties' => []]);
        $service->persistManualValue($layer3, 'walking_network', 'lwn');
        $service->persistCalculatedValues($layer3, ['distance' => 12.5, 'stage_count' => 3]);

        $rowNew2 = DB::selectOne("SELECT properties->'attributes' AS attributes FROM layers WHERE id = ?", [$layer3->id]);
        $attributesNew2 = json_decode($rowNew2->attributes, true);

        $this->assertSame('lwn', $attributesNew2['walking_network'] ?? null);
        $this->assertSame(12.5, $attributesNew2['distance'] ?? null);
        $this->assertSame(3, $attributesNew2['stage_count'] ?? null);
    }

    public function test_emptying_network_removes_the_key_instead_of_setting_it_to_null(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $layer = Layer::factory()->create([
            'user_id' => $admin->id,
            'properties' => ['attributes' => ['walking_network' => 'lwn']],
        ]);

        $response = $this->actingAs($admin)
            ->putJson('/nova-api/layers/'.$layer->id, [
                'name' => $layer->name,
                'properties->attributes->walking_network' => '',
            ]);

        $response->assertStatus(200);

        $exists = (bool) DB::selectOne(
            "SELECT (jsonb_exists(properties->'attributes', 'walking_network'))::int AS e FROM layers WHERE id = ?",
            [$layer->id]
        )->e;

        $this->assertFalse($exists, 'La chiave "walking_network" deve essere assente dopo aver svuotato il campo, non impostata a null.');
    }

    public function test_emptying_season_removes_the_key_instead_of_setting_it_to_null(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $layer = Layer::factory()->create([
            'user_id' => $admin->id,
            'properties' => ['attributes' => ['season' => ['summer']]],
        ]);

        $response = $this->actingAs($admin)
            ->putJson('/nova-api/layers/'.$layer->id, [
                'name' => $layer->name,
                'properties->attributes->season' => json_encode([]),
            ]);

        $response->assertStatus(200);

        $exists = (bool) DB::selectOne(
            "SELECT (jsonb_exists(properties->'attributes', 'season'))::int AS e FROM layers WHERE id = ?",
            [$layer->id]
        )->e;

        $this->assertFalse($exists, 'La chiave "season" deve essere assente dopo aver svuotato il campo, non impostata a null.');
    }
}

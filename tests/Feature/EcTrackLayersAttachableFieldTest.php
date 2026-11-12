<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Nova\Layer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Il campo «Layers» della tappa, come già EcPois/EcTracks (oc:8611), referenziava la Resource
 * Nova del package. Lì non è una questione di permessi — il campo è riservato all'Administrator
 * — ma di costo: `Wm\WmPackage\Nova\Layer` aggiunge le Zone fra le tassonomie storiche, e Nova
 * costruisce i campi della Resource una volta per ogni voce dell'elenco di attach. Con
 * `taxonomy_wheres` piena (7.930 righe, 104 MB) la richiesta impiegava 124 secondi e il menu non
 * si apriva (oc:8974).
 */
class EcTrackLayersAttachableFieldTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();

        RolesAndPermissionsService::seedDatabase();

        if (WmApp::count() === 0) {
            WmApp::factory()->create();
        }
    }

    private function novaRequestFor($user): NovaRequest
    {
        $this->actingAs($user);

        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function layersField($user): ?MorphToMany
    {
        $track = EcTrack::factory()->createQuietly(['user_id' => $user->id]);

        foreach ((new \App\Nova\EcTrack($track))->fields($this->novaRequestFor($user)) as $field) {
            if ($field instanceof MorphToMany && $field->attribute === 'layers') {
                return $field;
            }
        }

        return null;
    }

    public function test_layers_field_points_to_local_layer_resource(): void
    {
        $admin = $this->createUserWithRole('Administrator');

        $field = $this->layersField($admin);

        $this->assertInstanceOf(MorphToMany::class, $field);
        $this->assertSame(Layer::class, $field->resourceClass);
    }

    public function test_layers_field_stays_hidden_to_validator(): void
    {
        $validator = $this->createUserWithRole('Validator');

        $field = $this->layersField($validator);

        $this->assertNotNull($field);
        $this->assertFalse($field->authorize($this->novaRequestFor($validator)));
    }

    /**
     * La query dell'attach non deve portarsi dietro l'eager load della Resource (tracce, POI,
     * App, App associate): per comporre le voci del menu bastano id e nome del cammino.
     */
    public function test_relatable_layers_query_drops_eager_loads_and_selects_few_columns(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $request = $this->novaRequestFor($admin);

        $query = \App\Nova\EcTrack::relatableLayers($request, Layer::newModel()->newQuery()->with(['ecTracks', 'ecPois']));

        $this->assertSame([], $query->getEagerLoads());
        $this->assertSame(['layers.id', 'layers.name', 'layers.user_id'], $query->getQuery()->columns);
    }
}

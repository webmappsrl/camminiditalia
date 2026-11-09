<?php

namespace Tests\Feature;

use App\Nova\EcPoi as NovaEcPoi;
use App\Nova\EcTrack as NovaEcTrack;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Resources\MergeValue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Nella sezione Tassonomie di una tappa e di un POI il package 1.6.1 mette anche le tassonomie
 * storiche dichiarate in config (`legacy_taxonomies`, cioè le Attività). Su Cammini d'Italia
 * quel tab non si vuole: le Attività restano gestibili dalla loro relazione, ma non si
 * ripropongono qui (oc:8951). Deve invece restare il tab Generale con le Zone calcolate dalla
 * geometria, che è in sola lettura.
 */
class EcResourcesLegacyTaxonomiesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();
    }

    public function test_ec_poi_shows_no_activities_choice_field(): void
    {
        $attributes = $this->attributesOf((new NovaEcPoi(new EcPoi))->fields(NovaRequest::create('/')));

        $this->assertNotContains('legacy_taxonomy_activities', $attributes);
    }

    public function test_ec_poi_shows_poi_types_as_a_taxonomy_choice(): void
    {
        $attributes = $this->attributesOf((new NovaEcPoi(new EcPoi))->fields(NovaRequest::create('/')));

        $this->assertContains('legacy_taxonomy_poi_types', $attributes);
    }

    /**
     * Un solo posto da cui si modificano i Tipi POI: quello nella sezione Tassonomie. La lista
     * di attach del package resterebbe un secondo controllo sulla stessa relazione nella stessa
     * pagina, che su un altro shard ha già svuotato le associazioni al primo salvataggio.
     */
    public function test_ec_poi_has_no_attach_list_for_poi_types(): void
    {
        $attributes = $this->attributesOf((new NovaEcPoi(new EcPoi))->fields(NovaRequest::create('/')));

        $this->assertNotContains('taxonomyPoiTypes', $attributes);
    }

    public function test_ec_track_keeps_the_computed_zones_field(): void
    {
        $attributes = $this->attributesOf((new NovaEcTrack(new EcTrack))->fields(NovaRequest::create('/')));

        $this->assertContains('computed_taxonomy_wheres', $attributes);
    }

    public function test_ec_track_shows_no_activities_choice_field(): void
    {
        $attributes = $this->attributesOf((new NovaEcTrack(new EcTrack))->fields(NovaRequest::create('/')));

        $this->assertNotContains('legacy_taxonomy_activities', $attributes);
    }

    /**
     * @param  array<int, mixed>  $fields
     * @return array<int, string>
     */
    private function attributesOf(array $fields): array
    {
        $attributes = [];

        $walk = function ($items) use (&$walk, &$attributes) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $walk($item);

                    continue;
                }
                // Panel, Tab e TabsGroup estendono MergeValue: i campi stanno in ->data.
                if ($item instanceof MergeValue) {
                    $walk($item->data);

                    continue;
                }
                if (is_object($item) && property_exists($item, 'attribute')) {
                    $attributes[] = $item->attribute;
                }
            }
        };

        $walk($fields);

        return $attributes;
    }
}

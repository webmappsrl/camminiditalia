<?php

namespace Tests\Feature;

use App\Models\User;
use App\Nova\EcPoi as NovaEcPoi;
use App\Nova\EcTrack as NovaEcTrack;
use App\Nova\Layer as NovaLayer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Resources\MergeValue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\TabsGroup;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Layer as WmNovaLayer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Le sezioni di tassonomie che wm-package aggiunge con oc:8910 non compaiono su camminiditalia
 * (oc:8944).
 *
 * Layer, sezione «Filters»: i cammini non si filtrano per tassonomia (oc:8311), e un filtro
 * salvato su un layer in modalità auto lancerebbe l'assegnazione per tassonomia, che qui non
 * vale: la modalità auto assegna per owner.
 *
 * EcTrack, sezione «Taxonomies»: le attività delle tappe sono quasi inutilizzate e la regione è
 * già mostrata da oc:8588; in cima alla detail view sarebbe solo rumore.
 *
 * EcPoi, sezione «Taxonomies»: la tassonomia usata davvero sono i tipi di POI, che hanno già il
 * loro MorphToMany «Taxonomy Poi Types».
 */
class TaxonomySectionsHiddenTest extends TestCase
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

    /** @return array{fields: array<int, string|null>, groups: array<int, string|null>} */
    private function attributesOf(object $resource): array
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $admin);

        $fields = [];
        $groups = [];
        $walk = function ($items) use (&$walk, &$fields, &$groups) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $walk($item);

                    continue;
                }
                if ($item instanceof TabsGroup) {
                    $groups[] = $item->attribute;
                }
                // Panel e Tab estendono MergeValue: i campi contenuti stanno in ->data.
                if ($item instanceof MergeValue) {
                    $walk($item->data);

                    continue;
                }
                if (is_object($item) && property_exists($item, 'attribute')) {
                    $fields[] = $item->attribute;
                }
            }
        };

        $walk($resource->fields($request));

        return ['fields' => $fields, 'groups' => $groups];
    }

    public function test_layer_fields_do_not_expose_taxonomy_filters(): void
    {
        ['fields' => $fields, 'groups' => $groups] = $this->attributesOf(new NovaLayer(new Layer));

        foreach (['taxonomyActivities', 'taxonomyWheres', 'taxonomyTerms'] as $relation) {
            $this->assertNotContains($relation, $fields, "Il campo {$relation} non deve comparire sul Layer");
        }
        $this->assertNotContains(WmNovaLayer::taxonomySectionKey(), $groups, 'La sezione «Filters» non deve comparire sul Layer');
    }

    public function test_ec_track_fields_do_not_expose_taxonomy_section(): void
    {
        ['groups' => $groups] = $this->attributesOf(new NovaEcTrack(new EcTrack));

        $this->assertNotContains(NovaEcTrack::taxonomySectionKey(), $groups, 'La sezione «Taxonomies» non deve comparire sulla EcTrack');
    }

    public function test_ec_poi_fields_do_not_expose_taxonomy_section(): void
    {
        ['groups' => $groups] = $this->attributesOf(new NovaEcPoi(new EcPoi(['properties' => []])));

        $this->assertNotContains(NovaEcPoi::taxonomySectionKey(), $groups, 'La sezione «Taxonomies» non deve comparire sulla EcPoi');
    }
}

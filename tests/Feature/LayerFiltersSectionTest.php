<?php

namespace Tests\Feature;

use App\Models\User;
use App\Nova\Layer as NovaLayer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Il package 1.6.1 aggiunge al Layer una sezione di filtri per tassonomia (oc:8910).
 * Cammini d'Italia non la usa — la modalità automatica assegna gli EC per proprietario e non
 * passa dalla tassonomia (oc:8311) — e soprattutto costruirla costa: le opzioni delle Zone si
 * ricavano con un SELECT su taxonomy_wheres, che qui ha quasi ottomila righe con dentro le
 * geometrie. Va quindi impedita a monte, non filtrata a valle (oc:8951).
 */
class LayerFiltersSectionTest extends TestCase
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

    public function test_layer_fields_contain_no_taxonomy_section(): void
    {
        $this->assertFalse(
            $this->fieldsContainTaxonomySection($this->userWithRole('Administrator')),
            'La sezione delle tassonomie non deve arrivare ai fields del Layer.'
        );
    }

    public function test_validator_sees_no_taxonomy_section_either(): void
    {
        $this->assertFalse(
            $this->fieldsContainTaxonomySection($this->userWithRole('Validator')),
            'Nemmeno un Validator deve vedere la sezione delle tassonomie sul Layer.'
        );
    }

    public function test_building_layer_fields_does_not_query_taxonomy_wheres(): void
    {
        $user = $this->userWithRole('Administrator');
        Auth::login($user);

        DB::enableQueryLog();
        DB::flushQueryLog();

        (new NovaLayer(new Layer))->fields(NovaRequest::create('/'));

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertFalse(
            $queries->contains(fn (string $query) => str_contains($query, 'taxonomy_wheres')),
            'Costruire i fields del Layer non deve leggere taxonomy_wheres.'
        );
    }

    private function fieldsContainTaxonomySection(User $user): bool
    {
        Auth::login($user);

        $fields = (new NovaLayer(new Layer))->fields(NovaRequest::create('/'));

        // Tab::group($label, $tabs, $key) mette la chiave in `attribute`, non in meta():
        // verificato ispezionando il TabsGroup costruito dal package (oc:8951).
        foreach ($fields as $field) {
            if (($field->attribute ?? null) === NovaLayer::taxonomySectionKey()) {
                return true;
            }
        }

        return false;
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}

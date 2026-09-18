<?php

namespace Tests\Feature;

use App\Models\User;
use App\Nova\Traits\HasLayerOverride;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Fields\Hidden;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class HasLayerOverrideTest extends TestCase
{
    use DatabaseTransactions;

    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();

        if (WmApp::count() === 0) {
            WmApp::factory()->create();
        }

        // UgcObserver dispatches ResolveUgcLayerJob/SendUgcReportMailJob for
        // form.id=report reports created below; faking the queue isolates
        // every test here from that unrelated side effect (mail sending is
        // not reachable in the test environment).
        Queue::fake();

        $this->subject = new class
        {
            use HasLayerOverride;

            public $properties = [];

            public static $model;
        };
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        return $admin;
    }

    private function makeAdminRequest(array $params = []): NovaRequest
    {
        $admin = $this->makeAdmin();
        $request = NovaRequest::create('/', $params ? 'POST' : 'GET', $params);
        $request->setUserResolver(fn () => $admin);

        return $request;
    }

    public function test_apply_manual_layer_override_sets_layer_id_and_marks_as_manual(): void
    {
        $layer = Layer::factory()->create();
        $track = UgcTrack::factory()->create(['properties' => ['form' => ['id' => 'report']]]);
        $admin = $this->makeAdmin();

        $this->subject::applyManualLayerOverride($track, $layer->id, $admin);
        $track->save();
        $track->refresh();

        $this->assertSame($layer->id, $track->properties['layer_id']);
        $this->assertFalse($track->properties['layer_id_auto_resolved']);
        $this->assertSame($layer->id, $track->properties['form']['layer_id']);
    }

    public function test_apply_manual_layer_override_can_clear_the_layer(): void
    {
        $layer = Layer::factory()->create();
        $track = UgcTrack::factory()->create([
            'properties' => ['layer_id' => $layer->id, 'layer_id_auto_resolved' => true, 'form' => ['id' => 'report', 'layer_id' => $layer->id]],
        ]);
        $admin = $this->makeAdmin();

        $this->subject::applyManualLayerOverride($track, null, $admin);
        $track->save();
        $track->refresh();

        $this->assertNull($track->properties['layer_id']);
        $this->assertFalse($track->properties['layer_id_auto_resolved']);
        $this->assertNull($track->properties['form']['layer_id']);
    }

    public function test_apply_manual_layer_override_ignores_missing_form_key(): void
    {
        $layer = Layer::factory()->create();
        $poi = UgcPoi::factory()->create(['properties' => []]);
        $admin = $this->makeAdmin();

        $this->subject::applyManualLayerOverride($poi, $layer->id, $admin);
        $poi->save();
        $poi->refresh();

        $this->assertSame($layer->id, $poi->properties['layer_id']);
        $this->assertArrayNotHasKey('form', $poi->properties);
    }

    public function test_layer_override_field_returns_null_for_non_administrator(): void
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');

        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $validator);

        $this->assertNull($this->subject->layerOverrideField($request));
    }

    public function test_layer_override_field_returns_select_for_administrator(): void
    {
        $this->assertInstanceOf(Select::class, $this->subject->layerOverrideField($this->makeAdminRequest()));
    }

    public function test_layer_override_original_field_returns_null_for_non_administrator(): void
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');

        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $validator);

        $this->assertNull($this->subject->layerOverrideOriginalField($request));
    }

    public function test_layer_override_original_field_returns_hidden_for_administrator(): void
    {
        $this->assertInstanceOf(Hidden::class, $this->subject->layerOverrideOriginalField($this->makeAdminRequest()));
    }

    public function test_layer_override_fill_using_sets_layer_when_value_differs(): void
    {
        $oldLayer = Layer::factory()->create();
        $newLayer = Layer::factory()->create();

        $track = UgcTrack::factory()->create([
            'properties' => ['form' => ['id' => 'report'], 'layer_id' => $oldLayer->id, 'layer_id_auto_resolved' => true],
        ]);

        $request = $this->makeAdminRequest([
            'layer_override' => $newLayer->id,
            'layer_override_original' => $oldLayer->id,
        ]);

        $field = $this->subject->layerOverrideField($request);
        $field->fillInto($request, $track, 'layer_override', 'layer_override');
        $track->save();
        $track->refresh();

        $this->assertSame($newLayer->id, $track->properties['layer_id']);
        $this->assertFalse($track->properties['layer_id_auto_resolved']);
    }

    public function test_layer_override_fill_using_clears_layer_when_value_empty(): void
    {
        $layer = Layer::factory()->create();

        $track = UgcTrack::factory()->create([
            'properties' => ['form' => ['id' => 'report'], 'layer_id' => $layer->id, 'layer_id_auto_resolved' => true],
        ]);

        $request = $this->makeAdminRequest([
            'layer_override' => '',
            'layer_override_original' => $layer->id,
        ]);

        $field = $this->subject->layerOverrideField($request);
        $field->fillInto($request, $track, 'layer_override', 'layer_override');
        $track->save();
        $track->refresh();

        $this->assertNull($track->properties['layer_id']);
        $this->assertFalse($track->properties['layer_id_auto_resolved']);
    }

    public function test_layer_override_fill_using_is_noop_when_value_unchanged(): void
    {
        $layer = Layer::factory()->create();

        $track = UgcTrack::factory()->create([
            'properties' => ['form' => ['id' => 'report'], 'layer_id' => $layer->id, 'layer_id_auto_resolved' => true],
        ]);

        $request = $this->makeAdminRequest([
            'layer_override' => $layer->id,
            'layer_override_original' => $layer->id,
        ]);

        $field = $this->subject->layerOverrideField($request);
        $field->fillInto($request, $track, 'layer_override', 'layer_override');
        $track->save();
        $track->refresh();

        // Same value as before: no spurious rewrite, layer_id_auto_resolved stays true.
        $this->assertSame($layer->id, $track->properties['layer_id']);
        $this->assertTrue($track->properties['layer_id_auto_resolved']);
    }

    public function test_layer_override_fill_using_is_noop_when_unrelated_save_happens_after_concurrent_auto_resolution(): void
    {
        // Reproduces the race Finding #3 (wm-review-ticket, oc:8575): an admin
        // opens the Update form while layer_id is still null (job not run yet),
        // then a concurrent ResolveUgcLayerJob resolves and saves a layer BEFORE
        // the admin saves an unrelated field. The browser still submits the
        // stale page-load value for both layer_override and its companion
        // layer_override_original (both null, matching what was rendered) —
        // the fill must recognize "admin never touched this" from that pair,
        // not from the live (now-changed) DB value, and leave the fresh
        // auto-resolution untouched.
        $autoResolvedLayer = Layer::factory()->create();

        // Stato al momento del render della pagina: nessun layer assegnato.
        $track = UgcTrack::factory()->create([
            'properties' => ['form' => ['id' => 'report']],
        ]);

        // Il job di risoluzione automatica corre e salva DOPO il render,
        // PRIMA che l'admin clicchi Salva.
        $track->setAttribute('properties', [
            'form' => ['id' => 'report'],
            'layer_id' => $autoResolvedLayer->id,
            'layer_id_auto_resolved' => true,
        ]);
        $track->save();

        // Il browser risottomette il valore stale visto al render (null per
        // entrambi i campi), non toccando affatto il select.
        $request = $this->makeAdminRequest([
            'layer_override' => '',
            'layer_override_original' => '',
        ]);

        $field = $this->subject->layerOverrideField($request);
        $field->fillInto($request, $track, 'layer_override', 'layer_override');
        $track->save();
        $track->refresh();

        $this->assertSame($autoResolvedLayer->id, $track->properties['layer_id']);
        $this->assertTrue($track->properties['layer_id_auto_resolved']);
    }

    public function test_layer_override_fill_using_is_noop_when_attribute_absent(): void
    {
        $layer = Layer::factory()->create();

        $originalProperties = ['form' => ['id' => 'report'], 'layer_id' => $layer->id, 'layer_id_auto_resolved' => true];
        $track = UgcTrack::factory()->create([
            'properties' => $originalProperties,
        ]);

        // No 'layer_override' key at all: simulates an unrelated Nova update
        // that never touched this field.
        $request = $this->makeAdminRequest([]);

        $field = $this->subject->layerOverrideField($request);
        $field->fillInto($request, $track, 'layer_override', 'layer_override');
        $track->save();
        $track->refresh();

        $this->assertEquals($originalProperties, $track->properties);
    }
}

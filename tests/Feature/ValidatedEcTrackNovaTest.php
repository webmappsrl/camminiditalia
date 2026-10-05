<?php

namespace Tests\Feature;

use App\Models\CertificationRequest;
use App\Models\EcTrack;
use App\Models\ValidatedEcTrack;
use App\Nova\Filters\ValidatedEcTrackLayerFilter;
use App\Nova\Filters\ValidatedEcTrackSourceFilter;
use App\Nova\Filters\ValidatedEcTrackUserFilter;
use App\Nova\ValidatedEcTrack as NovaValidatedEcTrack;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Sezione Nova «Tappe validate» (oc:8676): scoping per cammino, campi, filtri
 * e sola lettura.
 */
class ValidatedEcTrackNovaTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    private const URI = '/nova-api/validated-ec-tracks';

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
     * Associazione al layer con insert diretto su `layerables`: attach()
     * farebbe scattare LayerableObserver (oc:8080), che cambia l'ownership.
     *
     * @param  array<int, Layer>  $layers
     */
    private function track(int $ownerId, array $layers = [], string $name = 'Tappa'): WmEcTrack
    {
        $track = EcTrack::factory()->create([
            'user_id' => $ownerId,
            'name' => ['it' => $name],
            'properties' => [],
        ]);

        foreach ($layers as $layer) {
            DB::table('layerables')->insert([
                'layer_id' => $layer->id,
                'layerable_type' => (new (config('wm-package.ec_track_model')))->getMorphClass(),
                'layerable_id' => $track->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $track;
    }

    private function validate(User $walker, WmEcTrack $track, Layer $layer, string $source = ValidatedEcTrack::SOURCE_MANUAL, ?int $requestId = null, ?Carbon $at = null): ValidatedEcTrack
    {
        return ValidatedEcTrack::create([
            'user_id' => $walker->id,
            'ec_track_id' => $track->id,
            'layer_id' => $layer->id,
            'certification_request_id' => $requestId,
            'source' => $source,
            'validated_at' => $at ?? now(),
        ]);
    }

    private function novaRequestFor(User $user): NovaRequest
    {
        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    /**
     * @return array<int, int>
     */
    private function indexIds(User $user): array
    {
        return NovaValidatedEcTrack::indexQuery($this->novaRequestFor($user), ValidatedEcTrack::query())
            ->pluck('id')->sort()->values()->all();
    }

    /**
     * @return array<int, int>
     */
    private function apiIds(User $user, array $filters = [], string $query = ''): array
    {
        $uri = self::URI.'?perPage=100'.$query;
        if ($filters !== []) {
            $uri .= '&filters='.base64_encode(json_encode(
                collect($filters)->map(fn ($value, $class) => [$class => $value])->values()->all()
            ));
        }

        return collect($this->actingAs($user)->getJson($uri)->assertOk()->json('resources'))
            ->pluck('id.value')->sort()->values()->all();
    }

    /**
     * Due Validator con un layer ciascuno; nel layer di A anche una tappa di
     * proprietà di B (non conta per A). Validazioni: manuale e gps su tappe di
     * A, una sulla tappa di B nel layer di A, una nel layer di B.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        $a = $this->createUserWithRole('Validator');
        $b = $this->createUserWithRole('Validator');
        $layerA = $this->createLayer($a->id);
        $layerB = $this->createLayer($b->id);

        $tA1 = $this->track($a->id, [$layerA], 'A1');
        $tA2 = $this->track($a->id, [$layerA], 'A2');
        $tForeignInA = $this->track($b->id, [$layerA, $layerB], 'B in A');
        $tB = $this->track($b->id, [$layerB], 'B1');

        $walker1 = User::factory()->create(['name' => 'Mario Rossi']);
        $walker2 = User::factory()->create(['name' => 'Anna Bianchi']);

        return [
            'a' => $a, 'b' => $b, 'layerA' => $layerA, 'layerB' => $layerB,
            'walker1' => $walker1, 'walker2' => $walker2,
            'manualA' => $this->validate($walker1, $tA1, $layerA, ValidatedEcTrack::SOURCE_MANUAL),
            'gpsA' => $this->validate($walker2, $tA2, $layerA, ValidatedEcTrack::SOURCE_GPS),
            'foreignInA' => $this->validate($walker1, $tForeignInA, $layerA),
            'onB' => $this->validate($walker2, $tB, $layerB, ValidatedEcTrack::SOURCE_GPS),
        ];
    }

    public function test_administrator_sees_all_validations(): void
    {
        $s = $this->scenario();
        $admin = $this->createUserWithRole('Administrator');
        $expected = collect([$s['manualA'], $s['gpsA'], $s['foreignInA'], $s['onB']])->pluck('id')->sort()->values()->all();

        $this->assertSame($expected, array_values(array_intersect($this->indexIds($admin), $expected)));
        $this->assertSame($expected, array_values(array_intersect($this->apiIds($admin), $expected)));
    }

    public function test_validator_sees_validations_of_managed_tracks_in_own_layers(): void
    {
        $s = $this->scenario();
        $expected = collect([$s['manualA'], $s['gpsA']])->pluck('id')->sort()->values()->all();

        $this->assertSame($expected, $this->indexIds($s['a']));
        $this->assertSame($expected, $this->apiIds($s['a']));
        $this->assertNull($s['gpsA']->certification_request_id);

        // Regola B: la tappa di B presente nel layer di A è gestita da B (suo layer).
        $this->assertSame(
            collect([$s['foreignInA'], $s['onB']])->pluck('id')->sort()->values()->all(),
            $this->indexIds($s['b'])
        );

        // Detail di una riga non visibile: negato.
        $response = $this->actingAs($s['a'])->getJson(self::URI.'/'.$s['foreignInA']->id);
        $this->assertContains($response->status(), [403, 404]);
        $this->actingAs($s['a'])->getJson(self::URI.'/'.$s['manualA']->id)->assertOk();
    }

    public function test_validator_without_layers_sees_nothing(): void
    {
        $this->scenario();
        $validator = $this->createUserWithRole('Validator');

        $this->assertSame([], $this->indexIds($validator));
        $this->assertSame([], $this->apiIds($validator));
    }

    public function test_guest_cannot_access_resource(): void
    {
        $s = $this->scenario();

        foreach ([$this->createUserWithRole('Guest'), $this->createUserWithoutRole()] as $user) {
            $this->actingAs($user)->getJson(self::URI)->assertForbidden();
            $this->assertContains(
                $this->actingAs($user)->getJson(self::URI.'/'.$s['manualA']->id)->status(),
                [403, 404]
            );
            $this->assertSame([], $this->indexIds($user));
            $this->assertFalse(NovaValidatedEcTrack::authorizedToViewAny($this->novaRequestFor($user)));
        }
    }

    public function test_request_detail_panel_uses_layer_scoping(): void
    {
        $s = $this->scenario();
        $request = CertificationRequest::create([
            'user_id' => $s['walker1']->id,
            'layer_id' => $s['layerA']->id,
            'status' => CertificationRequest::STATUS_APPROVED,
            'disclaimer_accepted_at' => now(),
        ]);
        $s['manualA']->forceFill(['certification_request_id' => $request->id])->save();
        $s['foreignInA']->forceFill(['certification_request_id' => $request->id])->save();

        $uri = self::URI.'?viaResource=certification-requests&viaResourceId='.$request->id
            .'&viaRelationship=validatedTracks&relationshipType=hasMany';

        // Validator A: la richiesta è sul suo layer, ma la tappa di B non conta per lui.
        $ids = collect($this->actingAs($s['a'])->getJson($uri)->assertOk()->json('resources'))->pluck('id.value')->all();
        $this->assertSame([$s['manualA']->id], $ids);

        $admin = $this->createUserWithRole('Administrator');
        $ids = collect($this->actingAs($admin)->getJson($uri)->assertOk()->json('resources'))->pluck('id.value')->sort()->values()->all();
        $this->assertSame(collect([$s['manualA'], $s['foreignInA']])->pluck('id')->sort()->values()->all(), $ids);
    }

    public function test_user_field_is_link_only_for_administrator(): void
    {
        $s = $this->scenario();
        $s['walker1']->update(['name' => 'Mario <b>Rossi</b>']);
        $admin = $this->createUserWithRole('Administrator');

        $userField = function (User $viewer) use ($s) {
            $rows = collect($this->actingAs($viewer)->getJson(self::URI.'?perPage=100')->assertOk()->json('resources'));
            $row = $rows->firstWhere('id.value', $s['manualA']->id);
            $this->assertNotNull($row);

            return collect($row['fields'])->firstWhere('name', __('User'));
        };

        $field = $userField($admin);
        $this->assertTrue($field['asHtml']);
        $url = url(Nova::path().'/resources/users/'.$s['walker1']->id);
        $this->assertStringContainsString('href="'.e($url).'"', $field['value']);
        $this->assertStringContainsString('Mario &lt;b&gt;Rossi&lt;/b&gt;', $field['value']);
        $this->assertStringNotContainsString('<b>', $field['value']);
        $this->assertTrue($field['sortable']);

        $field = $userField($s['a']);
        $this->assertFalse($field['asHtml']);
        $this->assertSame('Mario <b>Rossi</b>', $field['value']);

        // Colonne in ordine e «Routes» con i layer in cui la tappa conta.
        $rows = collect($this->actingAs($admin)->getJson(self::URI.'?perPage=100')->json('resources'));
        $fields = collect($rows->firstWhere('id.value', $s['foreignInA']->id)['fields']);
        $this->assertSame(
            [__('User'), __('Stage'), __('Routes'), __('Validated at'), __('Source')],
            $fields->pluck('name')->all()
        );
        $this->assertSame($s['layerB']->getStringName(), $fields->firstWhere('name', __('Routes'))['value']);
        $this->assertSame('B in A', $fields->firstWhere('name', __('Stage'))['value']);
    }

    public function test_index_default_ordering_is_validated_at_desc(): void
    {
        $s = $this->scenario();
        $s['manualA']->forceFill(['validated_at' => now()->subDays(3)])->save();
        $s['gpsA']->forceFill(['validated_at' => now()->subDay()])->save();

        $ids = collect($this->actingAs($s['a'])->getJson(self::URI)->assertOk()->json('resources'))->pluck('id.value')->all();
        $this->assertSame([$s['gpsA']->id, $s['manualA']->id], $ids);
    }

    public function test_layer_filter_uses_managed_tracks_not_layer_id(): void
    {
        $s = $this->scenario();
        $admin = $this->createUserWithRole('Administrator');

        // Regola B: foreignInA ha layer_id = A, ma la tappa è gestita in B: il filtro B la include.
        $this->assertSame(
            collect([$s['foreignInA'], $s['onB']])->pluck('id')->sort()->values()->all(),
            $this->apiIds($admin, [ValidatedEcTrackLayerFilter::class => $s['layerB']->id])
        );
        $this->assertSame(
            collect([$s['manualA'], $s['gpsA']])->pluck('id')->sort()->values()->all(),
            $this->apiIds($admin, [ValidatedEcTrackLayerFilter::class => $s['layerA']->id])
        );

        // Opzioni: Administrator tutti i layer con tappe, Validator solo i propri.
        $adminOptions = (new ValidatedEcTrackLayerFilter)->options($this->novaRequestFor($admin));
        $this->assertContains($s['layerA']->id, $adminOptions);
        $this->assertContains($s['layerB']->id, $adminOptions);
        $this->assertSame(
            [$s['layerA']->getStringName() => $s['layerA']->id],
            (new ValidatedEcTrackLayerFilter)->options($this->novaRequestFor($s['a']))
        );
    }

    public function test_source_and_user_filters(): void
    {
        $s = $this->scenario();

        $this->assertSame([$s['gpsA']->id], $this->apiIds($s['a'], [ValidatedEcTrackSourceFilter::class => 'gps']));
        $this->assertSame([$s['manualA']->id], $this->apiIds($s['a'], [ValidatedEcTrackSourceFilter::class => 'manual']));
        $this->assertSame([$s['manualA']->id], $this->apiIds($s['a'], [ValidatedEcTrackUserFilter::class => $s['walker1']->id]));

        $this->assertSame(
            [__('Paper passport') => 'manual', __('GPS') => 'gps'],
            (new ValidatedEcTrackSourceFilter)->options($this->novaRequestFor($s['a']))
        );

        // Camminatori: solo chi ha validazioni visibili al richiedente.
        $outsider = User::factory()->create(['name' => 'Fuori Scope']);
        $this->validate($outsider, $this->track($s['b']->id, [$s['layerB']]), $s['layerB']);
        $this->assertSame(
            ['Anna Bianchi' => $s['walker2']->id, 'Mario Rossi' => $s['walker1']->id],
            (new ValidatedEcTrackUserFilter)->options($this->novaRequestFor($s['a']))
        );
        $this->assertSame([], (new ValidatedEcTrackUserFilter)->options($this->novaRequestFor($this->createUserWithRole('Guest'))));
    }

    public function test_resource_is_read_only(): void
    {
        $s = $this->scenario();
        $admin = $this->createUserWithRole('Administrator');
        $request = Request::create('/');
        $request->setUserResolver(fn () => $admin);
        $resource = new NovaValidatedEcTrack($s['manualA']);

        $this->assertFalse(NovaValidatedEcTrack::authorizedToCreate($request));
        $this->assertFalse($resource->authorizedToUpdate($request));
        $this->assertFalse($resource->authorizedToDelete($request));

        $this->actingAs($admin)->getJson(self::URI.'/creation-fields')->assertForbidden();
        $this->actingAs($admin)->putJson(self::URI.'/'.$s['manualA']->id, ['source' => 'gps'])->assertForbidden();
        $this->actingAs($admin)->deleteJson(self::URI.'?resources[]='.$s['manualA']->id);
        $this->assertDatabaseHas('validated_ec_tracks', ['id' => $s['manualA']->id, 'source' => 'manual']);
    }

    public function test_menu_item_visible_only_to_administrator_and_validator(): void
    {
        $visible = function (User $user): bool {
            $request = Request::create('/nova');
            $request->setUserResolver(fn () => $user);
            $section = collect(Nova::resolveMainMenu($request))
                ->first(fn ($section) => (string) $section->name === __('Passport'));
            if ($section === null || ! $section->authorizedToSee($request)) {
                return false;
            }
            $item = collect($section->items)->first(fn ($item) => str_contains((string) $item->path, 'validated-ec-tracks'));

            return $item !== null && $item->authorizedToSee($request);
        };

        $this->assertTrue($visible($this->createUserWithRole('Administrator')));
        $this->assertTrue($visible($this->createUserWithRole('Validator')));
        $this->assertFalse($visible($this->createUserWithRole('Guest')));
    }
}

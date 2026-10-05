<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Models\ValidatedEcTrack;
use App\Nova\Filters\ValidatedEcTrackLayerFilter;
use App\Services\StageProgressService;
use App\Support\LayerOwner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Http\Requests\LensRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Traits\HasDemClassification;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class StageProgressServiceTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    private StageProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->service = app(StageProgressService::class);
    }

    /**
     * Associazione al layer con insert diretto su `layerables`: con
     * `$layer->ecTracks()->attach()` LayerableObserver (oc:8080) trasferirebbe
     * l'ownership della tappa al proprietario del layer, rendendo impossibile
     * riprodurre le tappe con proprietario diverso.
     *
     * @param  array<int, Layer>  $layers
     * @param  array<string, mixed>  $properties
     */
    private function track(int $ownerId, array $layers = [], array $properties = [], ?int $osmid = null): WmEcTrack
    {
        $track = EcTrack::factory()->create([
            'user_id' => $ownerId,
            'osmid' => $osmid,
            'properties' => $properties,
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

    private function validate(User $user, WmEcTrack $track, Layer $layer, string $source = ValidatedEcTrack::SOURCE_MANUAL, ?Carbon $at = null): ValidatedEcTrack
    {
        return ValidatedEcTrack::create([
            'user_id' => $user->id,
            'ec_track_id' => $track->id,
            'layer_id' => $layer->id,
            'source' => $source,
            'validated_at' => $at ?? now(),
        ]);
    }

    private function setRawName(WmEcTrack $track, string $raw): void
    {
        DB::table('ec_tracks')->where('id', $track->id)->update(['name' => $raw]);
    }

    private function layerWithoutOwner(): Layer
    {
        $layer = $this->createLayer();
        $layer->forceFill(['user_id' => null])->saveQuietly();

        return $layer->fresh();
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return array<int, string> id tappa => status
     */
    private function statuses(array $progress): array
    {
        return collect($progress['tracks'])->mapWithKeys(fn ($t) => [(int) $t['id'] => (string) $t['status']])->all();
    }

    public function test_progress_counts_all_route_tracks_regardless_of_owner(): void
    {
        $a = $this->createUserWithRole('Validator');
        $b = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $layer = $this->createLayer($a->id);
        $t1 = $this->track($a->id, [$layer]);
        $t2 = $this->track($a->id, [$layer]);
        $t3 = $this->track($a->id, [$layer]);
        $foreign = $this->track($b->id, [$layer]);
        $this->validate($walker, $t1, $layer);
        $this->validate($walker, $t2, $layer);

        $progress = $this->service->progressFor($walker, $layer);

        $this->assertSame($layer->id, $progress['layer_id']);
        $this->assertSame(2, $progress['validated']);
        $this->assertSame(4, $progress['total']);
        $this->assertSame(50, $progress['percentage']);
        $this->assertFalse($progress['completed']);
        $this->assertSame([
            $t1->id => 'validated',
            $t2->id => 'validated',
            $t3->id => 'not_validated',
            $foreign->id => 'not_validated',
        ], $this->statuses($progress));
        $this->assertSame(
            [$t1->id, $t2->id, $t3->id, $foreign->id],
            array_column($progress['tracks'], 'id'),
        );
        $validated = collect($progress['tracks'])->firstWhere('id', $t1->id);
        $this->assertSame('manual', $validated['source']);
        $this->assertNotNull($validated['validated_at']);
        $notValidated = collect($progress['tracks'])->firstWhere('id', $t3->id);
        $this->assertNull($notValidated['validated_at']);
        $this->assertNull($notValidated['source']);
    }

    public function test_foreign_track_validated_elsewhere_is_validated(): void
    {
        $a = $this->createUserWithRole('Validator');
        $b = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $layerA = $this->createLayer($a->id);
        $layerB = $this->createLayer($b->id);
        foreach (range(1, 3) as $i) {
            $this->track($a->id, [$layerA]);
        }
        $foreign = $this->track($b->id, [$layerA, $layerB]);
        $this->validate($walker, $foreign, $layerB, ValidatedEcTrack::SOURCE_GPS);

        $progress = $this->service->progressFor($walker, $layerA);

        $this->assertSame(4, $progress['total']);
        $this->assertSame(1, $progress['validated']);
        $byId = collect($progress['tracks'])->keyBy('id');
        $this->assertSame('validated', $byId[$foreign->id]['status']);
        $this->assertSame('gps', $byId[$foreign->id]['source']);
    }

    public function test_layer_without_owner_counts_all_tracks(): void
    {
        $owner = $this->createUserWithRole('Administrator');
        config(['camminiditalia.default_owner_id' => $owner->id]);
        $layer = $this->layerWithoutOwner();
        $own = $this->track($owner->id, [$layer]);
        $other = $this->track(User::factory()->create()->id, [$layer]);

        $progress = $this->service->progressFor(User::factory()->create(), $layer);

        $this->assertSame(2, $progress['total']);
        $this->assertSame([
            $own->id => 'not_validated',
            $other->id => 'not_validated',
        ], $this->statuses($progress));
    }

    public function test_route_tracks_query_ignores_owner(): void
    {
        $a = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($a->id);
        $own = $this->track($a->id, [$layer]);
        $foreign = $this->track(User::factory()->create()->id, [$layer]);
        $this->track($a->id);

        $route = $this->service->routeTracksQuery()
            ->where('layerables.layer_id', $layer->id)
            ->pluck('ec_track_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $managed = $this->service->managedTracksQuery()
            ->where('layerables.layer_id', $layer->id)
            ->pluck('ec_track_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertEqualsCanonicalizing([$own->id, $foreign->id], $route);
        $this->assertSame([$own->id], $managed);
    }

    public function test_sql_owner_matches_layer_owner_helper(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $default = $this->createUserWithRole('Administrator');
        config(['camminiditalia.default_owner_id' => $default->id]);
        $owned = $this->createLayer($owner->id);
        $orphan = $this->layerWithoutOwner();
        $stranger = User::factory()->create();

        foreach ([$owner, $default, $stranger] as $user) {
            $this->track($user->id, [$owned, $orphan]);
            $this->track($user->id, [$owned]);
        }

        foreach ([$owned, $orphan] as $layer) {
            $expected = $layer->ecTracks()
                ->where('ec_tracks.user_id', LayerOwner::idFor($layer))
                ->pluck('ec_tracks.id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $actual = $this->service->managedTracksQuery()
                ->where('layerables.layer_id', $layer->id)
                ->pluck('ec_track_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $this->assertNotEmpty($expected);
            $this->assertEqualsCanonicalizing($expected, $actual);
        }
    }

    public function test_shared_track_same_owner_counts_in_both_layers(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $first = $this->createLayer($owner->id);
        $second = $this->createLayer($owner->id);
        $shared = $this->track($owner->id, [$first, $second]);
        $this->validate($walker, $shared, $first);

        foreach ([$first, $second] as $layer) {
            $progress = $this->service->progressFor($walker, $layer);
            $this->assertSame(1, $progress['validated']);
            $this->assertSame(1, $progress['total']);
            $this->assertTrue($progress['completed']);
        }
    }

    public function test_shared_track_different_owners_counts_in_both_layers(): void
    {
        $a = $this->createUserWithRole('Validator');
        $b = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $layerA = $this->createLayer($a->id);
        $layerB = $this->createLayer($b->id);
        $shared = $this->track($a->id, [$layerA, $layerB]);
        $this->validate($walker, $shared, $layerA);

        $progressA = $this->service->progressFor($walker, $layerA);
        $progressB = $this->service->progressFor($walker, $layerB);

        $this->assertSame(1, $progressA['validated']);
        $this->assertSame([$shared->id => 'validated'], $this->statuses($progressA));
        $this->assertSame(1, $progressA['total']);
        $this->assertSame(1, $progressB['validated']);
        $this->assertSame(1, $progressB['total']);
        $this->assertTrue($progressB['completed']);
        $this->assertSame([$shared->id => 'validated'], $this->statuses($progressB));
    }

    public function test_completed_layer_returns_in_progress_after_new_track(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $layer = $this->createLayer($owner->id);
        $this->validate($walker, $this->track($owner->id, [$layer]), $layer);
        $this->validate($walker, $this->track($owner->id, [$layer]), $layer);

        $before = $this->service->progressFor($walker, $layer);
        $this->assertSame(100, $before['percentage']);
        $this->assertTrue($before['completed']);

        $this->track($owner->id, [$layer]);
        $after = $this->service->progressFor($walker, $layer);

        $this->assertSame(2, $after['validated']);
        $this->assertSame(3, $after['total']);
        $this->assertFalse($after['completed']);
    }

    public function test_distance_follows_package_priority(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $layer = $this->createLayer($owner->id);
        $manual = $this->track($owner->id, [$layer], ['manual_data' => ['distance' => '29'], 'osm_data' => ['distance' => 99], 'dem_data' => ['distance' => 98]], 123);
        $this->track($owner->id, [$layer], ['osm_data' => ['distance' => 10], 'dem_data' => ['distance' => 97]], 456);
        $this->track($owner->id, [$layer], ['osm_data' => ['distance' => 10], 'dem_data' => ['distance' => 8]]);
        $this->track($owner->id, [$layer], ['dem_data' => ['distance' => 5]]);
        $this->track($owner->id, [$layer], []);
        $this->track($owner->id, [$layer], ['manual_data' => ['distance' => '12,5'], 'dem_data' => ['distance' => 96]]);
        $this->validate($walker, $manual, $layer);

        $progress = $this->service->progressFor($walker, $layer);

        $this->assertSame(52.0, $progress['km_total']);
        $this->assertSame(29.0, $progress['km_validated']);
    }

    /**
     * distanceSql() deve seguire la stessa priorità di
     * HasDemClassification::classifyField() del package. Unica differenza
     * voluta: un valore non numerico (es. "12,5") per il package è la stringa
     * stessa, per l'SQL 0 (cast protetto, nessuna correzione). L'assenza di
     * distanza (package `null`) in SQL è 0.
     */
    public function test_distance_sql_matches_package_classify_field(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        $tracks = [
            'manual' => $this->track($owner->id, [$layer], ['manual_data' => ['distance' => '29'], 'osm_data' => ['distance' => 99], 'dem_data' => ['distance' => 98]], 123),
            'manual_float' => $this->track($owner->id, [$layer], ['manual_data' => ['distance' => 3.5], 'dem_data' => ['distance' => 95]]),
            'manual_blank' => $this->track($owner->id, [$layer], ['manual_data' => ['distance' => ''], 'dem_data' => ['distance' => 7]]),
            'osm_with_osmid' => $this->track($owner->id, [$layer], ['osm_data' => ['distance' => 10], 'dem_data' => ['distance' => 97]], 456),
            'osmid_without_osm' => $this->track($owner->id, [$layer], ['dem_data' => ['distance' => 4]], 789),
            'osm_without_osmid' => $this->track($owner->id, [$layer], ['osm_data' => ['distance' => 10], 'dem_data' => ['distance' => 8]]),
            'dem' => $this->track($owner->id, [$layer], ['dem_data' => ['distance' => 5]]),
            'none' => $this->track($owner->id, [$layer], []),
            'non_numeric' => $this->track($owner->id, [$layer], ['manual_data' => ['distance' => '12,5'], 'dem_data' => ['distance' => 96]]),
        ];

        $sql = $this->service->layerTracksQuery($layer->id)
            ->pluck('distance_km', 'ec_track_id')
            ->map(fn ($km) => (float) $km);

        $classifier = new class
        {
            use HasDemClassification;
        };

        foreach ($tracks as $case => $track) {
            $package = $classifier->classifyField(EcTrack::findOrFail($track->id), 'distance')['currentValue'];
            $actual = $sql[$track->id];

            if ($case === 'non_numeric') {
                $this->assertSame('12,5', $package, $case);
                $this->assertSame(0.0, $actual, $case);
            } elseif ($case === 'none') {
                $this->assertNull($package, $case);
                $this->assertSame(0.0, $actual, $case);
            } else {
                $this->assertTrue(is_numeric($package), $case);
                $this->assertEqualsWithDelta((float) $package, $actual, 0.0001, $case);
            }
        }
    }

    public function test_km_are_rounded_to_one_decimal(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        $this->track($owner->id, [$layer], ['dem_data' => ['distance' => 10.04]]);
        $this->track($owner->id, [$layer], ['dem_data' => ['distance' => 2.03]]);

        $progress = $this->service->progressFor(User::factory()->create(), $layer);

        $this->assertSame(12.1, $progress['km_total']);
        $this->assertSame(0.0, $progress['km_validated']);
    }

    public function test_tracks_expose_name_translations_and_distance(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $other = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $layer = $this->createLayer($owner->id);
        $json = $this->track($owner->id, [$layer], ['dem_data' => ['distance' => 10.04]]);
        $plain = $this->track($owner->id, [$layer], ['dem_data' => ['distance' => 2.06]]);
        $empty = $this->track($owner->id, [$layer], []);
        $foreign = $this->track($other->id, [$layer], ['dem_data' => ['distance' => 7]]);
        $this->setRawName($json, '{"it":"Tappa 06: Pacentro - Caramanico Terme","de":null,"en":""}');
        $this->setRawName($plain, 'Tappa semplice');
        $this->setRawName($empty, '{"it":null}');
        $this->setRawName($foreign, '{"it":"Di altro proprietario"}');
        $this->validate($walker, $json, $layer);
        $this->validate($walker, $plain, $layer);

        $progress = $this->service->progressFor($walker, $layer);
        $byId = collect($progress['tracks'])->keyBy('id');

        $this->assertSame(['id', 'name', 'distance', 'status', 'progress', 'validated_at', 'source'], array_keys($byId[$json->id]));
        $this->assertEquals(['it' => 'Tappa 06: Pacentro - Caramanico Terme'], (array) $byId[$json->id]['name']);
        $this->assertSame(10.0, $byId[$json->id]['distance']);
        $this->assertSame(100, $byId[$json->id]['progress']);
        $this->assertSame(0, $byId[$foreign->id]['progress']);
        foreach ($progress['tracks'] as $t) {
            $this->assertSame($t['status'] === 'validated' ? 100 : 0, $t['progress']);
        }
        $this->assertEquals(['it' => 'Tappa semplice'], (array) $byId[$plain->id]['name']);
        $this->assertSame(2.1, $byId[$plain->id]['distance']);
        $this->assertSame([], (array) $byId[$empty->id]['name']);
        $this->assertSame(0.0, $byId[$empty->id]['distance']);
        $this->assertSame('not_validated', $byId[$foreign->id]['status']);
        $this->assertEquals(['it' => 'Di altro proprietario'], (array) $byId[$foreign->id]['name']);
        $this->assertSame(7.0, $byId[$foreign->id]['distance']);
        $this->assertSame(
            $progress['km_validated'],
            round(collect($progress['tracks'])->where('status', 'validated')->sum('distance'), 1),
        );
    }

    public function test_layer_without_tracks_has_zero_percentage(): void
    {
        $layer = $this->createLayer($this->createUserWithRole('Validator')->id);

        $progress = $this->service->progressFor(User::factory()->create(), $layer);

        $this->assertSame(0, $progress['validated']);
        $this->assertSame(0, $progress['total']);
        $this->assertSame(0, $progress['percentage']);
        $this->assertFalse($progress['completed']);
        $this->assertSame([], $progress['tracks']);
    }

    public function test_passport_lists_only_layers_with_validations(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $layer = $this->createLayer($owner->id);
        $untouched = $this->createLayer($owner->id);
        $old = $this->track($owner->id, [$layer], ['dem_data' => ['distance' => 4]]);
        $recent = $this->track($owner->id, [$layer], ['dem_data' => ['distance' => 6]]);
        $this->track($owner->id, [$layer], ['dem_data' => ['distance' => 10]]);
        $this->track($owner->id, [$untouched]);
        $this->validate($walker, $old, $layer, ValidatedEcTrack::SOURCE_MANUAL, Carbon::parse('2026-09-01 08:00:00'));
        $this->validate($walker, $recent, $layer, ValidatedEcTrack::SOURCE_GPS, Carbon::parse('2026-09-30 10:00:00'));

        $passport = $this->service->passportFor($walker);

        $this->assertCount(1, $passport);
        $this->assertSame([
            'layer_id' => $layer->id,
            'name' => $layer->getStringName(),
            'validated' => 2,
            'total' => 3,
            'percentage' => 66,
            'completed' => false,
            'km_validated' => 10.0,
            'km_total' => 20.0,
            'last_validated_at' => Carbon::parse('2026-09-30 10:00:00')->toIso8601String(),
        ], $passport[0]);
    }

    public function test_passport_includes_route_with_only_foreign_track_validated(): void
    {
        $a = $this->createUserWithRole('Validator');
        $b = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $layerA = $this->createLayer($a->id);
        $layerB = $this->createLayer($b->id);
        $this->track($a->id, [$layerA], ['dem_data' => ['distance' => 3]]);
        $foreign = $this->track($b->id, [$layerA, $layerB], ['dem_data' => ['distance' => 5]]);
        $this->validate($walker, $foreign, $layerB);

        $passport = collect($this->service->passportFor($walker))->keyBy('layer_id');

        $this->assertEqualsCanonicalizing([$layerA->id, $layerB->id], $passport->keys()->all());
        $this->assertSame(1, $passport[$layerA->id]['validated']);
        $this->assertSame(2, $passport[$layerA->id]['total']);
        $this->assertSame(8.0, $passport[$layerA->id]['km_total']);
        $this->assertSame(5.0, $passport[$layerA->id]['km_validated']);
        $this->assertTrue($passport[$layerB->id]['completed']);
    }

    public function test_summary_query_counts_route_tracks_and_scopes_rows_by_layer_owner(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $other = $this->createUserWithRole('Validator');
        $own = $this->createLayer($validator->id);
        $walker = User::factory()->create();
        $mine = collect(range(1, 11))->map(fn () => $this->track($validator->id, [$own]));
        $foreign = collect(range(1, 2))->map(fn () => $this->track($other->id, [$own]));
        $mine->take(6)->each(fn ($t) => $this->validate($walker, $t, $own));
        $this->validate($walker, $foreign[0], $own);

        $row = $this->service->summaryQuery($validator)->where('layer_id', $own->id)->first();

        $this->assertNotNull($row);
        $this->assertSame(7, (int) $row->getAttribute('validated'));
        $this->assertSame(13, (int) $row->getAttribute('total'));
        $this->assertSame(
            6,
            $this->service->scopeVisibleTo(ValidatedEcTrack::query()->where('user_id', $walker->id), $validator)->count(),
        );
    }

    public function test_passport_is_empty_without_validations(): void
    {
        $this->assertSame([], $this->service->passportFor(User::factory()->create()));
    }

    public function test_summary_query_groups_by_user_and_layer(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        $tracks = collect(range(1, 3))->map(fn () => $this->track($owner->id, [$layer]));
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->validate($first, $tracks[0], $layer, ValidatedEcTrack::SOURCE_MANUAL, Carbon::parse('2026-09-01 08:00:00'));
        $this->validate($first, $tracks[1], $layer, ValidatedEcTrack::SOURCE_MANUAL, Carbon::parse('2026-09-02 08:00:00'));
        $this->validate($second, $tracks[2], $layer, ValidatedEcTrack::SOURCE_GPS, Carbon::parse('2026-09-03 08:00:00'));

        $rows = $this->service->summaryQuery()
            ->where('layer_id', $layer->id)
            ->orderBy('user_id')
            ->get()
            ->keyBy('user_id');

        $this->assertCount(2, $rows);
        $this->assertSame(2, (int) $rows[$first->id]->getAttribute('validated'));
        $this->assertSame(3, (int) $rows[$first->id]->getAttribute('total'));
        $this->assertSame(1, (int) $rows[$second->id]->getAttribute('validated'));
        $this->assertSame(3, (int) $rows[$second->id]->getAttribute('total'));
        $this->assertSame(($second->id << StageProgressService::SUMMARY_ID_USER_SHIFT) | $layer->id, (int) $rows[$second->id]->id);
        $this->assertSame(
            '2026-09-02 08:00:00',
            Carbon::parse($rows[$first->id]->getAttribute('last_validated_at'))->format('Y-m-d H:i:s'),
        );
    }

    public function test_scope_visible_to(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $admin = $this->createUserWithRole('Administrator');
        $other = $this->createUserWithRole('Validator');
        $walker = User::factory()->create();
        $otherWalker = User::factory()->create();
        $own = $this->createLayer($validator->id);
        $foreign = $this->createLayer($other->id);
        $counting = $this->track($validator->id, [$own]);
        $notCounting = $this->track($other->id, [$own]);
        $elsewhere = $this->track($other->id, [$foreign]);

        $manual = $this->validate($walker, $counting, $own);
        $gps = $this->validate($otherWalker, $counting, $foreign, ValidatedEcTrack::SOURCE_GPS);
        $hidden = $this->validate($walker, $notCounting, $own);
        $elsewhereValidation = $this->validate($walker, $elsewhere, $foreign);
        $this->assertNull($gps->certification_request_id);

        $ids = [$manual->id, $gps->id, $hidden->id, $elsewhereValidation->id];
        $visible = fn (User $user) => $this->service
            ->scopeVisibleTo(ValidatedEcTrack::query()->whereIn('validated_ec_tracks.id', $ids), $user)
            ->pluck('validated_ec_tracks.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertEqualsCanonicalizing($ids, $visible($admin));
        $this->assertEqualsCanonicalizing([$manual->id, $gps->id], $visible($validator));
        $this->assertSame([], $visible($this->createUserWithRole('Guest')));
        $this->assertSame([], $visible($this->createUserWithoutRole()));
    }

    public function test_summary_query_scoped_by_viewer(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $other = $this->createUserWithRole('Validator');
        $own = $this->createLayer($validator->id);
        $foreign = $this->createLayer($other->id);
        $walker = User::factory()->create();
        $this->validate($walker, $this->track($validator->id, [$own]), $own);
        $this->validate($walker, $this->track($other->id, [$foreign]), $foreign);
        $layerIds = [$own->id, $foreign->id];

        $visible = fn (?User $viewer) => $this->service->summaryQuery($viewer)
            ->whereIn('layer_id', $layerIds)
            ->pluck('layer_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertEqualsCanonicalizing($layerIds, $visible(null));
        $this->assertEqualsCanonicalizing($layerIds, $visible($this->createUserWithRole('Administrator')));
        $this->assertSame([$own->id], $visible($validator));
        $this->assertSame([], $visible($this->createUserWithRole('Guest')));
        $this->assertSame([], $visible($this->createUserWithoutRole()));
    }

    public function test_validator_as_default_owner_sees_validations_of_layer_without_owner(): void
    {
        $validator = $this->createUserWithRole('Validator');
        config(['camminiditalia.default_owner_id' => $validator->id]);
        $orphan = $this->layerWithoutOwner();
        $walker = User::factory()->create();
        $validation = $this->validate($walker, $this->track($validator->id, [$orphan]), $orphan);

        // Lens
        $this->assertSame(
            [$orphan->id],
            $this->service->summaryQuery($validator)
                ->where('layer_id', $orphan->id)
                ->pluck('layer_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        );

        // Elenco
        $this->assertSame(
            [$validation->id],
            $this->service->scopeVisibleTo(ValidatedEcTrack::query()->where('validated_ec_tracks.id', $validation->id), $validator)
                ->pluck('validated_ec_tracks.id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        );

        // Filtro cammino, su risorsa e Lens
        foreach ([NovaRequest::create('/'), LensRequest::create('/')] as $request) {
            $request->setUserResolver(fn () => $validator);
            $this->assertContains($orphan->id, (new ValidatedEcTrackLayerFilter)->options($request));
        }
    }

    public function test_summary_ids_are_unique_for_shared_track(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $first = $this->createLayer($owner->id);
        $second = $this->createLayer($owner->id);
        $walker = User::factory()->create();
        $this->validate($walker, $this->track($owner->id, [$first, $second]), $first);

        $rows = $this->service->summaryQuery()
            ->whereIn('layer_id', [$first->id, $second->id])
            ->get();

        $this->assertCount(2, $rows);
        $this->assertCount(2, $rows->pluck('id')->unique());
        $this->assertEqualsCanonicalizing(
            [($walker->id << StageProgressService::SUMMARY_ID_USER_SHIFT) | $first->id, ($walker->id << StageProgressService::SUMMARY_ID_USER_SHIFT) | $second->id],
            $rows->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_user_has_validated_ec_tracks_relation(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        // validatedEcTracks() è definita solo sul modello locale, non su Wm\WmPackage\Models\User.
        $walker = \App\Models\User::factory()->create();
        $validation = $this->validate($walker, $this->track($owner->id, [$layer]), $layer);

        $this->assertSame([$validation->id], $walker->validatedEcTracks()->pluck('id')->all());
    }
}

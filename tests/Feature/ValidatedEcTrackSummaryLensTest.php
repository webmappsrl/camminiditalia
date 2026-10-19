<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Models\ValidatedEcTrack;
use App\Nova\Filters\ValidatedEcTrackLayerFilter;
use App\Nova\Filters\ValidatedEcTrackUserFilter;
use App\Services\StageProgressService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Lens «Per camminatore e cammino» (oc:8676): una riga per coppia
 * camminatore/cammino, numeri con la regola A (tutte le tappe del cammino),
 * scoping delle righe per ruolo, nessun link al dettaglio.
 */
class ValidatedEcTrackSummaryLensTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    private const URI = '/nova-api/validated-ec-tracks/lens/validated-ec-track-summary';

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
     * Insert diretto su `layerables`: attach() farebbe scattare
     * LayerableObserver (oc:8080), che cambia l'ownership.
     *
     * @param  array<int, Layer>  $layers
     */
    private function track(int $ownerId, array $layers): WmEcTrack
    {
        $track = EcTrack::factory()->create([
            'user_id' => $ownerId,
            'name' => ['it' => 'Tappa'],
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

    private function validate(User $walker, WmEcTrack $track, Layer $layer, ?Carbon $at = null): ValidatedEcTrack
    {
        return ValidatedEcTrack::create([
            'user_id' => $walker->id,
            'ec_track_id' => $track->id,
            'layer_id' => $layer->id,
            'source' => ValidatedEcTrack::SOURCE_MANUAL,
            'validated_at' => $at ?? now(),
        ]);
    }

    private static function rowId(int $userId, int $layerId): int
    {
        return ($userId << StageProgressService::SUMMARY_ID_USER_SHIFT) | $layerId;
    }

    /**
     * @param  array<class-string, mixed>  $filters
     */
    private function lens(User $viewer, array $filters = [], string $query = ''): \Illuminate\Testing\TestResponse
    {
        $uri = self::URI.'?perPage=100'.$query;
        if ($filters !== []) {
            $uri .= '&filters='.base64_encode(json_encode(
                collect($filters)->map(fn ($value, $class) => [$class => $value])->values()->all()
            ));
        }

        return $this->actingAs($viewer)->getJson($uri);
    }

    /**
     * Righe della lens come `id` => [nome campo => valore].
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(User $viewer, array $filters = []): array
    {
        return collect($this->lens($viewer, $filters)->assertOk()->json('resources'))
            ->mapWithKeys(fn (array $r) => [
                $r['id']['value'] => collect($r['fields'])->mapWithKeys(fn (array $f) => [$f['name'] => $f['value']])->all(),
            ])
            ->all();
    }

    /**
     * Due Validator con un layer ciascuno (2 tappe per layer), due camminatori
     * con validazioni in entrambi i layer.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        $a = $this->createUserWithRole('Validator');
        $b = $this->createUserWithRole('Validator');
        $layerA = $this->createLayer($a->id);
        $layerB = $this->createLayer($b->id);

        $tA1 = $this->track($a->id, [$layerA]);
        $tA2 = $this->track($a->id, [$layerA]);
        $tB1 = $this->track($b->id, [$layerB]);
        $this->track($b->id, [$layerB]);

        $walker1 = User::factory()->create(['name' => 'Mario Rossi']);
        $walker2 = User::factory()->create(['name' => 'Anna Bianchi']);

        // walker1: A completo (2/2), B 1/2. walker2: A 1/2, B 1/2.
        $this->validate($walker1, $tA1, $layerA, Carbon::parse('2026-05-01 10:00:00'));
        $this->validate($walker1, $tA2, $layerA, Carbon::parse('2026-05-03 10:00:00'));
        $this->validate($walker1, $tB1, $layerB);
        $this->validate($walker2, $tA1, $layerA);
        $this->validate($walker2, $tB1, $layerB);

        return compact('a', 'b', 'layerA', 'layerB', 'walker1', 'walker2');
    }

    public function test_lens_returns_one_row_per_user_and_route(): void
    {
        $s = $this->scenario();
        $admin = $this->createUserWithRole('Administrator');

        $ours = [
            self::rowId($s['walker1']->id, $s['layerA']->id) => '2 / 2',
            self::rowId($s['walker1']->id, $s['layerB']->id) => '1 / 2',
            self::rowId($s['walker2']->id, $s['layerA']->id) => '1 / 2',
            self::rowId($s['walker2']->id, $s['layerB']->id) => '1 / 2',
        ];

        $rows = array_intersect_key($this->rows($admin), $ours);

        $this->assertCount(4, $rows);
        foreach ($ours as $id => $stages) {
            $this->assertSame($stages, $rows[$id][__('Stages')]);
        }

        $row = $rows[self::rowId($s['walker1']->id, $s['layerA']->id)];
        $this->assertSame($s['layerA']->getStringName(), $row[__('Route')]);
        $this->assertStringContainsString('/resources/users/'.$s['walker1']->id, $row[__('User')]);
        $this->assertStringContainsString('Mario Rossi', $row[__('User')]);
        $this->assertStringContainsString('2026-05-03', (string) $row[__('Last validation')]);
    }

    public function test_lens_status_completed_when_all_tracks_validated(): void
    {
        $s = $this->scenario();
        $rows = $this->rows($s['a']);

        $this->assertSame(__('Completed'), $rows[self::rowId($s['walker1']->id, $s['layerA']->id)][__('Status')]);
        $this->assertSame(__('In progress'), $rows[self::rowId($s['walker2']->id, $s['layerA']->id)][__('Status')]);
    }

    public function test_lens_for_validator_shows_only_own_routes(): void
    {
        $s = $this->scenario();

        $rows = $this->rows($s['a']);
        $this->assertEqualsCanonicalizing([
            self::rowId($s['walker1']->id, $s['layerA']->id),
            self::rowId($s['walker2']->id, $s['layerA']->id),
        ], array_keys($rows));

        // Validator: nome in chiaro, nessun link al profilo utente.
        $this->assertSame('Mario Rossi', $rows[self::rowId($s['walker1']->id, $s['layerA']->id)][__('User')]);

        $this->assertSame([], $this->rows($this->createUserWithRole('Validator')));
    }

    public function test_lens_counts_all_route_tracks_while_index_shows_only_managed(): void
    {
        $v = $this->createUserWithRole('Validator');
        $other = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($v->id);
        $walker = User::factory()->create();
        $mine = collect(range(1, 11))->map(fn () => $this->track($v->id, [$layer]));
        $foreign = collect(range(1, 2))->map(fn () => $this->track($other->id, [$layer]));
        $own = $mine->take(6)->map(fn ($t) => $this->validate($walker, $t, $layer));
        $this->validate($walker, $foreign[0], $layer);

        $rows = $this->rows($v);
        $this->assertSame(['7 / 13'], array_column($rows, __('Stages')));
        $this->assertSame(__('In progress'), $rows[self::rowId($walker->id, $layer->id)][__('Status')]);

        $indexIds = collect($this->actingAs($v)->getJson('/nova-api/validated-ec-tracks?perPage=100')->assertOk()->json('resources'))
            ->pluck('id.value')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
        $this->assertSame($own->pluck('id')->sort()->values()->all(), $indexIds);
    }

    public function test_lens_numbers_include_track_validated_in_another_route(): void
    {
        $a = $this->createUserWithRole('Validator');
        $b = $this->createUserWithRole('Validator');
        $layerA = $this->createLayer($a->id);
        $layerB = $this->createLayer($b->id);
        $walker = User::factory()->create();
        $this->track($a->id, [$layerA]);
        $shared = $this->track($b->id, [$layerA, $layerB]);
        $this->validate($walker, $shared, $layerB);

        $admin = $this->createUserWithRole('Administrator');
        $rows = $this->rows($admin, [ValidatedEcTrackUserFilter::class => $walker->id]);

        $this->assertSame('1 / 2', $rows[self::rowId($walker->id, $layerA->id)][__('Stages')]);
        $this->assertSame('1 / 1', $rows[self::rowId($walker->id, $layerB->id)][__('Stages')]);
        $this->assertSame(['1 / 2'], array_column($this->rows($a), __('Stages')));
    }

    public function test_lens_filters_by_user_and_route(): void
    {
        $s = $this->scenario();
        $admin = $this->createUserWithRole('Administrator');

        $this->assertEqualsCanonicalizing([
            self::rowId($s['walker1']->id, $s['layerA']->id),
            self::rowId($s['walker1']->id, $s['layerB']->id),
        ], array_keys($this->rows($admin, [ValidatedEcTrackUserFilter::class => $s['walker1']->id])));

        $this->assertEqualsCanonicalizing([
            self::rowId($s['walker1']->id, $s['layerB']->id),
            self::rowId($s['walker2']->id, $s['layerB']->id),
        ], array_keys($this->rows($admin, [ValidatedEcTrackLayerFilter::class => $s['layerB']->id])));
    }

    public function test_lens_rows_have_no_detail_link_and_no_actions(): void
    {
        $s = $this->scenario();

        $resources = $this->lens($s['a'])->assertOk()->json('resources');

        $this->assertNotEmpty($resources);
        foreach ($resources as $resource) {
            $this->assertFalse($resource['authorizedToView']);
            $this->assertFalse($resource['authorizedToUpdate']);
            $this->assertFalse($resource['authorizedToDelete']);
            $this->assertSame([], $resource['actions']);
        }

        $this->actingAs($s['a'])->getJson(self::URI.'/actions')->assertOk()->assertJsonPath('actions', []);
    }

    public function test_lens_http_index_paginates(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($validator->id);
        $track = $this->track($validator->id, [$layer]);
        $this->track($validator->id, [$layer]);

        $walkers = User::factory()->count(26)->create();
        foreach ($walkers as $walker) {
            $this->validate($walker, $track, $layer);
        }

        $page1 = $this->actingAs($validator)->getJson(self::URI.'?perPage=25&orderBy=user_id&orderByDirection=asc')->assertOk();
        $this->assertCount(25, $page1->json('resources'));
        $this->assertNotNull($page1->json('nextPageUrl'));

        $page2 = $this->actingAs($validator)->getJson(self::URI.'?perPage=25&page=2&orderBy=user_id&orderByDirection=asc')->assertOk();
        $this->assertCount(1, $page2->json('resources'));

        $ids = collect([...$page1->json('resources'), ...$page2->json('resources')])->pluck('id.value')->all();
        $this->assertEqualsCanonicalizing(
            $walkers->map(fn (User $w) => self::rowId($w->id, $layer->id))->all(),
            $ids
        );
    }

    public function test_guest_cannot_access_lens(): void
    {
        $this->scenario();

        foreach ([$this->createUserWithRole('Guest'), $this->createUserWithoutRole()] as $user) {
            $this->assertContains($this->lens($user)->status(), [403, 404]);
        }
    }
}

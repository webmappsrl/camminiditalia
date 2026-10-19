<?php

namespace Tests\Feature;

use App\Models\PassportStage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/**
 * Popolamento e riallineamento del passaporto (oc:8165).
 *
 * Il `--dry-run` è il solo momento in cui un errore si vede prima che diventi definitivo: una
 * validazione, una volta scritta, non si revoca.
 */
class ComputeStageValidationsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private App $app_;

    private Layer $layer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app_ = App::factory()->createQuietly();
        // Il passaporto si accende per singolo cammino (oc:8763): senza il flag non si calcola nulla.
        $this->layer = Layer::factory()->create([
            'app_id' => $this->app_->id,
            'properties' => ['passport_enabled' => true],
        ]);

        config(['camminiditalia.passport.coverage_threshold' => 80]);
    }

    private function user(): User
    {
        return User::factory()->create(['app_id' => $this->app_->id]);
    }

    private function stage(): EcTrack
    {
        $track = EcTrack::withoutEvents(
            fn () => EcTrack::factory()->create(['app_id' => $this->app_->id, 'properties' => [], 'osmid' => null])
        );

        DB::table('layerables')->insert([
            'layer_id' => $this->layer->id,
            'layerable_id' => $track->id,
            'layerable_type' => UgcTrackMatchingService::make()->ecTrackMorphClass(),
        ]);

        return $track;
    }

    private function walkOn(User $user, EcTrack $stage, array $segments): UgcTrack
    {
        $walk = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
            'user_id' => $user->id,
            'app_id' => $this->app_->id,
            'properties' => ['locations' => [['latitude' => 44.0, 'longitude' => 11.0]]],
        ]));

        DB::table('ugc_track_ec_track_matches')->insert([
            'ugc_track_id' => $walk->id,
            'ec_track_id' => $stage->id,
            'app_id' => $this->app_->id,
            'matched_segments' => json_encode($segments),
            'total_segments' => 10,
            'segment_length_meters' => 400,
            'max_distance_meters' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $walk;
    }

    public function test_dry_run_writes_nothing_and_reports_counts()
    {
        $user = $this->user();
        $this->walkOn($user, $this->stage(), [0, 1, 2, 3, 4, 5, 6, 7]);

        $this->artisan('camminiditalia:compute-stage-validations --dry-run')
            ->expectsOutputToContain('1 tappe')
            ->expectsOutputToContain('1 persone')
            ->assertSuccessful();

        $this->assertSame(0, PassportStage::where('user_id', $user->id)->count());
    }

    public function test_without_dry_run_it_writes_validations()
    {
        $user = $this->user();
        $stage = $this->stage();
        $this->walkOn($user, $stage, [0, 1, 2, 3, 4, 5, 6, 7]);

        $this->artisan('camminiditalia:compute-stage-validations')->assertSuccessful();

        $row = PassportStage::where(['user_id' => $user->id, 'ec_track_id' => $stage->id])->first();
        $this->assertNotNull($row);
        $this->assertSame(80, $row->progress);
        $this->assertNotNull($row->validated_at);
    }

    public function test_running_it_twice_changes_nothing()
    {
        $user = $this->user();
        $stage = $this->stage();
        $this->walkOn($user, $stage, [0, 1, 2, 3, 4, 5, 6, 7]);

        $this->artisan('camminiditalia:compute-stage-validations')->assertSuccessful();
        $first = PassportStage::where('user_id', $user->id)->get(['progress', 'validated_at', 'source'])->toArray();

        $this->artisan('camminiditalia:compute-stage-validations')->assertSuccessful();
        $dopo = PassportStage::where('user_id', $user->id)->get(['progress', 'validated_at', 'source'])->toArray();

        $this->assertEquals($first, $dopo);
    }

    public function test_user_option_limits_the_computation_to_that_person()
    {
        $tizio = $this->user();
        $caio = $this->user();
        $stage = $this->stage();
        $this->walkOn($tizio, $stage, [0, 1, 2, 3, 4, 5, 6, 7]);
        $this->walkOn($caio, $stage, [0, 1, 2, 3, 4, 5, 6, 7]);

        $this->artisan("camminiditalia:compute-stage-validations --user={$tizio->id}")->assertSuccessful();

        $this->assertSame(1, PassportStage::where('user_id', $tizio->id)->count());
        $this->assertSame(0, PassportStage::where('user_id', $caio->id)->count());
    }
}

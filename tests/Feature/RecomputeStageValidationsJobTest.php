<?php

namespace Tests\Feature;

use App\Jobs\RecomputeStageValidationsJob;
use App\Models\PassportStage;
use App\Services\StageValidationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\UgcTrackMatchingRun;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/**
 * Il progresso si aggiorna appena la corrispondenza di una UGC track è pronta (oc:8165), così
 * l'app lo trova già calcolato quando torna a chiedere il passaporto.
 */
class RecomputeStageValidationsJobTest extends TestCase
{
    use DatabaseTransactions;

    private App $app_;

    private User $user;

    private Layer $layer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app_ = App::factory()->createQuietly();
        $this->user = User::factory()->create(['app_id' => $this->app_->id]);
        // Il passaporto si accende per singolo cammino (oc:8763): senza il flag non si calcola nulla.
        $this->layer = Layer::factory()->create([
            'app_id' => $this->app_->id,
            'properties' => ['passport_enabled' => true],
        ]);

        config(['camminiditalia.passport.coverage_threshold' => 80]);

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

    private function walk(bool $recorded = true): UgcTrack
    {
        return UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
            'user_id' => $this->user->id,
            'app_id' => $this->app_->id,
            'properties' => $recorded
                ? ['locations' => [['latitude' => 44.0, 'longitude' => 11.0]]]
                : ['_gpxType' => 'trk'],
        ]));
    }

    private function matched(UgcTrack $walk, EcTrack $stage, array $segments, int $total = 10): void
    {
        DB::table('ugc_track_ec_track_matches')->insert([
            'ugc_track_id' => $walk->id,
            'ec_track_id' => $stage->id,
            'app_id' => $this->app_->id,
            'matched_segments' => json_encode($segments),
            'total_segments' => $total,
            'segment_length_meters' => 400,
            'max_distance_meters' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_queues_the_recompute_when_the_match_is_ready()
    {
        Queue::fake();
        $walk = $this->walk();

        UgcTrackMatchingRun::query()->updateOrCreate(['ugc_track_id' => $walk->id], [
            'status' => UgcTrackMatchingRun::STATUS_COMPUTED,
            'segment_length_meters' => 400,
            'max_distance_meters' => 50,
            'computed_at' => now(),
        ]);

        Queue::assertPushed(
            RecomputeStageValidationsJob::class,
            fn (RecomputeStageValidationsJob $job) => $job->userId === $this->user->id
        );
    }

    public function test_queues_nothing_for_an_imported_ugc_track()
    {
        Queue::fake();
        $walk = $this->walk(recorded: false);

        UgcTrackMatchingRun::query()->updateOrCreate(['ugc_track_id' => $walk->id], [
            'status' => UgcTrackMatchingRun::STATUS_COMPUTED,
            'segment_length_meters' => 400,
            'max_distance_meters' => 50,
            'computed_at' => now(),
        ]);

        Queue::assertNotPushed(RecomputeStageValidationsJob::class);
    }

    public function test_queues_nothing_when_the_match_failed()
    {
        Queue::fake();
        $walk = $this->walk();

        UgcTrackMatchingRun::query()->updateOrCreate(['ugc_track_id' => $walk->id], [
            'status' => UgcTrackMatchingRun::STATUS_FAILED,
            'error' => 'boom',
            'computed_at' => now(),
        ]);

        Queue::assertNotPushed(RecomputeStageValidationsJob::class);
    }

    public function test_the_job_writes_progress()
    {
        $stage = $this->stage();
        $walk = $this->walk();
        $this->matched($walk, $stage, [0, 1, 2, 3, 4, 5, 6, 7], 10);

        (new RecomputeStageValidationsJob($this->user->id))->handle(app(StageValidationService::class));

        $row = PassportStage::where(['user_id' => $this->user->id, 'ec_track_id' => $stage->id])->first();
        $this->assertSame(80, $row->progress);
        $this->assertNotNull($row->validated_at);
    }

    public function test_two_runs_leave_the_same_state()
    {
        $stage = $this->stage();
        $this->matched($this->walk(), $stage, [0, 1, 2, 3, 4, 5, 6, 7], 10);
        $service = app(StageValidationService::class);

        (new RecomputeStageValidationsJob($this->user->id))->handle($service);
        $first = PassportStage::where('user_id', $this->user->id)->get(['ec_track_id', 'progress', 'validated_at'])->toArray();

        (new RecomputeStageValidationsJob($this->user->id))->handle($service);
        $dopo = PassportStage::where('user_id', $this->user->id)->get(['ec_track_id', 'progress', 'validated_at'])->toArray();

        $this->assertEquals($first, $dopo);
        $this->assertCount(1, $dopo);
    }
}

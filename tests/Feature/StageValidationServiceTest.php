<?php

namespace Tests\Feature;

use App\Models\PassportStage;
use App\Services\StageValidationService;
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
 * Il verdetto sulla tappa (oc:8165): unendo i segmenti percorsi da tutte le UGC track
 * registrate di una persona, oltre la soglia la tappa è validata.
 *
 * I test scrivono direttamente in `ugc_track_ec_track_matches`: la geometria la calcola
 * wm-package (oc:8773) ed è coperta dai suoi test, qui si misura la regola che ci sta sopra.
 */
class StageValidationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private App $app_;

    private User $user;

    private Layer $layer;

    private StageValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app_ = App::factory()->createQuietly();
        $this->user = User::factory()->create(['app_id' => $this->app_->id]);
        $this->layer = Layer::factory()->create([
            'app_id' => $this->app_->id,
            'properties' => ['passport_enabled' => true],
        ]);
        $this->service = app(StageValidationService::class);

        config(['camminiditalia.passport.coverage_threshold' => 80]);
    }

    /** Tappa del cammino. */
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

    /** UGC track registrata (ha i punti grezzi) o importata. */
    private function walk(bool $recorded = true): UgcTrack
    {
        $properties = $recorded
            ? ['locations' => [['latitude' => 44.0, 'longitude' => 11.0]]]
            : ['_gpxType' => 'trk'];

        return UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
            'user_id' => $this->user->id,
            'app_id' => $this->app_->id,
            'properties' => $properties,
        ]));
    }

    /** Corrispondenza fra una UGC track e una tappa, con i segmenti percorsi. */
    private function matched(UgcTrack $walk, EcTrack $stage, array $segments, int $total = 10, ?int $segmentLength = null): void
    {
        DB::table('ugc_track_ec_track_matches')->insert([
            'ugc_track_id' => $walk->id,
            'ec_track_id' => $stage->id,
            'app_id' => $this->app_->id,
            'matched_segments' => json_encode($segments),
            'total_segments' => $total,
            'segment_length_meters' => $segmentLength ?? 400,
            'max_distance_meters' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function row(EcTrack $stage): ?PassportStage
    {
        return PassportStage::where(['user_id' => $this->user->id, 'ec_track_id' => $stage->id])->first();
    }

    public function test_validates_when_segments_pass_the_threshold()
    {
        $stage = $this->stage();
        $this->matched($this->walk(), $stage, [0, 1, 2, 3, 4, 5, 6, 7], 10);

        $this->service->recomputeForUser($this->user->id);

        $row = $this->row($stage);
        $this->assertSame(80, $row->progress);
        $this->assertNotNull($row->validated_at);
        $this->assertSame(PassportStage::SOURCE_GPS, $row->source);
        $this->assertSame($this->layer->id, (int) $row->layer_id);
    }

    public function test_merges_segments_from_different_ugc_tracks_of_the_same_user()
    {
        $stage = $this->stage();
        // Due uscite, metà tappa ciascuna, senza sovrapporsi: insieme fanno il 100%.
        $this->matched($this->walk(), $stage, [0, 1, 2, 3, 4], 10);
        $this->matched($this->walk(), $stage, [5, 6, 7, 8, 9], 10);

        $this->service->recomputeForUser($this->user->id);

        $this->assertSame(100, $this->row($stage)->progress);
    }

    public function test_does_not_count_the_same_segment_twice()
    {
        $stage = $this->stage();
        // Stesso tratto percorso due volte: resta il 50%, non il 100%.
        $this->matched($this->walk(), $stage, [0, 1, 2, 3, 4], 10);
        $this->matched($this->walk(), $stage, [0, 1, 2, 3, 4], 10);

        $this->service->recomputeForUser($this->user->id);

        $this->assertSame(50, $this->row($stage)->progress);
        $this->assertNull($this->row($stage)->validated_at);
    }

    public function test_ignores_imported_ugc_tracks()
    {
        $stage = $this->stage();
        $this->matched($this->walk(recorded: false), $stage, [0, 1, 2, 3, 4, 5, 6, 7, 8, 9], 10);

        $this->service->recomputeForUser($this->user->id);

        $this->assertNull($this->row($stage));
    }

    public function test_ignores_matches_computed_with_other_parameters()
    {
        $stage = $this->stage();
        // Riga vecchia, calcolata con segmenti da 200 m invece dei 400 correnti.
        $this->matched($this->walk(), $stage, [0, 1, 2, 3, 4, 5, 6, 7, 8, 9], 10, segmentLength: 200);

        $this->service->recomputeForUser($this->user->id);

        $this->assertNull($this->row($stage));
    }

    public function test_never_touches_a_manual_validation()
    {
        $stage = $this->stage();
        $manualRow = PassportStage::create([
            'user_id' => $this->user->id,
            'ec_track_id' => $stage->id,
            'layer_id' => $this->layer->id,
            'source' => PassportStage::SOURCE_MANUAL,
            'validated_at' => now()->subMonth(),
            'progress' => 0,
        ]);
        $when = $manualRow->validated_at;

        $this->matched($this->walk(), $stage, [0, 1, 2, 3], 10);

        $this->service->recomputeForUser($this->user->id);

        $row = $this->row($stage)->fresh();
        $this->assertSame(PassportStage::SOURCE_MANUAL, $row->source);
        $this->assertSame(0, $row->progress);
        $this->assertTrue($when->equalTo($row->validated_at));
    }

    public function test_never_revokes_a_validation_when_coverage_drops()
    {
        $stage = $this->stage();
        $walk = $this->walk();
        $this->matched($walk, $stage, [0, 1, 2, 3, 4, 5, 6, 7], 10);
        $this->service->recomputeForUser($this->user->id);
        $when = $this->row($stage)->validated_at;

        // Il gestore ritocca il tracciato: il matching si rifà e la copertura scende.
        DB::table('ugc_track_ec_track_matches')
            ->where(['ugc_track_id' => $walk->id, 'ec_track_id' => $stage->id])
            ->update(['matched_segments' => json_encode([0, 1, 2])]);

        $this->service->recomputeForUser($this->user->id);

        $row = $this->row($stage);
        $this->assertSame(30, $row->progress, 'la percentuale segue il nuovo calcolo');
        $this->assertNotNull($row->validated_at, 'la validazione non si revoca');
        $this->assertTrue($when->equalTo($row->validated_at), 'e la data non si sposta');
    }

    public function test_links_the_ugc_tracks_that_produced_the_validation()
    {
        $stage = $this->stage();
        $first = $this->walk();
        $second = $this->walk();
        $faraway = $this->walk();
        $this->matched($first, $stage, [0, 1, 2, 3, 4], 10);
        $this->matched($second, $stage, [5, 6, 7, 8], 10);
        $this->matched($faraway, $this->stage(), [0], 10);

        $this->service->recomputeForUser($this->user->id);

        $linked = $this->row($stage)->ugcTracks()->pluck('ugc_tracks.id')->sort()->values()->all();
        $this->assertSame([$first->id, $second->id], $linked);
    }

    public function test_updates_progress_without_validating_below_threshold()
    {
        $stage = $this->stage();
        $this->matched($this->walk(), $stage, [0, 1, 2, 3, 4, 5, 6], 10);

        $this->service->recomputeForUser($this->user->id);

        $row = $this->row($stage);
        $this->assertSame(70, $row->progress);
        $this->assertNull($row->validated_at);
        $this->assertSame(PassportStage::SOURCE_GPS, $row->source);
    }

    public function test_ignores_routes_with_the_passport_switched_off()
    {
        // Il passaporto è acceso per singolo cammino (oc:8763): su quelli spenti non esiste per
        // nessuno, quindi non si calcola nulla nemmeno per chi ci ha camminato.
        $off = Layer::factory()->create(['app_id' => $this->app_->id, 'properties' => []]);
        $stage = EcTrack::withoutEvents(
            fn () => EcTrack::factory()->create(['app_id' => $this->app_->id, 'properties' => [], 'osmid' => null])
        );
        DB::table('layerables')->insert([
            'layer_id' => $off->id,
            'layerable_id' => $stage->id,
            'layerable_type' => UgcTrackMatchingService::make()->ecTrackMorphClass(),
        ]);
        $this->matched($this->walk(), $stage, [0, 1, 2, 3, 4, 5, 6, 7], 10);

        $this->service->recomputeForUser($this->user->id);

        $this->assertNull($this->row($stage));
    }

    public function test_recompute_for_layer_populates_who_already_walked()
    {
        // Quando il gestore accende il passaporto, chi ci ha già camminato deve trovarselo
        // popolato invece di aspettare la propria uscita successiva.
        $stage = $this->stage();
        $this->matched($this->walk(), $stage, [0, 1, 2, 3, 4, 5, 6, 7], 10);

        $outcome = $this->service->recomputeForLayer($this->layer->id);

        $this->assertSame(1, $outcome['validated']);
        $this->assertNotNull($this->row($stage)->validated_at);
    }

    public function test_switching_the_passport_on_queues_the_computation()
    {
        \Illuminate\Support\Facades\Queue::fake();

        $off = Layer::factory()->create(['app_id' => $this->app_->id, 'properties' => []]);

        $off->properties = ['passport_enabled' => true];
        $off->save();

        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\RecomputePassportStagesForLayerJob::class,
            fn ($job) => $job->layerId === $off->id
        );
    }

    public function test_switching_the_passport_off_queues_nothing()
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->layer->properties = [];
        $this->layer->save();

        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RecomputePassportStagesForLayerJob::class);
    }

    public function test_the_layer_job_populates_the_passport()
    {
        // Il pezzo in mezzo fra l'observer e il servizio: è quello che in locale è fallito,
        // perché il worker girava con una versione del codice precedente al metodo chiamato.
        $stage = $this->stage();
        $this->matched($this->walk(), $stage, [0, 1, 2, 3, 4, 5, 6, 7], 10);

        (new \App\Jobs\RecomputePassportStagesForLayerJob($this->layer->id))
            ->handle(app(StageValidationService::class));

        $this->assertNotNull($this->row($stage)->validated_at);
    }

    public function test_preview_counts_without_writing()
    {
        $stage = $this->stage();
        $this->matched($this->walk(), $stage, [0, 1, 2, 3, 4, 5, 6, 7], 10);

        $counts = $this->service->previewForAllUsers();

        $this->assertSame(1, $counts['tappe']);
        $this->assertSame(1, $counts['persone']);
        $this->assertNull($this->row($stage), 'la preview non scrive nulla');
    }

    public function test_preview_counts_only_stages_still_to_unlock()
    {
        // Il numero serve a decidere se lanciare un'operazione irreversibile: deve dire quante
        // tappe si sbloccherebbero adesso, non quante risultano percorse in tutto.
        $already = $this->stage();
        $fresh = $this->stage();
        $this->matched($this->walk(), $already, [0, 1, 2, 3, 4, 5, 6, 7], 10);
        $this->matched($this->walk(), $fresh, [0, 1, 2, 3, 4, 5, 6, 7], 10);

        $this->service->recomputeForUser($this->user->id, [$already->id]);

        $counts = $this->service->previewForAllUsers();

        $this->assertSame(1, $counts['tappe'], 'solo quella non ancora validata');
        $this->assertSame(1, $counts['persone']);
    }

    public function test_does_not_link_ugc_tracks_to_a_manual_validation()
    {
        $stage = $this->stage();
        PassportStage::create([
            'user_id' => $this->user->id,
            'ec_track_id' => $stage->id,
            'layer_id' => $this->layer->id,
            'source' => PassportStage::SOURCE_MANUAL,
            'validated_at' => now(),
            'progress' => 0,
        ]);
        $this->matched($this->walk(), $stage, [0, 1], 10);

        $this->service->recomputeForUser($this->user->id);

        $this->assertSame(0, $this->row($stage)->ugcTracks()->count());
    }

    public function test_does_not_link_ugc_tracks_matched_with_other_parameters()
    {
        $stage = $this->stage();
        $current = $this->walk();
        $stale = $this->walk();
        $this->matched($current, $stage, [0, 1, 2, 3, 4, 5, 6, 7], 10);
        $this->matched($stale, $stage, [8, 9], 10, segmentLength: 200);

        $this->service->recomputeForUser($this->user->id);

        $linked = $this->row($stage)->ugcTracks()->pluck('ugc_tracks.id')->all();
        $this->assertSame([$current->id], $linked, 'una corrispondenza stantia non ha contribuito');
    }
}

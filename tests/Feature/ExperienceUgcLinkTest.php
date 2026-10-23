<?php

namespace Tests\Feature;

use App\Jobs\LinkExperienceUgcTracksJob;
use App\Models\Experience;
use App\Services\ExperienceService;
use App\Services\ExperienceUgcLinkService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\CreatesCertificationTracks;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class ExperienceUgcLinkTest extends TestCase
{
    use CreatesCertificationTracks, DatabaseTransactions, FakesCertificationDisk, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->fakeCertificationDisk();
        Queue::fake();
        Http::fake();
    }

    /**
     * Registrazione (ha i punti grezzi, quindi `recorded`) con data `createdAt` e riga di
     * corrispondenza con la tappa; di default copre tutti i segmenti.
     */
    private function recording(int $userId, int $ecTrackId, string $date): UgcTrack
    {
        $appId = App::first()->id;
        $ugc = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
            'app_id' => $appId,
            'user_id' => $userId,
            'properties' => ['createdAt' => $date, 'locations' => [['latitude' => 44.0, 'longitude' => 11.0]]],
        ]));

        $this->match($ugc, $ecTrackId, [0, 1], 2);

        return $ugc;
    }

    /** Corrispondenza con i parametri correnti dell'App, quelli che il criterio oc:8165 considera. */
    private function match(UgcTrack $ugc, int $ecTrackId, array $segments, int $total): void
    {
        $params = UgcTrackMatchingService::make()->params((int) $ugc->app_id);

        DB::table('ugc_track_ec_track_matches')->insert([
            'ugc_track_id' => $ugc->id,
            'ec_track_id' => $ecTrackId,
            'app_id' => $ugc->app_id,
            'matched_segments' => json_encode($segments),
            'total_segments' => $total,
            'segment_length_meters' => $params['segment_length_meters'],
            'max_distance_meters' => $params['max_distance_meters'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function experience(int $userId, int $layerId, ?int $ecTrackId, string $createdAt): Experience
    {
        $e = Experience::factory()->create(['user_id' => $userId, 'layer_id' => $layerId, 'ec_track_id' => $ecTrackId]);
        $e->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $e->fresh();
    }

    private function runLink(Experience $e): array
    {
        (new LinkExperienceUgcTracksJob($e->id))->handle(app(ExperienceUgcLinkService::class));

        return $e->ugcTracks()->pluck('ugc_tracks.id')->map(fn ($i) => (int) $i)->sort()->values()->all();
    }

    public function test_lega_registrazioni_della_tappa_nella_finestra(): void
    {
        $user = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $track = $this->createTrack($user->id, 'Tappa 1', $layer);
        $a = $this->recording($user->id, $track->id, '2026-06-10T08:00:00Z');
        $b = $this->recording($user->id, $track->id, '2026-06-12T08:00:00Z');
        $other = $this->createUserWithoutRole();
        $this->recording($other->id, $track->id, '2026-06-11T08:00:00Z');

        $e = $this->experience($user->id, $layer->id, $track->id, '2026-06-13 10:00:00');

        $this->assertSame([$a->id, $b->id], $this->runLink($e));
    }

    public function test_esperienza_scritta_due_mesi_dopo_lega_le_registrazioni_di_giugno(): void
    {
        $user = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $track = $this->createTrack($user->id, 'Tappa 1', $layer);
        $ids = [];
        foreach (['2026-06-02', '2026-06-10', '2026-06-20'] as $d) {
            $ids[] = $this->recording($user->id, $track->id, $d.'T09:00:00Z')->id;
        }

        $e = $this->experience($user->id, $layer->id, $track->id, '2026-08-30 10:00:00');

        $this->assertSame($ids, $this->runLink($e));
    }

    public function test_registrazione_fuori_finestra_non_legata(): void
    {
        $user = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $track = $this->createTrack($user->id, 'Tappa 1', $layer);
        $anchor = $this->recording($user->id, $track->id, '2026-06-20T09:00:00Z');
        $this->recording($user->id, $track->id, '2026-05-01T09:00:00Z');

        $e = $this->experience($user->id, $layer->id, $track->id, '2026-06-21 10:00:00');

        $this->assertSame([$anchor->id], $this->runLink($e));
    }

    public function test_registrazione_successiva_all_esperienza_non_legata(): void
    {
        $user = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $track = $this->createTrack($user->id, 'Tappa 1', $layer);
        $before = $this->recording($user->id, $track->id, '2026-06-10T09:00:00Z');
        $this->recording($user->id, $track->id, '2026-06-20T09:00:00Z');

        $e = $this->experience($user->id, $layer->id, $track->id, '2026-06-15 10:00:00');

        $this->assertSame([$before->id], $this->runLink($e));
    }

    public function test_esperienza_sul_cammino_usa_tutte_le_tappe(): void
    {
        $user = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $t1 = $this->createTrack($user->id, 'Tappa 1', $layer);
        $t2 = $this->createTrack($user->id, 'Tappa 2', $layer);
        $outside = $this->createTrack($user->id, 'Fuori cammino');
        $a = $this->recording($user->id, $t1->id, '2026-06-10T09:00:00Z');
        $b = $this->recording($user->id, $t2->id, '2026-06-11T09:00:00Z');
        $this->recording($user->id, $outside->id, '2026-06-11T10:00:00Z');

        $e = $this->experience($user->id, $layer->id, null, '2026-06-12 10:00:00');

        $this->assertSame([$a->id, $b->id], $this->runLink($e));
    }

    public function test_modifica_ricalcola_il_legame(): void
    {
        $user = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $track = $this->createTrack($user->id, 'Tappa 1', $layer);
        $e = $this->experience($user->id, $layer->id, $track->id, '2026-06-15 10:00:00');
        $this->assertSame([], $this->runLink($e));

        // La corrispondenza arriva dopo la creazione: la modifica rilancia il job e il legame compare.
        $ugc = $this->recording($user->id, $track->id, '2026-06-14T09:00:00Z');
        app(ExperienceService::class)->update($e, 'nuovo testo', [], []);

        Queue::assertPushed(LinkExperienceUgcTracksJob::class, fn ($job) => $job->experienceId === $e->id);
        $this->assertSame([$ugc->id], $this->runLink($e));
    }

    /**
     * Il bug che ha portato al criterio oc:8165: una registrazione che percorre tutta la tappa A
     * e sfiora la tappa B adiacente per un segmento su dieci non racconta la tappa B. Il verdetto
     * è per singola registrazione, non sull'unione: anche con un'altra uscita che copre tutta B,
     * la registrazione che la sfiora resta fuori dall'esperienza su B.
     */
    public function test_registrazione_che_sfiora_la_tappa_adiacente_non_e_legata_a_quella_tappa(): void
    {
        config(['camminiditalia.passport.coverage_threshold' => 80]);
        $user = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $stageA = $this->createTrack($user->id, 'Tappa A', $layer);
        $stageB = $this->createTrack($user->id, 'Tappa B', $layer);

        $ugc = $this->recording($user->id, $stageA->id, '2026-06-10T08:00:00Z');
        $this->match($ugc, $stageB->id, [0], 10);

        $onB = $this->experience($user->id, $layer->id, $stageB->id, '2026-06-10 20:00:00');
        $onA = $this->experience($user->id, $layer->id, $stageA->id, '2026-06-10 20:00:00');
        $onRoute = $this->experience($user->id, $layer->id, null, '2026-06-10 20:00:00');

        $this->assertSame([], $this->runLink($onB));
        $this->assertSame([$ugc->id], $this->runLink($onA));
        $this->assertSame([$ugc->id], $this->runLink($onRoute));

        // Una seconda uscita, nella finestra, percorre tutta B: per unione B risulterebbe percorsa
        // anche dalla prima, ma la regola è per registrazione e lega solo la seconda.
        $fullB = $this->recording($user->id, $stageB->id, '2026-06-09T08:00:00Z');
        DB::table('ugc_track_ec_track_matches')->where('ugc_track_id', $fullB->id)
            ->update(['matched_segments' => json_encode(range(0, 9)), 'total_segments' => 10]);

        $this->assertSame([$fullB->id], $this->runLink($onB));
    }

    /** Sotto soglia non basta neanche sulla tappa giusta; i file caricati non contano. */
    public function test_copertura_sotto_soglia_e_tracce_caricate_non_legate(): void
    {
        config(['camminiditalia.passport.coverage_threshold' => 80]);
        $user = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $stage = $this->createTrack($user->id, 'Tappa 1', $layer);

        $partial = $this->recording($user->id, $stage->id, '2026-06-10T08:00:00Z');
        DB::table('ugc_track_ec_track_matches')->where('ugc_track_id', $partial->id)
            ->update(['matched_segments' => json_encode([0, 1, 2, 3, 4, 5, 6]), 'total_segments' => 10]);

        // Stessi punti grezzi di una registrazione, più il marcatore del file caricato: è esclusa
        // perché caricata, non perché manca `locations`.
        $uploaded = $this->recording($user->id, $stage->id, '2026-06-11T08:00:00Z');
        $uploaded->forceFill(['properties' => $uploaded->properties + ['_gpxType' => 'trk']])->saveQuietly();

        $e = $this->experience($user->id, $layer->id, $stage->id, '2026-06-12 10:00:00');

        $this->assertSame([], $this->runLink($e));
    }

    /** Soglia al limite: 8/10 è l'80% e lega, 79/100 no. */
    public function test_soglia_al_limite(): void
    {
        config(['camminiditalia.passport.coverage_threshold' => 80]);
        $user = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $stage = $this->createTrack($user->id, 'Tappa 1', $layer);

        $atThreshold = $this->recording($user->id, $stage->id, '2026-06-10T08:00:00Z');
        DB::table('ugc_track_ec_track_matches')->where('ugc_track_id', $atThreshold->id)
            ->update(['matched_segments' => json_encode(range(0, 7)), 'total_segments' => 10]);

        $justBelow = $this->recording($user->id, $stage->id, '2026-06-11T08:00:00Z');
        DB::table('ugc_track_ec_track_matches')->where('ugc_track_id', $justBelow->id)
            ->update(['matched_segments' => json_encode(range(0, 78)), 'total_segments' => 100]);

        $e = $this->experience($user->id, $layer->id, $stage->id, '2026-06-12 10:00:00');

        $this->assertSame([$atThreshold->id], $this->runLink($e));
    }
}

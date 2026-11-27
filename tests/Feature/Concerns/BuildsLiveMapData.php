<?php

namespace Tests\Feature\Concerns;

use App\Models\EcTrack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Models\Layer;

/**
 * Dati per i test della mappa con le persone in tempo reale (oc:9040): tracce con geometria nota
 * e risposte finte di PostHog all'evento `userMoved`.
 */
trait BuildsLiveMapData
{
    /**
     * Chiamato da Laravel prima di ogni test (`setUp` + nome del trait): senza `Queue::fake()` la
     * creazione di una EC track fa partire il calcolo DEM, che chiama un servizio esterno.
     */
    protected function setUpBuildsLiveMapData(): void
    {
        Queue::fake();
    }

    /**
     * EC track lungo il parallelo 43.0, da lng $fromLng a $toLng, associata al layer (se dato) con
     * insert diretto su `layerables`, come in PassportRouteShareApiTest.
     */
    protected function trackOnParallel(?Layer $layer, float $fromLng, float $toLng, string $name = 'Tappa'): WmEcTrack
    {
        $track = EcTrack::factory()->create([
            'osmid' => null,
            'name' => ['it' => $name],
            'properties' => [],
        ]);

        $wkt = "MULTILINESTRING Z(({$fromLng} 43.0 120.5, {$toLng} 43.0 130.25))";
        DB::table('ec_tracks')->where('id', $track->id)->update(['geometry' => DB::raw("ST_GeomFromText('{$wkt}', 4326)")]);

        if ($layer !== null) {
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

    /**
     * PostHog configurato e una risposta finta con le righe [person_id, lat, lng, user_id].
     *
     * @param  list<array{0: string, 1: float, 2: float, 3: ?int}>  $rows
     */
    protected function fakePostHogPositions(array $rows): void
    {
        config([
            'services.posthog.host' => 'https://posthog.test',
            'services.posthog.project_id' => '1',
            'services.posthog.personal_api_key' => 'test-key',
        ]);

        Http::fake(['posthog.test/*' => Http::response(['results' => $rows])]);
    }

    /**
     * PostHog non configurato: nessuna richiesta deve partire.
     */
    protected function withoutPostHog(): void
    {
        config([
            'services.posthog.host' => null,
            'services.posthog.project_id' => null,
            'services.posthog.personal_api_key' => null,
        ]);

        Http::fake();
    }
}

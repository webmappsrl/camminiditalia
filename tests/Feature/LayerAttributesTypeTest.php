<?php

namespace Tests\Feature;

use App\Enums\RouteShape;
use App\Services\LayerAttributesService;
use Tests\TestCase;

class LayerAttributesTypeTest extends TestCase
{
    private function service(): LayerAttributesService
    {
        return app(LayerAttributesService::class);
    }

    public function test_single_track_with_coincident_ends_is_a_loop(): void
    {
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.0, 43.0]],
        ];

        $this->assertSame(RouteShape::ROUNDTRIP, $this->service()->determineType($endpoints));
    }

    public function test_single_track_with_distant_ends_is_linear(): void
    {
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [12.5, 41.9]],
        ];

        $this->assertSame(RouteShape::LINEAR, $this->service()->determineType($endpoints));
    }

    public function test_chain_of_three_tracks_is_linear(): void
    {
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]],
            ['track_id' => 2, 'start' => [11.2, 42.8], 'end' => [11.5, 42.5]],
            ['track_id' => 3, 'start' => [11.5, 42.5], 'end' => [12.5, 41.9]],
        ];

        $this->assertSame(RouteShape::LINEAR, $this->service()->determineType($endpoints));
    }

    public function test_closed_chain_of_three_tracks_is_a_loop(): void
    {
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]],
            ['track_id' => 2, 'start' => [11.2, 42.8], 'end' => [11.5, 42.5]],
            ['track_id' => 3, 'start' => [11.5, 42.5], 'end' => [11.0, 43.0]],
        ];

        $this->assertSame(RouteShape::ROUNDTRIP, $this->service()->determineType($endpoints));
    }

    public function test_endpoints_within_tolerance_are_treated_as_joined(): void
    {
        // Scarto di ~28 m (offset di 0.00025° in lon e lat a 42.8°N),
        // ben sotto la tolleranza di 400 m: gli estremi sono considerati
        // lo stesso punto.
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]],
            ['track_id' => 2, 'start' => [11.2005, 42.8005], 'end' => [12.5, 41.9]],
        ];

        $this->assertSame(RouteShape::LINEAR, $this->service()->determineType($endpoints));
    }

    /**
     * Caso reale che ha motivato la soglia di 400 m (oc:8180): la chiusura
     * dell'"Anello di Teodelapio" (Layer 130) a Spoleto misura ~362 m fra
     * la fine della Tappa 05 (id 1340) e l'inizio della Tappa 01 (id 1336).
     * Offset di puro spostamento in latitudine di 0.0032555° a 43°N, che
     * con la formula di Haversine corrisponde a ~362 m.
     */
    public function test_endpoints_at_362_meters_like_teodelapio_junction_are_joined(): void
    {
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]],
            ['track_id' => 2, 'start' => [11.2, 42.8032555], 'end' => [11.5, 42.5]],
        ];

        $this->assertNotSame(RouteShape::DISCONTINUOUS, $this->service()->determineType($endpoints));
    }

    /**
     * Simmetrico al test precedente: a ~450 m (sopra la soglia di 400 m)
     * gli stessi due estremi non devono più risultare connessi.
     */
    public function test_endpoints_at_450_meters_are_not_joined(): void
    {
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]],
            ['track_id' => 2, 'start' => [11.2, 42.8040462], 'end' => [11.5, 42.5]],
        ];

        $this->assertSame(RouteShape::DISCONTINUOUS, $this->service()->determineType($endpoints));
    }

    /**
     * Difetto di metodo corretto da oc:8180: due punti separati solo in
     * longitudine di ~300 m e due punti separati solo in latitudine della
     * stessa distanza reale (~300 m) devono dare lo stesso esito. Con il
     * vecchio confronto per-asse in gradi questo test sarebbe fallito (un
     * grado di longitudine è più corto di un grado di latitudine alle
     * latitudini italiane); con la distanza geodetica reale (Haversine)
     * passa.
     */
    public function test_longitude_only_and_latitude_only_offsets_of_same_real_distance_give_same_result(): void
    {
        // ~300 m di puro offset in longitudine a 43°N (dLon = 0.0036879°).
        $lonOnlyEndpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]],
            ['track_id' => 2, 'start' => [11.2036879, 42.8], 'end' => [11.5, 42.5]],
        ];

        // ~300 m di puro offset in latitudine (dLat = 0.0026984°).
        $latOnlyEndpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]],
            ['track_id' => 2, 'start' => [11.2, 42.8026984], 'end' => [11.5, 42.5]],
        ];

        $lonOnlyResult = $this->service()->determineType($lonOnlyEndpoints);
        $latOnlyResult = $this->service()->determineType($latOnlyEndpoints);

        $this->assertSame($lonOnlyResult, $latOnlyResult);
        $this->assertSame(RouteShape::LINEAR, $lonOnlyResult);
    }

    public function test_disconnected_tracks_produce_discontinuous_type(): void
    {
        // Due tappe che non si toccano: due componenti separate del grafo
        // tappe-tappe, nessun percorso unico ricostruibile.
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]],
            ['track_id' => 2, 'start' => [9.0, 45.0], 'end' => [9.5, 45.5]],
        ];

        $this->assertSame(RouteShape::DISCONTINUOUS, $this->service()->determineType($endpoints));
    }

    public function test_empty_endpoints_produce_null_type(): void
    {
        $this->assertNull($this->service()->determineType([]));
    }

    public function test_two_identical_tracks_form_a_loop(): void
    {
        // Due tappe con esattamente gli stessi estremi: grafo connesso (le
        // due tappe si toccano su entrambi gli estremi) e nessun estremo
        // libero → anello, secondo il criterio di connessione del grafo
        // (non esiste più un guard dedicato ai duplicati).
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [12.0, 44.0]],
            ['track_id' => 2, 'start' => [11.0, 43.0], 'end' => [12.0, 44.0]],
        ];

        $this->assertSame(RouteShape::ROUNDTRIP, $this->service()->determineType($endpoints));
    }

    public function test_closed_loop_with_extra_detached_track_is_discontinuous(): void
    {
        // Anello chiuso di 3 tappe più una quarta tappa staccata: due
        // componenti del grafo, quindi discontinuo (non più "indeterminato"
        // ma un valore vero: i tratti non si toccano).
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]],
            ['track_id' => 2, 'start' => [11.2, 42.8], 'end' => [11.5, 42.5]],
            ['track_id' => 3, 'start' => [11.5, 42.5], 'end' => [11.0, 43.0]],
            ['track_id' => 4, 'start' => [20.0, 50.0], 'end' => [20.5, 50.5]],
        ];

        $this->assertSame(RouteShape::DISCONTINUOUS, $this->service()->determineType($endpoints));
    }

    /**
     * Caso motivante l'intera modifica: una catena di 3 tappe più una
     * quarta tappa attaccata a una giunzione intermedia (variante/bretella).
     * Grafo connesso, più di due estremi liberi → lineare, non discontinuo
     * né indeterminato.
     */
    public function test_chain_with_variant_attached_at_intermediate_junction_is_linear(): void
    {
        $endpoints = [
            // Catena principale A-B-C-D
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]], // A-B
            ['track_id' => 2, 'start' => [11.2, 42.8], 'end' => [11.5, 42.5]], // B-C
            ['track_id' => 3, 'start' => [11.5, 42.5], 'end' => [11.8, 42.2]], // C-D
            // Variante attaccata alla giunzione intermedia B, verso E
            ['track_id' => 4, 'start' => [11.2, 42.8], 'end' => [10.5, 43.5]], // B-E
        ];

        $this->assertSame(RouteShape::LINEAR, $this->service()->determineType($endpoints));
    }

    /**
     * Anello di 3 tappe con una bretella attaccata a una delle giunzioni:
     * grafo connesso con un solo estremo libero (la punta della bretella)
     * → lineare, perché l'esistenza di un'estremità esclude l'anello.
     */
    public function test_loop_with_a_branch_is_linear(): void
    {
        $endpoints = [
            // Anello A-B-C-A
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]], // A-B
            ['track_id' => 2, 'start' => [11.2, 42.8], 'end' => [11.5, 42.5]], // B-C
            ['track_id' => 3, 'start' => [11.5, 42.5], 'end' => [11.0, 43.0]], // C-A
            // Bretella attaccata in A, verso D (estremo libero)
            ['track_id' => 4, 'start' => [11.0, 43.0], 'end' => [9.0, 45.0]], // A-D
        ];

        $this->assertSame(RouteShape::LINEAR, $this->service()->determineType($endpoints));
    }

    /**
     * Due tronconi separati di 2 tappe ciascuno: due componenti del grafo,
     * indipendentemente dalla forma (anello o lineare) di ciascun troncone.
     */
    public function test_two_separate_two_track_clusters_are_discontinuous(): void
    {
        $endpoints = [
            // Troncone 1: A-B-C
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]],
            ['track_id' => 2, 'start' => [11.2, 42.8], 'end' => [11.5, 42.5]],
            // Troncone 2: X-Y-Z, lontano dal primo
            ['track_id' => 3, 'start' => [30.0, 50.0], 'end' => [30.2, 50.2]],
            ['track_id' => 4, 'start' => [30.2, 50.2], 'end' => [30.5, 50.5]],
        ];

        $this->assertSame(RouteShape::DISCONTINUOUS, $this->service()->determineType($endpoints));
    }
}

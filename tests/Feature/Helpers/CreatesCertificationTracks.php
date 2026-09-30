<?php

namespace Tests\Feature\Helpers;

use App\Models\EcTrack;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Models\Layer;

/**
 * Tappe (EcTrack) per i test del passaporto (oc:8671). Da usare con
 * Queue::fake() + Http::fake(): la creazione di un EcTrack fa partire i job
 * DEM del package, che senza fake chiamano un servizio esterno.
 */
trait CreatesCertificationTracks
{
    /**
     * L'associazione al layer passa da un insert diretto su `layerables`:
     * LayerableObserver (oc:8080) trasferirebbe l'ownership della traccia al
     * proprietario del layer, rendendo impossibile riprodurre le tappe con
     * proprietario diverso.
     */
    protected function createTrack(int $ownerId, string $name, ?Layer $layer = null): WmEcTrack
    {
        $track = EcTrack::factory()->create([
            'user_id' => $ownerId,
            'name' => ['it' => $name],
            'properties' => [],
        ]);

        if ($layer !== null) {
            DB::table('layerables')->insert([
                'layer_id' => $layer->id,
                'layerable_type' => EcTrack::class,
                'layerable_id' => $track->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $track;
    }
}

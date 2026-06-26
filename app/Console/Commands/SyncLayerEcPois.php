<?php

namespace App\Console\Commands;

use App\Models\EcPoi;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\Layer;

class SyncLayerEcPois extends Command
{
    protected $signature = 'camminiditalia:sync-layer-ec-pois';

    protected $description = 'Associa tutti i related poi delle tracce ai layer di appartenenza e aggiorna i proprietari';

    public function handle(): int
    {
        $layers = Layer::with('ecTracks.ecPois')->get();

        $this->info("Sincronizzo {$layers->count()} layer...");

        $ecPoiModelClass = config('wm-package.ec_poi_model', \Wm\WmPackage\Models\EcPoi::class);
        $ecPoiMorphType = array_search($ecPoiModelClass, Relation::morphMap()) ?: $ecPoiModelClass;

        $this->withProgressBar($layers, function (Layer $layer) use ($ecPoiMorphType) {
            $poiIds = $layer->ecTracks
                ->flatMap(fn ($track) => $track->ecPois->pluck('id'))
                ->unique()
                ->values()
                ->toArray();

            if (empty($poiIds)) {
                return;
            }

            // Insert directly to bypass LayerableObserver::created — ownership
            // is handled in bulk below to avoid redundant per-row UPDATEs.
            $existingPoiIds = DB::table('layerables')
                ->where('layer_id', $layer->id)
                ->where('layerable_type', $ecPoiMorphType)
                ->pluck('layerable_id')
                ->toArray();

            $newPoiIds = array_diff($poiIds, $existingPoiIds);

            foreach ($newPoiIds as $poiId) {
                DB::table('layerables')->insert([
                    'layer_id' => $layer->id,
                    'layerable_id' => $poiId,
                    'layerable_type' => $ecPoiMorphType,
                ]);
            }

            $ownerId = $layer->user_id ?? config('camminiditalia.default_owner_id');
            EcPoi::whereIn('id', $poiIds)->update(['user_id' => $ownerId]);
        });

        $this->newLine();
        $this->info('Sincronizzazione completata.');

        return self::SUCCESS;
    }
}

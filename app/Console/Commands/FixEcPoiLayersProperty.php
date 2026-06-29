<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\LayerService;

class FixEcPoiLayersProperty extends Command
{
    protected $signature = 'camminiditalia:fix-ec-poi-layers-property
                            {--force : Bypassa il check di pre-deploy (usa solo se il fix in wm-package è confermato deployato)}';

    protected $description = 'Riallinea properties.layers su EcPoi e EcTrack per tutti i layer.';

    protected $help = 'IMPORTANTE: eseguire DOPO il deploy del fix in wm-package (oc:8140). Fare un backup del DB prima di procedere.';

    public function handle(LayerService $layerService): int
    {
        if (! $this->option('force') && ! $this->guardIsInPlace($layerService)) {
            $this->error('Il fix in wm-package (oc:8140) non sembra deployato.');
            $this->line('LayerService::updateLayersPropertyOnLayeredFeature() sta ancora processando layer senza filtri.');
            $this->line('Esegui il deploy del fix, poi rilancia questo command.');
            $this->line('Per bypassare questo controllo usa --force (solo se sai cosa fai).');

            return self::FAILURE;
        }

        $layers = Layer::with(['taxonomyActivities'])->get();
        $modelClasses = $layerService->getModelsWithLayersInProperties();

        $this->info("Riallineamento properties.layers su {$layers->count()} layer × ".count($modelClasses).' model class...');
        $this->newLine();

        $totalAdded = 0;
        $totalDeleted = 0;

        $this->withProgressBar($layers, function (Layer $layer) use ($layerService, $modelClasses, &$totalAdded, &$totalDeleted) {
            foreach ($modelClasses as $modelClass) {
                $result = $layerService->updateLayersPropertyOnLayeredFeature($layer, $modelClass);

                $added = count($result['added']);
                $deleted = count($result['deleted']);
                $totalAdded += $added;
                $totalDeleted += $deleted;

                if ($added > 0 || $deleted > 0) {
                    $this->newLine();
                    $modelName = class_basename($modelClass);
                    $this->line("  [{$layer->getStringName()}] {$modelName}: +{$added} / -{$deleted}");
                }
            }
        });

        $this->newLine(2);
        $this->info('Riallineamento completato.');
        $this->line("Totale aggiunte: {$totalAdded} | Totale rimozioni: {$totalDeleted}");

        return self::SUCCESS;
    }

    private function guardIsInPlace(LayerService $layerService): bool
    {
        $candidate = Layer::with(['taxonomyActivities'])
            ->whereDoesntHave('taxonomyActivities')
            ->get()
            ->first(function (Layer $layer) {
                return empty($layer->properties['taxonomy_where'] ?? []);
            });

        if (! $candidate) {
            return true;
        }

        // Esegui la verifica dentro una transazione che viene annullata: il metodo scrive nel DB
        // (beginTransaction + saveQuietly), ma il rollBack esterno annulla tutto tramite savepoint PostgreSQL.
        $isInPlace = true;
        DB::beginTransaction();
        try {
            foreach ($layerService->getModelsWithLayersInProperties() as $modelClass) {
                $result = $layerService->updateLayersPropertyOnLayeredFeature($candidate, $modelClass);
                if (! empty($result['added'])) {
                    $isInPlace = false;
                    break;
                }
            }
        } finally {
            DB::rollBack();
        }

        return $isInPlace;
    }
}

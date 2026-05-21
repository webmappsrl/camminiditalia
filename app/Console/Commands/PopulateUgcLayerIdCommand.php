<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\UgcService;

class PopulateUgcLayerIdCommand extends Command
{
    protected $signature = 'ugc:populate-layer-id';

    protected $description = 'Popola layer_id nelle properties dei UgcPoi e UgcTrack che ne sono privi tramite query spaziale PostGIS';

    public function handle(UgcService $ugcService): int
    {
        $this->processModel($ugcService, UgcPoi::class);
        $this->processModel($ugcService, UgcTrack::class);

        return self::SUCCESS;
    }

    private function processModel(UgcService $ugcService, string $modelClass): void
    {
        $shortName = class_basename($modelClass);
        $query = $modelClass::whereNull('properties->layer_id');
        $total = $query->count();

        if ($total === 0) {
            $this->info("{$shortName}: nessun record senza layer_id.");

            return;
        }

        $this->info("{$shortName}: trovati {$total} record senza layer_id.");
        $bar = $this->output->createProgressBar($total);
        $updated = 0;

        $query->chunkById(100, function ($models) use ($ugcService, $bar, &$updated) {
            foreach ($models as $model) {
                $layer = $ugcService->resolveLayer($model);
                if ($layer) {
                    $properties = $model->properties ?? [];
                    $properties['layer_id'] = $layer->id;
                    $model->properties = $properties;
                    $model->saveQuietly();
                    $updated++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("{$shortName}: completato {$updated}/{$total} aggiornati.");
    }
}

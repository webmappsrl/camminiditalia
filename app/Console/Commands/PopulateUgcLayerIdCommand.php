<?php

namespace App\Console\Commands;

use App\Jobs\ResolveUgcLayerJob;
use Illuminate\Console\Command;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;

class PopulateUgcLayerIdCommand extends Command
{
    protected $signature = 'ugc:populate-layer-id';

    protected $description = 'Accoda ResolveUgcLayerJob per tutti i UgcPoi e UgcTrack privi di layer_id';

    public function handle(): int
    {
        $this->processModel(UgcPoi::class);
        $this->processModel(UgcTrack::class);

        return self::SUCCESS;
    }

    private function processModel(string $modelClass): void
    {
        $shortName = class_basename($modelClass);
        $query = $modelClass::whereNull('properties->layer_id');
        $total = $query->count();

        if ($total === 0) {
            $this->info("{$shortName}: nessun record senza layer_id.");

            return;
        }

        $this->info("{$shortName}: accodamento di {$total} job...");
        $bar = $this->output->createProgressBar($total);

        $query->chunkById(100, function ($models) use ($bar) {
            foreach ($models as $model) {
                ResolveUgcLayerJob::dispatch($model, notify: false);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("{$shortName}: {$total} job accodati.");
    }
}

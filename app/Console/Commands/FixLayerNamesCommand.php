<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Models\Layer;

class FixLayerNamesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:layer-names';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rename layer names to the correct format';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $allLayers = Layer::all();
        $renamedLayers = 0;

        foreach ($allLayers as $layer) {
            $rawName = $layer->getRawOriginal('name');
            $nameData = json_decode($rawName, true);

            if (str_starts_with($nameData['it'], 'Cammini -')) {
                $oldName = $nameData['it'];
                $newName = preg_replace('/^Cammini -\s*/', '', $oldName);
                $nameData['it'] = $newName;

                $layer->name = $nameData;
                $layer->saveQuietly();

                $this->line("Layer ID {$layer->id}: '{$oldName}' → '{$newName}'");
                $renamedLayers++;
            }
        }

        $this->info('Operation completed successfully');
        $this->info("Layer renamed correctly: {$renamedLayers}".' on '.$allLayers->count());
    }
}

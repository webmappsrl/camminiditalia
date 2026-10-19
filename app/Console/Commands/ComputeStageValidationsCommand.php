<?php

namespace App\Console\Commands;

use App\Services\StageValidationService;
use App\Support\PassportLayer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/**
 * Popola e riallinea l'avanzamento del passaporto (oc:8165).
 *
 * Serve in due momenti: al rilascio, per far valere le UGC track già in archivio senza
 * aspettare che qualcuno ne registri una nuova; e ogni volta che cambia qualcosa sotto — una
 * tappa ritoccata, le varianti dichiarate — perché in quei casi il package rifà le
 * corrispondenze ma nessun evento dice al passaporto di rifare i conti.
 *
 * **Il lancio senza `--dry-run` è irreversibile**: una validazione, una volta scritta, non si
 * revoca. Guardare prima i conteggi.
 */
class ComputeStageValidationsCommand extends Command
{
    protected $signature = 'camminiditalia:compute-stage-validations
        {--dry-run : conta senza scrivere nulla}
        {--user= : id di una sola persona}';

    protected $description = 'Calcola quali tappe risultano percorse dalle UGC track registrate e aggiorna il passaporto';

    public function handle(StageValidationService $service): int
    {
        $onlyUser = $this->option('user');

        if ($this->option('dry-run')) {
            $counts = $service->previewForAllUsers();

            $this->info("Soglia di copertura: {$service->threshold()}%");
            $this->info("{$counts['tappe']} tappe si sbloccherebbero");
            $this->info("{$counts['persone']} persone riceverebbero almeno una tappa");
            $this->warn('Nessuna scrittura: è un dry-run. Il lancio vero non si può annullare.');

            return self::SUCCESS;
        }

        $userIds = $onlyUser
            ? [(int) $onlyUser]
            : $this->usersWithUgcTracks();

        $validatedCount = 0;
        $updatedCount = 0;

        $bar = $this->output->createProgressBar(count($userIds));
        $bar->start();

        foreach ($userIds as $userId) {
            $outcome = $service->recomputeForUser($userId);
            $validatedCount += $outcome['validated'];
            $updatedCount += $outcome['updated'];
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->info("{$updatedCount} coppie persona-tappa aggiornate, di cui {$validatedCount} validate");

        return self::SUCCESS;
    }

    /**
     * Le persone che hanno almeno una UGC track registrata su una tappa di un cammino **con il
     * passaporto acceso**: per le altre non c'è niente da calcolare.
     *
     * @return array<int>
     */
    private function usersWithUgcTracks(): array
    {
        return DB::query()
            ->fromSub(UgcTrack::recorded()->getQuery()->select('ugc_tracks.id', 'ugc_tracks.user_id'), 'u')
            ->join('ugc_track_ec_track_matches as m', 'm.ugc_track_id', '=', 'u.id')
            ->join('layerables as l', fn ($join) => $join
                ->on('l.layerable_id', '=', 'm.ec_track_id')
                ->where('l.layerable_type', UgcTrackMatchingService::make()->ecTrackMorphClass()))
            ->join('layers', 'layers.id', '=', 'l.layer_id')
            ->whereRaw(PassportLayer::enabledSql('layers'))
            ->distinct()
            ->orderBy('u.user_id')
            ->pluck('u.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}

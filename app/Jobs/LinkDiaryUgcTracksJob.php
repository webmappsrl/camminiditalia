<?php

namespace App\Jobs;

use App\Models\Diary;
use App\Services\DiaryUgcLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Ricalcola il legame fra un'esperienza e le registrazioni dell'autore (oc:8169).
 * Parte dopo il commit: la corrispondenza fra registrazioni e tappe può
 * arrivare dopo la creazione dell'esperienza, quindi il job rilegge tutto.
 */
class LinkDiaryUgcTracksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $diaryId)
    {
        // Parte solo a transazione chiusa (Queueable dichiara già la proprietà $afterCommit).
        $this->afterCommit = true;
    }

    public function handle(DiaryUgcLinkService $service): void
    {
        // Esperienza cancellata nel frattempo: niente da fare.
        $diary = Diary::find($this->diaryId);
        if ($diary !== null) {
            $service->link($diary);
        }
    }
}

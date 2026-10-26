<?php

namespace App\Jobs;

use App\Models\Experience;
use App\Services\ExperienceUgcLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Ricalcola il legame fra un'esperienza e le registrazioni dell'autore (oc:8169).
 * Parte dopo il commit: la corrispondenza fra registrazioni e tappe può
 * arrivare dopo la creazione dell'esperienza, quindi il job rilegge tutto.
 */
class LinkExperienceUgcTracksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $experienceId)
    {
        // Parte solo a transazione chiusa (Queueable dichiara già la proprietà $afterCommit).
        $this->afterCommit = true;
    }

    public function handle(ExperienceUgcLinkService $service): void
    {
        // Esperienza cancellata nel frattempo: niente da fare.
        $experience = Experience::find($this->experienceId);
        if ($experience !== null) {
            $service->link($experience);
        }
    }
}

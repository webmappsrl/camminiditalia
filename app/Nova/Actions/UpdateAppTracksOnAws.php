<?php

namespace App\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackAwsJob;

class UpdateAppTracksOnAws extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * Perform the action on the given models.
     *
     * @param  \Laravel\Nova\Fields\ActionFields  $fields
     * @param  \Illuminate\Support\Collection  $models
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $totalTracks = 0;

        foreach ($models as $app) {
            // Recupera tutte le tracks dell'app
            $tracks = $app->ecTracks;
            $totalTracks += $tracks->count();

            // Per ogni track, dispatcha il job di aggiornamento
            foreach ($tracks as $track) {
                UpdateEcTrackAwsJob::dispatch($track);
            }
        }

        return Action::message("Aggiornamento avviato per $totalTracks tracks!");
    }

    /**
     * Get the fields available on the action.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function fields(NovaRequest $request)
    {
        return [];
    }

    /**
     * Get the displayable name of the action.
     *
     * @return string
     */
    public function name()
    {
        return 'Aggiorna Tracks su AWS';
    }
}

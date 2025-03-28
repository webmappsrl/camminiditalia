<?php

namespace App\Nova;

use App\Nova\Actions\UpdateAppTracksOnAws;
use Wm\WmPackage\Nova\App as NovaApp;

class App extends NovaApp
{
    /**
     * Get the actions available for the resource.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function actions(\Laravel\Nova\Http\Requests\NovaRequest $request)
    {
        return array_merge(parent::actions($request), [
            (new UpdateAppTracksOnAws)
                ->onlyOnDetail()
                ->confirmText('Sei sicuro di voler aggiornare tutte le tracks di questa app su AWS?')
                ->confirmButtonText('Sì, aggiorna')
                ->cancelButtonText('No, annulla'),
        ]);
    }
}

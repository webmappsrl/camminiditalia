<?php

namespace App\Models;

use Illuminate\Support\Facades\Auth;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;

class EcTrack extends WmEcTrack
{
    /**
     * Boot the model and register events.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::creating(function ($ecTrack) {
            // Imposta automaticamente l'app
            if (empty($ecTrack->app_id)) {
                $ecTrack->app_id = App::first()->id;
            }

            // Se l'utente è un Validator, imposta automaticamente se stesso
            /** @var \App\Models\User|null $currentUser */
            $currentUser = Auth::user();
            if (empty($ecTrack->user_id) && $currentUser) {
                if ($currentUser->hasRole('Validator')) {
                    $ecTrack->user_id = $currentUser->id;
                }
            }
        });
    }
}

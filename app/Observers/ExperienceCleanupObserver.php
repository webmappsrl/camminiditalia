<?php

namespace App\Observers;

use App\Models\Experience;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

/**
 * Rimuove le Experience (e i relativi media e file, tramite InteractsWithMedia su
 * Experience) quando l'utente o il layer a cui appartengono viene cancellato.
 *
 * Registrato su `deleting` (non `deleted`) per lo stesso motivo di
 * CertificationRequestCleanupObserver: il FK `cascadeOnDelete()` cancellerebbe le righe
 * con un cascade SQL puro, senza eventi Eloquent, e media e file resterebbero orfani.
 */
class ExperienceCleanupObserver
{
    /**
     * Cancella, tramite il modello, le esperienze dell'utente o del layer in cancellazione.
     */
    public function deleting(Model $model): void
    {
        if ($model instanceof User) {
            Experience::where('user_id', $model->id)->cursor()->each->delete();

            return;
        }

        if ($model instanceof Layer) {
            Experience::where('layer_id', $model->id)->cursor()->each->delete();
        }
    }
}

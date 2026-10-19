<?php

namespace App\Observers;

use App\Models\CertificationRequest;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

/**
 * Rimuove le CertificationRequest (e i relativi media e file, tramite InteractsWithMedia
 * su CertificationRequest) quando l'utente o il layer a cui appartengono viene cancellato.
 *
 * Registrato su `deleting` (non `deleted`): sia `users.id` che `layers.id` hanno un FK
 * `cascadeOnDelete()` su `certification_requests`, quindi il DB cancellerebbe comunque le
 * righe — ma lo farebbe con un cascade SQL puro, senza passare da Eloquent e quindi senza
 * innescare gli eventi che cancellano media e file (resterebbero orfani). Agganciandoci a `deleting` (prima
 * che la riga User/Layer venga effettivamente rimossa) possiamo ancora interrogare e
 * cancellare le CertificationRequest tramite il modello.
 */
class CertificationRequestCleanupObserver
{
    public function deleting(Model $model): void
    {
        if ($model instanceof User) {
            CertificationRequest::where('user_id', $model->id)->cursor()->each->delete();

            return;
        }

        if ($model instanceof Layer) {
            CertificationRequest::where('layer_id', $model->id)->cursor()->each->delete();
        }
    }
}

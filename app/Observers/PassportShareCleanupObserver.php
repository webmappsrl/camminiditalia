<?php

namespace App\Observers;

use App\Models\PassportShare;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

/**
 * Cancella le condivisioni del passaporto, tappe (oc:8702) e cammini completati
 * (oc:8703), e con loro media e file dell'immagine (InteractsWithMedia), quando
 * si cancella l'utente, il layer o la tappa a cui appartengono.
 *
 * Stesso schema di CertificationRequestCleanupObserver: le FK di
 * `passport_shares` sono `cascadeOnDelete()`, ma il cascade SQL non
 * passa da Eloquent e lascerebbe orfane le righe `media` e i file. Su
 * `deleting` la riga madre esiste ancora e le condivisioni si cancellano
 * tramite il model.
 *
 * Limite: scatta solo per le cancellazioni Eloquent di un singolo model.
 * Le cancellazioni massive con il query builder (`User::where(...)->delete()`,
 * `DB::table(...)->delete()`) e i cascade a catena delle FK (per esempio
 * cancellare un'App, che porta via i suoi layer e le sue tappe a livello di
 * DB) non passano da qui e lasciano orfani media e file.
 */
class PassportShareCleanupObserver
{
    /**
     * Cancella via Eloquent le condivisioni legate al model in cancellazione.
     */
    public function deleting(Model $model): void
    {
        // cursor(): una riga alla volta, come CertificationRequestCleanupObserver.
        $query = match (true) {
            $model instanceof User => PassportShare::where('user_id', $model->getKey()),
            // Il layer porta via le sue tappe condivise e il cammino stesso.
            $model instanceof Layer => PassportShare::where('layer_id', $model->getKey()),
            $model instanceof EcTrack => PassportShare::where('shareable_type', $model->getMorphClass())
                ->where('shareable_id', $model->getKey()),
            default => null,
        };

        $query?->cursor()->each->delete();
    }
}

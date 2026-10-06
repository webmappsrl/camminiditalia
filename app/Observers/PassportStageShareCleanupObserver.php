<?php

namespace App\Observers;

use App\Models\PassportStageShare;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

/**
 * Cancella le condivisioni delle tappe del passaporto (oc:8702), e con loro
 * media e file dell'immagine (InteractsWithMedia), quando si cancella
 * l'utente, il layer o la tappa a cui appartengono.
 *
 * Stesso schema di CertificationRequestCleanupObserver: le FK di
 * `passport_stage_shares` sono `cascadeOnDelete()`, ma il cascade SQL non
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
class PassportStageShareCleanupObserver
{
    /**
     * Cancella via Eloquent le condivisioni legate al model in cancellazione.
     */
    public function deleting(Model $model): void
    {
        $column = match (true) {
            $model instanceof User => 'user_id',
            $model instanceof Layer => 'layer_id',
            $model instanceof EcTrack => 'ec_track_id',
            default => null,
        };

        if ($column !== null) {
            // cursor(): una riga alla volta, come CertificationRequestCleanupObserver.
            PassportStageShare::where($column, $model->getKey())->cursor()->each->delete();
        }
    }
}

<?php

namespace Tests\Feature\Helpers;

use App\Jobs\RecalculateLayerAttributesJob;
use App\Models\User;
use App\Support\PassportLayer;
use Illuminate\Bus\UniqueLock;
use Wm\WmPackage\Models\Layer;

trait LayerTestHelpers
{
    protected function createUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function createLayer(?int $userId = null): Layer
    {
        $layer = Layer::factory()->create($userId ? ['user_id' => $userId] : []);

        // LayerObserver::saved() (oc:8180, auto-riparazione) dispatcha
        // RecalculateLayerAttributesJob su OGNI save(), quindi anche su questa
        // create(). Con ShouldBeUniqueUntilProcessing il lock viene preso al
        // dispatch e rilasciato solo quando il job INIZIA l'esecuzione: sotto
        // Queue::fake() il job non viene mai eseguito, quindi il lock
        // resterebbe preso per questo layer_id per tutta la durata del test,
        // mascherando (come "duplicato scartato") ogni dispatch successivo
        // che il test vuole verificare esplicitamente. Lo rilasciamo qui,
        // subito dopo la creazione, per isolare i test dal dispatch
        // implicito della creazione stessa: in produzione il rilascio
        // avverrebbe comunque quasi subito, appena un worker prende in
        // carico il job.
        $this->releaseRecalculateLayerAttributesLock($layer->id);

        return $layer;
    }

    /**
     * Accende sul cammino sia il passaporto cartaceo sia quello GPS (oc:8763, oc:8809).
     * Scrive con `saveQuietly()` per non far scattare i job di LayerObserver.
     */
    protected function enableGpsPassport(Layer $layer): Layer
    {
        return $this->setPassportFlags($layer, [PassportLayer::PAPER_KEY => true, PassportLayer::GPS_KEY => true]);
    }

    /**
     * Accende solo il passaporto cartaceo sul cammino (GPS spento).
     */
    protected function enablePaperPassport(Layer $layer): Layer
    {
        return $this->setPassportFlags($layer, [PassportLayer::PAPER_KEY => true]);
    }

    /**
     * @param  array<string, bool>  $flags
     */
    private function setPassportFlags(Layer $layer, array $flags): Layer
    {
        $layer->forceFill(['properties' => array_merge($layer->properties ?? [], $flags)])->saveQuietly();

        return $layer;
    }

    protected function releaseRecalculateLayerAttributesLock(int $layerId): void
    {
        app(UniqueLock::class)->release(new RecalculateLayerAttributesJob($layerId));
    }

    protected function createUserWithoutRole(): User
    {
        return User::factory()->create();
    }
}

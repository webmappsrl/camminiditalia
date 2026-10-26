<?php

namespace App\Nova\Actions;

use App\Nova\Layer;
use App\Observers\PassportLayerObserver;
use App\Support\PassportLayer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Nova\Actions\BulkEditAction;

/**
 * Modifica in blocco dei due interruttori del Passaporto sui cammini (oc:8809).
 *
 * I campi li eredita da `BulkEditAction` del package, limitato alle due chiavi del passaporto:
 * i Boolean della risorsa diventano Select tri-stato (vuoto = non cambiare). Si riscrive solo
 * `handle()`, perché deve applicare il vincolo «GPS acceso ⇒ cartaceo acceso» e far partire
 * il ricalcolo delle tappe all'accensione del GPS (lo spegnimento non toglie nulla).
 *
 * Salva con `saveQuietly()` e chiama a mano i passi di `PassportLayerObserver` (`saving()` e
 * `dispatchOnGpsTurnedOn()`): un `save()` farebbe scattare anche `LayerObserver`, che a ogni
 * salvataggio accoda il ricalcolo degli attributi del cammino, qui inutile su tutti i cammini
 * selezionati. Un futuro observer di layer che debba reagire al flag del passaporto quindi da
 * qui non scatta: va chiamato a mano.
 *
 * Il tutto-o-niente vale per blocco: Nova chiama `handle()` a chunk (200 modelli), quindi una
 * selezione più grande può applicarsi a metà. Oggi i cammini sono 122, un chunk solo.
 */
class BulkEditPassportAction extends BulkEditAction
{
    public function __construct()
    {
        parent::__construct(Layer::class, [
            'properties->'.PassportLayer::PAPER_KEY,
            'properties->'.PassportLayer::GPS_KEY,
        ]);
    }

    public function name(): string
    {
        return __('Passport: bulk edit');
    }

    /**
     * Il `handle()` del package è `void`: non può restituire `Action::danger()`. Nova però cerca
     * prima `handleFor<Modelli>` (`ActionMethod::determine`), e per i cammini è questo.
     */
    public function handleForLayers(ActionFields $fields, Collection $models): mixed
    {
        $requested = [
            PassportLayer::PAPER_KEY => $this->triState($this->rawValue($fields, PassportLayer::PAPER_KEY)),
            PassportLayer::GPS_KEY => $this->triState($this->rawValue($fields, PassportLayer::GPS_KEY)),
        ];
        $requested = array_filter($requested, fn ($value) => $value !== null);

        if ($requested === []) {
            return null;
        }

        // Prima si calcola tutto: se anche un solo cammino viola il vincolo non se ne modifica nessuno.
        $resolved = [];
        $violating = [];
        foreach ($models as $layer) {
            $properties = array_merge($layer->properties ?? [], $requested);
            $normalized = PassportLayer::normalize($properties);

            // Viola il vincolo chi chiedeva il cartaceo spento ma, col GPS acceso, la
            // normalizzazione lo riaccende: la regola GPS ⇒ cartaceo sta solo in normalize().
            if (($requested[PassportLayer::PAPER_KEY] ?? null) === false
                && PassportLayer::paperEnabledIn($normalized)) {
                $violating[] = $layer->getStringName();
            }

            $resolved[] = [$layer, $normalized];
        }

        if ($violating !== []) {
            return Action::danger(__(
                'The paper passport cannot be turned off on :names: the automatic passport is on.',
                ['names' => implode(', ', $violating)]
            ));
        }

        $observer = app(PassportLayerObserver::class);

        DB::transaction(function () use ($resolved, $observer) {
            foreach ($resolved as [$layer, $properties]) {
                // Il «prima» si legge qui: dopo saveQuietly() getOriginal() ha già il valore nuovo.
                $before = $layer->properties ?? [];
                $layer->properties = $properties;
                // Ridondante: `PassportLayer::normalize()` ha già sistemato le properties. Resta
                // solo per seguire l'observer se in futuro `saving()` facesse altro.
                $observer->saving($layer);
                $layer->saveQuietly();

                if ($layer->wasChanged('properties')) {
                    $observer->dispatchOnGpsTurnedOn($layer, $before);
                }
            }
        });

        return null;
    }

    /**
     * Mai chiamato da Nova (vince `handleForLayers`); delega per chi lo invocasse a mano.
     */
    public function handle(ActionFields $fields, Collection $models): void
    {
        $this->handleForLayers($fields, $models);
    }

    /**
     * Nova serializza l'arrow notation in un oggetto annidato (`properties` => [chiave => v]);
     * i test usano la chiave flat `properties->chiave`: si leggono entrambe.
     */
    private function rawValue(ActionFields $fields, string $key): mixed
    {
        $raw = $fields->getAttributes();
        $flat = 'properties->'.$key;

        return array_key_exists($flat, $raw) ? $raw[$flat] : data_get($raw, 'properties.'.$key);
    }

    /**
     * '1' → true, '0' → false, vuoto/assente → null (non cambiare).
     */
    private function triState(mixed $value): ?bool
    {
        return match ((string) $value) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }
}

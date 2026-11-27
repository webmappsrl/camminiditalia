<?php

namespace App\Nova\Dashboards;

use Laravel\Nova\Card;
use Laravel\Nova\Dashboards\Main as Dashboard;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Cards\LayerAnalytics\LayerAnalyticsCard;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\LayerMapCard;

/**
 * Dashboard «Statistiche» (oc:9040): l'unico posto in cui si vedono le statistiche dei cammini.
 *
 * - Administrator: l'aggregato di tutti i cammini e la mappa di tutti i cammini con le persone in
 *   tempo reale.
 * - Validator: le statistiche e la mappa con le persone in tempo reale del suo primo layer, cioè
 *   quello con l'id più basso (la data in cui un layer viene assegnato a un Validator non è
 *   registrata).
 * - Altri ruoli: dashboard vuota.
 *
 * Le mappe scaricano i percorsi una volta e le posizioni ogni 90 secondi (LayerMapCard).
 */
class Main extends Dashboard
{
    public function name()
    {
        return __('Statistics');
    }

    /**
     * Esplicito: l'URL /dashboards/main è la pagina di arrivo dopo il login di Nova e non deve
     * dipendere dal nome della classe.
     */
    public function uriKey()
    {
        return 'main';
    }

    /**
     * Get the cards for the dashboard.
     *
     * @return array<int, Card>
     */
    public function cards(): array
    {
        $user = request()->user();

        if ($user === null) {
            return [];
        }

        if ($user->hasRole('Administrator')) {
            return $this->administratorCards();
        }

        if ($user->hasRole('Validator')) {
            return $this->validatorCards($user->id);
        }

        return [];
    }

    /**
     * @return array<int, Card>
     */
    private function administratorCards(): array
    {
        $anyLayer = Layer::query()->first();

        if ($anyLayer === null) {
            return [];
        }

        $cards = $this->analyticsEnabled($anyLayer) ? [LayerAnalyticsCard::global()] : [];

        // La mappa di tutti i cammini c'è anche con l'analytics spento, come quella del Validator:
        // mostra comunque i percorsi (oc:9040, seconda parte).
        // Legenda e numero di persone sotto la mappa: richiesta di Cammini d'Italia (oc:9040).
        $cards[] = LayerMapCard::global()->withLiveLegend();

        return $cards;
    }

    /**
     * @return array<int, Card>
     */
    private function validatorCards(int $userId): array
    {
        $firstLayer = Layer::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->first();

        if ($firstLayer === null) {
            return [];
        }

        $cards = [];

        if ($this->analyticsEnabled($firstLayer)) {
            $analytics = new LayerAnalyticsCard($firstLayer);
            // La card del singolo layer nasce per la detail view (onlyOnDetail = true), e nelle
            // dashboard il frontend di Nova mostra solo le card con onlyOnDetail false
            // (vendor/laravel/nova/resources/js/components/Cards.vue): l'API la restituirebbe,
            // ma la pagina la scarterebbe.
            $analytics->onlyOnDetail = false;
            $cards[] = $analytics;
        }

        // La mappa c'è anche con analytics spento: mostra comunque il percorso del cammino.
        $cards[] = (new LayerMapCard($firstLayer))->withLiveLegend();

        return $cards;
    }

    /**
     * Stessa condizione di Wm\WmPackage\Nova\Layer::detailCards() e indexCards()
     * (wm-package/src/Nova/Layer.php:271-307), che nel package è in metodi protected della
     * Resource: se il package la cambia, va riallineata qui a mano.
     */
    private function analyticsEnabled(Layer $layer): bool
    {
        $app = $layer->appOwner()->first();
        $properties = $app ? ($app->properties ?? []) : [];

        return $app !== null
            && (($properties['analytics_app_enabled'] ?? false)
                || ($properties['analytics_webapp_enabled'] ?? false));
    }
}

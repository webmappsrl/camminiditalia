# Dashboard «Statistiche»

## Come funziona oggi

Le statistiche dei cammini (PostHog) si vedono **solo** nella dashboard `App\Nova\Dashboards\Main`,
che si chiama «Statistiche» (`name()` = `__('Statistics')`) e ha `uriKey()` fisso a `main`:
`/nova/dashboards/main` è la pagina su cui Nova porta ogni utente dopo il login
(`vendor/laravel/nova/src/Concerns/HandlesRoutes.php:18`), quindi l'URL non deve cambiare.

Cosa vede ciascun ruolo:

| Ruolo | Card |
|---|---|
| Administrator | `LayerAnalyticsCard::global()`, l'aggregato di tutti i cammini, se l'analytics è acceso |
| Validator | `LayerAnalyticsCard` del suo **primo layer** (se l'analytics è acceso), poi `LayerMapCard` dello stesso layer |
| Validator senza layer | niente |
| Guest | non entra: il gate di Nova risponde 403, come prima |

- **«Primo layer» = il layer con `user_id` del Validator e `id` più basso** (`orderBy('id')`).
- **«Analytics acceso»** = l'App del layer ha `analytics_app_enabled` o `analytics_webapp_enabled`.
  È la stessa condizione che il package usa in `Wm\WmPackage\Nova\Layer::detailCards()` e
  `indexCards()` (metodi `protected`), copiata in `Main::analyticsEnabled()`: se il package la
  cambia, va riallineata a mano.
- **`onlyOnDetail`:** nelle dashboard il frontend di Nova mostra solo le card con
  `onlyOnDetail == false` (`vendor/laravel/nova/resources/js/components/Cards.vue`), mentre l'API
  le restituisce tutte. `new LayerAnalyticsCard($layer)` nasce con `onlyOnDetail = true` per la
  detail view: nella dashboard va messo a `false`. Il test lo controlla.
- **La mappa** è `LayerMapCard` di `wm-package`: come funziona è nella pagina del package,
  [wm-package/docs/knowledge/analytics-posthog.md](../../wm-package/docs/knowledge/analytics-posthog.md),
  voce «La stessa mappa in una card».
- **Pagine dei Layer:** niente statistiche. `App\Nova\Layer::canSeeGlobalAnalyticsCard()`
  restituisce `false` (index) e `App\Nova\Layer::detailCards()` toglie `LayerAnalyticsCard` dalle
  card del package (detail view), lasciando le altre. La mappa con le posizioni in tempo reale
  resta nella detail view.
- In locale senza le variabili `POSTHOG_*` nel `.env` la card delle statistiche mostra
  «Impossibile caricare i dati analytics.»: la richiesta va a `api/projects//query`.

Test: `tests/Feature/LayerGlobalAnalyticsCardVisibilityTest.php` (dashboard e pagine dei Layer),
`tests/Feature/LayerMapCardTest.php` (la card del package, testata qui perché i test del package
non si caricano dal consumer).

## Perché così

- **Le statistiche si spostano, non si duplicano** (oc:9040): il cliente ha chiesto di metterle
  nella dashboard, e allo scrum del 25/11 Peppe ha parlato di «taglia e incolla» della logica
  statistica «sui layer», sia l'aggregato dell'Administrator sia quella del Validator.
- **Il Validator vede un solo layer, il primo** (oc:9040, review di Alessandro): un Validator in
  teoria gestisce un solo cammino. Il «primo» è l'`id` più basso perché la data in cui un layer
  viene assegnato a un Validator **non è registrata** (solo `layers.user_id`; `created_at` è la
  nascita del layer, `updated_at` cambia a ogni modifica; nessuna tabella di storico). **Il criterio
  non viene dal cliente**: se non va bene si cambia l'ordinamento, e per «il primo assegnato»
  bisogna prima iniziare a registrare le assegnazioni.
- **`detailCards()` filtra invece di riscrivere** (oc:9040): così le card che il package aggiungerà
  alla detail view del Layer continuano a comparire in camminiditalia.
- **Nessun aggiornamento automatico della mappa** (oc:9040): la dashboard è la pagina di arrivo dopo
  il login, e il polling ogni 2 s della detail view (circa 100 KB a richiesta) vorrebbe dire decine
  di migliaia di richieste al giorno per ogni scheda del browser lasciata aperta; le posizioni restano comunque in cache
  90 s lato server.
- **Statistiche prima, mappa dopo** (oc:9040): il cliente chiede «la parte di statistiche, inclusa
  la mappa»; le linee guida sulle dashboard mettono in alto ciò che conta di più.

## Domande aperte

- **Statistiche di un singolo cammino:** dopo oc:9040 l'Administrator vede solo l'aggregato, e un
  Validator con più layer vede solo il primo. Da chiarire con il cliente (filtro per cammino nella
  card globale, oppure la card del layer di nuovo nella detail view).
- **Mappa globale dell'Administrator:** non fatta. Servono 1.252 EC track (35 MB di GeoJSON, circa
  1,7 MB semplificate) e metodi oggi `private` di `AnalyticsService`; stimata 3-4 ore più il rischio
  di prestazioni. Passata ad Alessandro.
- **La route del GeoJSON della mappa risponde senza login**: il perché è nella pagina del package
  ([analytics-posthog.md](../../wm-package/docs/knowledge/analytics-posthog.md), «Limiti
  accettati»). Su camminiditalia, in locale e senza cookie, `/nova-vendor/feature-collection-map/layers/1`
  risponde 200 mentre `/nova-api/layers` risponde 401 (verificato il 25/11). Gravità da valutare
  con Alessandro; in produzione non verificato.

## Come ci siamo arrivati

- **Card aggregata sopra l'index dei Layer, solo Administrator** (oc:8182, superato da oc:9040):
  il punto per decidere chi la vedeva era `canSeeGlobalAnalyticsCard()`. Superato quando il
  cliente ha chiesto le statistiche nella dashboard: chi vuole cambiare chi vede le statistiche
  tocca `App\Nova\Dashboards\Main`.

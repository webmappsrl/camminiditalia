# Dashboard «Statistiche»

## Come funziona oggi

Le statistiche dei cammini (PostHog) si vedono **solo** nella dashboard `App\Nova\Dashboards\Main`,
che si chiama «Statistiche» (`name()` = `__('Statistics')`) e ha `uriKey()` fisso a `main`:
`/nova/dashboards/main` è la pagina su cui Nova porta ogni utente dopo il login
(`vendor/laravel/nova/src/Concerns/HandlesRoutes.php:18`), quindi l'URL non deve cambiare.

Cosa vede ciascun ruolo:

| Ruolo | Card |
|---|---|
| Administrator | `LayerAnalyticsCard::global()`, l'aggregato di tutti i cammini, se l'analytics è acceso; poi `LayerMapCard::global()`, la mappa di tutti i cammini, sempre (se esiste almeno un layer) |
| Validator | `LayerAnalyticsCard` del suo **primo layer** (se l'analytics è acceso), poi `LayerMapCard` dello stesso layer |
| Validator che non è proprietario di nessun layer | niente |
| Guest | non entra: il gate di Nova risponde 403, come prima |

- **«Primo layer» = il layer con `user_id` del Validator e `id` più basso** (`orderBy('id')`).
- **Un Validator vede la dashboard vuota se non è proprietario di nessun layer**, anche quando
  possiede le tracce di un cammino. Succede quando `layers.user_id` è null. Sul DB locale il 26/11:
  40 layer su 118 senza proprietario e 25 Validator su 45 senza layer (per esempio «Cammino del
  Salento», layer 52 senza proprietario e 5 tracce dell'utente 2562). Prima di oc:9040 era uguale:
  la detail view del Layer apre solo al proprietario.
- **«Analytics acceso»** = l'App del layer ha `analytics_app_enabled` o `analytics_webapp_enabled`.
  È la stessa condizione che il package usa in `Wm\WmPackage\Nova\Layer::detailCards()` e
  `indexCards()` (metodi `protected`), copiata in `Main::analyticsEnabled()`: se il package la
  cambia, va riallineata a mano.
- **`onlyOnDetail`:** nelle dashboard il frontend di Nova mostra solo le card con
  `onlyOnDetail == false` (`vendor/laravel/nova/resources/js/components/Cards.vue`), mentre l'API
  le restituisce tutte. `new LayerAnalyticsCard($layer)` nasce con `onlyOnDetail = true` per la
  detail view: nella dashboard va messo a `false`. Il test lo controlla.
- **Le mappe** sono `LayerMapCard` di `wm-package`. Scaricano i percorsi una volta e le posizioni
  ogni 90 s, senza cambiare zoom e centro. La card del Validator prende il percorso da
  `/nova-vendor/layer-analytics/{layer}/routes`, quella dell'Administrator da `…/all-routes`. Come
  funzionano, e chi può leggere gli endpoint, è nella pagina del package,
  [wm-package/docs/knowledge/analytics-posthog.md](../../wm-package/docs/knowledge/analytics-posthog.md),
  voce «La stessa mappa in una card».
- **«Tutti i cammini»**: il package traduce `All routes` in modo neutro («Tutti i percorsi»), e
  `resources/lang/{it,fr,es,de}.json` lo sovrascrive con le parole di Cammini d'Italia.
- **Pagine dei Layer:** niente statistiche. `App\Nova\Layer::canSeeGlobalAnalyticsCard()`
  restituisce `false` (index) e `App\Nova\Layer::detailCards()` toglie `LayerAnalyticsCard` dalle
  card del package (detail view), lasciando le altre. La mappa con le posizioni in tempo reale
  resta nella detail view.
- In locale senza le variabili `POSTHOG_*` nel `.env` la card delle statistiche mostra
  «Impossibile caricare i dati analytics.»: la richiesta va a `api/projects//query`.

Test:
- `tests/Feature/LayerGlobalAnalyticsCardVisibilityTest.php`: dashboard e pagine dei Layer;
- `tests/Feature/LayerMapCardTest.php`: la card del package, testata qui perché i test del package
  non si caricano dal consumer;
- `tests/Feature/LiveMapEndpointsTest.php`: chi legge gli endpoint delle mappe;
- `tests/Feature/AllLayersLiveMapDataTest.php`: percorsi e posizioni della mappa globale.

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
- **Posizioni ogni 90 s, percorsi una volta** (oc:9040, seconda parte). Il polling ogni 2 s della
  detail view riscarica tutto il GeoJSON (circa 100 KB a richiesta). Per la dashboard, che è la
  pagina di arrivo dopo il login, vorrebbe dire decine di migliaia di richieste al giorno per ogni
  tab lasciata aperta. Le posizioni hanno quindi un endpoint a sé, leggero, chiamato con la stessa
  cadenza della loro cache sul server (90 s). Vale per la card dell'Administrator e per quella del
  Validator.
- **Mappa globale solo con le persone vicine a un cammino** (oc:9040, seconda parte): Giuseppe
  l'ha chiesta in call per gli amministratori, e senza i percorsi i pallini non hanno senso. I
  percorsi sono semplificati a circa 50 m (1,4 MB invece di 29 MB) e le persone sono quelle entro
  50 m da una traccia di un layer, come nella mappa del singolo cammino.
- **Validator senza layer: dashboard vuota** (oc:9040, decisione di Alessandro del 26/11). Ricavare
  il cammino dalle tracce darebbe a un Validator i dati di un layer di cui non è proprietario, e
  una tappa può stare in più cammini. I layer senza proprietario si segnalano al cliente, che li
  sistema da Nova, come già deciso in oc:8314.
- **Statistiche prima, mappa dopo** (oc:9040): il cliente chiede «la parte di statistiche, inclusa
  la mappa»; le linee guida sulle dashboard mettono in alto ciò che conta di più.

## Domande aperte

- **Statistiche di un singolo cammino:** dopo oc:9040 l'Administrator vede solo l'aggregato, e un
  Validator con più layer vede solo il primo. Da chiarire con il cliente (filtro per cammino nella
  card globale, oppure la card del layer di nuovo nella detail view).
- **La route del GeoJSON della detail view risponde senza login**: il perché è nella pagina del
  package ([analytics-posthog.md](../../wm-package/docs/knowledge/analytics-posthog.md), «Limiti
  accettati»). Su camminiditalia, in locale e senza cookie, `/nova-vendor/feature-collection-map/layers/1`
  risponde 200 mentre `/nova-api/layers` risponde 401 (verificato il 25/11). Le card della
  dashboard non la usano più (oc:9040, seconda parte), la detail view sì. Da chiudere in un
  ticket separato del package; in produzione non verificato.
- **Senza login una 403 su una route Nova diventa 500**: `NovaExceptionHandler` disegna il menu, e
  `app/Providers/NovaServiceProvider.php:114` chiama `hasAnyRole()` su un utente null. Gli
  endpoint delle mappe rispondono 403 in JSON per non passarci. Da correggere in un ticket a parte.

## Come ci siamo arrivati

- **Card aggregata sopra l'index dei Layer, solo Administrator** (oc:8182, superato da oc:9040):
  il punto per decidere chi la vedeva era `canSeeGlobalAnalyticsCard()`. Superato quando il
  cliente ha chiesto le statistiche nella dashboard: chi vuole cambiare chi vede le statistiche
  tocca `App\Nova\Dashboards\Main`.
- **Mappa globale dell'Administrator** (oc:9040, rimandata nella prima parte e fatta nella
  seconda): era stata lasciata fuori per il peso dei percorsi (35 MB di GeoJSON). Risolto con i
  percorsi semplificati in PostGIS e in cache.

> Ticket: oc:9040

# Notes — Personalizzazione Laravel: logo definitivo e dashboard «Statistiche»

## Divergenze dal piano, task per task

### Task 5 test della dashboard e delle pagine dei Layer

- Il Guest non vede una dashboard vuota, come scritto nell'overview: `/nova-api/dashboards/cards/main`
  gli risponde **403**, perché il gate di Nova lo blocca già oggi (comportamento esistente del repo,
  non introdotto qui). Il caso «Validator senza layer e Guest» è diventato due test:
  `test_validator_without_layers_sees_an_empty_dashboard` e `test_guest_cannot_open_the_dashboard`.
- La card di benvenuto di Nova ha componente `help-card`, non `help`.
- Le card della dashboard si leggono da `/nova-api/dashboards/cards/main`; quelle della detail view
  del Layer da `/nova-api/layers/cards?resourceId={id}` (Nova filtra lì le card `onlyOnDetail`).
  La dashboard invece non filtra `onlyOnDetail` (`Nova::availableDashboardCardsForDashboard()`), per
  questo `new LayerAnalyticsCard($layer)` vi compare.

## Bug trovati

- **La card delle statistiche del Validator non compariva nella dashboard** (trovato dalla dev nel
  browser, 25/11). `new LayerAnalyticsCard($layer)` ha `onlyOnDetail = true`, e nelle dashboard il
  frontend di Nova mostra solo le card con `onlyOnDetail == false`
  (`vendor/laravel/nova/resources/js/components/Cards.vue:76-80`). L'API la restituiva, quindi il
  test sull'API passava. Corretto in `Main::validatorCards()` (`onlyOnDetail = false`); il test ora
  controlla anche `onlyOnDetail` di ogni card della dashboard.

- **La route del GeoJSON della mappa risponde senza login** (trovato nella challenge, 25/11;
  gravità da valutare con Alessandro, non blocca il merge).
  - `wm-package/src/Nova/Fields/FeatureCollectionMap/src/FieldServiceProvider.php:34` registra
    `/nova-vendor/feature-collection-map/{model}/{id}` con il solo middleware `nova`; in
    `config/nova.php:103-116` `Authenticate` e `Authorize` stanno in `api_middleware`, non in
    `nova`.
  - Verifica in locale senza cookie: `/nova-api/layers` → 401, `/nova-vendor/layer-analytics/global`
    → 403, `/nova-vendor/feature-collection-map/layers/1` → **200** con il GeoJSON (109.813 byte).
  - In locale la risposta non contiene posizioni (PostHog non configurato nel `.env`). In
    produzione le dovrebbe contenere (`wm-package/src/Models/Layer.php:468`), anonime perché
    camminiditalia non accende `ANALYTICS_SHOW_LIVE_USER_IDENTITY`. **Non verificato in
    produzione.**
  - Probabilmente una svista, non una scelta: `wm-package/docs/knowledge/analytics-posthog.md:153-155`
    accetta che «chi supera il gate Nova» veda tutti i layer, quindi dà per scontato il login; il
    middleware è quello del commit che ha creato il Field (`7e73651e`, 04/11/2025), quando la
    mappa disegnava solo geometrie, e le posizioni sono arrivate dopo (oc:8910, oc:8637).
  - Esiste già oggi, non lo introduce questo ticket; la `LayerMapCard` ne diventa un secondo
    utilizzatore. Eventuale correzione: ticket separato in `wm-package` (`Authenticate` sulla
    route e controllo sul singolo layer), da decidere con Alessandro.

## Decisioni

- **Tag** (25/11): associato il tag `camminiditalia` (id 495). Scartati `Backend Cyclando`,
  `Documentation: Documentazione backend EcPoi/RelatedPoi`, `collaudo camminiditalia passaporto`:
  non riguardano questo lavoro.
- **Stima** (25/11): 3h decise dalla dev (proposta indipendente: misurato 1,7h + stimato 2,0h).
- **Nuova stima dopo la review di Alessandro** (25/11): lo scope è cresciuto con la mappa del
  Validator (`LayerMapCard` in `wm-package`) e la rimozione delle statistiche dalla detail view.
  Proposta indipendente: misurato 5,1h + stimato 4,8h = 9,9h; la dev ha scelto **8h**. La mappa
  globale dell'Administrator è esclusa dalla stima.
- **Validator nella dashboard: un solo layer, il primo** (review di Alessandro, 25/11). Il
  Validator in teoria è responsabile di un solo layer; se ne ha più di uno, la dashboard mostra
  la card del solo primo.
  - «Primo» vuol dire **`id` più basso** (`orderBy('id')`), perché la data in cui un layer è stato
    assegnato a un Validator **non è registrata**: il legame è solo `layers.user_id`, la tabella
    `layers` ha solo `created_at` (nascita del layer) e `updated_at` (qualunque modifica), e non
    c'è nessuna tabella di storico o di audit (verificato sul DB locale il 25/11).
  - Caso reale sul DB locale: il Validator 1378 ha 3 layer (id 3 Via Ellenica, 4 Via Peuceta,
    45 Via Lucana - Cammino Materano), creati a pochi secondi di distanza il 22/04/2025; vede la
    Via Ellenica. Qui `id`, `created_at` e `rank` danno lo stesso ordine.
  - **Il criterio non è stato specificato dal cliente.** Se al cliente non va bene, si cambia
    l'ordinamento secondo quello che chiede. Se vuole davvero «il primo assegnato», serve prima
    registrare le assegnazioni (colonna o tabella nuova), e varrebbe solo da quel momento in poi.

- **Le statistiche escono da tutte le pagine dei Layer** (25/11, rilettura di ticket e scrum con
  la dev). La card delle statistiche sparisce sia dall'index dei Layer (`indexCards()`, card
  aggregata) sia dalla detail view (`detailCards()`, card del singolo layer), per Administrator e
  Validator: restano solo nella dashboard «Statistiche». La mappa con le persone in tempo reale
  invece resta nella detail view **e** compare nella dashboard.
  - Fonti: ticket (Davide, 24/11) «inseriamo lì la parte di statistiche»; scrum 25/11 (Peppe)
    «spostare tutta la logica di fatto statistica che abbiamo messo sui layer […] sia quando sono
    validatore […] dove dovrebbe in realtà stare nella dashboard», «taglia e incolla».
  - **Da chiarire con il cliente:** né il ticket né lo scrum dicono come l'Administrator vede le
    statistiche di **un singolo cammino**: dopo questo ticket ha solo l'aggregato di tutti i
    cammini nella dashboard. Stesso problema per un Validator con più layer: vede solo il primo
    (es. il Validator 1378 non vede più le statistiche di Via Peuceta e Via Lucana). Possibili
    risposte, se il cliente lo chiede: un filtro per cammino nella card globale, oppure la card
    del singolo layer rimessa nella detail view.

## Follow-up

- **Logo per il dark mode:** chiedere al cliente il logo in SVG, o una versione con la scritta
  chiara. Oggi la scritta grigio scuro del PNG si legge male sulla barra scura di Nova.
- **Dashboard «Statistiche» in `wm-package`:** da valutare solo se la chiede anche un altro
  progetto.

---

# Seconda parte — mappa globale dell'Administrator e posizioni aggiornate (Alessandro, 25/11)

Decisioni, divergenze e bug del codice del package: `wm-package/docs/features/9040-personalizzazione-laravel/notes.md`,
«Seconda parte».

## Divergenze dal piano, task per task

### Task C2 posizioni e percorsi di tutti i cammini

- I dati di test (EC track con geometria nota, risposta finta di PostHog) stanno nel trait
  `tests/Feature/Concerns/BuildsLiveMapData.php`, usato da tre classi di test. Il trait fa
  `Queue::fake()` prima di ogni test (`setUpBuildsLiveMapData()`): senza, creare una EC track
  fa partire il calcolo DEM, che chiama un servizio esterno e fallisce.

## Bug trovati

- **`app/Providers/NovaServiceProvider.php:114`**: il `canSee` del menu «Passport» chiama
  `$request->user()->hasAnyRole()` senza controllare che ci sia un utente. Quando una route Nova
  risponde con un'eccezione HTTP senza login, Nova disegna la pagina d'errore con il menu e la
  403 diventa un 500. Preesistente; gli endpoint nuovi lo evitano (vedi note del package). Da
  correggere in un ticket separato.

## Decisioni

- **Stima della seconda parte saltata** su richiesta di Alessandro (proposta: 7,25h, non scritta
  sul ticket).

## Follow-up

- Correggere `NovaServiceProvider.php:114` (vedi sopra).

## Cleanup dopo la review (26/11)

Il dettaglio è nel `notes.md` del package. Qui:

- la mappa del Validator prende il percorso da `/nova-vendor/layer-analytics/{layer}/routes`;
- «All routes» → «Tutti i cammini» (e fr, es, de) in `resources/lang/*.json`, come override della
  traduzione neutra del package;
- nuovo trait `tests/Feature/Concerns/CreatesUsersWithRole.php`: `userWithRole()` era copiato in
  `LiveMapEndpointsTest`, `LayerGlobalAnalyticsCardVisibilityTest` e `LayerFiltersSectionTest`;
- `BuildsLiveMapData::withoutPostHog()`;
- test nuovi:
  - `LiveMapEndpointsTest::test_guest_cannot_read_an_ownerless_layer`;
  - le route del layer in `test_validator_reads_only_own_layer_positions`;
  - `LayerMapCardTest`: `live_positions_url` null senza PostHog.
- Moduli toccati da riportare nell'overview alla chiusura:
  - `tests/Feature/Concerns/BuildsLiveMapData.php`
  - `tests/Feature/Concerns/CreatesUsersWithRole.php`
  - `tests/Feature/AllLayersLiveMapDataTest.php`
  - `tests/Feature/LiveMapEndpointsTest.php`
  - `tests/Feature/LayerFiltersSectionTest.php`
  - `resources/lang/{it,fr,es,de}.json`


## Terza parte — design system (26/11)

- **Grigi di Nova spostati di un gradino rispetto al piano.** Il piano dava `gray-400` =
  `secondario-400` #6E7B7E, molto più scuro del `gray-400` di Nova (148, 163, 184). Nel tema scuro
  Nova scrive in `gray-400` su `gray-800`/`gray-900`, e #6E7B7E su #1D282B si legge male. In
  `resources/css/nova/brand.css`:
  - 400 = #9AA4A6, ricavato;
  - 500…900 = `secondario-400`, `-500`, `-600`, `-700` e `secondario`;
  - 950 ricavato.
- **Font:** i TTF del design system, non convertiti in woff2, perché nel container non c'è un
  convertitore e i file pesano circa 60 KB l'uno. `font-display: swap`.
- **Tracce in arancio da `config/wm-package.php`** (override versionato, come le altre chiavi del
  file), non da `.env`.
- **Cambio di tema:** il grafico della card legge i colori quando viene disegnato. Passando da
  chiaro a scuro senza ricaricare la pagina, le barre restano con i colori del tema precedente
  fino al prossimo disegno (cambio di periodo o ricarica).
- **Colori della card: «scala dell'arancio»** (Alessandro, 26/11), scelta fra cinque prove
  nell'artifact «Prove colori Statistiche» (https://claude.ai/artifact/7PDenvjwbAiAW4NHdWBwTU).
  Il piano metteva iOS in `secondario`, ma sullo schermo l'ardesia dominava le altre serie.
  - **Serie, tema chiaro:** Android `primario-700`, iOS `primario`, Webapp `primario-300`.
  - **Serie, tema scuro:** Android `primario-600`, iOS `primario-400`, Webapp `primario-100`.
  - **Numeri:** `primario` e non `primario-700`. Sono testo grande, come i titoli del sito che il
    design system scrive in `primario`; il contrasto più basso è una scelta di Alessandro.
  - **Cammini più frequentati:** `primario`, `primario-400` sul tema scuro.
  - **Box dei numeri e fondo delle barre:** `fondo` #F4F6F8.
- **Knowledge in una pagina nuova**, `docs/knowledge/colori-e-caratteri-di-nova.md`, e non
  rinominando `logo-nel-pannello-nova.md` come diceva il piano: la pagina del logo è citata nei
  documenti chiusi di oc:9031, e rinominarla avrebbe rotto quei link.

## Quarta parte — logo del cammino, favicon, numero di utenti (27/11)

- **Legenda e numero di utenti solo in Cammini d'Italia** (Alessandro, 27/11): la `LayerMapCard`
  è nel package, e gli altri progetti che la usano non devono vederli. `withLiveLegend()` li
  accende insieme; la dashboard `Main` lo chiama per le due mappe. Di default la card mostra solo
  la mappa con i pallini. Una sola variabile per il frontend, `show_live_legend`, vera solo se lo
  shard la accende e PostHog è configurato (tolta `show_live_user_positions`, che diceva la stessa
  cosa).
- **Tutta la logica del live fuori dal componente della mappa** (idea di Alessandro, 27/11): per
  ora no. La `LayerMapCard` resta nel package; resta da valutare una mappa generica (un layer
  ricaricato ogni N secondi, legenda con campione a punto) con legenda e numero decisi dallo shard. La legenda della detail view del Layer (`DetailField.vue`) esisteva
  prima di oc:9040 e resta com'è.
- **Il numero sta su una riga a sé, sotto la legenda** (Alessandro, 27/11), non nella stessa riga.
- **`FeatureCollectionMap.vue` emette `live-positions-loaded`** con il numero di posizioni
  ricevute: due righe, nessun cambiamento a quello che la mappa disegna. Senza `livePositionsUrl`
  (detail view e altri Field) non parte.
- **Favicon: un test con `Nova::$jsonVariables = []`.** Nova calcola le variabili per il
  JavaScript una volta per processo e ci memorizza l'id dell'utente della prima richiesta. Nella
  suite, una richiesta da ospite dopo un test con utente costruisce il menu senza utente e
  `NovaServiceProvider.php:114` risponde 500 (il follow-up già segnato). In produzione ogni
  richiesta riparte da zero.
- **Package su un branch nuovo**, `feature/oc-9040-numero-utenti-legenda`, da `develop`: la PR #353
  è già mergiata.

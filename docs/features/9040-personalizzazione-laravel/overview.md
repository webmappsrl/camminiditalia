> Ticket: oc:9040

# Personalizzazione Laravel: logo definitivo e dashboard «Statistiche»

Feature **mista**: logo, dashboard e scoping in camminiditalia; card della mappa in `wm-package`
(vedi `wm-package/docs/features/9040-personalizzazione-laravel/overview.md`).

## Cosa cambia

1. **Logo del pannello Nova.** `resources/img/camminiditalia-logo.svg` (stesso nome, stesso
   `brand.logo` in `config/nova.php`) contiene il logo ufficiale inviato dal cliente
   (`logo___scritta_grigio.png`, 1080×328, disco + scritta «CAMMINI D'ITALIA» grigio scuro) al
   posto del logo di prova di oc:9031. Il nome «Cammini d'Italia» disegnato accanto al disco
   sparisce, perché è già nell'immagine.
2. **Dashboard `Main` → «Statistiche».** La dashboard `App\Nova\Dashboards\Main` si chiama
   «Statistiche», perde la card `Help` di Nova e diventa l'unico posto in cui si vedono le
   statistiche:
   - **Administrator:** la card aggregata di tutti i cammini, `LayerAnalyticsCard::global()`.
   - **Validator:** la card delle statistiche e la mappa con le persone in tempo reale del suo
     **primo** layer (`id` più basso).
3. **Statistiche tolte dalle pagine dei Layer.** La card delle statistiche non compare più né
   sopra l'index dei Layer (card aggregata) né nella detail view del singolo Layer (card del
   layer), per nessun ruolo. La mappa con le persone in tempo reale **resta** nella detail view.
4. **Mappa dell'Administrator con tutte le persone su tutti i cammini** (seconda parte, Alessandro,
   25/11). Nella dashboard l'Administrator vede, sotto la card aggregata, una mappa con i percorsi
   di tutti i cammini e le persone degli ultimi 30 minuti che si trovano a meno di 50 m da un
   cammino.
5. **Le mappe della dashboard si aggiornano da sole** (seconda parte). Sia la mappa
   dell'Administrator sia quella del Validator scaricano i percorsi una volta sola e riscaricano
   le sole posizioni ogni 90 s, mentre la dashboard resta aperta. Supera la decisione della prima
   parte (nessun aggiornamento automatico).

## Perché

Il cliente (Davide, oc:9040) ha mandato il logo definitivo e ha chiesto di rinominare «Main» in
«Statistiche» mettendoci «la parte di statistiche, inclusa la mappa in cui vedono le persone in
tempo reale». Allo scrum del 25/11 Peppe ha confermato le due parti nello stesso ticket: il logo
si sostituisce tenendo lo stesso nome di file; le statistiche «sui layer», sia l'aggregato
dell'Administrator sia quelle del Validator, si spostano con un «taglia e incolla» nella
dashboard, «dove dovrebbe in realtà stare». Nella review dell'overview (25/11) Alessandro ha
deciso: il Validator vede un solo layer, il primo; la mappa entra in questo ticket.

Alle 12:12 del 25/11 si era deciso che la mappa la vedessero solo i Validator, per il dubbio sul
costo di farla per tutta Italia; nella stessa call Peppe aveva però detto che vederla anche per
Cammini d'Italia, «se a noi non ci costa», era utile. Alle 14:47 la mappa completa è rimasta come
unica parte mancante. Il 25/11 Alessandro, che chiude il ticket, ha deciso di farla: i dati
misurati (sotto, in Rischi) mostrano che il costo è contenuto.

## Requisiti

### Logo
- [ ] `resources/img/camminiditalia-logo.svg` è un SVG che incorpora il PNG del cliente
      (`<image>` con data URI base64): Nova inserisce il file nella pagina con
      `file_get_contents()` (`vendor/laravel/nova/src/Nova.php:898-906`), quindi un PNG grezzo
      non funziona.
- [ ] Il nuovo SVG rispetta i vincoli di Nova documentati in
      `docs/knowledge/logo-nel-pannello-nova.md`: niente `id`, niente `<style>`, altezza 36 px
      nello `style` inline, larghezza nell'header ≤ 200 px (1080×328 a 36 px ≈ 119 px).
- [ ] Il nome «Cammini d'Italia» disegnato accanto al disco (classe `cdi-logo-name`) non c'è più.
- [ ] Il PNG originale del cliente è salvato in
      `docs/features/9040-personalizzazione-laravel/logo-cliente.png`; il commento in
      `config/nova.php:169-170` punta a quel file e non più a `build-logo.py`, e la pagina di
      conoscenza dice che `build-logo.py` non va più usato (rilanciarlo rimetterebbe il logo di
      prova di oc:9031).
- [ ] Dark mode del logo lasciato com'è (vedi Rischi).
- [ ] Prima del merge il logo è controllato nel browser, sulla pagina di login e nell'header.

### Dashboard «Statistiche»
- [ ] `name()` tradotto «Statistics»; `uriKey()` esplicito `main`, così l'URL
      `/nova/dashboards/main` (pagina di arrivo dopo il login) e
      `MenuSection::dashboard(Main::class)` non cambiano.
- [ ] La card `Help` di Nova non c'è più.
- [ ] **Administrator:** `LayerAnalyticsCard::global()` alle stesse condizioni che oggi valgono
      sull'index dei Layer (`wm-package/src/Nova/Layer.php:288-307`): esiste almeno un Layer e
      la sua App ha `analytics_app_enabled` o `analytics_webapp_enabled`.
- [ ] **Validator:** il «primo layer» è il layer con `user_id` = utente e `id` più basso
      (`orderBy('id')`). Se esiste:
      - `new LayerAnalyticsCard($layer)` se l'App del layer ha l'analytics acceso (stessa
        condizione di `detailCards()`, `wm-package/src/Nova/Layer.php:271-286`);
      - `LayerMapCard` del package (vedi overview in `wm-package`) su quel layer.
      Se il Validator non ha layer, la dashboard è vuota.
- [ ] Guest: non entra nella dashboard (403 dal gate di Nova, come già prima del ticket; vedi
      `notes.md`, Task 5).

### Pagine dei Layer
- [ ] `App\Nova\Layer::canSeeGlobalAnalyticsCard()` restituisce `false`: niente card sopra
      l'index dei Layer.
- [ ] `App\Nova\Layer::detailCards()` sovrascritto: chiama `parent::detailCards($request)` e
      toglie solo le istanze di `LayerAnalyticsCard`, così ogni altra card del package (oggi
      `LayerApiLinksCard`, domani eventuali card nuove) continua a comparire. Non si riscrive
      l'elenco delle card. `wm-package` non si tocca per questo.
- [ ] Il Field `FeatureCollectionMap` con i marker live nella detail view del Layer resta invariato.

### Mappa dell'Administrator (seconda parte)
- [ ] **Administrator:** dopo `LayerAnalyticsCard::global()`, la `LayerMapCard` del package in
      modalità globale (vedi overview in `wm-package`), se esiste almeno un Layer. Come per il
      Validator, la mappa c'è anche con l'analytics spento: mostra comunque i percorsi.
- [ ] La mappa globale disegna i percorsi di tutte le EC track associate ad almeno un layer,
      semplificati a 0.0005° (circa 50 m) e senza quota, e le persone degli ultimi 30 minuti a meno
      di 50 m da una di quelle tracce (stesso criterio della mappa del singolo layer), con il
      limite di 500 persone già usato per il layer.
- [ ] **Aggiornamento automatico** per entrambe le card della dashboard (Administrator e
      Validator): percorsi scaricati una volta, posizioni riscaricate ogni 90 s (la durata della
      cache delle posizioni), senza cambiare zoom e centro della mappa (la `View` di OpenLayers).
- [ ] La mappa del Validator smette di prendere le posizioni dal GeoJSON del layer e le prende
      dall'endpoint delle posizioni del suo layer: nessun pallino doppio.
- [ ] Il Field `FeatureCollectionMap` nella detail view del Layer resta invariato (polling ogni
      2 s compreso).
- [ ] Gli endpoint nuovi delle posizioni sono protetti: login obbligatorio; l'endpoint globale e
      quello dei percorsi di tutti i cammini solo per l'Administrator; quello di un layer per
      l'Administrator o per il Validator proprietario del layer; altrimenti 403. La route esistente
      `/nova-vendor/feature-collection-map/{model}/{id}` non si tocca (vedi Out of scope).

### Traduzioni e test
- [ ] Chiave «Statistics» in `resources/lang/{it,en,fr,es,de}.json` (default effettivo
      `APP_LOCALE=en`).
- [ ] `NovaBrandLogoTest`: tolti `test_logo_contains_the_name` e
      `test_logo_style_only_targets_the_name`; aggiunto un test che trova nell'SVG un `<image>`
      con `href` `data:image/png;base64,…`, lo decodifica e verifica con
      `getimagesizefromstring()` che sia un PNG 1080×328 (un logo malformato blocca anche la
      pagina di login).
- [ ] `LayerGlobalAnalyticsCardVisibilityTest` riscritto sulla dashboard: Administrator con card
      globale; Validator con card e mappa del layer con `id` più basso (e non degli altri);
      Validator senza layer e Guest con dashboard vuota; analytics spento; nessuna card delle
      statistiche su `/nova-api/layers/cards` né sulla detail view del Layer; nessuna `help`;
      nome e `uriKey`.
- [ ] Seconda parte: `LayerGlobalAnalyticsCardVisibilityTest` controlla anche la mappa globale
      dell'Administrator (presente, assente senza layer, assente per il Validator);
      `LayerMapCardTest` copre la modalità globale e gli URL delle posizioni; test nuovi sugli
      endpoint delle posizioni e dei percorsi: 401/403 senza login, Validator su un layer non suo
      e Validator sull'endpoint globale; 200 per l'Administrator e per il proprietario; persone
      lontane dai cammini escluse. Nel consumer, perché i test del package non si caricano da qui.

## Rischi

- **Dark mode del logo:** la scritta del PNG è grigio scuro e sulla barra scura si legge male.
  Accettato per ora; va chiesto al cliente il logo in SVG o una versione con la scritta chiara.
- **Condizione analytics duplicata:** la dashboard ripete il controllo «analytics acceso» che nel
  package sta in metodi `protected` della Resource Layer. Se il package lo cambia, la dashboard
  non segue da sola.
- **Statistiche del singolo cammino non più raggiungibili:** l'Administrator vede solo
  l'aggregato; il Validator con più layer vede solo il primo (il Validator 1378 perde Via Peuceta
  e Via Lucana). Conseguenza accettata, da chiarire con il cliente (vedi `notes.md`).
- **«Primo layer» = `id` più basso:** la data di assegnazione di un layer a un Validator non è
  registrata. Il criterio non viene dal cliente e può cambiare (vedi `notes.md`).
- **La route del GeoJSON della mappa risponde senza login:** verificato in locale (200 senza
  cookie, contro 401/403 delle altre API Nova). Esiste già oggi; la `LayerMapCard` ne diventa un
  secondo utilizzatore. Gravità da valutare con Alessandro, non blocca il merge: dettaglio in
  `notes.md`, «Bug trovati».
- **Logo in base64:** il PNG (35 KB) incorporato nell'SVG aggiunge circa 48 KB a ogni pagina HTML di
  Nova, login compresa, perché Nova inserisce il file nella pagina e non lo serve come asset
  separato. Accettato.
- **Peso dei percorsi di tutti i cammini** (misurato sul DB locale, 25/11, 1.287 EC track): 29 MB
  di GeoJSON senza semplificazione; semplificati a 0.0005° e senza quota 1,4 MB, 445 kB compressi
  con gzip; la query impiega 0,28 s. Mitigazione: cache lunga sul server, separata da quella di
  90 s delle posizioni, e percorsi scaricati una volta sola per apertura della dashboard. Che la
  risposta arrivi compressa dipende dal web server, non dal codice.
- **Filtro delle posizioni su tutti i cammini:** il controllo «a meno di 50 m da una traccia» passa
  da le tracce di un solo layer a tutte le tracce dei layer, fino a 500 punti. `ec_tracks` ha
  l'indice GiST su `geometry`; il tempo reale della query con PostHog vero non è misurabile in
  locale (PostHog non configurato).
- **Pagina di arrivo dopo il login:** la dashboard è la prima pagina di ogni Administrator e
  Validator; con l'aggiornamento ogni 90 s ogni tab del browser aperto fa una richiesta leggera (solo
  posizioni) ogni 90 s, servita dalla cache.
- **`Dashboard::cards()` non riceve la request:** la scelta per ruolo usa `request()->user()`; i
  test devono passare da una richiesta HTTP autenticata, non chiamare `cards()` a vuoto.

## Out of scope

- Logo con colori diversi per il dark mode.
- Un modo per vedere le statistiche di un singolo cammino dopo lo spostamento (filtro per
  cammino nella card globale o altro): da chiarire con il cliente.
- Spostare la dashboard «Statistiche» in `wm-package` per gli altri progetti.
- Protezione della route esistente `/nova-vendor/feature-collection-map/{model}/{id}` (risponde
  senza login e non controlla il record): da un ticket separato in `wm-package`, perché la usano
  anche gli altri progetti; da segnalare nelle note dev.
- Percorsi della mappa globale presi dai tile pbf dell'App: `FeatureCollectionMap.vue` oggi legge
  solo GeoJSON; da valutare se i cammini crescono molto.

## Moduli toccati

**camminiditalia**
- `resources/img/camminiditalia-logo.svg` — sostituito
- `docs/features/9040-personalizzazione-laravel/logo-cliente.png` — PNG originale del cliente
- `config/nova.php` — solo il commento sopra `brand.logo`
- `app/Nova/Dashboards/Main.php` — nome, `uriKey`, card per ruolo; seconda parte: mappa globale
  per l'Administrator
- `app/Nova/Layer.php` — `canSeeGlobalAnalyticsCard()` → `false`, override di `detailCards()`
- `resources/lang/{it,en,fr,es,de}.json` — chiave «Statistics»
- `tests/Feature/NovaBrandLogoTest.php`, `tests/Feature/LayerGlobalAnalyticsCardVisibilityTest.php`
- `tests/Feature/LayerMapCardTest.php` — test della `LayerMapCard` del package (i test del package
  non si caricano dal consumer: vedi `notes.md` del package); seconda parte: modalità globale
- `tests/Feature/` — seconda parte: test nuovo sugli endpoint delle posizioni e dei percorsi
  (autorizzazione, filtro a 50 m)
- gitlink `wm-package`
- `docs/knowledge/logo-nel-pannello-nova.md` — riscritta a fine lavoro: la regola «non togliere il
  nome accanto al disco» (oc:9031) passa in «Come ci siamo arrivati»
- `docs/knowledge/` — pagina nuova sulla dashboard «Statistiche»
- `CLAUDE.md` — **non si tocca** (la dev non può modificarlo): la riga di oc:8182, che indica
  `canSeeGlobalAnalyticsCard()` come il punto in cui decidere chi vede la card, diventa falsa; va
  segnalato nelle note dev del ticket

**wm-package** — vedi la sua overview.

---

# Terza parte — interfaccia di Nova con i colori e i caratteri del design system (Alessandro, 26/11)

Richiesta di Alessandro del 26/11, dentro oc:9040: rendere l'interfaccia di Nova compatibile con il design
system di Cammini d'Italia (artifact «Design System», `project/README.md` e `project/tokens.json`).

## Cosa cambia

- **Colore principale di Nova = `primario` #F07821**, con la sua scala: `config/nova.php` →
  `brand.colors` passa dalle 3 tonalità verde acqua di oggi (`24, 182, 155`) a tutte quelle che
  Nova usa (`--colors-primary-50…950`, `vendor/laravel/nova/src/Nova.php:1217`). Valori del design
  system per 100, 300, 400, 500, 600, 700; 50, 200, 800, 900, 950 ricavati da noi fra quelli
  vicini.
- **Grigi di Nova = scala del `secondario`** (#1D282B…), al posto del grigio-blu di Tailwind:
  - fondo della pagina `fondo` #F4F6F8;
  - bordi `secondario-100` e `secondario-200`;
  - testo minore `secondario-400` e `secondario-500`;
  - fondi scuri `secondario` e `secondario-700`.
  Le tonalità che il design system non ha sono ricavate da noi.
- **Caratteri:** DM Sans per il testo, Instrument Sans per i titoli, al posto di Nunito Sans.
  Sono i file del design system (TTF, licenza OFL) serviti da `public/fonts/`, senza chiamare
  Google Fonts.
- **Tema scuro tenuto**, con l'arancio e la scala del `secondario`: il design system non ha un
  tema scuro, i valori scuri li scegliamo noi.
- **Card delle statistiche** con i colori del brand, tramite le variabili CSS che il package
  aggiunge (vedi l'overview del package):
  - i numeri grandi in `primario-700` #BB4613, perché il design system lo indica per scrivere in
    arancio; sul tema scuro `primario`;
  - le tre serie del grafico (Android, iOS, Webapp) con le tre serie del design system:
    `primario`, `secondario` (chiaro-su-scuro sul tema scuro), `primario-300`.
- **Tracce sulle mappe in `primario`** quando il layer non ha un colore suo: la nuova config del
  package impostata a #F07821 in `config/wm-package.php`, versionata.

## Perché

- Nova oggi ha il verde acqua di Nova e i colori Tailwind della card, che non sono del
  brand (screenshot di Alessandro del 26/11).
- Decisioni di Alessandro del 26/11:
  - primario #F07821 come nel design system, anche se il bianco sui pulsanti sta a 2,6:1;
  - tema scuro tenuto;
  - tracce in arancio, con il colore configurabile;
  - nel package i colori restano quelli di oggi, modificabili tramite variabili.

## Requisiti

- Pulsanti, link, `MenuItem` attivo e focus di Nova in arancio #F07821, nei due temi.
- Grigi, fondo e caratteri come sopra, nei due temi.
- Card delle statistiche con i colori del brand nei due temi; l'export PNG con gli stessi colori.
- Tracce in #F07821 su detail view del Layer, card del Validator e mappa di tutti i cammini,
  tranne i layer con `properties.color`.
- Nessun cambiamento a mappe e card degli altri progetti che usano il package.

## Rischi

- **Gli override sui grigi e sui caratteri toccano variabili e classi di Nova**: a ogni
  aggiornamento di Nova vanno ricontrollati.
- **Contrasto**: bianco su #F07821 a 2,6:1 e link arancio su bianco a 2,8:1, sotto AA. Scelta
  consapevole di Alessandro.
- **Il logo** ha la scritta grigio scuro e sul tema scuro si legge poco: resta la richiesta al
  cliente di una versione chiara o SVG.
- **I pallini verdi delle persone** restano verdi: non sono nel perimetro.

## Out of scope

- Colori delle mappe diversi dal colore di default delle tracce (pallini, POI, zone).
- Il colore di una traccia preso dalla EcTrack (`TrackColor`) invece che dal layer.
- Componenti Nova del package diversi dalla card delle statistiche.

## Moduli toccati

- `config/nova.php` (`brand.colors`)
- `config/wm-package.php` (colore di default delle tracce)
- `app/Providers/NovaServiceProvider.php` (`Nova::style`)
- `resources/css/nova/brand.css` (nuovo)
- `public/fonts/` (nuova)
- gitlink `wm-package`
- test in `tests/Feature/`

# Diario del camminatore: modello, API e protezione delle foto

## Ciclo 4 — Date di percorrenza e collegamento (oc:8169, 4 novembre 2026)

Il resto della pagina descrive i cicli precedenti; per il collegamento delle registrazioni e le date
note vale questa sezione.

- **Solo l'ultima percorrenza.** `passport_stage_ugc_track` contiene tutte le registrazioni
  dell'utente su una tappa, di qualsiasi anno (`StageValidationService::syncUgcTracks`): le date note
  (`walked_dates`) e i km di una tappa vengono solo da quelle dell'ultima percorrenza
  (`App\Support\WalkPeriods::latest`, giorni a più di 30 giorni di distanza = percorrenze diverse).
  Vale per la scheda del diario e per il collegamento.
- **Date del diario** `walk_start_date`/`walk_end_date` (fine vuota = un giorno), validate con «oggi»
  nel fuso di Roma: l'applicazione è in UTC, e fra mezzanotte e le 2 italiane «oggi» sarebbe ieri.
- **Collegamento delle registrazioni** (`DiaryUgcLinkService`): periodo = date scritte, altrimenti
  ultima percorrenza delle tappe candidate, ±1 giorno, senza il limite della creazione del diario;
  senza nessuna data, i 30 giorni prima dell'ultima registrazione. Il giorno di una registrazione è
  uno solo per Passaporto e diari (`App\Support\UgcRecordingDate`: primo punto, poi `createdAt`,
  poi la riga, nel fuso di Roma).

## Ciclo 3 — Racconto in HTML (oc:8169, 4 novembre 2026)

Il resto della pagina descrive i cicli precedenti; dove questo ciclo cambia il formato del racconto (prima testo semplice, ora HTML) vale questa sezione.

- **`description` è sempre HTML ripulito** da `DiaryTextService`: HTMLPurifier di wm-package
  (`HasEmbeddableRichText::buildRichTextPurifier()`, nessun iframe) con un elenco chiuso di tag; i
  collegamenti relativi o con schemi diversi da http/https/mailto perdono il tag (HTMLPurifier filtra
  solo gli schemi); un testo che non inizia con `<` è testo semplice e diventa paragrafi.
- **La pulizia avviene in `prepareForValidation()`** delle due richieste, così i limiti valgono
  sull'HTML che si salva: 20.000 caratteri di testo visibile (spazi non separabili esclusi) e 100.000
  di HTML. Un valore non testuale o più lungo di 100.000 non si ripulisce: lo rifiutano `string` e
  `max`, senza far girare il purifier su diversi MB.
- **Nova** ripulisce di nuovo il racconto quando lo mostra (`asHtml` non si fida del dato salvato),
  mostra un estratto nell'elenco e cerca con `regexp_replace` sul testo senza tag (solo PostgreSQL;
  le entità non si decodificano).
- **Nel dettaglio Nova il racconto ha uno stile suo** (`resources/css/nova/diary-rich-text.css`,
  caricato da `NovaServiceProvider`): il reset CSS di Nova toglie a titoli, elenchi e citazioni ogni
  stile, e senza il foglio il racconto formattato arriva come righe tutte uguali. Il testo sta in un
  riquadro, così si vede dove inizia e finisce quello scritto dall'utente; sotto, «Copia testo» e
  «Copia HTML» (`resources/js/nova/diary-copy.js`, un listener delegato perché il campo è reso con
  `v-html`). Il testo semplice lo costruisce lo script, non `innerText`, che perderebbe i segni degli
  elenchi: «•» e numeri sulle voci, «>» sulle citazioni, l'indirizzo dei link fra parentesi.
  L'HTML copiato è quello già ripulito dal server.
- Nessuna migration di conversione: la funzione non è ancora né in produzione né sul dev.

## Ciclo 2 — «Il mio diario» (oc:8169, 3 novembre 2026)

Il modello si chiama `Diary` (tabelle `diaries` e `diary_ugc_track`, API `/api/diaries`, limiter
`diaries`, config `camminiditalia.diaries.*`, variabili `CAMMINIDITALIA_DIARIES_ENABLED` e
`CAMMINIDITALIA_DIARY_LINK_DAYS`). Il resto della pagina descrive il primo ciclo con la parola
«esperienza»; dove il secondo ciclo l'ha cambiato vale questa sezione.

- **Consenso alla ripubblicazione** in `publication_consent` (default `false`), distinto da
  `is_public`: `is_public` riguarda la lettura da parte degli altri utenti, il consenso i canali di
  Cammini d'Italia. Si invia in creazione e in modifica (`null` = invariato) e torna nell'API. In Nova
  è in sola lettura, con il filtro `DiaryPublicationConsentFilter`.
- **Solo l'Administrator vede i diari**, con o senza consenso (`viewAny`, `scopeVisibleTo`,
  `isVisibleTo`): il cliente non vuole che li vedano i gestori dei cammini (call del 3 novembre 2026).
  Prima il Validator vedeva quelli pubblici dei propri cammini.

### Aggiunte del 4 novembre 2026

- **Valutazioni** `rating_route`, `rating_hospitality`, `rating_difficulty` (interi da 1 a 5,
  `null` = non date), nella migration di creazione della tabella. In creazione e in modifica
  (`Diary::RATINGS`, `safe()->only`): una chiave assente non cambia il valore, una vuota lo azzera.
  In Nova in sola lettura, nascoste dall'elenco.
- **Progresso del cammino** (`StageProgressService`, `GET /api/layer/{layer}/progress`): per tappa
  `user_distance` e `walked_dates`, per il cammino `user_distance_total`.
  - **Km dell'utente**: `properties.stats.distance` della registrazione (la regola di wm-package),
    altrimenti la lunghezza della geometria. Una tappa ha `user_distance` solo se nessuna delle sue
    registrazioni è collegata anche ad altre tappe; il totale somma ogni registrazione una volta
    sola, più la lunghezza delle tappe validate senza registrazione, `null` se nessuna tappa è
    validata.
  - **Giorni**: per una registrazione il giorno del primo punto, poi `createdAt`, poi `created_at`,
    nel fuso di Roma (una registrazione che passa la mezzanotte conta un giorno solo). Per una tappa
    cartacea tutti i giorni da `start_date` a `end_date` della richiesta che l'ha validata, **mai
    `validated_at`**, che è la data di approvazione del gestore.

## Come funziona oggi

Un'esperienza è un testo e fino a 6 foto scritti da un utente su un cammino o su una sua tappa.
È un modello proprio di camminiditalia, `Experience` (tabella `experiences`), e non un `UgcPoi`:
una tabella dedicata permette di aprire la lettura pubblica senza toccare la visibilità dei dati
privati degli UGC, e tiene la funzione fuori da `wm-package`. Il frontend è in
`wm-core/docs/knowledge/8169-esperienze-utente-cammino-foto-descrizione.md`.

- **API** (`routes/api.php`, `auth:api`): elenco delle proprie esperienze, creazione, modifica,
  eliminazione, tutte solo per l'autore. `is_public` esiste ma resta `false`: la lettura pubblica
  non c'è.
- **Interruttore `CAMMINIDITALIA_EXPERIENCES_ENABLED`**: a `false` tutte le route rispondono 404,
  e l'app lo legge come «esperienze non attive». Il 404 deve arrivare **prima** dell'autenticazione,
  cioè anche a chi non ha il token. Laravel ordina i middleware per priorità, non per come sono
  scritti sulla route: senza `prependToPriorityList` in `bootstrap/app.php`, `auth:api` passerebbe
  davanti a `EnsureExperiencesEnabled` e si otterrebbe 401. Chi aggiunge un altro interruttore dello
  stesso tipo deve fare la stessa cosa.
- **Limiter con nome `experiences`** (`AppServiceProvider`): creazione e modifica, 10 al minuto per
  utente. Il perché è nella trappola `.claude/rules/rate-limiter.md`.
- **Legame con le registrazioni** (`ExperienceUgcLinkService`, job `LinkExperienceUgcTracksJob`,
  accodato dopo il commit): si calcola alla creazione e a ogni modifica, non c'è un comando di
  ricalcolo.
  - Candidata è solo la registrazione che ha **percorso** la tappa secondo il criterio del
    passaporto (oc:8165, `coverage_threshold`), valutato sulla **singola registrazione**: due uscite
    parziali, nessuna oltre soglia, non legano nessuna delle due. Per riusare il criterio
    `StageValidationService` è diviso in `isWalked`, `matchedSegments`, `progressSql` e
    `stagesWalkedByTrack`; il passaporto non cambia.
  - Finestra di `camminiditalia.experiences.ugc_link_window_days` giorni (default 30), **ancorata
    all'ultima registrazione candidata precedente all'esperienza**, non alla data dell'esperienza:
    chi scrive ad agosto della Francigena fatta a giugno ottiene le tracce di giugno.
  - La data della registrazione è `properties->createdAt`; `created_at` della riga è l'arrivo sul
    server (spesso posteriore, per la sincronizzazione differita) e fa solo da ripiego.
  - Se l'esperienza è scritta prima che la registrazione arrivi sul server, il legame non si crea
    finché l'esperienza non viene modificata. Rischio accettato dal dev. Non ci si aggancia agli
    eventi di `wm-package`: `MatchUgcTrackToEcTracksJob` non ne emette.
- **Foto**: collection di spatie medialibrary. Le regole usano `mimes:jpeg,jpg,png,webp` e non
  `image`, che accetta anche SVG. L'accessor `app_id` di `Experience` ricava l'app dal cammino: il
  `MediaObserver` di `wm-package` lo legge per assegnare `app_id` ai media e altrimenti ripiega
  su 1. È una dipendenza da un dettaglio interno del package: se l'observer cambia, i media delle
  esperienze finiscono sull'app sbagliata senza errori.
- **Nova** (`app/Nova/Experience.php`, sola lettura): l'Administrator vede tutto; il Validator solo
  le esperienze pubbliche dei propri cammini, cioè oggi nessuna. Per questo la voce di menu
  (`NovaServiceProvider`) è nascosta a chi non è Administrator: da riaccendere quando le esperienze
  potranno essere pubbliche. `app/Nova/Media.php` toglie le foto delle esperienze dalle query di
  Nova a chi non è Administrator, perché la `MediaPolicy` del package dà al Validator tutti i media.

## `ProtectExperienceMedia`: difetti di wm-package coperti

Le API dei media di `wm-package` cercano il media per id senza guardare a chi appartiene.
`ProtectExperienceMedia` (gruppo `api`, `bootstrap/app.php`) per le sole foto delle esperienze
permette la lettura all'autore, o a tutti se l'esperienza è pubblica, e la cancellazione solo
all'autore; altrimenti 404. Gli altri media passano invariati.

- **`Controller::validateUser()` non interrompe la richiesta**: restituisce un 403 che
  `MediaController::destroy()` ignora. Prima del middleware, qualsiasi utente loggato cancellava le
  foto di un altro con `GET /api/ugc/media/delete/{id}`. Vale per tutti i media UGC, non solo per
  le esperienze.
- **Le letture (`api/media/{id}`, `api/ec/media/{id}`) rispondono 500** per qualsiasi media, quindi
  oggi non espongono nulla: la protezione di lettura scatta il giorno in cui vengono sistemate.
- Quando le esperienze diventeranno pubbliche basta `is_public = true`.

## Limiti noti, accettati dal dev

- **Un Validator che conosce l'id** può ancora modificare o cancellare una foto con l'API di Nova:
  il filtro sta nelle query, non nella policy. Considerato ipotetico finché non c'è la voce di menu
  e le esperienze sono private.
- **Gli URL delle foto sono permanenti** (`getUrl()`, S3 diretto): chi lo conosce apre la foto anche
  se l'esperienza è privata. Per una privacy vera servono URL firmati a scadenza, che toccano anche la
  cache offline dell'app. Le UGC oggi funzionano allo stesso modo.
- **`client_max_body_size` di nginx**, fuori dal repo, è il limite reale dell'upload: da verificare
  prima del rilascio (`docker/configs/phpfpm/www.conf` impone 1024M con `php_admin_value`, che
  prevale su `php.ini`).

## Per «Di tutti» (lettura pubblica)

L'elenco ha già i filtri `layer_id`, `ec_track_id`, `ugc_track_id`; servono la paginazione e un
campo `author: {display_name, avatar_url, is_me}`, che non rompe l'app. Da correggere in `wm-package`:
`validateUser()` deve fare `abort(403)`.

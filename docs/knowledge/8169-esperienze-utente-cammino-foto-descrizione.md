# Esperienze utente: modello, API e protezione delle foto

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

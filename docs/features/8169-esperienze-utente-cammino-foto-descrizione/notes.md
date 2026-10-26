> Ticket: oc:8169

# Esperienze utente — note di implementazione (backend)

Gemella frontend: `wm-core/docs/features/8169-esperienze-utente-cammino-foto-descrizione/notes.md`.

## Deviazioni dal piano

### Legame con le registrazioni: criterio del passaporto

Il piano legava le registrazioni presenti in `ugc_track_ec_track_matches` con una tappa candidata.
Una corrispondenza qualsiasi però basta a legare una registrazione che sfiora la tappa accanto per
un segmento. Su indicazione del dev il legame usa lo **stesso criterio del passaporto** (oc:8165):
la registrazione deve aver percorso la tappa oltre `camminiditalia.passport.coverage_threshold`.
La regola vale per la singola registrazione e non unisce più uscite: una tappa percorsa in due
uscite parziali, nessuna oltre soglia, non lega nessuna delle due.

Per riusare il criterio, `StageValidationService` è stato spezzato in `isWalked`, `matchedSegments`,
`progressSql` e `stagesWalkedByTrack`. Il comportamento del passaporto è invariato: lo verificano
`StageValidationServiceTest` e le suite del passaporto e delle certificazioni.

La data della registrazione è `properties->createdAt`. `created_at` della riga è l'arrivo sul server,
spesso posteriore per la sincronizzazione differita, e resta solo come ripiego.

Il legame si calcola alla creazione e a ogni modifica (`LinkExperienceUgcTracksJob`); non c'è un
comando di ricalcolo. Un'esperienza scritta prima che la registrazione arrivi sul server non la
lega finché non viene modificata: rischio accettato dal dev.

### Foto

- Regole `mimes:jpeg,jpg,png,webp` e `dimensions` al posto di `image`, che accetta anche SVG.
- `php.ini` non è stato toccato: `docker/configs/phpfpm/www.conf` impone 1024M con
  `php_admin_value`, che prevale su `php.ini`.
- Accessor `app_id` sul modello, ricavato dal cammino: il `MediaObserver` di wm-package lo legge per
  assegnare `app_id` ai media e altrimenti ripiega su 1. È una dipendenza da un dettaglio interno di
  wm-package.

### Aggiunte non previste dal piano

- **`ProtectExperienceMedia`** (review del 20/10): le API dei media di wm-package cercano qualsiasi
  media per id senza guardare a chi appartiene. Per le foto di un'esperienza il middleware permette
  la lettura all'autore, o a tutti se l'esperienza è pubblica, e la cancellazione solo all'autore;
  altrimenti 404. Prima, qualsiasi utente loggato poteva cancellare le foto di un altro con
  `GET /api/ugc/media/delete/{id}`, perché `Controller::validateUser()` restituisce un 403 che
  `MediaController::destroy()` ignora. Le API di lettura (`api/media/{id}`, `api/ec/media/{id}`)
  oggi rispondono 500 per qualsiasi media, quindi non espongono nulla; la protezione scatta il giorno
  in cui vengono sistemate. Quando le esperienze diventeranno pubbliche basta `is_public = true`.
- **Limite di richieste `experiences`**, con chiave propria: con `throttle:10,1` creazione e modifica
  consumerebbero le 10 richieste al minuto di certificazione e condivisione della tappa (lo stesso
  motivo di `passport-route-share`, oc:8703).
- **Reinvio con lo stesso `uuid`**: aggiorna anche il testo, così la correzione fatta dopo una
  risposta persa non va perduta.
- **Errore nel salvare le foto**: qualunque errore, non solo di validazione, elimina l'esperienza
  appena creata, che altrimenti resterebbe vuota.
- **Nova**: la voce «Esperienze» la vede solo l'Administrator. Il Validator vedrebbe solo le
  esperienze pubbliche dei propri cammini, cioè nessuna. Da riaccendere quando potranno essere
  pubbliche.

## Limiti noti, accettati

- **Foto modificabili dal Validator via Nova.** La `MediaPolicy` di wm-package dà al Validator
  accesso a tutti i media. `app/Nova/Media.php` toglie le foto delle esperienze dalle query di Nova
  per chi non è Administrator, ma un Validator che conosce l'id potrebbe ancora modificarle o
  cancellarle con l'API di Nova. Il dev lo considera ipotetico: il Validator non ha la voce
  Esperienze, e le esperienze potranno diventare pubbliche.
- **URL delle foto permanenti.** L'API restituisce l'URL S3 diretto (`getUrl()`): chi lo conosce
  apre la foto anche se l'esperienza è privata. Per una privacy vera servirebbero URL firmati a
  scadenza, che toccano anche la cache offline dell'app. Oggi le UGC funzionano allo stesso modo.
- **nginx in produzione.** Il limite reale dell'upload è `client_max_body_size`, fuori dal repo. Con
  le foto ridimensionate dall'app a 1600 px, 6 foto stanno in pochi MB: da verificare prima del
  rilascio.

## Lasciato com'è dalla review del 20/10 (non bloccante)

- Regole di validazione duplicate fra `StoreExperienceRequest` e `UpdateExperienceRequest`.
- Proprietà dell'esperienza verificata con `abort_unless` in request e controller invece che con
  la policy (`ExperiencePolicy` copre solo Nova).
- `thumbnail` uguale a `url` nella risposta.

## Follow-up

- **wm-package**: `Controller::validateUser()` deve interrompere la richiesta (`abort(403)`) invece
  di restituire una response che nessuno usa; vale per tutti i media UGC. Lì anche la `MediaPolicy`
  che dà al Validator tutti i media.
- **oc:8165 (Giuseppe)**: in `syncUgcTracks()` il caso dell'attach di un solo segmento, emerso
  lavorando al legame.
- **Esperienze pubbliche**: la colonna `is_public` c'è già. L'API può aggiungere
  `author: {display_name, avatar_url, is_me}` senza rompere l'app, e i filtri `layer_id`,
  `ec_track_id`, `ugc_track_id` dell'elenco servono per «Di tutti», con la paginazione.

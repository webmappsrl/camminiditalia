> Ticket: oc:8169

# Esperienze utente su cammino con foto e descrizione — backend

> Overview gemella (frontend): `wm-core/docs/features/8169-esperienze-utente-cammino-foto-descrizione/overview.md`.
> Sostituisce l'overview del branch `feature/oc-8169-esperienze-utente-cammino-foto-descrizione`
> (esperienza come `UgcPoi` con form `feedback`), superata allo scrum del 19/10.

## Cosa cambia

Nasce un modello nuovo `Experience`, con tabella `experiences`, custom di camminiditalia: un testo e
fino a 6 foto scritti da un utente su un cammino o su una sua tappa, modificabili ed eliminabili
dall'autore. Lo stile segue `ValidatedEcTrack` e `CertificationRequest` del Passaporto.

- **Colonne**: `id`, `uuid` (generato dall'app), `user_id` (FK), `layer_id` (FK obbligatoria),
  `ec_track_id` (FK facoltativa, azzerata se la tappa viene cancellata), `description` (testo,
  facoltativo), `is_public` (booleano, default `false`), timestamp. Vincolo unico su
  `(user_id, uuid)`.
- **Foto**: spatie medialibrary, collection propria, al massimo 6, ciascuna al massimo 5 MB,
  raddrizzate secondo l'orientamento EXIF e poi ripulite dei metadati.
- **Legame con le registrazioni**: tabella ponte `experience_ugc_track` (`experience_id`,
  `ugc_track_id`), molti-a-molti, facoltativa. Si legano le `ugc_tracks` dell'utente che in
  `ugc_track_ec_track_matches` (oc:8773) corrispondono alla tappa dell'esperienza, o a una tappa del
  suo cammino. Candidata è solo la registrazione che quella tappa l'ha **percorsa** secondo il
  criterio del passaporto (oc:8165, `coverage_threshold`), valutato sulla singola registrazione:
  sfiorare la tappa accanto per un segmento non basta. La finestra è **ancorata all'ultima registrazione corrispondente precedente
  all'esperienza** e va indietro di un numero di giorni configurabile (default 30): chi scrive ad
  agosto della Francigena fatta a giugno ottiene il legame con le tracce di giugno. Il legame si
  calcola alla creazione e a ogni modifica, in un job accodato dopo il commit.
- **API** (`routes/api.php`, `auth:api`): elenco delle esperienze dell'utente (filtrabile per
  cammino, tappa e UGC track), creazione multipart (testo + `images[]`), modifica (testo, foto
  nuove, id delle foto da tenere), eliminazione. Ogni operazione è permessa solo all'autore. Le
  route sono raggruppate, così si possono spegnere in un punto solo: l'app tratta il 404 come
  «esperienze non attive».
  - Interruttore `CAMMINIDITALIA_EXPERIENCES_ENABLED` (default `true`): a `false` tutte le route
    `api/experiences` rispondono 404, prima dell'autenticazione (quindi 404 e non 401).
  - Creazione e modifica: al più 10 richieste al minuto per utente (`throttle:10,1`, 429 oltre);
    id non numerico nell'URL di modifica ed eliminazione: 404.
- **Nova**: risorsa `Experience` in sola lettura. L'Administrator vede tutto e può eliminare; il
  Validator vede solo le esperienze con `is_public = true` dei cammini di cui è proprietario
  (`layer.user_id`), quindi nessuna finché la lettura pubblica non esiste.
- **Pulizia**: alla cancellazione di un utente o di un cammino le esperienze si cancellano via
  Eloquent nell'evento `deleting`, sullo schema di `CertificationRequestCleanupObserver`, così
  spatie rimuove anche i file. La cascata del DB da sola li lascerebbe su S3.

## Perché

Il cliente vuole raccogliere i racconti dei camminatori come patrimonio della community, con una
lettura pubblica futura. Una tabella dedicata permette di aprirla senza toccare la visibilità dei
dati privati degli UGC, non richiede una geometria senza significato, e tiene la funzione fuori da
wm-package, perché è una personalizzazione di camminiditalia (scrum del 19/10).

## Requisiti

- [ ] Migration `experiences` con le colonne sopra, `is_public` default `false`, vincolo unico su
  `(user_id, uuid)`.
- [ ] Migration `experience_ugc_track` con chiavi esterne e cancellazione a cascata su entrambi i lati.
- [ ] Modello `Experience` con `HasMedia`, relazioni `user`, `layer`, `ecTrack`, `ugcTracks`, e
  scope `visibleTo` sullo schema di `CertificationRequest`.
- [ ] Observer di pulizia su `User` e `Layer` (`deleting`) che cancella le esperienze via Eloquent.
- [ ] Creazione: se arriva `ec_track_id` senza `layer_id`, il cammino si ricava da `layerables`;
  per una tappa in più cammini vale una regola esplicita e documentata.
- [ ] Creazione e modifica rifiutate se testo (dopo trim) e foto risultanti sono entrambi vuoti;
  `ec_track_id` deve appartenere a `layer_id`.
- [ ] Nuovo invio con lo stesso `uuid` dello stesso utente: aggiorna l'esperienza esistente e
  aggiunge le foto mancanti (doppioni scartati con l'hash, come per gli UGC), invece di crearne una
  seconda. La ricerca dell'`uuid` è limitata all'utente.
- [ ] Modifica: il campo delle foto da tenere è obbligatorio, anche vuoto, così «togli tutte» non si
  confonde con «campo omesso»; le foto non elencate si rimuovono; il totale resta entro 6.
- [ ] Foto: al massimo 6, al massimo 5 MB ciascuna, orientamento applicato, EXIF rimosso.
- [ ] Legame con le registrazioni alla creazione e alla modifica, in un job `afterCommit`, con la
  finestra in configurazione (default 30 giorni) ancorata all'ultima registrazione corrispondente.
- [ ] Elenco filtrabile per UGC track, per il dettaglio della registrazione nell'app.
- [ ] Risorsa Nova in sola lettura con la regola di visibilità sopra; nessuna creazione o modifica
  da Nova.
- [ ] Limiti dell'upload verificati su 6 foto: `post_max_size` e `upload_max_filesize` di PHP
  (oggi `php.ini` ha 50M e `www.conf` 1024M, da allineare) e `client_max_body_size` di nginx.
- [ ] Test sui casi: creazione da cammino e da tappa, `uuid` ripetuto, visibilità per ruolo,
  modifica con elenco vuoto, legame entro e fuori dalla finestra, cancellazione di utente e cammino
  con pulizia dei media.

## Rischi

- **Legame incompleto, accettato.** Se l'utente scrive l'esperienza prima che la registrazione sia
  sincronizzata e abbinata, il legame con quella registrazione non si crea (lo recupera solo una
  modifica successiva). Non si aggancia agli eventi di wm-package: `MatchUgcTrackToEcTracksJob` non
  ne emette, e il ricalcolo lato tappa (`matchEcTrack`) non aggiorna `ugc_track_matching_runs`.
- **Tappe in più cammini** (3 su 1282 nel DB locale): una tappa aperta senza cammino porta a un
  `layer_id` scelto da una regola, che va documentata.
- **I gestori non vedono le esperienze**, al contrario di quanto scritto nella richiesta del
  cliente: è una scelta di privacy, da spiegare nella risposta al cliente.
- **Cancellare un cammino cancella le esperienze** scritte su di esso, come succede per le richieste
  di certificazione.

## Out of scope

- Lettura pubblica e interruttore «condividi» (la colonna `is_public` c'è, ma resta `false`).
- Moderazione da parte dei gestori.
- Flag di attivazione per cammino: le esperienze sono attive su tutta l'istanza.
- Ricalcolo periodico del legame con le registrazioni.
- Upload diretto su S3 con URL firmati.
- Rilascio: PR su `develop`.

## Moduli toccati

- `database/migrations/` — `experiences`, `experience_ugc_track`
- `app/Models/Experience.php`
- controller e request delle esperienze, `routes/api.php`
- `app/Nova/Experience.php`, `app/Policies/ExperiencePolicy.php`
- `app/Observers/` — pulizia su utente e cammino
- job e servizio del legame con le registrazioni
- `config/` — finestra del legame e interruttore
- `app/Http/Middleware/` — `EnsureExperiencesEnabled` (interruttore) e `ProtectExperienceMedia`
  (foto delle esperienze sulle API dei media di wm-package), registrati in `bootstrap/app.php`
- `app/Services/StageValidationService.php` — criterio del passaporto estratto per riusarlo nel legame
- `app/Nova/Media.php` — foto delle esperienze nascoste a chi non è Administrator
- `app/Providers/AppServiceProvider.php` (policy, observer, limiter `experiences`) e
  `NovaServiceProvider.php` (voce di menu)
- `resources/lang/it.json`
- `tests/`

Aggiornati dopo l'implementazione: le deviazioni sono in [notes.md](notes.md).

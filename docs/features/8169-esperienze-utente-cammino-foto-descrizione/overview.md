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


## Ciclo 2 — «Il mio diario» (3 novembre 2026)

> Fonti: richiesta del cliente del 2 novembre 2026 nel ticket; call con Cammini d'Italia del 3 novembre
> (tag Orchestrator 716, «[CALL][CAMMINIDITALIA][2026] Chiarimenti sul diario del cammino»); scrum del 3
> novembre ore 11:37. Scadenza: 4 novembre 2026 ore 12, un giorno di sviluppo.

### Cosa cambia

1. **Rinomino in «diario», ovunque.** I testi visibili passano da «esperienza» a «diario» (sezione «Il mio
   diario», «Racconta il tuo cammino», «Racconta questa tappa», «Nuova pagina di diario», …) in tutte e 7 le
   lingue. Anche il codice si rinomina: `Experience` → `Diary` nel backend (modello, tabelle `diaries` e
   `diary_ugc_track`, rotte `/api/diaries`, policy, richieste, controller, job, servizio di collegamento delle
   tracce, risorsa Nova, config `camminiditalia.diaries.*`, variabile d'ambiente) e in wm-core (modello,
   `DiaryService`, cartella `diaries/`, selettori `wm-diary-*`, flag `showDiaries`, store localforage). Il
   branch non è mergiato né rilasciato: si rinomina la migration di creazione, non serve una migrazione dei
   dati. È il primo passo, in un commit dedicato per repo.
2. **Pulsante solo vicino al cammino.** Nel dettaglio del cammino e delle sue tappe, il pulsante per
   scrivere un diario compare solo se l'utente è stato a **meno di 1 km** da una qualsiasi tappa del cammino
   negli **ultimi 90 giorni**. Lo stato «cammino attivo» si salva sul telefono per `layerId` con la data, e
   vale per il cammino e per tutte le sue tappe. Soglia e durata stanno in costanti.
   **Quando e come si controlla:** un servizio di wm-core, attivo solo nella variante camminiditalia, ascolta
   `GeolocationService.onLocationChange$` (mappa aperta o registrazione in corso), al massimo una volta ogni
   pochi minuti, e controlla anche all'apertura del dettaglio. Prima scarta i cammini il cui `bbox`, allargato
   di 1 km, non contiene la posizione (118 cammini, costo trascurabile); per il cammino candidato carica le
   tappe con `EcService.getEcTrack` (cache in localforage) e misura la distanza minima dalla linea. Senza rete
   e senza tappe in cache vale l'esito del `bbox`. Un cammino già attivo oggi non si ricontrolla. I diari già scritti si
   vedono sempre. Nel Passaporto la regola della vicinanza non vale (vedi punto 5).
3. **Bozza e invio offline.** Il testo e le foto del form si salvano sul telefono mentre si scrive, sia per un
   diario nuovo sia per una modifica: chiudendo l'app non si perde nulla. Senza rete, un **diario nuovo** si
   mette in coda (uuid, testo, foto come Blob) e parte da solo quando la rete torna; resta visibile nella
   sezione con lo stato «In attesa di invio». La **modifica** di un diario già pubblicato resta bloccata senza
   rete, come oggi, ma la bozza è salvata.
   **Chiave e vita della bozza:** diario nuovo → `utente + cammino + tappa` (tappa «nessuna» per il cammino);
   modifica → `utente + id del diario`. L'uuid si salva nella bozza e si riusa alla riapertura. La bozza si
   cancella dopo la pubblicazione, dopo l'annullamento esplicito e quando il diario passa in coda. Il testo si
   salva dopo una pausa nella scrittura, le foto solo quando si aggiungono o si tolgono.
   **Utente e scadenza:** ogni bozza ed elemento in coda porta l'`userId` di chi l'ha scritto e si invia solo
   con lo stesso utente loggato; quelli di altri utenti aspettano il loro rientro. Al logout con diari in coda
   l'app avvisa: «Hai N diari in attesa di invio: se esci, partiranno al tuo prossimo accesso». All'avvio si
   cancellano bozze ed elementi in coda con ultima modifica più vecchia di 90 giorni (costante). Lo stato
   «cammino attivo» resta del telefono, non dell'utente.
   **Avvio solo in camminiditalia:** il processore della coda e il servizio del «cammino attivo» si avviano
   nel costruttore di `tabs.page.camminiditalia.ts` dell'app (già in `fileReplacements`, vivo per tutta la
   sessione); negli altri shard non esistono. L'invio parte a evento: passaggio di `NetworkService.online$`
   da falso a vero, login, scadenza delle attese crescenti dopo un errore. Nessun timer a coda vuota. Nella
   webapp c'è solo la bozza nel form, senza processore della coda.
4. **Consenso alla ripubblicazione.** Nel form, una spunta facoltativa, non selezionata di default: «Cammini
   d'Italia può pubblicare il mio racconto sui suoi canali». Si salva nel campo dedicato
   `publication_consent` (default `false`), distinto da `is_public`; si invia con la creazione e la modifica,
   e torna nell'API. In Nova si vede in sola lettura, con un filtro.
5. **Diario del cammino nel Passaporto anche a cammino iniziato.** Oggi il blocco del cammino nel
   Passaporto sta nella sezione del traguardo (`passport-detail.component.html:55`, `*ngIf="vm.completed"`)
   e compare solo a cammino completato. Si sposta perché compaia per ogni cammino con almeno una tappa
   riconosciuta (`completedStages > 0`), senza la regola della vicinanza. Il diario della singola tappa nel
   Passaporto resta com'è.
6. **Nova solo per gli amministratori.** I diari li vedono solo gli Administrator (Cammini d'Italia e
   Webmapp), con o senza consenso. Il Validator viene tolto da `viewAny` e da `visibleTo`.

### Perché

Il cliente ha validato il prototipo e ha chiesto una serie di modifiche (messaggio del 2 novembre),
chiarite in call il 3 novembre. Webmapp copre un altro giorno di sviluppo: si fanno le richieste
indispensabili e quelle più semplici. Il consenso è indicato dal cliente come «da fare subito», perché
senza consenso non potrà riusare i racconti scritti prima.

### Requisiti

- [ ] Nessun testo visibile all'utente contiene più «esperienza» o «esperienze»; le 7 lingue hanno tutte
      le chiavi nuove e nessuna chiave vecchia rimasta.
- [ ] Backend e wm-core non contengono più `Experience`/`experience` nei nomi (classi, tabelle, rotte,
      config, selettori), a parte i riferimenti storici nella documentazione del cantiere.
- [ ] I test esistenti del backend passano dopo il rinomino.
- [ ] Il pulsante compare sul cammino e sulle sue tappe solo se l'utente è stato entro 1 km da una tappa di
      quel cammino negli ultimi 90 giorni; senza posizione o senza permesso di localizzazione non compare.
- [ ] I diari già scritti restano visibili anche quando il pulsante non c'è.
- [ ] Nel Passaporto il diario del cammino si può scrivere da quando c'è almeno una tappa riconosciuta,
      senza regola della vicinanza; a cammino completato resta nel traguardo come oggi.
- [ ] Il form salva testo e foto sul telefono mentre si scrive e li ripropone alla riapertura, per un
      diario nuovo e per una modifica, con la propria chiave per contesto; la bozza si cancella dopo la
      pubblicazione, l'annullamento o il passaggio in coda, e scade dopo 90 giorni.
- [ ] Bozze ed elementi in coda di un utente non partono mai con il token di un altro; al logout con diari
      in coda l'app avvisa.
- [ ] Senza rete, «Pubblica» su un diario nuovo lo mette in coda e chiude il form con un messaggio chiaro;
      al ritorno della rete parte il POST con lo stesso uuid, senza creare doppioni.
- [ ] La spunta del consenso è facoltativa e non selezionata di default; il valore si salva e torna
      dall'API, anche in modifica.
- [ ] In Nova i diari li vede solo l'Administrator; il Validator non vede la risorsa.
- [ ] [UX] Lo stato «In attesa di invio» è un'etichetta testuale con icona, non solo un colore, ed è
      annunciato come stato (`role="status"`); scompare quando l'invio riesce.
- [ ] [UX] Esiti dell'invio dalla coda: 401 → resta in coda e parte dopo il prossimo login; rete assente o
      5xx → nuovo tentativo con attese crescenti (1, 5, 15 minuti, poi a ogni ritorno della rete); 422, 413,
      404 → il diario si ferma come «Non inviato» e, toccandolo, si riapre nel form con testo, foto e il
      messaggio d'errore, per correggerlo e ripubblicarlo, oppure eliminarlo.
- [ ] [UX] La spunta ha l'etichetta visibile accanto, un'area di tocco di almeno 44×44 px, e il testo
      completo non troncato.
- [ ] [UX] Il passaggio da form a coda è confermato con un toast: «Sei offline: il diario partirà appena
      torni online».

### Rischi

- **Rinomino esteso su un giorno di lavoro**: tocca molti file in due repo, e un rinomino a metà (backend su
  `/api/diaries`, app su `/api/experiences`) fa sparire la funzione senza errori, perché il 404 vale come
  «spenta dal backend». Mitigazione: il rinomino si fa per primo, backend e wm-core insieme, in un commit
  dedicato per repo, chiuso con i test del backend e la build dell'app; o è completo o non si fa (in quel
  caso si torna indietro su entrambi e resta «diario» solo nei testi visibili).
- **Ordine dei lavori** (un giorno di sviluppo, scrum del 3 novembre ore 11:37): rinomino → consenso e Nova
  → Passaporto a cammino iniziato → bozza e coda offline → pulsante vicino al cammino. L'ultimo è quello
  che si rimanda con meno danni: la sezione resta visibile come oggi.
- **Coda e logout, errori non risolvibili riprovando, avvio della coda, bozze sovrascritte o doppie**:
  gestiti nei punti 3 e nei requisiti [UX] (userId, scadenza a 90 giorni, esiti per codice HTTP, avvio a
  evento solo in camminiditalia, chiave e vita della bozza).
- **Spec dei diari esclusi in silenzio**: `core/angular.json:198` e `core/tsconfig.spec.json:26` dell'app
  puntano a `experiences/`; vanno aggiornati nel rinomino.
- **Limite di upload in locale**: il container PHP ha `upload_max_filesize = 2M`, sotto i 5 MB delle regole;
  in produzione va verificato nginx (già annotato in oc:8169).
- **Doppio invio dalla coda** (rete instabile, app riaperta durante l'invio): si mitiga con l'uuid già
  idempotente lato backend e con un solo invio alla volta.
- **Foto in coda e spazio sul telefono**: al massimo 6 foto già ridimensionate per diario; la coda si
  svuota a invio riuscito.
- **Utente senza GPS**: chi non concede la posizione non vede mai il pulsante sul cammino. È voluto dal
  cliente; il Passaporto resta la strada sempre disponibile.
- [UX] **Pulsante che sparisce senza spiegazione**: chi ha già scritto da quel cammino e torna dopo 90
  giorni non trova più il pulsante. Mitigato dal Passaporto, dove resta sempre.

### Out of scope

Rimandati, da rivalutare dopo questo ciclo: promemoria a fine cammino, diari nel profilo, editor con
formattazione, foto nel testo e racconti per tappa nel diario del cammino, scheda con i dati del
percorso, valutazioni, periodo di percorrenza per collegare le tracce, coda offline per le modifiche.
Esclusi dallo scrum del 3 novembre: titolo e descrizione per ogni foto. Il blocco nella webapp è già
attivo sul branch (`angular.json`, sostituzioni camminiditalia) e non richiede lavoro.

### Moduli toccati

**camminiditalia (backend)**: migration di creazione (`experiences` → `diaries`, `experience_ugc_track` →
`diary_ugc_track`, colonna `publication_consent`), `app/Models/Experience.php` → `Diary.php`, policy,
`Store/UpdateExperienceRequest` → `Store/UpdateDiaryRequest`, `ExperienceController` → `DiaryController`,
rotte API, `LinkExperienceUgcTracksJob`, `ExperienceUgcLinkService`, `ExperienceService`, middleware
`EnsureExperiencesEnabled` e `ProtectExperienceMedia`, observer, risorsa Nova e filtro del consenso,
`config/camminiditalia.php`, factory e test.

**wm-core**: `experiences/` → `diaries/` (modello, servizio, form, sezione, dettaglio), `track-properties`
e `home-layer` (varianti camminiditalia, flag), Passaporto (punti di montaggio), `utils/localForage.ts`,
file `localization/i18n/*.ts` (7 lingue), nuovo servizio per lo stato «cammino attivo» e per la coda.

**webmapp-app**: puntatore di wm-core; `core/src/app/pages/tabs/tabs.page.camminiditalia.ts` (avvio dei due
servizi); `core/angular.json` (`architect.test.options.include`, riga 198) e `core/tsconfig.spec.json`
(riga 26), che puntano alla cartella `experiences/` e vanno portati a `diaries/`, altrimenti gli spec dei
diari smettono di girare senza segnalarlo.

**wm-webapp**: solo il puntatore di wm-core.

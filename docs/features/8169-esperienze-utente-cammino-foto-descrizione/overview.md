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




### Ciclo 2, aggiunte (3 novembre 2026, sera)

Aree del tag 716 aggiunte dal dev a oc:8169 dopo il primo blocco, tutte **solo camminiditalia**.
Prototipo: https://claude.ai/artifact/VKvfbYKfDCBaRQBUVyfcz7

7. **Notifica di cammino completato** (al posto dell'invito nell'email). Un servizio di wm-core,
   avviato da `tabs.page.camminiditalia.ts`, ascolta `PassportService.passportRoutes$()`: quando un
   cammino passa a `completed` e non è fra quelli già visti (salvati sul telefono per utente), mostra
   una scheda in alto (`ion-toast` personalizzato, persistente, chiudibile trascinandola su):
   «Hai completato <cammino>!», «Guarda il traguardo e racconta il cammino nel diario», pulsanti
   «Più tardi» e «Vai al traguardo», che apre il dettaglio del Passaporto di quel cammino. Alla prima
   esecuzione per un utente i cammini già completati si segnano come visti senza notifica. Una scheda
   alla volta.
8. **Il mio diario nel profilo.** Nella variante camminiditalia del profilo, accanto al tab esistente,
   un secondo tab «Il mio diario»; il primo diventa «I miei dati» (solo in camminiditalia). Il tab
   elenca i diari raggruppati per cammino: una `wm-diaries-section` per cammino (`layerId`,
   `layout="list"`, `showCreate=false`, titolo il nome del cammino). Rotta in un
   `profile-routing.module.camminiditalia.ts` via `fileReplacements`, componente standalone: il modulo
   e il routing condivisi del profilo non si toccano. Senza diari, un messaggio vuoto.
   *(Superato in parte dal punto 11: la tab «I miei dati» è diventata «Account»; il componente non è
   standalone, vedi notes.md.)*
9. **Valutazioni** facoltative, uguali per diario del cammino e della tappa: Cammino (sulla tappa
   «Tappa»), Ospitalità, Difficoltà percepita, da 1 a 5, con un'unica icona: il **timbro con lo
   scarpone** (tavola «Scarponi di icons.json», variante B, nel canvas
   https://claude.ai/artifact/PcgenGHpyScsnXuHk2eHnj). Tondo pieno nel colore dell'app, bordo interno
   tratteggiato bianco, al centro lo scarpone `txn-boot` di `wm-package/resources/defaults/icons.json`
   in bianco; il punto non dato è solo il contorno grigio con lo scarpone grigio chiaro. L'SVG si
   copia nel componente di wm-core (quel file serve alle icone dei POI, non è caricabile da lì); in
   piccolo, nel dettaglio, senza bordo tratteggiato. Nel form in pagina unica dopo le foto; un tocco sul valore già
   scelto lo azzera.
   **Animazione, solo alla selezione nel form:** toccando il valore N, i timbri da 1 a N si
   «imprimono» uno dopo l'altro (60 ms di distanza), ciascuno da grande e leggermente ruotato a
   dimensione normale con un piccolo rimbalzo (circa 250 ms: scala 1,4 → 0,9 → 1, rotazione
   −12° → 0°). Abbassando il valore, i timbri oltre N tornano vuoti con una dissolvenza breve, senza
   rimbalzo. Nessuna animazione all'apertura del form con valori già salvati né nel dettaglio. Con la
   riduzione del movimento attiva nel sistema (`prefers-reduced-motion`) solo il cambio di colore. Nel dettaglio, solo quelle date. Backend: colonne `rating_route`,
   `rating_hospitality`, `rating_difficulty` (interi 1-5, null) nella migration di creazione, regole
   in creazione e modifica, API, Nova in sola lettura.
10. **Scheda dati del percorso** nel dettaglio del diario, solo se quello che racconta è stato
    percorso: diario della **tappa** a tappa completata, diario del **cammino** a cammino completato.
    Mostra data (o date di inizio e fine), numero di giorni e km. **Prima i dati dell'utente, dove
    mancano quelli del cammino:**
    - **km della tappa:** la lunghezza delle tracce GPS dell'utente che l'hanno validata
      (`PassportStage::ugcTracks()`, tabella `passport_stage_ugc_track`), se quelle tracce
      appartengono solo a questa tappa; altrimenti (validazione manuale, nessuna traccia, oppure
      una traccia registrata su più tappe insieme) la lunghezza della tappa.
    - **km del cammino:** la lunghezza delle tracce dell'utente collegate alle tappe completate,
      ciascuna contata una volta sola, più la lunghezza delle tappe completate senza traccia.
    - **date e giorni, stessa regola:** tappa completata col GPS → i giorni delle registrazioni
      collegate (data della traccia); tappa completata con la richiesta cartacea → i giorni da
      `start_date` a `end_date` della richiesta che l'ha validata (`PassportStage::certificationRequest()`).
      Per il cammino: dal primo all'ultimo giorno e numero di giorni distinti, unendo quelli di tutte
      le tappe. Se una tappa non ha né tracce né richiesta, i suoi giorni mancano e la riga dei giorni
      del cammino non si mostra (i km sì).
    Backend: nella risposta di `GET /api/layer/{layer}/progress` si aggiungono per tappa
    `user_distance` (km dalle tracce dell'utente, `null` se non applicabile) e `walked_dates` (le
    date `Y-m-d` in cui è stata percorsa, vuoto se non si sanno), e per il cammino
    `user_distance_total` (calcolato come sopra). Il frontend sceglie fra dato dell'utente e dato
    del cammino. Senza
    progresso (offline senza copia) la scheda non c'è.

**Ordine**: profilo → valutazioni → notifica → scheda dati (la più incerta, si rimanda se manca tempo).

**Rischi aggiunti**
- Notifica: `passportRoutes$` si aggiorna al login, al ritorno in primo piano e all'apertura del
  Passaporto, non in tempo reale: il completamento si vede alla prossima di queste occasioni.
- Notifica alla prima esecuzione: senza il «già visti» iniziale, chi ha più cammini completati
  riceverebbe più schede di fila.
- Profilo: `ion-tabs` con rotte figlie; la variante del routing va dichiarata nelle configuration di
  build e di serve.
- Scheda dati, km dell'utente: una traccia registrata in un giorno solo su due tappe è collegata a
  entrambe; senza la regola «una volta sola» i km del cammino si conterebbero due volte. Una traccia
  con il GPS che salta può dare km gonfiati: si mostrano così come sono stati registrati.
- Scheda dati: la `validated_at` di una tappa cartacea è la data di approvazione del gestore, non di
  percorrenza: per questo i giorni vengono da `start_date`/`end_date` della richiesta, mai da
  `validated_at`.
- Scheda dati, giorni GPS: la data di una traccia è quella di inizio registrazione; una traccia che
  passa la mezzanotte conta un giorno solo.

11. **Profilo riorganizzato** (solo camminiditalia, aggiunto il 3 novembre 2026 dopo la prova del
    tab «Il mio diario»: i pulsanti fissi in fondo coprivano la lista, e le informazioni erano
    sparse in modo incoerente). La schermata di accesso e registrazione resta com'è.
    - **Intestazione**: avatar, nome ed email come oggi; il tocco apre già la modifica del profilo.
    - **Tab «Il mio diario»**, la prima e quella aperta di default da loggato.
    - **Tab «Account»** al posto di «I miei dati», nell'ordine: Download (tracce scaricate, il
      contenuto di oggi), Modifica consenso dati, Esci, Elimina account. Tutti nel flusso della
      pagina, non più fissi in fondo: niente più sovrapposizioni. Da non loggato la tab mostra solo
      i download, e «Il mio diario» non c'è.
    - **Ingranaggio**: solo le impostazioni dell'app (lingua, GPS, schermo acceso, versione,
      pagine); «Esci» si sposta in «Account». L'avviso dei diari in coda all'uscita resta uguale.
    - Rischi: `SettingsComponent` è condiviso fra tutti gli shard; si nasconde «Esci» con un input
      che vale `true` di default, così gli altri shard non cambiano. La logica di uscita si sposta
      in un servizio dell'app usato da entrambi i punti.


## Ciclo 3 — Editor del racconto (4 novembre 2026)

> Fonti: messaggio del cliente del 2 novembre 2026 nel ticket (§3.1, «un editor più ricco di una
> semplice casella di testo», riferimento Substack); call con Cammini d'Italia del 3 novembre (tag
> Orchestrator 716): Davide Nanna chiede opzioni «tipo grassetto e cose del genere». Prova tecnica
> con Tiptap fatta la notte del 4 novembre e approvata dal dev. Allo scrum del 3 novembre Giuseppe
> Bonfanti aveva indicato le richieste nuove come non pagate e da mettere in backlog: il dev ha
> deciso di procedere comunque.

### Cosa cambia

Il racconto del diario si scrive con un editor che formatta il testo mentre si scrive, al posto
della casella di testo semplice, e si legge nel dettaglio del diario esattamente com'è stato
scritto: titoli, grassetto, elenchi e citazioni compresi. Da questo ciclo `description` è
**sempre HTML**, ripulito dal backend: il testo semplice che arriva viene convertito in paragrafi.
I diari già scritti non si convertono con una migration: la funzione non è ancora né in produzione
né sul dev, e i diari del database locale sono stati convertiti a mano. Niente regola per
riconoscere il formato. Le foto restano nella galleria
come oggi.

### Perché

È il punto che il cliente indica come più importante. Un racconto di un cammino di più giorni è
lungo, e senza titoli, elenchi e grassetto diventa un muro di testo, sia per chi lo scrive sia per
Cammini d'Italia, che lo ripubblica (blog, Substack, social) quando c'è il consenso. Tiptap salva
HTML: è lo stesso formato che servirà per le foto dentro il testo, quindi i racconti scritti da ora
non andranno convertiti in seguito.

### Requisiti

**Editor (wm-core, `wm-diary-editor`)**
- [ ] Tiptap 3.32.0 (`@tiptap/core`, `@tiptap/pm`, `@tiptap/starter-kit`, `@tiptap/extensions`,
      versione bloccata), caricato solo all'apertura del form con `import()` dinamico.
- [ ] Barra con 12 bottoni, in quest'ordine: grassetto, corsivo, barrato, titolo, sottotitolo,
      elenco puntato, elenco numerato, citazione, collegamento, linea di separazione, annulla,
      ripeti. Niente sottolineato, colori, allineamento: le estensioni dello `StarterKit` non
      ammesse (sottolineato, codice) sono spente anche nelle scorciatoie da tastiera, così l'editor
      non produce nulla che il backend poi scarta.
- [ ] La barra sta in cima al riquadro del racconto e, mentre si scorre un racconto lungo, resta
      attaccata in alto (sticky) finché il riquadro è visibile.
- [ ] Collegamenti: un avviso chiede l'indirizzo; sono ammessi solo `http`, `https` e `mailto`;
      un indirizzo senza protocollo (`www.camminiditalia.it`) diventa `https://…`. Vuoto toglie
      il collegamento.
- [ ] Contatore del testo visibile sotto l'editor («1.234 / 20.000»). Oltre i 20.000 caratteri il
      contatore diventa rosso, compare il messaggio vicino al campo e «Pubblica»/«Salva» si
      disabilita.
- [ ] L'HTML salvato non ha paragrafi vuoti in fondo; un editor vuoto vale testo vuoto, quindi
      «testo o almeno una foto» continua a funzionare.
- [ ] Incollare testo da altre app tiene solo le formattazioni ammesse: niente colori, caratteri,
      immagini.
- [ ] Se Tiptap non si carica (PWA senza rete al primo uso), il form mostra la casella di testo
      semplice di oggi, e il racconto si scrive e si salva lo stesso.
- [ ] Bozza e coda offline salvano l'HTML così com'è (nessun cambio di formato).

**Dettaglio, schede ed estratti (wm-core)**
- [ ] Il dettaglio mostra l'HTML con `[innerHTML]` (ripulito anche da Angular) e lo stesso foglio di
      stile dell'editor (`_diary-rich-text.scss`). Nessuna regola di riconoscimento del formato:
      `description` è HTML.
- [ ] I collegamenti nel dettaglio si aprono nel browser del telefono (`window.open(url,
      '_blank')`, come gli altri link esterni di wm-core), non dentro l'app; il tocco si intercetta
      anche dentro un collegamento in grassetto (`closest('a')`), e `mailto:` si apre come
      `location.href`.
- [ ] Le schede del carosello, l'elenco della tappa, il profilo e i diari in coda mostrano
      l'estratto in testo semplice, senza tag.

**Backend (camminiditalia)**
- [ ] `description` ripulita con HTMLPurifier, riusando
      `HasEmbeddableRichText::buildRichTextPurifier()` di wm-package (nessuna seconda
      configurazione), con un elenco chiuso: `p`, `br`, `strong`, `em`, `s`, `h2`, `h3`, `ul`,
      `ol`, `li`, `blockquote`, `hr`, `a` con solo `href` assoluto (`http`, `https`, `mailto`; i
      relativi spariscono). Tutto il resto (script, attributi `on…`, stili, immagini,
      `javascript:`) sparisce. La cache del purifier sta in `storage/`, non in `vendor/`.
- [ ] Il testo semplice che arriva (senza tag) si converte in paragrafi prima della pulizia, con i
      caratteri speciali protetti.
- [ ] Limiti: 20.000 caratteri di testo visibile (dopo la pulizia, senza tag, con le entità
      decodificate) e 100.000 caratteri di HTML. Messaggi d'errore distinti.
- [ ] La regola «scrivi un testo o aggiungi almeno una foto» conta il testo visibile: `<p></p>` è
      vuoto.
- [ ] Nova: nel dettaglio il racconto formattato, ripulito di nuovo al momento di mostrarlo
      (`asHtml` non si fida del dato salvato); nell'elenco l'estratto in testo semplice; la ricerca
      non cerca dentro i tag.

**Comuni**
- [ ] Testi nuovi (etichette dei bottoni, avviso del collegamento, contatore, messaggi di limite)
      in tutte e 7 le lingue di wm-core, chiave = testo italiano; messaggi del backend in
      `resources/lang`.
- [ ] Webapp: i pacchetti Tiptap si aggiungono anche al `package.json` della webapp, perché
      `WmCoreModule` dichiara l'editor e la build senza pacchetti non compila; il form nella
      webapp non c'è, non cambia altro.
- [ ] Test: editor (valore in ingresso e in uscita, paragrafi vuoti, contatore, ripiego senza
      Tiptap, sottolineato spento), funzioni del testo, estratti, link del dettaglio; backend:
      pulizia (script, `onclick`, `javascript:`, link relativi, immagini, stili, `<u>`), testo
      semplice convertito, limiti, regola testo-o-foto.

**[UX]**
- [ ] [UX] Ogni bottone ha un'area di tocco di almeno 44×44 px, `aria-label` tradotto, e
      `aria-pressed` per gli stili attivi; lo stato attivo si vede con sfondo e colore, non solo
      col colore.
- [ ] [UX] La barra sticky non copre la riga in cui si scrive: lo spazio della barra si riserva
      sull'elemento che scorre davvero (lo scroll di `ion-content` è interno), verificato sul
      dispositivo.
- [ ] [UX] Il testo dell'area di scrittura è di almeno 16 px, altrimenti iOS ingrandisce la pagina
      al tocco (oggi nella prova è 15 px).
- [ ] [UX] Il contatore viene letto dagli screen reader solo vicino al limite (dal 90%), non a
      ogni carattere.
- [ ] [UX] L'etichetta «Il tuo racconto» resta visibile sopra l'editor; il segnaposto non la
      sostituisce.

### Rischi

- **[UX] Tastiera e barra sticky su iOS dentro la WebView:** la barra può finire sotto l'intestazione
  del form o saltare quando si apre la tastiera. Mitigazione: prova sul dispositivo vero prima del
  commit.
- **Codice malevolo nel racconto (XSS):** l'HTML arriva dall'utente. Mitigazione: pulizia nel
  backend con elenco chiuso, e `[innerHTML]` di Angular che ripulisce di nuovo; mai
  `wm-inner-html`, che usa `bypassSecurityTrustHtml`. Bozza e coda non sono ripulite finché non
  arrivano al server, ma le vede solo chi le ha scritte.
- **Primo uso senza rete nella PWA:** il pezzo di codice di Tiptap non è ancora scaricato e l'editor
  non parte. Mitigazione: ripiego sulla casella di testo semplice. Nell'app nativa il codice è nel
  pacchetto.
- **Peso:** circa 140 KB compressi in più, scaricati solo all'apertura del form; il bundle
  iniziale non cambia.
- **Formato del campo `description`:** passa da testo a HTML per tutti i diari. Oggi lo leggono
  solo l'app, la webapp (che non mostra i diari) e Nova; un futuro uso «Di tutti» o un export
  parte dall'HTML ripulito o dall'estratto.
- **Rilascio:** il ciclo 3 deve uscire insieme ai cicli 1 e 2 (nessuno è in `main`): un'app senza
  l'editor mostrerebbe i tag nei racconti. Tornare indietro dopo il rilascio vorrebbe dire una
  conversione dei dati con perdita di titoli, elenchi e collegamenti.
- **Limite di tempo:** il giorno di sviluppo pagato è finito; il ciclo si fa per decisione del dev.

### Out of scope

- Foto dentro il testo (ciclo successivo, stesso formato HTML).
- Racconto per tappe dentro il diario del cammino.
- Titolo del diario, titolo/descrizione/tappa per ogni foto.
- Form del diario nella webapp (scrivere da PC).

### Moduli toccati

- **camminiditalia:** `composer.json`/`composer.lock`; nuovo servizio di pulizia del testo del
  diario (`app/Services/DiaryTextService.php` o simile) sul purifier di wm-package;
  `StoreDiaryRequest`, `UpdateDiaryRequest`, `DiaryService`; `app/Nova/Diary.php`;
  `resources/lang/*.json`; test in `tests/Feature/DiaryApiTest.php` e un test unitario del
  servizio.
- **wm-core:** `diaries/diary-editor/` (componente, template, stili, spec), `diaries/diary-text.utils.ts`
  (+ spec), `diaries/_diary-rich-text.scss`, `diary-form` (template, contatore, limite),
  `diary-detail` (HTML e collegamenti), `diaries-section` (estratti), `wm-core.module.ts`,
  `localization/i18n/*.ts`.
- **app:** `core/package.json`, `core/package-lock.json`.
- **webapp:** `package.json`, `package-lock.json` (con l'aggiornamento del puntatore di wm-core).

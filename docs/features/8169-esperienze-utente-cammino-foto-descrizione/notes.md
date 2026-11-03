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


## Ciclo 2 — «Il mio diario» (3-4 novembre 2026)

### Divergenze dal piano, task per task

#### Task 5 — passaporto a cammino iniziato
Lo spec di `passport-detail` non era negli elenchi degli spec dell'app (`core/tsconfig.spec.json`, `core/angular.json`): non girava da nessuna parte e il mock dell'host era rimasto indietro (`nav`, `registerGuard`). Aggiunta la cartella ai due elenchi e corretto il mock.

#### Task 8 — pulsante solo vicino al cammino
Ridisegnato dopo la review finale. Il piano leggeva le tappe di ogni cammino da `tracks`/`edges` della configurazione, ma nella config di produzione (124 cammini) quei campi non ci sono: il controllo si riduceva al solo rettangolo, e sulla tappa aperta senza cammino il pulsante non compariva mai. Disegno attuale, approvato dal dev: in sottofondo si salvano solo le posizioni del telefono (al massimo una ogni 3 minuti, almeno 200 m dall'ultima, 90 giorni); il confronto con le tappe si fa all'apertura del dettaglio — del cammino con le tappe che arrivano da Elastic (`ecTracks`), della tappa con la sua linea e i suoi `properties.layers` — e si rifà a ogni posizione nuova (`lastPositionAt$`). Senza linee disponibili vale il rettangolo del cammino.

### Bug trovati
- Revisione finale: coda mostrata anche nella sezione della registrazione; messaggio generico per un 422 dalla coda; foto riconvertite a ogni pausa nella scrittura; bozza esistente sovrascritta riaprendo un diario non inviato; 429 trattato come errore da correggere; conteggio dell'avviso al logout con i non inviati; bozza riscritta dopo la pubblicazione. Tutti corretti con test.
- Prova in locale: la posizione arrivata a dettaglio già aperto non rifaceva il confronto; `throttleTime` teneva solo la prima posizione della finestra (aggiunto `trailing`). Una posizione con accuracy di 150 m non arriva al servizio (filtro a monte, nel GPS).
- Prova in locale: il pulsante di chiusura del dettaglio era coperto dallo `swiper-container` della galleria (`z-index`).
- PHPStan: 10 errori preesistenti del ciclo 1 emersi nel diff per il rinomino dei file (`ProtectDiaryMedia`, `Diary.php`, `DiaryApiTest`), corretti.
- Ambiente locale: disco Docker pieno (Elasticsearch in restart continuo, un test fallito per spazio), indice `ec_tracks` vuoto; liberata la build cache e reindicizzato con `scout:import`.

### Decisioni
- Niente ticket nuovi: il ciclo 2 si fa dentro oc:8169 (tag Orchestrator 716 per le richieste della call del 3 novembre).
- Rinomino completo da `Experience` a `Diary` (codice, API, tabelle, config, variabili d'ambiente), in un commit unico per repo insieme alle funzioni nuove: i file toccati dal rinomino e dalle funzioni sono gli stessi, e senza staging interattivo non si separano.
- Consenso in un campo dedicato `publication_consent`, distinto da `is_public`.
- Coda offline solo per i diari nuovi; la modifica senza rete resta bloccata, con la bozza salvata. Coda e cammino attivo si avviano solo da `tabs.page.camminiditalia.ts`; nella webapp c'è solo la bozza.
- Richieste dopo il piano: dettaglio con «Modifica»/«Elimina» in un `ion-footer`; schede del carosello basse come nel wireframe; «visibile solo a te» sostituito da «Cammini d'Italia può pubblicarlo» quando il consenso c'è.

### Follow-up
- **Variabili d'ambiente rinominate**: `CAMMINIDITALIA_DIARIES_ENABLED`, `CAMMINIDITALIA_DIARY_LINK_DAYS` (dove era impostata `…_EXPERIENCES_ENABLED=false` la funzione si riaccenderebbe col default).
- Prova offline su dispositivo reale.
- Minori rimandati: ripristino della bozza in corsa con la digitazione e object URL non revocati; chiave della bozza diversa aprendo la tappa con o senza cammino; diari in coda invisibili se la sezione è nascosta; pulsante visibile un attimo prima del primo valore; nella webapp le bozze non scadono; sulla tappa si confronta solo la sua linea.
- Aree del tag 716 da rivalutare: promemoria a fine cammino, diari nel profilo, editor, scheda dati, valutazioni, periodo di percorrenza.

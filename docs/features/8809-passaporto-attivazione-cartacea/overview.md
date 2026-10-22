> Ticket: oc:8809

# [passaporto] Attivazione cartacea

## Cosa cambia

Il passaporto di un cammino oggi si accende con un solo flag sul layer
(`properties->passport_enabled`). Lo stesso flag apre le API per l'app e abilita lo sblocco
automatico delle tappe dalle tracce GPS (oc:8165). Dopo questo lavoro i flag per cammino sono
due, entrambi in Nova Layer e modificabili solo dall'Administrator:

| Flag (etichetta it) | Chiave in `layers.properties` | Cosa fa |
|---|---|---|
| **Passaporto cartaceo** | `passport_enabled` (la chiave di oggi, cambia solo l'etichetta) | Apre le API del passaporto per l'app: richieste di certificazione, progresso, condivisioni. È lo stesso comportamento del flag di oggi |
| **Passaporto automatico (GPS)** | `passport_gps_enabled` (nuovo) | Le tappe del cammino si sbloccano dalle tracce GPS registrate in app |

Regole:

- accendere il GPS accende anche il cartaceo, e finché il GPS è acceso il cartaceo non si può
  spegnere. Vale nel form del layer e nella modifica in blocco. I testi di aiuto dei due campi lo
  spiegano. Se nello stesso salvataggio si accende il GPS e si spegne il cartaceo, vince
  l'accensione del GPS;
- spegnere il GPS su un cammino cancella le tappe sbloccate dal GPS (`source = gps`) che fanno
  parte di quel cammino (`layerables`) e di nessun cammino con il GPS acceso nel momento in cui la
  revoca gira (quel cammino compreso, se nel frattempo è stato riacceso). Non si
  usa `passport_stages.layer_id`, che è il cammino in cui la tappa è stata validata per prima e non
  dice a quale cammino appartiene. Restano le tappe confermate con la credenziale cartacea
  (`source = manual`);
- spegnere il cartaceo (possibile solo con il GPS spento) non cancella nulla, come oggi;
- accendere il GPS fa partire il ricalcolo per chi ha già camminato sul cammino. È lo stesso
  ricalcolo che oggi parte all'accensione del passaporto (`RecomputePassportStagesForLayerJob`);
- accendere il solo cartaceo non fa partire nessun calcolo da GPS.

Le regole dei due flag stanno in una sola classe, usata sia dal form Nova sia dalla modifica in
blocco. La modifica in blocco diventa un'Action locale che estende la `BulkEditAction` di
wm-package: ne eredita i campi a tre scelte e riscrive solo il salvataggio. Salva in modo
silenzioso (`saveQuietly()`), come il package, e chiama di persona i due passi del passaporto
(vincolo prima, ricalcolo o revoca dopo), così non scattano gli altri observer del layer.

Al rilascio il GPS parte spento su tutti i cammini (la chiave `passport_gps_enabled` non esiste) e
la chiave `passport_enabled` resta com'è. Nessuna migration: le righe `source = gps` eventualmente
presenti al rilascio si cancellano a mano sul DB (in produzione il 22/10 erano 0).

## Perché

Il cliente chiede di attivare per ora solo la parte cartacea del passaporto, così gli enti
gestori imparano a gestire le richieste di certificazione. Allo scrum del 22/10 è stato riferito
che il cliente vuole brevettare l'algoritmo di sblocco automatico. Oggi però non esiste un modo
di accendere il passaporto senza accendere anche il GPS.

Stato della produzione il 22/10 alle 08:29 UTC: 0 cammini accesi su 122, 6 righe
`passport_stages` tutte `manual` (una richiesta approvata il 19/10), 0 righe `gps`, 0
condivisioni. Il passaporto è spento in attesa di poter accendere il solo cartaceo.

La soluzione con due flag per cammino è quella decisa allo scrum del 22/10. La chiave del flag
attuale resta `passport_enabled` e cambia solo l'etichetta in Nova (decisione del dev dopo
l'implementazione: vedi [notes.md](notes.md)).

## Requisiti

- [ ] La chiave `passport_enabled` resta quella del passaporto cartaceo; cambia solo l'etichetta
  in Nova
- [ ] Nuova chiave `passport_gps_enabled`, spenta quando manca
- [ ] Campo Nova «Passaporto cartaceo» (prima «Passaporto attivo») e nuovo campo «Passaporto
  automatico (GPS)», con testo di aiuto, solo per l'Administrator
- [ ] Accendere il GPS (dal form o in blocco) mette a true anche il cartaceo
- [ ] Non si può spegnere il cartaceo se il GPS è acceso: né dal form né in blocco, con un
  messaggio d'errore in Nova
- [ ] Le regole dei due flag stanno in una sola classe, usata dal form e dall'Action
- [ ] Action locale «Passaporto: modifica in blocco» che estende la `BulkEditAction`, con i due
  campi e le tre scelte («non cambiare», «sì», «no»); salva con `saveQuietly()` e chiama i passi
  del passaporto (vincolo, ricalcolo, revoca), senza far scattare gli altri observer del layer
- [ ] Il calcolo automatico (`StageValidationService`, `ComputeStageValidationsCommand`,
  ricalcolo per layer) considera solo i cammini con il GPS acceso
- [ ] Accendere il GPS fa partire il ricalcolo per layer; accendere il solo cartaceo no
- [ ] Spegnere il GPS cancella le righe `gps` delle tappe del cammino che non stanno in un altro
  cammino con il GPS acceso; le righe `manual` restano
- [ ] Il docblock di `PassportLayerObserver` («allo spegnimento non si cancella nulla») va
  riscritto con le due regole: lo spegnimento del cartaceo non cancella, quello del GPS sì
- [ ] Le API per l'app (`/api/passport`, `/api/layer/{layer}/...`) restano governate dal solo
  cartaceo: per l'app non cambia nulla
- [ ] Etichette, testi di aiuto e messaggi tradotti in it (default), en, fr, es, de
- [ ] Guida cliente `docs/guide/passaporto-attivazione/` aggiornata: testo e 3 screenshot
  rifatti dal Nova locale
- [ ] Test: vincolo fra i flag (form e Action), calcolo solo con il GPS
  acceso, cancellazione allo spegnimento del GPS con tappa condivisa fra due cammini

## Rischi

- **Cancellare le righe GPS revoca tappe che l'utente ha già visto in app.** È voluto, e le righe
  si ricreano dalle tracce UGC riaccendendo il GPS. In produzione il 22/10 le righe `gps` erano 0;
  se al rilascio ce ne fossero (passaporto acceso prima del deploy con il codice attuale), si
  cancellano a mano sul DB, altrimenti restano visibili in app finché non si accende e rispegne
  il GPS su quel cammino.
- **Tappa condivisa fra più cammini.** `passport_stages` ha una riga per utente e tappa, e
  `layer_id` non indica l'appartenenza: la cancellazione usa `layerables` ed esclude le tappe che
  stanno in un altro cammino con il GPS acceso. Una tappa sbloccata dal GPS in un cammino compare
  sbloccata anche in un cammino solo cartaceo che la contiene: è corretto, per quell'utente la
  tappa è sbloccata.
- **La `BulkEditAction` di wm-package salta gli observer** (`saveQuietly()`): per questo la
  modifica in blocco del passaporto passa a un'Action locale che la estende e chiama di persona i
  passi del passaporto. Rischio: un futuro observer di layer che debba reagire al flag non scatta
  dalla modifica in blocco (commento sull'Action).

## Out of scope

- Nessuna modifica al frontend (app e webapp).
- Nessuna modifica a wm-package.
- L'informativa privacy sui dati di posizione (follow-up di oc:8165) resta da sistemare a cura del
  cliente prima di accendere il GPS in produzione.

## Moduli toccati

Tutto nel repo principale `camminiditalia`:

- `app/Support/PassportLayer.php` — chiave cartacea, controllo GPS (PHP e SQL) e regole fra i due
  flag (o una classe dedicata accanto)
- `app/Observers/PassportLayerObserver.php` — ricalcolo all'accensione del GPS, cancellazione
  allo spegnimento, docblock riscritto
- `app/Services/StageValidationService.php`, `app/Console/Commands/ComputeStageValidationsCommand.php`,
  `app/Jobs/RecomputePassportStagesForLayerJob.php` — filtro sul GPS
- `app/Services/StageProgressService.php`, `routes/api.php`, `app/Http/Middleware/EnsurePassportEnabled.php`
  — solo commenti e chiave
- `app/Nova/Layer.php` — due campi con regole e testi di aiuto, nuova Action che estende la
  `BulkEditAction`
- `app/Nova/Actions/` — nuova Action «Passaporto: modifica in blocco»
- `resources/lang/{it,en,fr,es,de}.json`
- `docs/guide/passaporto-attivazione/` — testo e screenshot
- `tests/Feature/` — test esistenti sulla chiave e nuovi test
- `docs/knowledge/` — pagine del passaporto (8763 e 8676)

> Ticket: oc:8809

# Notes — [passaporto] Attivazione cartacea

## Divergenze dal piano, task per task

### Task 1: la chiave del cartaceo non cambia

Il piano rinominava `passport_enabled` in `passport_paper_enabled`, con una migration che spostava
il valore. Dopo l'implementazione il dev ha deciso di cambiare **solo l'etichetta in Nova**
(«Passaporto cartaceo») e di lasciare la chiave `passport_enabled`. Quindi:

- `PassportLayer::PAPER_KEY` vale `passport_enabled`;
- nessuna migration. Una prima versione cancellava le righe `passport_stages` con `source = 'gps'`
  al rilascio; il dev ha preferito toglierla: se al rilascio ci sono righe `gps` (in produzione il
  22/10 erano 0, ma possono nascere se il passaporto viene acceso prima del deploy con il codice
  attuale), cosa farne si decide con il team (vedi «Follow-up»): dopo lo scrum del 23/10 un timbro
  dato non si toglie.

Restano come da piano i nomi dei metodi (`isPaperEnabled`, `paperEnabledSql`, …) e la rimozione di
`isEnabled()`/`enabledSql()`.

### Task 3: spegnere il GPS non revoca nulla

Il piano (e la prima implementazione) prevedeva che spegnere il GPS cancellasse le tappe `gps` del
cammino, con `RevokeGpsPassportStagesForLayerJob` e `StageValidationService::revokeGpsForLayer()`.
Allo scrum del 23/10/2026 il team ha deciso il contrario: «le tappe validate non si torna
indietro… una volta che c'è il timbro è fatta». Job e metodo sono stati tolti: spegnere il GPS
ferma solo il calcolo su quel cammino (già garantito da `gpsEnabledSql()`), le tappe già sbloccate
restano. Accendere il GPS continua a far partire il ricalcolo.

### Task 4: chiavi e testo di aiuto

- Gli attributi dei campi Nova e del `dependsOn` si costruiscono da `PassportLayer::PAPER_KEY` /
  `GPS_KEY`, non da stringhe letterali; il controllo «solo il booleano `true`» sta in
  `PassportLayer::paperEnabledIn()` / `gpsEnabledIn()`, usati anche da observer e Action (review
  wm-review-ticket).
- Il testo di aiuto del flag GPS dice che spegnerlo ferma lo sblocco automatico ma le tappe già
  sbloccate restano (dopo lo scrum del 23/10; vedi il task 3).
- La chiave di traduzione «Paper passport» esisteva già (origine `manual` delle tappe): la seconda
  occorrenza aggiunta in un primo momento è stata tolta, perché in tedesco cambiava l'etichetta
  esistente.

### Task 5: l'Action estende la BulkEditAction

Il piano prevedeva un'Action a sé che salvasse con `save()`. Così però scattavano tutti gli observer
del layer, e `LayerObserver::saved` accoda un `RecalculateLayerAttributesJob` a ogni salvataggio: una
modifica in blocco su 122 cammini ne accodava 122 inutili. Su decisione del dev l'Action estende la
`BulkEditAction` di wm-package (ne eredita i campi a tre scelte; `resolveChanges()` è `private` e la
lettura dei due valori resta nella sottoclasse), salva con `saveQuietly()` e chiama di persona
`PassportLayerObserver::saving()` e `dispatchOnGpsTurnedOn()`. Un futuro observer di layer che debba
reagire al flag del passaporto non scatta dalla modifica in blocco: c'è un commento sull'Action.

Due dettagli tecnici:

- il `handle()` del package è `void` e non può restituire `Action::danger()`: la logica sta in
  `handleForLayers()`, che Nova preferisce a `handle()` quando esiste
  (`ActionMethod::determine()`: `'handleFor'.Str::plural(class_basename($model))`);
- dopo `saveQuietly()` `getOriginal()` ha già il valore nuovo, quindi chiamare `updated()` a mano non
  accodava nulla: l'Action legge le properties «prima» del salvataggio e le passa a
  `PassportLayerObserver::dispatchOnGpsTurnedOn($layer, $before)`, che anche `updated()` usa.

### Task 6: la guida dice che le tappe restano

Il piano chiedeva di scrivere nella guida che spegnendo il GPS le tappe sbloccate si cancellano, e
di rifare gli screenshot con la migration eseguita. Dopo lo scrum del 23/10 la guida dice che le
tappe già sbloccate restano, e la migration non esiste più: gli screenshot sono stati fatti con la
prima versione, che non cambia nulla di ciò che mostrano (i due interruttori e la finestra della
modifica in blocco).

### Task 7: niente controllo su wm-core

Il controllo «wm-core non legge `passport_enabled`» serviva solo per il rename della chiave: con la
chiave invariata non serve più. Anche «deploy e `migrate` nello stesso passaggio» non serve: non c'è
nessuna migration. Resta `horizon:terminate` dopo il deploy.

## Bug trovati

- **Revoca e ricalcolo in parallelo** (review finale; superato: la revoca è stata tolta dopo lo scrum del 23/10): `revokeGpsForLayer()` escludeva il layer
  stesso dal controllo «altro cammino con il GPS acceso». Con 2 worker sulla coda
  `ugc-track-matching`, uno spegni/riaccendi rapido poteva far girare la revoca dopo il ricalcolo e
  cancellare le tappe appena ricreate. Corretto: la revoca guarda lo stato di tutti i layer, layer
  stesso compreso, al momento in cui gira.
- **PHPStan sull'observer:** il ramo `is_string()` su `properties` in `PassportLayerObserver::saving()`
  era codice morto (il cast `array` di Layer restituisce già un array). Tolto.

## Decisioni

- **Form Nova:** con il GPS acceso il campo cartaceo diventa di sola lettura e `true` (`dependsOn`),
  senza un messaggio d'errore dedicato; lato server lo forza `PassportLayer::normalize()` su
  `saving`. È la regola «vince il GPS» dell'overview.
- **Modifica in blocco:** «cartaceo No» insieme a «GPS Sì» nella stessa richiesta è rifiutata con un
  messaggio e non modifica nessun cammino, invece di far vincere il GPS. Il tutto-o-niente vale per
  blocco di 200 cammini (Nova chiama `handle()` a chunk); oggi i cammini sono 122.
- **Stima rifatta su richiesta del dev:** da 4,8h a 3,7h stimate (più 0,9h misurate), perché
  l'esecuzione è tutta dell'LLM e il dev controlla solo il diff finale. Stima scritta su
  Orchestrator: 4,6h.
- **Ordine stima e piano invertito:** `wm-estimate` non stima senza `plan.md`, quindi il piano è
  stato scritto prima della stima.
- **Tag Orchestrator:** il dev non ha voluto associare tag in questo ciclo.
- **DB di sviluppo:** la migration della prima versione è stata eseguita in locale per rifare gli
  screenshot della guida e poi annullata; le 3 righe `gps` locali che aveva cancellato non tornano.

## Follow-up

- **Al rilascio:** controllare in produzione `SELECT count(*) FROM passport_stages WHERE source = 'gps'`
  (il 26/10 erano 0, con 0 cammini accesi). Se non è 0, quelle tappe sono state sbloccate dal codice
  precedente su cammini accesi prima del deploy: decidere con il team cosa farne **prima** di
  cancellare qualunque cosa, perché un timbro dato non si toglie (scrum del 23/10).
- **Informativa privacy** sui dati di posizione (già nel follow-up di oc:8165): da sistemare a cura
  del cliente prima di accendere il GPS in produzione.
- **13 errori PHPStan preesistenti** in 5 file di test non toccati (chiamate Mockery non
  riconosciute): `LayerFeatureControllerTest`, `PassportRouteShareApiTest`,
  `PassportStageShareApiTest`, `RouteShareImageServiceTest`, `StageShareImageServiceTest`.
- Test non scritti, ritenuti non necessari in review: rollback multi-layer dell'Action, testo
  italiano del messaggio d'errore.

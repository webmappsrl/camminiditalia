> Ticket: oc:8575

# Notes — Override manuale in Nova

## Deviazioni dal piano

Tutte le deviazioni sono state introdotte, verificate e già recepite direttamente in `plan.md` durante l'esecuzione (vedi le note "Corretta durante l'esecuzione" inline nei task, e la sezione "Correzioni da Fase: review formale" dopo il Self-Review). Riepilogo:

- **Task 2** — il `before()` copiato da `UgcPoiPolicy` negava tutte le ability a Guest via short-circuit, contraddicendo i test dello stesso brief che richiedevano `viewAny`/`view`/`create`/`delete` sempre `true`. Rimosso il branch "non-Validator → false".
- **Task 4** — il test originale scriveva la correzione manuale prima di chiamare `handle()`, senza mai esercitare la guardia nuova (il controllo di idempotenza preesistente l'avrebbe già intercettata). Riscritto con un mock di `UgcService::resolveLayerByProximity()` per iniettare la correzione nella vera finestra di race.
- **Task 3/4** — scoperto un bug preesistente non correlato: `NewUgcReportMail::extractCoordinates()` assume geometria Point e fallisce su `UgcTrack` (linea); workaround solo nei test (`Queue::fake()`/`Mail::fake()`), nessuna modifica in produzione (fuori scope).
- **Review formale (wm-review-ticket)** — 3 finding bloccanti, tutti corretti (vedi "Bug trovati" sotto).
- **Cleanup post-review** — su richiesta esplicita del dev, applicati tutti i cleanup non bloccanti: trait diviso in `HasLayerFilterAndLink` (presentazione) + nuovo `HasLayerOverride` (campo editabile + mutazione), nuova classe `App\Support\UgcLayerAssignment` per la logica condivisa, commenti accorciati, setup di test deduplicato. Non toccata la duplicazione minima tra `UgcPoiPolicy`/`UgcTrackPolicy` (scelta esplicita, motivata nel piano).
- **Fix label locale-dipendenti** — su segnalazione del dev (screenshot di produzione: `layerLinkField` mostrava "Layer" invece di "Cammino" su alcuni record `UgcTrack`), le label di tutti i field toccati da questo ticket (nuovi e preesistenti: `layerLinkField`, `layerFilterField`, `layerOverrideField`, `layerOverrideOriginalField`) sono state rese stringhe italiane hardcoded invece di passare da `__()` — il locale attivo in produzione non è sempre `it`, quindi la traduzione esistente (`"Layer": "Cammino"` in `resources/lang/it.json`) non è sempre effettiva. Root cause dell'inconsistenza di locale non investigata (fuori scope).
- **Label unificata a "Cammino"** — su richiesta del dev, `layerOverrideField` (editabile) usa la stessa label "Cammino" del link readonly `layerLinkField`, invece di "Assegna cammino": i due field sono mutuamente esclusivi (uno nasconde in Update, l'altro in Detail/Index), nessuna ambiguità visiva.

## Bug trovati

Durante la review formale (5 finder paralleli + verifica diretta sul codice/vendor prima di ogni fix):

1. **`App\Policies\UgcTrackPolicy` sostituiva una policy reale già attiva** (`Wm\WmPackage\Policies\UgcTrackPolicy`, auto-risolta da Laravel via `Gate::guessPolicyName()` — non serve `Gate::guessPolicyNamesUsing()` custom, verificato in `vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:717-737`). La prima stesura era più permissiva (apriva l'accesso a utenti senza ruolo) e più restrittiva per il Validator (che nel package bypassa tutto, non solo `update`). Corretto replicando esattamente il comportamento reale, restringendo solo `update` per il Validator.
2. **Il ramo di idempotenza preesistente di `ResolveUgcLayerJob`** (non toccato dai task originali) inviava comunque la mail se una correzione manuale avveniva prima che il job partisse. Corretto aggiungendo il controllo su `layer_id_auto_resolved` anche in quel ramo.
3. **Il no-op guard del `fillUsing`** confrontava il valore inviato con lo stato attuale del DB invece che con quello mostrato in pagina al render — un salvataggio non correlato dopo una risoluzione automatica concorrente la sovrascriveva silenziosamente. Corretto con un campo Nova nascosto companion (`layerOverrideOriginalField`).
4. **`NewUgcReportMail` crasha su geometria non-Point** (`UgcTrack`) — bug preesistente, non corretto in produzione (fuori scope), solo isolato nei test.
5. **Inconsistenza di locale in produzione** sulla label `layerLinkField` ("Layer" invece di "Cammino") — mitigata hardcodando le label italiane sui field toccati, root cause non investigata.

## Decisioni

- **Guardia anti race-condition bidirezionale**: la guardia originale (Task 4) protegge solo la direzione "job sovrascrive correzione manuale". La review formale ha trovato la direzione opposta (salvataggio non correlato sovrascrive risoluzione automatica) e l'ha trattata con lo stesso livello di priorità (blocker), non come rischio accettato, per consistenza con la prima guardia già implementata.
- **Nessuna estrazione forzata tra `UgcPoiPolicy`/`UgcTrackPolicy`**: le due policy divergono deliberatamente su più ability (Editor+hasUgcEnabled per `UgcTrack`, Validator-only per `UgcPoi`); un'estrazione in una classe base avrebbe introdotto più complessità della duplicazione stessa. Rischio accettato.
- **Root cause dell'inconsistenza di locale non investigata**: la scelta di hardcodare le label in italiano è una mitigazione locale (stesso pattern già in uso da `renderLayerLink()`), non una correzione della causa reale (perché il locale attivo in produzione non è sempre `it`). Segnalabile come follow-up separato se il problema si ripresenta altrove nell'app.

## Follow-up

- Bug preesistente `NewUgcReportMail`/geometria non-Point (trovato in Task 4) — da segnalare al dev per un ticket separato, non risolto qui.
- Inconsistenza di locale in produzione (label "Layer" vs "Cammino") — la causa reale non è nota; se si ripresenta su altri campi/label del progetto, vale la pena un'indagine dedicata invece di continuare a hardcodare stringa per stringa.
- Permesso di modifica per il Validator sul campo layer override, anche solo sui propri layer — rimandato esplicitamente a un ciclo successivo (già in overview.md, Out of scope).

> Ticket: oc:8763

# Passaporto per singolo cammino — piano backend

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** il passaporto vale solo sui cammini con `properties->passport_enabled = true`: flag e
modifica in blocco in Nova, 404 sugli endpoint del cammino spento, `/api/passport` come elenco
dei cammini accesi con `validated_tracks`.

**Architecture:** una sola regola «cammino acceso» in `App\Support\PassportLayer`, usata dal
middleware del gruppo `layer` e da `StageProgressService::passportFor`. Nova legge e scrive lo
stesso campo JSON.

**Tech Stack:** Laravel 11, Nova, PostgreSQL, PHPUnit.

**Spec:** [overview.md](overview.md). Parte app: `wm-core`, stesso slug.

## Global Constraints

- Flag: chiave `passport_enabled` in `layers.properties`; acceso solo se il valore è il booleano
  JSON `true`; assente = spento. Nessuna migration.
- Cammino spento o inesistente → **404** su tutti gli endpoint del gruppo `Route::prefix('layer')`.
- `routes` di `/api/passport`: solo cammini accesi con almeno una tappa associata, anche con 0
  convalidate; ogni elemento ha in più `validated_tracks`, oggetto JSON `{"<ec_track_id>": "<ISO
  8601>"}`, `{}` se vuoto (mai `[]`).
- Numero di query di `passportFor` costante, mai una per cammino.
- Campo Nova e action modificabili solo da `Administrator`.
- Test: `docker exec laravel-camminiditalia php artisan test` (container acceso, DB
  `camminiditalia_testing`).
- Commit: istruzioni per il dev, mai eseguiti in automatico; scope `feat(oc:8763)`.

## Review Focus

1. Tappa condivisa fra un cammino acceso e uno spento: compare in `validated_tracks` del cammino
   acceso, e il cammino spento non compare — test nel Task 2.
2. `validated_tracks` vuoto serializzato come `{}` e non `[]` (l'app lo legge come mappa) — test
   nel Task 2.
3. `passport_enabled` = stringa `"true"` o `1` scritto fuori da Nova: trattato come spento in PHP e
   in SQL allo stesso modo — test nel Task 1.
4. Proprietario del layer senza ruolo `Validator` (es. `Guest`): non può modificare il campo — test
   nel Task 3.
5. Richiesta di certificazione in attesa su un cammino spento: resta decidibile in Nova — test nel
   Task 1 (la decisione Nova non passa dal middleware).

---

### Task 1: Regola «cammino acceso» e middleware 404

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-regola-sql-del-flag)

**Files:**
- Create: `app/Support/PassportLayer.php`
- Create: `app/Http/Middleware/EnsurePassportEnabled.php`
- Modify: `routes/api.php:24-52` (middleware sul gruppo `layer`)
- Modify: `tests/Feature/Helpers/LayerTestHelpers.php` (helper `enablePassport`)
- Modify: `tests/Feature/{StageProgressApiTest,CertificationRequestApiTest,PassportStageShareApiTest,PassportRouteShareApiTest}.php`
- Create: `tests/Feature/PassportLayerGateTest.php`

**Interfaces:**
- Produces: `PassportLayer::isEnabled(Layer $layer): bool` (`=== true` su
  `$layer->properties['passport_enabled']`); `PassportLayer::enabledSql(string $alias = 'layers'): string`
  → `"({$alias}.properties->>'passport_enabled') = 'true'"`; helper di test
  `enablePassport(Layer $layer): Layer` (scrive con `saveQuietly`, così nessun job dell'observer).
- Middleware: legge `$request->route('layer')`, che può essere un modello o un id (dipende
  dall'ordine con `SubstituteBindings`): se modello lo usa, altrimenti `Layer::find`. Assente o
  spento → `abort(404)`.

- [ ] **Step 1: Test che falliscono** in `PassportLayerGateTest`:
  `test_<endpoint>_on_disabled_layer_returns_404` per i 5 endpoint (POST e GET certification,
  GET progress, POST stage share-image, POST share-image), con utente autenticato e cammino spento;
  `test_progress_on_enabled_layer_returns_200`;
  `test_string_true_is_treated_as_disabled` (`properties = ['passport_enabled' => 'true']` → 404);
  `test_pending_request_on_disabled_layer_is_still_decidable_in_nova` (richiesta `pending`,
  cammino spento, la decisione tramite il flusso Nova esistente di `CertificationDecisionControllerTest`
  va a buon fine).
- [ ] **Step 2:** `docker exec laravel-camminiditalia php artisan test --filter=PassportLayerGateTest` → FAIL.
- [ ] **Step 3:** implementare `PassportLayer`, il middleware e aggiungerlo al gruppo in
  `routes/api.php` (classe diretta in `->middleware([...])`, senza alias se non serve).
- [ ] **Step 4:** aggiungere `enablePassport()` ai test API esistenti dei 4 file elencati, dopo
  ogni `createLayer()` usato da un endpoint del gruppo; i test «unknown layer → 404» restano.
- [ ] **Step 5:** `docker exec laravel-camminiditalia php artisan test --filter='PassportLayerGate|StageProgressApi|CertificationRequestApi|PassportStageShareApi|PassportRouteShareApi|CertificationDecision'` → PASS.
- [ ] **Step 6: Commit (istruzione per il dev)** `feat(oc:8763): passaporto solo sui cammini accesi, 404 sugli altri`

### Task 2: `/api/passport` con i cammini accesi e `validated_tracks`

**Files:**
- Modify: `app/Services/StageProgressService.php` (`passportFor`, docblock alle righe 190-196)
- Modify: `tests/Feature/StageProgressApiTest.php`, `tests/Feature/StageProgressServiceTest.php`

**Interfaces:**
- Consumes: `PassportLayer::enabledSql()`.
- Produces: elemento di `routes` = campi di oggi + `validated_tracks` (vedi Global Constraints).

- [ ] **Step 1: Test che falliscono:**
  - `test_passport_lists_enabled_layer_without_validations` — cammino acceso con 2 tappe, utente
    senza convalide → un elemento con `validated: 0`, `total: 2`, `validated_tracks` = `{}` nel
    JSON grezzo (`assertStringContainsString('"validated_tracks":{}', $response->getContent())`).
  - `test_passport_omits_disabled_layer_with_validations` — cammino spento con tappe convalidate → assente.
  - `test_passport_omits_enabled_layer_without_tracks` → assente.
  - `test_validated_tracks_maps_track_id_to_iso_date` — `{"<id>": "2026-09-30T10:00:00+00:00"}`.
  - `test_shared_track_counts_only_in_enabled_layer` — tappa in cammino acceso A e spento B,
    convalidata con `layer_id` = B → A presente con la tappa in `validated_tracks`, B assente.
  - aggiornare `test_passport_without_validations_returns_empty_routes` (nessun cammino acceso →
    `routes: []`) e la `assertExactJsonStructure` di `test_passport_returns_routes_of_logged_user_only`.
- [ ] **Step 2:** `--filter='StageProgressApi|StageProgressService'` → FAIL.
- [ ] **Step 3:** in `passportFor`: join di `userTotalsQuery` con `layers` filtrato da
  `PassportLayer::enabledSql()`, via `havingRaw('COUNT(v.id) > 0')`; `validated_tracks` con
  `json_object_agg(v.ec_track_id, v.validated_at) FILTER (WHERE v.id IS NOT NULL)` nella stessa
  query, date convertite con `isoDate()`, risultato vuoto come `(object) []`. `userTotalsQuery`
  resta invariata per gli altri chiamanti (progress, Lens).
- [ ] **Step 4:** stesso filtro → PASS; poi la suite intera `php artisan test` per i chiamanti di
  `StageProgressService`.
- [ ] **Step 5: Commit (istruzione per il dev)** `feat(oc:8763): /api/passport elenca i cammini accesi con le tappe convalidate`

### Task 3: Nova — campo «Passaporto attivo» e modifica in blocco

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-testo-di-aiuto-e-chiavi)

**Files:**
- Modify: `app/Nova/Layer.php` (`fields()` e `actions()` alla riga 736)
- Modify: `resources/lang/{it,en,de,fr,es}.json`
- Create: `tests/Feature/LayerPassportFieldTest.php`

**Interfaces:**
- Campo: `Boolean::make(__('Passaporto attivo'), 'properties->passport_enabled')`, `readonly` per chi
  non ha il ruolo `Administrator`.
- Action: `(new BulkEditAction(self::class, ['properties->passport_enabled']))` con `canSee` e
  `canRun` solo per `Administrator`, come `app/Nova/EcPoi.php:159-161`.

- [ ] **Step 1: Test che falliscono** (stile `LayerActionsVisibilityTest`):
  `test_admin_sees_editable_passport_field`, `test_validator_sees_readonly_passport_field`,
  `test_guest_owner_sees_readonly_passport_field`, `test_bulk_edit_passport_visible_only_to_admin`,
  `test_bulk_edit_sets_passport_enabled_true_on_selected_layers` (handle dell'action su 2 layer →
  `properties['passport_enabled'] === true`, le altre chiavi di `properties` intatte).
- [ ] **Step 2:** `--filter=LayerPassportFieldTest` → FAIL.
- [ ] **Step 3:** campo, action e traduzione di «Passaporto attivo» nei 5 json.
- [ ] **Step 4:** `--filter='LayerPassportField|LayerActionsVisibility'` → PASS.
- [ ] **Step 5: Commit (istruzione per il dev)** `feat(oc:8763): interruttore del passaporto sul cammino in Nova`

### Task 4: Guida per il cliente

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-versione-breve)

**Files:**
- Create: `docs/guide/passaporto-attivazione/README.md` e gli screenshot nella stessa cartella

- [ ] **Step 1:** testo senza nomi di file, classi o branch: accendere e spegnere il passaporto
  dalla scheda del cammino; accenderlo su più cammini con la modifica in blocco (spiegare le tre
  scelte del menu: Sì, No, nessuna modifica); al rilascio è spento ovunque e il tab Passaporto
  mostra solo un messaggio finché nessun cammino è acceso; prima di spegnere un cammino,
  controllare che non abbia richieste di certificazione in attesa.
- [ ] **Step 2:** screenshot in Nova locale — **passaggio manuale del dev**: scheda del cammino con
  il campo, modale della modifica in blocco.
- [ ] **Step 3:** il dev approva il testo; si prepara la versione breve per la risposta al cliente
  su Orchestrator (fase di chiusura del workflow).
- [ ] **Step 4: Commit (istruzione per il dev)** `docs(oc:8763): guida all'attivazione del passaporto`

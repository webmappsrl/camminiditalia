> Ticket: oc:8671

# Accettazione della richiesta di certificazione e tappe validate — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** il gestore del cammino (o l'Administrator) decide in Nova una richiesta di certificazione — approva con una selezione di tappe o rifiuta, con nota — in modo irreversibile; le tappe approvate finiscono in una nuova tabella, il camminatore riceve una mail nella sua lingua e l'API di stato restituisce l'ultima richiesta in qualsiasi stato.

**Architecture:** tutta la logica di decisione vive in `CertificationRequestService::decide()` (transazione + lock, regole di validità); l'azione Nova è solo raccolta input e chiamata al service. Nuovo modello `App\Models\ValidatedEcTrack` su tabella `validated_ec_tracks`, predisposto per le future validazioni GPS (`source`). Nessuna modifica a `wm-package`.

**Tech Stack:** Laravel, Nova 5, PostgreSQL, Spatie Media Library, PHPUnit (feature test su `camminiditalia_testing`).

**Spec:** [overview.md](overview.md)

## Global Constraints

- Ogni comando artisan/test/composer va eseguito nel container: `docker exec laravel-camminiditalia ...`.
- Nessun commit, push o branch automatico: i passi "Commit" sono istruzioni testuali per il dev, da eseguire solo dopo il review-gate.
- Migration `2026_09_28_150000_create_certification_requests_table.php` **si modifica**, non se ne crea una nuova. Una sola nuova migration: `validated_ec_tracks`.
- Tappe selezionabili = `$layer->ecTracks()` con `ec_tracks.user_id = $layer->user_id ?? config('camminiditalia.default_owner_id')` (stessa regola di `app/Http/Controllers/LayerFeatureController.php:26,75`).
- Lingue ammesse per `locale`: `it`, `en`, `fr`, `es`, `de`; default `it`.
- Ogni nuova stringa `__()` presente in tutti e cinque i file `resources/lang/{it,en,fr,es,de}.json`.
- Stati: costanti esistenti `CertificationRequest::STATUS_PENDING|APPROVED|REJECTED|NONE`. Esiti dell'azione: `approve`, `reject`.
- Test class nuove: `DatabaseTransactions`, `LayerTestHelpers`, `RolesAndPermissionsService::seedDatabase()` e creazione `App` come nei test esistenti di oc:8653.

## Review Focus

- **Doppia decisione** (secondo click / secondo gestore su una richiesta già decisa): errore leggibile, nessuna riga duplicata, stato invariato → test `test_decide_twice_throws_and_changes_nothing` (Task 2).
- **Tappa non del proprietario del layer passata a mano nella POST dell'azione** (manomissione del payload): rifiutata dal service anche se il modale non la mostra → `test_decide_rejects_track_not_owned_by_layer_owner` (Task 2).
- **Richiesta in un nuovo cammino con tappa già validata altrove** (tappa condivisa): la tappa non è selezionabile, e se forzata il vincolo unico diventa errore leggibile, non 500 → `test_decide_already_validated_track_is_readable_error` (Task 2).
- **`Accept-Language` in formato reale o sconosciuto** (`de-DE,de;q=0.9,en;q=0.8`, `pt-BR`, assente) → `test_store_saves_locale_from_accept_language` data provider (Task 3).
- **Esecuzione dell'azione su più richieste insieme** (bulk via API): nessuna scrittura; al massimo il riepilogo di una richiesta → `test_action_on_multiple_requests_writes_nothing` (Task 6).
- **Chiamata diretta alla route di conferma saltando il primo modale** (payload manomesso o richiesta già decisa): il controller rifà autorizzazione e validazione → `test_confirm_with_track_not_selectable_gets_422`, `test_confirm_on_decided_request_gets_403` (Task 6).

---

### Task 0: Branch e schema

**Files:**
- Modify: `database/migrations/2026_09_28_150000_create_certification_requests_table.php`
- Create: `database/migrations/2026_09_30_120000_create_validated_ec_tracks_table.php`
- Create: `app/Models/ValidatedEcTrack.php`
- Modify: `app/Models/CertificationRequest.php`
- Test: `tests/Feature/ValidatedEcTrackModelTest.php`, `tests/Feature/CertificationRequestModelTest.php`

**Interfaces:**
- Produces: `ValidatedEcTrack` (`SOURCE_MANUAL = 'manual'`, `SOURCE_GPS = 'gps'`), relazioni `user()`, `ecTrack()`, `layer()`, `certificationRequest()`; su `CertificationRequest`: `validatedTracks(): HasMany<ValidatedEcTrack>`, `decidedBy(): BelongsTo<User>`, costanti `SUPPORTED_LOCALES = ['it','en','fr','es','de']`, `DEFAULT_LOCALE = 'it'`, `isPending(): bool`; nuovi `$fillable`: `decision_note`, `decided_at`, `decided_by`, `locale`; cast `decided_at` datetime.

- [ ] **Step 1: Creare il branch** a partire dal branch di oc:8653 (istruzione per il dev, non automatica): `git checkout -b feature/oc-8671-passaporto-camminatore-accettazione-della-richiesta-di-certificazione-e-tappe-validate` da `feature/oc-8653-...`.
- [ ] **Step 2: Pulire i media locali e fare rollback.** Prima del rollback cancellare via Eloquent le richieste locali (il drop della tabella non passa da `InteractsWithMedia` e lascerebbe righe in `media` e file):
  `docker exec laravel-camminiditalia php artisan tinker --execute="App\Models\CertificationRequest::all()->each->delete();"`
  poi `docker exec laravel-camminiditalia php artisan migrate:rollback --path=database/migrations/2026_09_28_150000_create_certification_requests_table.php`. Expected: `Rolling back ... DONE`.
- [ ] **Step 3: Scrivere i test del modello** — `test_validated_ec_track_relations_resolve`, `test_unique_user_ec_track_is_enforced` (seconda insert stessa coppia → `UniqueConstraintViolationException`), `test_deleting_certification_request_nulls_validated_track_fk` (la riga resta, `certification_request_id` null), `test_deleting_user_cascades_validated_tracks`; in `CertificationRequestModelTest`: `test_locale_defaults_to_it`, `test_decided_by_relation`.
- [ ] **Step 4: Eseguire** `docker exec laravel-camminiditalia php artisan test --filter='ValidatedEcTrackModelTest|CertificationRequestModelTest'` → FAIL (tabella/colonne mancanti).
- [ ] **Step 5: Modificare la migration esistente**: dopo `disclaimer_accepted_at` aggiungere `string('locale', 5)->default('it')`, `text('decision_note')->nullable()`, `timestamp('decided_at')->nullable()`, `foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete()`.
- [ ] **Step 6: Nuova migration** `validated_ec_tracks`: `id`; `user_id` FK users `cascadeOnDelete`; `ec_track_id` FK ec_tracks `cascadeOnDelete`; `layer_id` FK layers `cascadeOnDelete`; `certification_request_id` FK certification_requests nullable `nullOnDelete`; `string('source')->default('manual')`; `timestamp('validated_at')`; timestamps; `unique(['user_id','ec_track_id'])`; indice su `certification_request_id`.
- [ ] **Step 7: Modello e relazioni** come da Interfaces. `ecTrack()` punta a `config('wm-package.ec_track_model')` (come `Layer::ecTracks()`).
- [ ] **Step 8:** `docker exec laravel-camminiditalia php artisan migrate` e `php artisan migrate --env=testing`; rieseguire i test dello Step 4 → PASS.
- [ ] **Step 9: Commit (istruzione per il dev)** `feat(oc:8671): schema decisione richieste e tappe validate`.

### Task 1: Tappe selezionabili

**Files:**
- Modify: `app/Services/CertificationRequestService.php`
- Test: `tests/Feature/CertificationRequestServiceTest.php`

**Interfaces:**
- Produces:
  - `selectableTracks(CertificationRequest $request): Collection<int, EcTrack>` — tappe del layer del proprietario del layer, **escluse** quelle già in `validated_ec_tracks` per `request->user_id`, ordinate per etichetta con ordinamento naturale case-insensitive (`strnatcasecmp`).
  - `alreadyValidatedTracks(CertificationRequest $request): Collection<int, EcTrack>` — tappe dello stesso insieme già validate per l'utente, stesso ordinamento.
  - `trackLabel(EcTrack $track): string` — `getTranslation('name', app()->getLocale(), true)`, fallback alla prima traduzione non vuota, poi `"#{id}"`.

- [ ] **Step 1: Test** — `test_selectable_tracks_only_owned_by_layer_owner` (layer con 3 tappe del proprietario + 1 di altro utente → 3), `test_selectable_tracks_use_default_owner_when_layer_has_no_owner`, `test_selectable_tracks_natural_order` (nomi `Tappa 10`, `Tappa 2`, `tappa 1` → `tappa 1`, `Tappa 2`, `Tappa 10`), `test_already_validated_tracks_are_excluded_and_listed_separately`.
- [ ] **Step 2:** eseguire `--filter=CertificationRequestServiceTest` → FAIL.
- [ ] **Step 3:** implementare i tre metodi.
- [ ] **Step 4:** rieseguire → PASS.
- [ ] **Step 5: Commit (istruzione)** `feat(oc:8671): tappe selezionabili per la decisione`.

### Task 2: Decisione

**Files:**
- Create: `app/Exceptions/CertificationDecisionException.php`
- Modify: `app/Services/CertificationRequestService.php`
- Test: `tests/Feature/CertificationRequestDecisionTest.php`

**Interfaces:**
- Consumes: `selectableTracks()` (Task 1), `ValidatedEcTrack` (Task 0).
- Produces: `decide(CertificationRequest $request, User $decider, string $outcome, array $ecTrackIds, bool $selectAll, ?string $note): CertificationRequest`. `CertificationDecisionException extends RuntimeException` con messaggio già tradotto (`__()`), usato da Nova come `Action::danger($e->getMessage())`. Dispatcha `SendCertificationDecisionMailJob::dispatch($request)->afterCommit()` (classe creata nel Task 5: in questo task il test usa `Queue::fake()` e asserisce solo il push; creare il job vuoto qui se serve a far compilare, riempirlo nel Task 5).

Regole (in `DB::transaction`, rileggendo la richiesta con `lockForUpdate()`):
- stato ≠ `pending` → eccezione `This request has already been decided.`
- `outcome` non in `['approve','reject']` → eccezione.
- `approve`: tappe = tutte le `selectableTracks()` se `$selectAll`, altrimenti `$ecTrackIds`; vuote → `Select at least one stage to approve.`; qualsiasi id non in `selectableTracks()` → `Some selected stages cannot be validated for this request.`; inserisce una riga `ValidatedEcTrack` per tappa (`source=manual`, `validated_at=now()`, `layer_id` della richiesta, `certification_request_id`); `UniqueConstraintViolationException` → eccezione con lo stesso messaggio.
- `reject`: ignora tappe e `$selectAll`.
- scrive `status`, `decided_at=now()`, `decided_by`, `decision_note` (stringa vuota → null).

- [ ] **Step 1: Test** — `test_approve_with_selected_tracks`, `test_approve_select_all_takes_every_selectable_track`, `test_approve_without_tracks_throws`, `test_reject_ignores_tracks_and_saves_note`, `test_decide_twice_throws_and_changes_nothing`, `test_decide_rejects_track_not_owned_by_layer_owner`, `test_decide_rejects_track_of_another_layer`, `test_decide_already_validated_track_is_readable_error`, `test_decision_mail_job_dispatched_after_commit` (`Queue::fake()`), `test_after_decision_user_can_submit_new_request` (indice parziale sui pending).
- [ ] **Step 2:** eseguire `--filter=CertificationRequestDecisionTest` → FAIL.
- [ ] **Step 3:** implementare eccezione e `decide()`.
- [ ] **Step 4:** rieseguire → PASS.
- [ ] **Step 5: Commit (istruzione)** `feat(oc:8671): decisione della richiesta di certificazione`.

### Task 3: API — lingua al POST e ultima richiesta al GET

**Files:**
- Modify: `app/Services/CertificationRequestService.php` (`submit()` riceve `string $locale`; `currentFor()` → `latestFor()`)
- Modify: `app/Http/Controllers/Api/CertificationRequestController.php`
- Test: `tests/Feature/CertificationRequestApiTest.php`, `tests/Feature/CertificationRequestServiceTest.php`

**Interfaces:**
- Produces: `latestFor(User $user, Layer $layer): ?CertificationRequest` (qualsiasi stato, `latest('id')`); `resolveLocale(?string $acceptLanguage): string` nel controller o come metodo statico di `CertificationRequest` — usa `Request::getPreferredLanguage(CertificationRequest::SUPPORTED_LOCALES)` solo se l'header contiene una lingua supportata, altrimenti `DEFAULT_LOCALE`. Risposta JSON: `{status, submitted_at, decided_at, decision_note}` (`decided_at` ISO 8601 o null); `{status: "none"}` se nessuna richiesta.

- [ ] **Step 1: Test** — aggiornare `test_show_returns_pending_with_submitted_at` a `assertExactJson` con `decided_at: null`, `decision_note: null`; nuovi `test_show_returns_latest_approved_request_with_decision`, `test_show_returns_latest_rejected_request_with_note`, `test_show_returns_most_recent_when_multiple` (rifiutata poi nuova pending → pending); `test_store_saves_locale_from_accept_language` con provider: `de-DE,de;q=0.9,en;q=0.8`→`de`, `fr`→`fr`, `pt-BR`→`it`, assente→`it`, `EN-us`→`en`. Aggiornare le chiamate a `submit()` nei test del service.
- [ ] **Step 2:** eseguire `--filter='CertificationRequestApiTest|CertificationRequestServiceTest'` → FAIL.
- [ ] **Step 3:** implementare.
- [ ] **Step 4:** rieseguire → PASS.
- [ ] **Step 5: Commit (istruzione)** `feat(oc:8671): lingua della richiesta e stato dell'ultima richiesta`.

### Task 4: Policy — cancellazione solo delle richieste pending

**Files:**
- Modify: `app/Policies/CertificationRequestPolicy.php`
- Test: `tests/Feature/CertificationRequestPolicyTest.php`, `tests/Feature/CertificationRequestNovaTest.php`

- [ ] **Step 1: Test** — nella matrice, `administrator delete` resta `true` sulla richiesta pending; nuovo `test_administrator_cannot_delete_decided_request` con provider `approved`/`rejected`; nuovo `test_nobody_can_delete_decided_request_from_nova` (DELETE `/nova-api/certification-requests?resources[]=id` come Administrator → richiesta ancora presente).
- [ ] **Step 2:** eseguire `--filter='CertificationRequestPolicyTest|CertificationRequestNovaTest'` → FAIL.
- [ ] **Step 3:** `ADMINISTRATOR_ABILITIES = ['viewAny', 'view']`; `delete()` → `$user->hasRole('Administrator') && $request->isPending()`.
- [ ] **Step 4:** rieseguire → PASS.
- [ ] **Step 5: Commit (istruzione)** `feat(oc:8671): richieste decise non cancellabili`.

### Task 5: Mail di esito al camminatore

**Files:**
- Create: `app/Mail/CertificationDecisionMail.php`, `resources/views/emails/certification-decision.blade.php`
- Modify/Create: `app/Jobs/SendCertificationDecisionMailJob.php`
- Test: `tests/Feature/CertificationDecisionMailTest.php`

**Interfaces:**
- Consumes: `CertificationRequest` decisa (Task 2), `trackLabel()` (Task 1).
- Produces: `CertificationDecisionMail(CertificationRequest $request)` — nel costruttore `$this->locale($request->locale ?: CertificationRequest::DEFAULT_LOCALE)`; soggetto `__('Your certification request for :route has been approved'|'... rejected', ['route' => $layer->getStringName()])`; vista: esito, elenco etichette delle tappe validate da questa richiesta (solo se approvata), nota del gestore se presente (escapata con `{{ }}`). Job: `tries = 3`, `deleteWhenMissingModels = true` (come il job esistente), `Mail::to($request->user->email)->send(...)`; se l'utente non ha email → `Log::warning` e return.

- [ ] **Step 1: Test** — `test_job_sends_mail_to_walker`, `test_mail_uses_request_locale` (locale `de` → `$mail->locale === 'de'` e soggetto tradotto in tedesco), `test_approved_mail_lists_validated_stages`, `test_rejected_mail_contains_note_and_no_stages`, `test_note_is_escaped` (`<script>` non compare non escapato in `render()`).
- [ ] **Step 2:** eseguire `--filter=CertificationDecisionMailTest` → FAIL.
- [ ] **Step 3:** implementare mail, vista, job.
- [ ] **Step 4:** rieseguire → PASS.
- [ ] **Step 5: Commit (istruzione)** `feat(oc:8671): mail di esito al camminatore`.

### Task 6: Nova — azione "Decidi richiesta" in due passaggi e tappe validate nel dettaglio

Pattern di riferimento: `wm-package/src/Nova/Actions/ImportTaxonomyWhere.php:287-313` (`Action::modal()` con due argomenti apre un secondo modale), componente `wm-package/resources/js/geohub-where-selection.js` (render function, nessun build), registrazione `Nova::script` + route `nova-vendor/...` in `wm-package/src/WmPackageServiceProvider.php:116,144`.

**Files:**
- Create: `app/Nova/Actions/DecideCertificationRequest.php`
- Create: `app/Http/Controllers/Nova/CertificationDecisionController.php`
- Create: `resources/js/certification-decision-confirm.js`
- Modify: `app/Providers/NovaServiceProvider.php` (`Nova::script('certification-decision-confirm', resource_path('js/certification-decision-confirm.js'))` + route `Route::middleware(['nova'])->prefix('nova-vendor/certification-decision')->post('/confirm', ...)`)
- Create: `app/Nova/ValidatedEcTrack.php`
- Modify: `app/Nova/CertificationRequest.php`
- Test: `tests/Feature/CertificationRequestNovaActionTest.php`, `tests/Feature/CertificationDecisionControllerTest.php`

**Interfaces:**
- Consumes: `selectableTracks()`, `alreadyValidatedTracks()`, `trackLabel()` (Task 1), `decide()` + `CertificationDecisionException` (Task 2).
- Produces:
  - action `uriKey()` = `decide-certification-request`; costante `CONFIRM_MODAL_COMPONENT = 'certification-decision-confirm-modal'` (identica alla stringa di `app.component(...)` nel JS).
  - `CertificationRequestService::previewDecision(CertificationRequest $request, string $outcome, array $ecTrackIds, bool $selectAll, ?string $note): array` — stesse validazioni di `decide()` **senza scrivere**, restituisce `['outcome', 'ec_track_ids' => int[], 'track_labels' => string[], 'walker' => string, 'route' => string, 'note' => ?string]`; `decide()` riusa la stessa validazione (metodo privato condiviso) per non duplicare le regole.
  - `POST /nova-vendor/certification-decision/confirm` body `{certification_request_id, outcome, ec_track_ids: int[], decision_note}` → 200 `{message}` | 403 | 422 `{message}`.

Primo passaggio (azione):
- `->sole()`; `canSee`/`canRun`: `$request->user()->can('view', $model) && $model->isPending()`.
- `fields()`: la richiesta si risolve dalla singola risorsa selezionata della `NovaRequest`; campi `Select outcome` (`approve`/`reject`, required), testo sola lettura "Già validate: …" (nascosto se vuoto), `Boolean select_all`, `BooleanGroup tracks` (opzioni `id => trackLabel` nell'ordine di `selectableTracks()`), `Textarea decision_note`; `select_all` e `tracks` visibili solo con `approve` via `dependsOn(['outcome'], ...)`.
- `handle()`: **non scrive nulla**; chiama `previewDecision()`; `CertificationDecisionException` → `Action::danger($e->getMessage())`; altrimenti `Action::modal(self::CONFIRM_MODAL_COMPONENT, [...preview, 'certification_request_id', 'labels' => [...stringhe già tradotte lato server...]])`.

Secondo passaggio (componente JS):
- Riepilogo: «Stai approvando :count tappe per :walker (:route)» con elenco etichette, oppure «Stai rifiutando la richiesta di :walker (:route)»; nota se presente; avviso di irreversibilità; pulsanti *Annulla* / *Conferma decisione* (disabilitati durante la chiamata); su 200 mostra il messaggio (`Nova.success`) e ricarica la pagina della risorsa; su 4xx mostra `message` (`Nova.error`). Tutti i testi arrivano tradotti nel payload (`labels`), come nell'esempio.

Controller di conferma:
- Ricarica la richiesta; **rifà l'autorizzazione** (`can('view')` + `isPending()`, altrimenti 403) senza fidarsi del primo passaggio; chiama `decide($request, $user, $outcome, $ecTrackIds, false, $note)` (gli id arrivano già risolti dal preview, quindi `selectAll=false`); `CertificationDecisionException` → 422.

Risorsa `App\Nova\ValidatedEcTrack`: `$displayInNavigation = false`; `authorizedToCreate/Update/Delete/Replicate` → false; campi: tappa (etichetta), data validazione, origine; `indexQuery` limitato alle righe la cui richiesta è `visibleTo($user)`.
Su `App\Nova\CertificationRequest::fields()`: `Badge` con label per `approved`/`rejected`; decisore (link solo per Administrator, testo per gli altri, come Walker); `decided_at`; `decision_note` sola lettura; `HasMany::make(__('Validated stages'), 'validatedTracks', ValidatedEcTrack::class)`. `actions()` → `[new DecideCertificationRequest]`.

- [ ] **Step 1: Test azione** (pattern `EcPoiNovaActionsTest`) — `test_action_returns_confirm_modal_without_writing` (risposta con `modal` = componente, nessuna riga in `validated_ec_tracks`, stato ancora `pending`), `test_action_preview_errors_are_danger_messages` (approve senza tappe), `test_foreign_validator_cannot_run` (403/404), `test_guest_cannot_run`, `test_action_not_available_on_decided_request`, `test_action_cannot_run_on_multiple_requests`, `test_action_fields_list_selectable_tracks_in_natural_order`, `test_detail_shows_validated_stages_and_decision`, `test_validated_ec_track_resource_is_read_only`.
- [ ] **Step 2: Test controller** — `test_owner_validator_confirms_approval`, `test_administrator_confirms_rejection_with_note`, `test_foreign_validator_gets_403`, `test_guest_gets_403`, `test_confirm_on_decided_request_gets_403`, `test_confirm_with_track_not_selectable_gets_422` (payload manomesso), `test_confirm_requires_nova_auth` (non autenticato → redirect/401).
- [ ] **Step 3:** eseguire `--filter='CertificationRequestNovaActionTest|CertificationDecisionControllerTest'` → FAIL.
- [ ] **Step 4:** implementare `previewDecision()`, azione, controller, route, script, risorsa, campi.
- [ ] **Step 5:** rieseguire → PASS.
- [ ] **Step 6: Prova manuale in browser** come Validator proprietario: primo modale (`dependsOn` esito ↔ tappe, "Seleziona tutte"), secondo modale con riepilogo corretto, *Annulla* non scrive nulla, *Conferma* scrive e ricarica, dettaglio con tappe validate. Riportare l'esito al dev.
- [ ] **Step 7: Commit (istruzione)** `feat(oc:8671): azione Nova di decisione in due passaggi e tappe validate`.

### Task 7: Traduzioni, qualità, note

**Files:**
- Modify: `resources/lang/{it,en,fr,es,de}.json`
- Create: `docs/features/8671-.../notes.md`

- [ ] **Step 1:** estrarre tutte le nuove chiavi `__('...')` introdotte (`git diff | grep -o "__('[^']*'"`) e aggiungerle ai cinque file JSON; verificare che nessuna chiave manchi in nessun file (script `php -r` che confronta le chiavi nuove con ciascun JSON).
- [ ] **Step 2:** `docker exec laravel-camminiditalia composer format`.
- [ ] **Step 3:** `docker exec laravel-camminiditalia vendor/bin/phpstan analyse --error-format=table` → nessun errore sui file del diff.
- [ ] **Step 4:** suite completa `docker exec laravel-camminiditalia php artisan test` → 0 falliti.
- [ ] **Step 5:** scrivere `notes.md` con la sezione **Procedura manuale di correzione di una decisione** (SQL per Administrator/sviluppatore, in transazione): cancellazione delle righe `validated_ec_tracks` con `certification_request_id = :id`, ripristino di `status='pending'`, `decided_at/decided_by/decision_note = NULL` sulla richiesta; avvertenza che la mail già inviata non si annulla e che la riapertura fallisce se esiste già un'altra richiesta pending per la stessa coppia utente-layer.
- [ ] **Step 6: Commit (istruzione)** `feat(oc:8671): traduzioni e note`.

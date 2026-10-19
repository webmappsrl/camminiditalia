> Ticket: oc:8653

# Passaporto camminatore: backend della validazione della credenziale cartacea — Piano di implementazione

> ⚠️ **Piano superato in parte.** Dopo la review finale le foto sono passate alla media library come gli UGC (tabella `media`, collection `default`): le sezioni *Architecture*, *Global Constraints* e *Review Focus* qui sotto, che parlano di «nessuna media library», visibilità `private`, `CertificationRequestImage` e dei relativi test, non valgono più. Lo stato reale e i motivi sono in [notes.md](notes.md), sezioni «Divergenze dal piano, task per task» e «Decisioni».

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> ⚠️ **Webmapp:** nessun `git add` / `git commit` / `git push` / creazione di branch durante l'esecuzione. I passi «Commit» sono **istruzioni testuali per il dev**, eseguite solo dopo il review-gate.

**Goal:** Il camminatore invia dall'app le foto della credenziale cartacea timbrata; il backend le salva in modo privato, avvisa via email il gestore del cammino e le mostra nella sezione Nova «Validazioni», scopata per gestore.

**Architecture:** Tutto nel repo principale `camminiditalia`, nessuna modifica a `wm-package`. Due modelli nuovi (`CertificationRequest` + `CertificationRequestImage`) con migration custom. I file vanno sullo stesso disco degli UGC (`config('media-library.disk_name')` = `wmfe`) ma **fuori dalla media library**, con visibilità `private`. Un service contiene la logica di invio atomica; controller API su un nuovo `routes/api.php`; job + Mailable per l'email; risorsa Nova + policy per il backoffice; observer `deleting` su User/Layer per la pulizia dei file.

**Tech Stack:** Laravel 12.50, Nova 5.7, PostgreSQL/PostGIS, tymon/jwt-auth (guard `api`), S3 (`wmfe`), PHPUnit (`tests/Feature`, `DatabaseTransactions`).

**Spec:** [overview.md](overview.md)

## Global Constraints

- Nessuna modifica a `wm-package/` (né submodule né `../wm-package`).
- Nessun uso di `spatie/laravel-medialibrary` per le foto delle richieste: nessuna riga nella tabella `media`.
- Disco: `config('media-library.disk_name')`; prefisso `certification-requests/{uuid}/`; visibilità `private`.
- `images[]`: da 1 a 6 file, `mimes:jpeg,png,webp,heic`, `max:8192` (KB) ciascuno.
- `serial_number`: opzionale, `string`, `max:255`; colonna `text` nullable.
- `disclaimer_accepted`: obbligatorio, regola `accepted`.
- Stato: stringa, unico valore scritto in questo ciclo `pending`. Costante `CertificationRequest::STATUS_PENDING = 'pending'`.
- Indice unico parziale `(user_id, layer_id) WHERE status = 'pending'`.
- Email: proprietario del layer (`layerOwner`), ripiego `info@camminiditalia.org`; link a `/nova/resources/certification-requests/{id}`; nessuna foto e nessun URL di foto.
- Nova: Administrator vede tutto e può eliminare; Validator vede solo le richieste dei layer con `layers.user_id = $user->id`, sola lettura; ogni altro ruolo nulla; creazione e modifica vietate a tutti.
- Traduzioni: ogni stringa nuova in `resources/lang/{it,en,fr,es,de}.json`.
- Test sempre dentro il container: `docker exec laravel-camminiditalia php artisan test --filter=...`.

## Review Focus

1. **Due POST simultanei dallo stesso utente sullo stesso cammino:** il secondo deve ricevere 409 e non lasciare file su disco, anche se il pre-check è passato → test `test_unique_violation_after_precheck_returns_conflict_and_cleans_files` (Task 2).
2. **Scrittura DB che fallisce dopo l'upload:** nessuna riga e nessun file residuo → test `test_db_failure_after_upload_removes_uploaded_files` (Task 2).
3. **Body oltre `post_max_size`:** l'app deve ricevere un 413 JSON, non un redirect HTML o un 422 fuorviante → test `test_post_too_large_returns_json_413` (Task 3).
4. **Un Validator che apre a mano l'URL del dettaglio di una richiesta di un altro cammino:** 403/404 e nessun URL temporaneo generato → test `test_validator_cannot_view_request_of_foreign_layer` (Task 5).
5. **Cancellazione account dall'app (`POST /api/auth/delete`):** le foto dell'utente spariscono dal disco → test `test_deleting_user_removes_request_files` (Task 6).

---

### Task 1: Migration e modelli

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-migration-e-modelli)

**Files:**
- Create: `database/migrations/2026_09_28_150000_create_certification_requests_table.php`
- Create: `database/migrations/2026_09_28_150001_create_certification_request_images_table.php`
- Create: `app/Models/CertificationRequest.php`
- Create: `app/Models/CertificationRequestImage.php`
- Test: `tests/Feature/CertificationRequestModelTest.php`

**Interfaces:**
- Produces:
  - `App\Models\CertificationRequest` — tabella `certification_requests`: `id`, `user_id` (FK `users`, `cascadeOnDelete`), `layer_id` (FK `layers`, `cascadeOnDelete`), `status` (string, default `pending`, index), `serial_number` (text nullable), `disclaimer_accepted_at` (timestamp), `timestamps`. Indice unico parziale via `DB::statement('CREATE UNIQUE INDEX certification_requests_one_pending_per_user_layer ON certification_requests (user_id, layer_id) WHERE status = \'pending\'')`.
  - `const STATUS_PENDING = 'pending'`; `$fillable = ['user_id','layer_id','status','serial_number','disclaimer_accepted_at']`; cast `disclaimer_accepted_at` → `datetime`.
  - `user(): BelongsTo` (`Wm\WmPackage\Models\User`), `layer(): BelongsTo` (`Wm\WmPackage\Models\Layer`), `images(): HasMany` ordinata per `position`.
  - `scopePending(Builder $q): Builder`.
  - Evento `deleting`: per ogni immagine chiama `$image->delete()` (vedi sotto), così i file vengono rimossi prima che il cascade tolga le righe.
  - `App\Models\CertificationRequestImage` — tabella `certification_request_images`: `id`, `certification_request_id` (FK `cascadeOnDelete`), `disk` (string), `path` (string), `original_name` (string), `mime_type` (string), `size` (unsignedInteger), `position` (unsignedSmallInteger), `timestamps`.
  - `request(): BelongsTo`; evento `deleted`: `Storage::disk($this->disk)->delete($this->path)` in try/catch con `Log::warning(...)` (un errore di storage non blocca la cancellazione).
  - `temporaryUrl(int $minutes = 10): string` → `Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes($minutes))`.

- [ ] **Step 1: Scrivere i test che falliscono**

`CertificationRequestModelTest` (`DatabaseTransactions`, `RolesAndPermissionsService::seedDatabase()`, `LayerTestHelpers`, `Storage::fake('wmfe')` e `config(['media-library.disk_name' => 'wmfe'])`):
- `test_second_pending_request_for_same_user_and_layer_violates_unique_index`: crea una richiesta `pending`, la seconda `create()` con stessi `user_id`/`layer_id` lancia `Illuminate\Database\UniqueConstraintViolationException`.
- `test_new_pending_request_allowed_when_previous_is_not_pending`: prima richiesta con `status = 'rejected'` (valore futuro, scritto a mano nel test), la seconda `pending` viene creata.
- `test_deleting_request_removes_image_files`: crea richiesta + 2 immagini con file `Storage::disk('wmfe')->put(...)`; `$request->delete()`; `Storage::disk('wmfe')->assertMissing($path)` per entrambi e 0 righe in `certification_request_images`.

- [ ] **Step 2: Eseguire i test e verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=CertificationRequestModelTest`
Expected: FAIL (classe `App\Models\CertificationRequest` inesistente).

- [ ] **Step 3: Implementare migration e modelli** come da Interfaces. Il `down()` della prima migration fa `dropIfExists` (l'indice parziale cade con la tabella). Migrare anche il DB di test: `docker exec laravel-camminiditalia php artisan migrate --env=testing`.

- [ ] **Step 4: Eseguire i test e verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter=CertificationRequestModelTest`
Expected: PASS.

- [ ] **Step 5: Commit (istruzione per il dev)**

```bash
git add database/migrations/2026_09_28_1500*_create_certification_*.php app/Models/CertificationRequest*.php tests/Feature/CertificationRequestModelTest.php
git commit -m "feat(oc:8653): modelli e migration delle richieste di certificazione"
```

---

### Task 2: Service di invio atomico

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-service-di-invio-atomico)

**Files:**
- Create: `app/Services/CertificationRequestService.php`
- Create: `app/Exceptions/PendingCertificationRequestExistsException.php`
- Test: `tests/Feature/CertificationRequestServiceTest.php`

**Interfaces:**
- Consumes: modelli del Task 1.
- Produces:
  - `CertificationRequestService::submit(User $user, Layer $layer, array $images, ?string $serialNumber): CertificationRequest` — `$images` è `UploadedFile[]`. Lancia `PendingCertificationRequestExistsException` se esiste già una `pending` per `(user, layer)`.
  - `CertificationRequestService::currentFor(User $user, Layer $layer): ?CertificationRequest` — ultima `pending` o `null`.

Algoritmo di `submit()` (l'ordine è la decisione della challenge):
1. Pre-check: se `CertificationRequest::pending()->where(user,layer)->exists()` → eccezione, **nessun upload**.
2. `$disk = config('media-library.disk_name')`, `$dir = 'certification-requests/'.Str::uuid()`; per ogni file `Storage::disk($disk)->putFile($dir, $file, 'private')`, accumulando i path. Se un upload fallisce (ritorno `false` o eccezione) → cancella i path accumulati e rilancia.
3. `DB::transaction(...)`: crea la richiesta (`status` pending, `disclaimer_accepted_at = now()`) e le righe immagine (`position` = indice 0-based).
4. Se la transazione lancia `UniqueConstraintViolationException` → cancella i file e lancia `PendingCertificationRequestExistsException`; qualunque altra `Throwable` → cancella i file e rilancia.
5. Dopo il commit: `SendCertificationRequestMailJob::dispatch($request)` (introdotto nel Task 4; in questo task lasciare solo il punto di aggancio, aggiunto al Task 4).

- [ ] **Step 1: Scrivere i test che falliscono** (`Storage::fake('wmfe')`, `UploadedFile::fake()->image('c1.jpg')`):
- `test_submit_stores_request_and_private_files`: 2 immagini → 1 richiesta `pending`, `serial_number` salvato, 2 righe immagine con `position` 0 e 1, file presenti sotto `certification-requests/`; `Storage::disk('wmfe')->getVisibility($path) === 'private'`.
- `test_submit_with_existing_pending_throws_and_uploads_nothing`: richiesta pending già presente → `PendingCertificationRequestExistsException`; `Storage::disk('wmfe')->allFiles('certification-requests')` vuoto.
- `test_db_failure_after_upload_removes_uploaded_files`: passa un `Layer` non persistito con `id = 999999` (violazione FK nella transazione) → eccezione rilanciata, 0 righe, `allFiles('certification-requests')` vuoto.
- `test_unique_violation_after_precheck_returns_conflict_and_cleans_files`: simula la corsa creando la richiesta pending concorrente dentro un listener `CertificationRequest::creating` registrato solo nel test (inserisce via `DB::table` una riga pending per la stessa coppia la prima volta che scatta) → `PendingCertificationRequestExistsException` e nessun file residuo.

- [ ] **Step 2: Eseguire i test e verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=CertificationRequestServiceTest`
Expected: FAIL (classe service inesistente).

- [ ] **Step 3: Implementare service ed eccezione** come da Interfaces e algoritmo.

- [ ] **Step 4: Eseguire i test e verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter=CertificationRequestServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit (istruzione per il dev)**

```bash
git add app/Services/CertificationRequestService.php app/Exceptions/PendingCertificationRequestExistsException.php tests/Feature/CertificationRequestServiceTest.php
git commit -m "feat(oc:8653): invio atomico della richiesta di certificazione"
```

---

### Task 3: API `certification` (route, controller, validazione, 413)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-api-certification-route-controller-validazione-413)

**Files:**
- Create: `routes/api.php`
- Modify: `bootstrap/app.php` (`withRouting(api: __DIR__.'/../routes/api.php')`, `withExceptions` per 413)
- Create: `app/Http/Controllers/Api/CertificationRequestController.php`
- Create: `app/Http/Requests/StoreCertificationRequestRequest.php`
- Test: `tests/Feature/CertificationRequestApiTest.php`

**Interfaces:**
- Consumes: `CertificationRequestService::submit()` / `currentFor()`, `PendingCertificationRequestExistsException`.
- Produces:
  - `POST /api/layer/{layer}/certification` → nome `camminiditalia.api.layer.certification.store`, middleware `auth:api`.
  - `GET /api/layer/{layer}/certification` → nome `camminiditalia.api.layer.certification.show`, middleware `auth:api`.
  - `{layer}` con binding esplicito su `Wm\WmPackage\Models\Layer` (`Route::model` o type-hint) → 404 se assente.
  - Risposte: store 201 `{"status":"pending","submitted_at":"<ISO 8601>"}`; show 200 `{"status":"none"}` oppure `{"status":"pending","submitted_at":"<ISO 8601>"}`; 409 `{"message": __('A certification request is already pending for this route.')}`.
  - `withExceptions`: `PostTooLargeException` su `$request->is('api/*')` → `response()->json(['message' => __('The uploaded files are too large.')], 413)`.

Nota: prima di scrivere `routes/api.php` verificare con `docker exec laravel-camminiditalia php artisan route:list --path=api/layer` che nessuna route del package sia già registrata su `api/layer/{layer}/certification`, e che dopo la modifica le route `layer.favorite.*` del package siano ancora presenti.

- [ ] **Step 1: Scrivere i test che falliscono** (`Storage::fake('wmfe')`, `Queue::fake()`, utente autenticato con `$this->actingAs($user, 'api')`, header `Accept: application/json`):
- `test_store_returns_201_with_pending_status`
- `test_store_without_auth_returns_401`
- `test_store_on_missing_layer_returns_404`
- `test_store_validation`: data provider con 0 immagini, 7 immagini, file `pdf`, immagine da 9000 KB (`UploadedFile::fake()->create('x.jpg', 9000, 'image/jpeg')`), `serial_number` da 256 caratteri, `disclaimer_accepted` assente → 422 con errore sulla chiave attesa.
- `test_store_ignores_notes_field_and_saves_serial_number`: invia `notes` e `serial_number` → salvato solo `serial_number`.
- `test_store_with_existing_pending_returns_409`
- `test_show_returns_none_without_request` / `test_show_returns_pending_with_submitted_at`
- `test_show_does_not_leak_other_users_request`: la pending di un altro utente sullo stesso layer → `none`.
- `test_post_too_large_returns_json_413`: richiesta con header `CONTENT_LENGTH` oltre `post_max_size` → 413 JSON.
- `test_package_layer_favorite_routes_still_registered`: `Route::has('layer.favorite.toggle')` o nome effettivo letto con `route:list`.

- [ ] **Step 2: Eseguire i test e verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=CertificationRequestApiTest`
Expected: FAIL (route inesistenti, 404).

- [ ] **Step 3: Implementare route, FormRequest, controller e gestore 413.** Il controller mappa `PendingCertificationRequestExistsException` → 409. `submitted_at` = `created_at->toIso8601String()`.

- [ ] **Step 4: Eseguire i test e verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter=CertificationRequestApiTest`
Expected: PASS.

- [ ] **Step 5: Commit (istruzione per il dev)**

```bash
git add routes/api.php bootstrap/app.php app/Http/Controllers/Api/CertificationRequestController.php app/Http/Requests/StoreCertificationRequestRequest.php tests/Feature/CertificationRequestApiTest.php
git commit -m "feat(oc:8653): API di invio e stato della richiesta di certificazione"
```

---

### Task 4: Email al gestore

**Files:**
- Create: `app/Jobs/SendCertificationRequestMailJob.php`
- Create: `app/Mail/NewCertificationRequestMail.php`
- Create: `resources/views/emails/new-certification-request.blade.php`
- Modify: `app/Services/CertificationRequestService.php` (dispatch dopo il commit)
- Test: `tests/Feature/CertificationRequestMailTest.php`

**Interfaces:**
- Consumes: `CertificationRequest` (Task 1), `submit()` (Task 2).
- Produces:
  - `SendCertificationRequestMailJob(CertificationRequest $request)` — `ShouldQueue`, `$tries = 3`; stessa logica di `App\Jobs\SendUgcReportMailJob`: `layer->layerOwner` → `Mail::to($owner->email)`, altrimenti `Log::info` + `Mail::to('info@camminiditalia.org')` con `noOwner: true`.
  - `NewCertificationRequestMail(CertificationRequest $request, bool $noOwner = false)` — `public string $novaUrl = rtrim(config('app.url'), '/').'/nova/resources/certification-requests/'.$request->id`; subject `'Nuova richiesta di certificazione su '.$layer->getStringName()`; `app()->setLocale('it')` come il Mailable UGC; vista `emails.new-certification-request`.
  - La vista riusa lo stile di `emails/new-ugc-report.blade.php` (header, alert-bar, campi, CTA, avviso no-owner) con i campi: cammino, camminatore (nome + email), data di invio, numero seriale (se presente), numero di foto. **Nessun tag `<img>` di foto e nessun URL di file.**

- [ ] **Step 1: Scrivere i test che falliscono**:
- `test_submit_dispatches_mail_job`: `Queue::fake()`, `submit()` → `Queue::assertPushed(SendCertificationRequestMailJob::class)`.
- `test_failed_submit_does_not_dispatch_mail_job`: caso 409 → `Queue::assertNotPushed`.
- `test_job_sends_to_layer_owner` / `test_job_falls_back_to_info_address_without_owner`: `Mail::fake()`, `(new SendCertificationRequestMailJob($r))->handle()` → `Mail::assertSent(NewCertificationRequestMail::class, fn ($m) => $m->hasTo(...))`.
- `test_mail_contains_nova_link_and_no_image_urls`: `render()` del Mailable contiene `$novaUrl` e non contiene `certification-requests/` né alcun `temporaryUrl`.

- [ ] **Step 2: Eseguire i test e verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=CertificationRequestMailTest`
Expected: FAIL.

- [ ] **Step 3: Implementare job, Mailable e vista; aggiungere nel service `SendCertificationRequestMailJob::dispatch($request)->afterCommit()` dopo la transazione.**

- [ ] **Step 4: Eseguire i test e verificare che passino** (anche i test dei Task 2 e 3)

Run: `docker exec laravel-camminiditalia php artisan test --filter=CertificationRequest`
Expected: PASS.

- [ ] **Step 5: Commit (istruzione per il dev)**

```bash
git add app/Jobs/SendCertificationRequestMailJob.php app/Mail/NewCertificationRequestMail.php resources/views/emails/new-certification-request.blade.php app/Services/CertificationRequestService.php tests/Feature/CertificationRequestMailTest.php
git commit -m "feat(oc:8653): email al gestore per ogni nuova richiesta di certificazione"
```

---

### Task 5: Policy e risorsa Nova «Validazioni»

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-policy-e-risorsa-nova-validazioni)

**Files:**
- Create: `app/Policies/CertificationRequestPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php` (`Gate::policy(CertificationRequest::class, CertificationRequestPolicy::class)`)
- Create: `app/Nova/CertificationRequest.php`
- Modify: `app/Providers/NovaServiceProvider.php` (voce di menu)
- Modify: `resources/lang/{it,en,fr,es,de}.json`
- Test: `tests/Feature/CertificationRequestPolicyTest.php`, `tests/Feature/CertificationRequestNovaTest.php`

**Interfaces:**
- Consumes: modelli (Task 1), `CertificationRequestImage::temporaryUrl()`.
- Produces:
  - `CertificationRequestPolicy`: `before()` → `true` per Administrator **solo** per `viewAny`/`view`/`delete` (non usare un `before()` che restituisca `true` per tutte le ability, altrimenti `create`/`update` passerebbero); `viewAny`: Administrator o Validator; `view`: Administrator, oppure Validator con `$request->layer->user_id === $user->id`; `create`, `update`, `replicate`, `restore`, `forceDelete`: sempre `false`; `delete`: solo Administrator.
  - `App\Nova\CertificationRequest`: `$model = App\Models\CertificationRequest::class`, `uriKey()` = `certification-requests`, `label()` = `__('Validations')`, `singularLabel()` = `__('Validation')`; `indexQuery()`: Administrator → invariata; Validator → `whereHas('layer', fn ($q) => $q->where('user_id', $user->id))`; altrimenti `whereRaw('1 = 0')`. Stesso filtro in `detailQuery()` e `relatableQuery()`.
  - Campi: `ID`, `BelongsTo` camminatore (`user`, risorsa Nova User locale), `BelongsTo` cammino (`layer`, `App\Nova\Layer`), `Text` numero seriale, `Badge` stato, `DateTime` data di invio (`created_at`), e — **solo in detail** — un campo `Text::make(__('Photos'))->asHtml()->onlyOnDetail()` che risolve `$resource->images->map->temporaryUrl()` in una griglia di `<a target="_blank" rel="noopener noreferrer"><img></a>` con URL escapati (`e()`); il campo è calcolato solo quando `$request->user()->can('view', $resource)`.
  - `authorizedToCreate` statico `false`; nessuna action.
  - Menu: `MenuItem::resource(\App\Nova\CertificationRequest::class)` in una nuova `MenuSection::make(__('Passport'), [...])`, `canSee` Administrator o Validator.
  - Chiavi i18n nuove: `Validations`, `Validation`, `Passport`, `Walker`, `Route`, `Serial number`, `Status`, `Submitted at`, `Photos`, `Pending`, più i due messaggi API del Task 3 (`A certification request is already pending for this route.`, `The uploaded files are too large.`). Testo `it`: «Validazioni», «Validazione», «Passaporto», «Camminatore», «Cammino», «Numero seriale», «Stato», «Data di invio», «Foto», «In attesa», «C'è già una richiesta di certificazione in attesa per questo cammino.», «I file caricati sono troppo grandi.»; tradurre in en/fr/es/de.

- [ ] **Step 1: Scrivere i test che falliscono** (`LayerTestHelpers`, `Storage::fake('wmfe')` con `Storage::disk('wmfe')->buildTemporaryUrlsUsing(fn ($path) => 'https://tmp.test/'.$path)`):
- Policy (`CertificationRequestPolicyTest`): data provider per ruolo × ability. Administrator: viewAny/view/delete `true`, create/update `false`. Validator proprietario: viewAny/view `true`, delete/create/update `false`. Validator non proprietario: view `false`. Guest e utente senza ruolo: tutto `false`.
- Nova (`CertificationRequestNovaTest`, pattern di `UgcPoiIndexQueryTest`):
  - `test_administrator_index_query_returns_all`
  - `test_validator_index_query_returns_only_own_layers` (due layer con owner diversi, 2+1 richieste)
  - `test_guest_index_query_returns_nothing`
  - `test_validator_cannot_view_request_of_foreign_layer`: `GET /nova-api/certification-requests/{id}` come Validator non proprietario → 403 o 404 e nessuna chiamata al builder di URL temporanei.
  - `test_detail_shows_temporary_urls_for_authorized_user`: come Validator proprietario il dettaglio contiene `https://tmp.test/certification-requests/`.
  - `test_nobody_can_create_or_update_from_nova`: `POST`/`PUT /nova-api/certification-requests` come Administrator → 403.
  - `test_administrator_delete_removes_files`: `DELETE /nova-api/certification-requests?resources[]={id}` → riga e file rimossi.

- [ ] **Step 2: Eseguire i test e verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter="CertificationRequestPolicyTest|CertificationRequestNovaTest"`
Expected: FAIL.

- [ ] **Step 3: Implementare policy, registrazione, risorsa Nova, menu e traduzioni** come da Interfaces.

- [ ] **Step 4: Eseguire i test e verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter="CertificationRequestPolicyTest|CertificationRequestNovaTest"`
Expected: PASS. Verifica manuale in Nova come Administrator e come Validator (login reale): voce di menu visibile, lista scopata, foto visibili nel dettaglio.

- [ ] **Step 5: Commit (istruzione per il dev)**

```bash
git add app/Policies/CertificationRequestPolicy.php app/Providers/AppServiceProvider.php app/Nova/CertificationRequest.php app/Providers/NovaServiceProvider.php resources/lang/*.json tests/Feature/CertificationRequestPolicyTest.php tests/Feature/CertificationRequestNovaTest.php
git commit -m "feat(oc:8653): sezione Nova Validazioni scopata per gestore"
```

---

### Task 6: Pulizia file alla cancellazione di utente e layer

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-pulizia-file-alla-cancellazione-di-utente-e-layer)

**Files:**
- Create: `app/Observers/CertificationRequestCleanupObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/CertificationRequestCleanupTest.php`

**Interfaces:**
- Consumes: `CertificationRequest` con evento `deleting` (Task 1).
- Produces: `CertificationRequestCleanupObserver::deleting(Model $model): void` — per un `User` usa `CertificationRequest::where('user_id', $model->id)`, per un `Layer` `where('layer_id', $model->id)`; `->cursor()->each->delete()` (passa dal modello, quindi rimuove i file). Registrato su `Wm\WmPackage\Models\User`, `App\Models\User` e `Wm\WmPackage\Models\Layer` (stesso schema doppio già usato per `EcPoi`/`EcTrack` in `AppServiceProvider`, perché `auth('api')->user()` può restituire l'una o l'altra classe).

- [ ] **Step 1: Scrivere i test che falliscono** (`Storage::fake('wmfe')`):
- `test_deleting_user_removes_request_files`: utente con richiesta + 2 file; `POST /api/auth/delete` come quell'utente (`actingAs($user, 'api')`) → 0 richieste, file mancanti.
- `test_deleting_layer_removes_request_files`: `$layer->delete()` → 0 richieste, file mancanti.
- `test_storage_error_does_not_block_user_deletion`: disco mockato che lancia su `delete()` → l'utente viene comunque cancellato e viene scritto un `Log::warning` (`Log::spy()`).

- [ ] **Step 2: Eseguire i test e verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=CertificationRequestCleanupTest`
Expected: FAIL (file ancora presenti dopo il cascade).

- [ ] **Step 3: Implementare l'observer e registrarlo.**

- [ ] **Step 4: Eseguire i test e verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter=CertificationRequestCleanupTest`
Expected: PASS.

- [ ] **Step 5: Commit (istruzione per il dev)**

```bash
git add app/Observers/CertificationRequestCleanupObserver.php app/Providers/AppServiceProvider.php tests/Feature/CertificationRequestCleanupTest.php
git commit -m "feat(oc:8653): rimozione delle foto alla cancellazione di utente o layer"
```

---

### Task 7: Verifica finale

- [ ] **Step 1: Suite completa** — `docker exec laravel-camminiditalia php artisan test` → nessun fallimento nuovo rispetto a `develop`.
- [ ] **Step 2: Formattazione** — `docker exec laravel-camminiditalia composer format` → nessuna modifica residua dopo il secondo passaggio.
- [ ] **Step 3: PHPStan** — `docker exec laravel-camminiditalia vendor/bin/phpstan analyse` → nessun errore sui file nuovi o modificati (non aggiungere voci al baseline per il codice nuovo).
- [ ] **Step 4: Prova manuale end-to-end** — con un JWT reale: `curl -F 'images[]=@c1.jpg' -F 'serial_number=CG-2026-00341' -F 'disclaimer_accepted=1' -H 'Authorization: Bearer …' -H 'Accept: application/json' http://localhost:<porta>/api/layer/9/certification` → 201; seconda chiamata → 409; email visibile in Mailpit; richiesta visibile in Nova come owner del layer 9 e invisibile a un altro Validator.
- [ ] **Step 5: Promemoria di rilascio in `notes.md`** — `client_max_body_size` di Nginx in produzione ≥ 50 MB; rilascio coordinato con wm-types/wm-core (`notes` → `serial_number`).

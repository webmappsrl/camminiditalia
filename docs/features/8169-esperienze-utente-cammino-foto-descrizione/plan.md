> Ticket: oc:8169

# Esperienze utente — piano backend (camminiditalia)

> **Superato durante l'implementazione** (dettaglio in [notes.md](notes.md)):
> - Task del legame: le registrazioni candidate non sono «presenti in `ugc_track_ec_track_matches`»
>   ma quelle che hanno percorso la tappa col criterio del passaporto (oc:8165); la data è
>   `properties->createdAt`, con ripiego su `created_at`;
> - Task 7: i limiti in `php.ini` non sono stati toccati, perché `www.conf` impone già 1024M con
>   `php_admin_value`; il limite reale in produzione è nginx;
> - regole delle foto: `mimes:jpeg,jpg,png,webp` al posto di `image` (niente SVG);
> - aggiunti, non previsti dal piano: il limite di richieste `throttle:experiences` e il middleware
>   `ProtectExperienceMedia`.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Nessun commit automatico.** Le righe «Commit» sono istruzioni per il dev: non eseguire `git add`, `git commit`, `git push`.

**Goal:** modello `Experience` con API autenticata (elenco, creazione, modifica, eliminazione), foto con spatie, legame con le registrazioni, Nova in sola lettura.

**Architecture:** tabella `experiences` più ponte `experience_ugc_track`; controller sottile + `ExperienceService` + FormRequest, sullo schema di `CertificationRequestController`/`CertificationRequestService`; legame calcolato da `LinkExperienceUgcTracksJob` dopo il commit.

**Tech Stack:** Laravel, spatie/laravel-medialibrary, Nova, PostgreSQL, PHPUnit/Pest.

**Spec:** `docs/features/8169-esperienze-utente-cammino-foto-descrizione/overview.md`

## Global Constraints

- Test e artisan solo nel container: `docker exec laravel-camminiditalia php artisan test --filter=<Nome>`.
- Disco dei media nei test: `Storage::fake('wmfe')`, come `FakesCertificationDisk`.
- Autenticazione nei test: `actingAs($user, 'api')` e `postJson`/`getJson`.
- Al massimo 6 foto per esperienza, al massimo 5120 KB ciascuna, `image`.
- `is_public` default `false`, mai scritto dall'API in questo ciclo.
- Finestra del legame: `config('camminiditalia.experiences.ugc_link_window_days')`, default 30.
- Ogni operazione dell'API è permessa solo all'autore; un'esperienza altrui risponde 404.
- Documentazione, commenti e messaggi di commit in italiano.

## Review Focus

- Nuovo invio con lo stesso `uuid` dopo un timeout: nessun doppione, foto mancanti aggiunte (Task 3).
- `uuid` di un altro utente: deve creare un'esperienza nuova, mai restituire quella altrui (Task 3).
- Modifica con `keep_media_ids` vuoto e nessuna foto nuova e testo vuoto: 422, non un'esperienza vuota (Task 4).
- Cancellazione dell'account: righe e file su disco rimossi (Task 2).
- Tappa in più cammini senza `layer_id`: cammino scelto con la regola documentata, sempre lo stesso (Task 3).

---

### Task 1: Migration, modello, config

**Files:**
- Create: `database/migrations/2026_10_20_100000_create_experiences_table.php`
- Create: `database/migrations/2026_10_20_100100_create_experience_ugc_track_table.php`
- Create: `app/Models/Experience.php`
- Create: `database/factories/ExperienceFactory.php`
- Modify: `config/camminiditalia.php` — chiave `experiences.ugc_link_window_days` (`env('CAMMINIDITALIA_EXPERIENCE_LINK_DAYS', 30)`)
- Test: `tests/Feature/ExperienceModelTest.php`

**Interfaces:**
- Produces: `App\Models\Experience` con `$fillable = ['uuid','user_id','layer_id','ec_track_id','description']`, `$casts = ['is_public' => 'boolean']`, costante `MEDIA_COLLECTION = 'photos'`, relazioni `user(): BelongsTo`, `layer(): BelongsTo`, `ecTrack(): BelongsTo`, `ugcTracks(): BelongsToMany` (tabella `experience_ugc_track`), `scopeVisibleTo(Builder, WmUser)`, `isVisibleTo(WmUser): bool`, `toApiArray(): array`.

Colonne `experiences`: `id`, `uuid` (string), `user_id` FK users cascade, `layer_id` FK layers cascade, `ec_track_id` FK ec_tracks nullable `nullOnDelete`, `description` text nullable, `is_public` boolean default false, timestamps; unique `(user_id, uuid)`; indice `(user_id, layer_id)`.
`experience_ugc_track`: `experience_id` FK cascade, `ugc_track_id` FK cascade, primary `(experience_id, ugc_track_id)`.

`toApiArray()` restituisce: `id, uuid, layer: {id, name}, ec_track: {id, name}|null, description, created_at, updated_at, photos: [{id, url, thumbnail}], ugc_track_ids: int[]`.

`scopeVisibleTo`: Administrator → tutto; Validator → `is_public = true` e `layer.user_id = $user->id`; altri → nulla (schema di `CertificationRequest::scopeVisibleTo`).

- [ ] **Step 1: test** `test_visible_to_rispetta_ruoli`: Administrator vede 2 esperienze private; Validator proprietario del layer ne vede 0 se private, 1 dopo aver messo `is_public = true`; utente semplice 0. `test_to_api_array_ha_tappa_e_foto`: con `ec_track_id` valorizzato `ec_track.name` è il nome della tappa, `photos` è un array.
- [ ] **Step 2:** `docker exec laravel-camminiditalia php artisan test --filter=ExperienceModelTest` → FAIL (classe mancante).
- [ ] **Step 3:** migration, modello (`HasMedia`, `InteractsWithMedia`, collection `photos`), factory, config.
- [ ] **Step 4:** stesso comando → PASS; `php artisan migrate` nel container senza errori.
- [ ] **Step 5: Commit** `feat(oc:8169): modello Experience e tabelle`

### Task 2: Pulizia su utente e cammino

**Files:**
- Create: `app/Observers/ExperienceCleanupObserver.php`
- Modify: `app/Providers/AppServiceProvider.php:96-98` — registrarlo su `WmUser`, `\App\Models\User`, `Layer` accanto a `CertificationRequestCleanupObserver`
- Test: `tests/Feature/ExperienceCleanupTest.php`

**Interfaces:** `ExperienceCleanupObserver::deleting(Model $model): void` — `Experience::where(...)->cursor()->each->delete()`, schema identico a `CertificationRequestCleanupObserver`.

- [ ] **Step 1: test** `test_cancellare_utente_rimuove_esperienze_e_file` e `test_cancellare_layer_rimuove_esperienze_e_file`: con una foto in `photos`, dopo `delete()` la riga non esiste e `Storage::disk('wmfe')` non ha più il file.
- [ ] **Step 2:** `--filter=ExperienceCleanupTest` → FAIL.
- [ ] **Step 3:** observer e registrazione.
- [ ] **Step 4:** → PASS.
- [ ] **Step 5: Commit** `feat(oc:8169): pulizia delle esperienze su utente e cammino`

### Task 3: Creazione ed elenco

**Files:**
- Create: `app/Http/Requests/StoreExperienceRequest.php`
- Create: `app/Services/ExperienceService.php`
- Create: `app/Http/Controllers/Api/ExperienceController.php`
- Modify: `routes/api.php` — gruppo `prefix('experiences')`, `middleware('auth:api')`, nomi `camminiditalia.api.experiences.*`
- Test: `tests/Feature/ExperienceApiTest.php`

**Interfaces:**
- Routes: `GET /api/experiences` (`index`, filtri query `layer_id`, `ec_track_id`, `ugc_track_id`), `POST /api/experiences` (`store`).
- `ExperienceService::create(User $user, array $data, array $images): Experience` — `$data`: `uuid`, `layer_id|null`, `ec_track_id|null`, `description|null`.
- `ExperienceService::resolveLayerId(int $ecTrackId): int` — da `layerables`, `layer_id` più basso fra quelli della tappa (regola documentata nel docblock).
- `ExperienceService::storeImages(Experience $e, array $images): void` — orientamento EXIF applicato poi metadati rimossi (con la libreria immagini già presente in `composer.json`), doppioni scartati con `UgcMediaHashService` come in `UgcController`.
- Risposte: `index` → `{"data": [toApiArray…]}` ordinate per `created_at` desc; `store` → 201 `toApiArray()`, oppure 200 se l'`uuid` esisteva già per quell'utente.

Regole di `StoreExperienceRequest`: `uuid` required uuid; `layer_id` nullable exists; `ec_track_id` nullable exists, required_without `layer_id`; `description` nullable string max 5000; `images` array max 6; `images.*` image max 5120. Dopo la validazione (`after`): testo (trim) vuoto e nessuna immagine → errore su `description`; `ec_track_id` non appartenente a `layer_id` in `layerables` → errore su `ec_track_id`.

`index` con `layer_id` restituisce le esperienze del cammino **e delle sue tappe** (stesso `layer_id`), con `ec_track_id` solo quelle della tappa, con `ugc_track_id` quelle legate a quella registrazione; sempre solo dell'utente autenticato.

- [ ] **Step 1: test** (uno per riga, nomi esatti):
  `test_crea_da_cammino` (201, `ec_track` null);
  `test_crea_da_tappa_senza_layer_ricava_il_cammino` (`layer.id` = layer più basso della tappa);
  `test_rifiuta_tappa_fuori_dal_cammino` (422 su `ec_track_id`);
  `test_rifiuta_testo_vuoto_senza_foto` (`description: "   "`, nessuna immagine → 422);
  `test_rifiuta_sette_foto` (422);
  `test_stesso_uuid_non_duplica_e_aggiunge_foto_mancanti` (secondo POST con 2 foto di cui 1 già salvata → 200, 1 riga, 2 media);
  `test_uuid_di_altro_utente_crea_nuova_esperienza` (201, 2 righe distinte);
  `test_index_del_cammino_include_le_tappe`;
  `test_index_non_mostra_esperienze_altrui`;
  `test_non_autenticato_riceve_401`.
- [ ] **Step 2:** `--filter=ExperienceApiTest` → FAIL.
- [ ] **Step 3:** request, service, controller, route.
- [ ] **Step 4:** → PASS.
- [ ] **Step 5: Commit** `feat(oc:8169): API di creazione ed elenco delle esperienze`

### Task 4: Modifica ed eliminazione

**Files:**
- Create: `app/Http/Requests/UpdateExperienceRequest.php`
- Modify: `app/Services/ExperienceService.php`, `app/Http/Controllers/Api/ExperienceController.php`, `routes/api.php`
- Test: `tests/Feature/ExperienceApiTest.php`

**Interfaces:**
- Routes: `POST /api/experiences/{experience}` (`update`, POST perché multipart), `DELETE /api/experiences/{experience}` (`destroy`, 204).
- `ExperienceService::update(Experience $e, ?string $description, array $keepMediaIds, array $images): Experience`
- `keep_media_ids`: campo **obbligatorio**, stringa JSON di interi (`"[]"` per «tieni nessuna»), così arriva anche vuoto in multipart.

Regole: totale foto tenute + nuove ≤ 6 (422 altrimenti); risultato con testo vuoto e zero foto → 422; id in `keep_media_ids` non appartenenti all'esperienza ignorati.

- [ ] **Step 1: test** `test_modifica_testo_e_toglie_foto` (2 foto, keep `[id1]` → 1 foto); `test_keep_media_ids_obbligatorio` (assente → 422); `test_keep_vuoto_toglie_tutte_se_resta_testo` (200, 0 foto); `test_modifica_che_svuota_tutto_rifiutata` (422); `test_modifica_oltre_sei_foto_rifiutata`; `test_modifica_esperienza_altrui_404`; `test_elimina_rimuove_file`; `test_elimina_esperienza_altrui_404`.
- [ ] **Step 2:** → FAIL.
- [ ] **Step 3:** implementazione; route model binding limitato all'autore (`abort(404)` se `user_id` diverso).
- [ ] **Step 4:** → PASS.
- [ ] **Step 5: Commit** `feat(oc:8169): modifica ed eliminazione delle esperienze`

### Task 5: Legame con le registrazioni

**Files:**
- Create: `app/Jobs/LinkExperienceUgcTracksJob.php`
- Create: `app/Services/ExperienceUgcLinkService.php`
- Modify: `app/Services/ExperienceService.php` — dispatch dopo `create` e `update`
- Test: `tests/Feature/ExperienceUgcLinkTest.php`

**Interfaces:**
- `LinkExperienceUgcTracksJob::__construct(int $experienceId)`, `ShouldQueue`, `$afterCommit = true`.
- `ExperienceUgcLinkService::link(Experience $e): void` — `sync()` della relazione `ugcTracks`.

Algoritmo:
1. tappe candidate: `[$e->ec_track_id]` se valorizzato, altrimenti le EcTrack del cammino da `layerables`;
2. registrazioni candidate: `ugc_tracks` di `$e->user_id` presenti in `ugc_track_ec_track_matches` con una delle tappe candidate;
3. data di registrazione: `properties->createdAt` se presente, altrimenti `created_at` (scegliere dopo aver guardato i dati locali; scriverlo nel docblock);
4. ancora = data massima fra le candidate con data ≤ `$e->created_at`; nessuna → nessun legame;
5. si legano le candidate con data in `[ancora − N giorni, ancora]`, N dalla config.

- [ ] **Step 1: test** `test_lega_registrazioni_della_tappa_nella_finestra`; `test_esperienza_scritta_due_mesi_dopo_lega_le_registrazioni_di_giugno` (registrazioni 2–20 giugno, esperienza 30 agosto → legate); `test_registrazione_fuori_finestra_non_legata` (ancora 20 giugno, registrazione 1 maggio → no); `test_registrazione_successiva_all_esperienza_non_legata`; `test_esperienza_sul_cammino_usa_tutte_le_tappe`; `test_modifica_ricalcola_il_legame`. Le righe di `ugc_track_ec_track_matches` si inseriscono a mano nel test.
- [ ] **Step 2:** → FAIL.
- [ ] **Step 3:** job e servizio.
- [ ] **Step 4:** → PASS.
- [ ] **Step 5: Commit** `feat(oc:8169): legame fra esperienze e registrazioni`

### Task 6: Nova e policy

**Files:**
- Create: `app/Nova/Experience.php`, `app/Policies/ExperiencePolicy.php`
- Modify: `app/Providers/NovaServiceProvider.php` — voce di menu accanto a `CertificationRequest` (riga 104)
- Test: `tests/Feature/ExperiencePolicyTest.php`

**Interfaces:** `ExperiencePolicy`: `viewAny` → Administrator o Validator; `view` → `isVisibleTo`; `create`/`update`/`replicate` → false; `delete` → solo Administrator. Risorsa con `indexQuery`/`detailQuery` che usano `visibleTo`, campi in sola lettura: autore (link solo per Administrator, come `CertificationRequest`), cammino, tappa, testo, foto, data, registrazioni legate.

- [ ] **Step 1: test** sulla policy: Administrator `delete` true; Validator `view` false su privata del suo layer, true se `is_public`; `update` sempre false.
- [ ] **Step 2:** → FAIL. **Step 3:** policy e risorsa. **Step 4:** → PASS; apertura manuale della risorsa in Nova come Administrator.
- [ ] **Step 5: Commit** `feat(oc:8169): esperienze in Nova in sola lettura`

### Task 7: Limiti dell'upload

**Files:** Modify: `docker/configs/phpfpm/php.ini` (`post_max_size`, `upload_max_filesize` allineati a `www.conf`, almeno 40M e 6M).

- [ ] **Step 1:** valori allineati; `docker exec laravel-camminiditalia php -i | grep -E "post_max_size|upload_max_filesize"` mostra i nuovi valori dopo il riavvio del container.
- [ ] **Step 2:** segnalare al dev che `client_max_body_size` di nginx in produzione non è nel repo e va verificato a mano (≥ 40M).
- [ ] **Step 3: Commit** `chore(oc:8169): limiti di upload per le foto delle esperienze`

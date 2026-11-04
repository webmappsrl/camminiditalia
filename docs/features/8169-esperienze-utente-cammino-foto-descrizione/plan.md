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


## Ciclo 2 — «Il mio diario» (3 novembre 2026)

### Piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Webmapp:** nessun `git commit`, `git add`, `git push` o creazione di branch durante l'esecuzione. I passi «Commit» sono istruzioni testuali per il dev, eseguite solo dopo il review-gate.

**Goal:** rinominare la funzione in «diario» ovunque e aggiungere consenso, visibilità Nova solo admin, diario del cammino nel Passaporto a cammino iniziato, bozza e coda offline, pulsante solo vicino al cammino.

**Architecture:** backend Laravel (`camminiditalia`) per modello, API, Nova; libreria Angular `wm-core` per componenti e servizi, montata dall'app (`webmapp-app`) e dalla webapp. I servizi che devono girare sempre (coda, cammino attivo) si avviano dalla variante `tabs.page.camminiditalia.ts` dell'app, già in `fileReplacements`.

**Tech Stack:** Laravel 11 + Nova + PHPUnit (Docker), Angular 20 + Ionic 8 + NgRx + localforage + OpenLayers, Karma/Jasmine.

**Spec:** `overview.md` del cantiere, sezione «Ciclo 2 — «Il mio diario»» (bozza in `scratchpad/overview-ciclo2.md`).

## Global Constraints

- Ordine vincolante dei task: rinomino → consenso e Nova → Passaporto → bozza e coda → cammino attivo. Il rinomino o è completo o non si fa.
- Nome in codice: `Diary` / `diaries` / `diary_ugc_track` / `/api/diaries` / `DiaryService` / cartella `diaries/` / selettori `wm-diary-*` / flag `showDiaries`.
- i18n wm-core: chiave = testo italiano, ripetuto come valore in `it.ts`; 7 lingue: de, en, es, fr, it, pr, sq. Nessuna chiave duplicata nello stesso file.
- Nuovi selettori e classi CSS con prefisso `wm-`; identificatori in inglese; commenti e docs in italiano; JSDoc su funzioni e metodi.
- Costanti: soglia vicinanza `DIARY_PROXIMITY_METERS = 1000`; durata cammino attivo e scadenza bozze/coda `DIARY_RETENTION_DAYS = 90`; attese dei tentativi `DIARY_OUTBOX_BACKOFF_MINUTES = [1, 5, 15]`; throttle della posizione `DIARY_PROXIMITY_THROTTLE_MS = 180000`.
- Testo del consenso, esatto: «Cammini d'Italia può pubblicare il mio racconto sui suoi canali».
- Commit: `refactor(oc:8169): …` per il rinomino, `feat(oc:8169): …` per il resto; un commit per repo per task.
- Backend: comandi in `docker exec laravel-camminiditalia …`; dopo aver cambiato una migration già eseguita: `php artisan migrate:fresh` e `php artisan migrate:fresh --env=testing`.

## Review Focus

1. Utente A con un diario in coda, logout, login di B con rete → il diario di A non parte (resta in attesa di A).
2. Invio dalla coda che riceve 422 → il diario resta come «Non inviato» e si riapre nel form con testo, foto e messaggio; nessun nuovo tentativo automatico.
3. Rete che torna mentre un invio è in corso → un solo POST in volo per uuid, nessun doppione.
4. Posizione a 600 m da una tappa ma fuori dal `bbox` di nessun cammino diverso → solo quel cammino diventa attivo; senza rete e senza tappe in cache vale l'esito del `bbox`.
5. Bozza della tappa 3 aperta dopo quella della tappa 4 → ognuna ritrova il proprio testo.

---

### Task 0: Allineamento dei branch e del database locale

**Files:** nessun file di codice.

- [ ] **Step 1:** in `wm-core` (`core/src/app/shared/wm-core`) `git checkout feature/oc-8169-esperienze-modello-dedicato` (oggi è in detached HEAD su `e8fe1866`; la cartella non tracciata `docs/features/descrizione-troncata-a-righe-intere/` resta e non va toccata).
- [ ] **Step 2:** in `camminiditalia` `git checkout feature/oc-8169-esperienze-modello-dedicato` e `git submodule update --init wm-package` (vedi oc:8719).
- [ ] **Step 3:** nell'app, il dev decide come mettere da parte le modifiche locali del branch oc:8892 (`core/src/environments/environment.ts`, puntatore di `wm-types`) prima del `git checkout feature/oc-8169-esperienze-modello-dedicato`. **Chiedere al dev, non eseguire in autonomia.**
- [ ] **Step 4:** in `wm-webapp` il branch è già quello giusto, con 4 modifiche locali: chiedere al dev cosa sono prima di toccare il repo.
- [ ] **Step 5:** verifica: `docker exec laravel-camminiditalia php artisan test --filter=Experience` → tutto PASS (base di partenza).

### Task 1: Rinomino nel backend (Experience → Diary)

**Files (camminiditalia):** rinomina con `git mv` e aggiorna contenuti:
- `app/Models/Experience.php` → `app/Models/Diary.php` (tabella `diaries`, relazione `ugcTracks` su `diary_ugc_track`, costante `MAX_PHOTOS` invariata)
- `app/Policies/ExperiencePolicy.php` → `DiaryPolicy.php`; registrazione in `app/Providers/AppServiceProvider.php` (anche il rate limiter `experiences` → `diaries`)
- `app/Http/Controllers/Api/ExperienceController.php` → `DiaryController.php`; `app/Http/Requests/{Store,Update}ExperienceRequest.php` → `{Store,Update}DiaryRequest.php`
- `app/Http/Middleware/EnsureExperiencesEnabled.php` → `EnsureDiariesEnabled.php`; `ProtectExperienceMedia.php` → `ProtectDiaryMedia.php`
- `app/Jobs/LinkExperienceUgcTracksJob.php` → `LinkDiaryUgcTracksJob.php`; `app/Services/ExperienceService.php` → `DiaryService.php`; `ExperienceUgcLinkService.php` → `DiaryUgcLinkService.php`
- `app/Observers/ExperienceCleanupObserver.php` → `DiaryCleanupObserver.php`; `app/Nova/Experience.php` → `app/Nova/Diary.php`; riferimenti in `app/Nova/Media.php`, `app/Providers/NovaServiceProvider.php`
- `routes/api.php`: prefisso `diaries`, nome `camminiditalia.api.diaries.`, parametro `{diary}`
- `config/camminiditalia.php`: chiave `diaries` con `enabled` ← `env('CAMMINIDITALIA_DIARIES_ENABLED', true)` e `ugc_link_window_days` ← `env('CAMMINIDITALIA_DIARY_LINK_DAYS', 30)`
- migration `2026_10_20_100000_create_experiences_table.php` → `…_create_diaries_table.php`, `2026_10_20_100100_create_experience_ugc_track_table.php` → `…_create_diary_ugc_track_table.php` (colonna `experience_id` → `diary_id`)
- `database/factories/ExperienceFactory.php` → `DiaryFactory.php`; `resources/lang/it.json` (etichette Nova: «Diario», «Diari»)
- `tests/Feature/Experience*Test.php` → `Diary*Test.php` (7 file)
- **Non toccare:** `database/migrations/2025_03_31_120623_create_apps_table.php`, `resources/views/welcome.blade.php` (contengono «experience» in altro senso).

**Interfaces — Produces:** `App\Models\Diary`, rotte `GET|POST /api/diaries`, `POST|DELETE /api/diaries/{diary}`, config `camminiditalia.diaries.*`.

- [ ] **Step 1:** rinomina file e simboli come sopra.
- [ ] **Step 2:** `git grep -n -i "experience" -- app config routes database tests resources/lang` → nessun risultato, tranne i due file esclusi.
- [ ] **Step 3:** `docker exec laravel-camminiditalia php artisan migrate:fresh` e `… migrate:fresh --env=testing`.
- [ ] **Step 4:** `docker exec laravel-camminiditalia php artisan test --filter=Diary` → PASS; poi suite completa → PASS.
- [ ] **Step 5:** `docker exec laravel-camminiditalia composer format`.
- [ ] **Step 6 (dev):** segnalare nel `notes.md` le variabili d'ambiente rinominate (`CAMMINIDITALIA_DIARIES_ENABLED`, `CAMMINIDITALIA_DIARY_LINK_DAYS`) da aggiornare negli ambienti.
- [ ] **Step 7 (commit, dev):** `refactor(oc:8169): rinomina experience in diary nel backend`.

### Task 2: Rinomino in wm-core, nell'app e nei testi (7 lingue)

**Files (wm-core, `projects/wm-core/src/`):**
- `experiences/` → `diaries/`: `experience.model.ts` → `diary.model.ts` (`Diary`, `DiaryDraft`, `DiaryFilter`, `DiariesStatus`), `experience.service.ts` → `diary.service.ts` (`DiaryService`, URL `/api/diaries`), `experience-launcher.service.ts` → `diary-launcher.service.ts`, `experience-offline.utils.ts` → `diary-offline.utils.ts`, `experience-photo.utils.ts` → `diary-photo.utils.ts`, componenti `experience-form` → `diary-form` (`wm-diary-form`), `experience-detail` → `diary-detail` (`wm-diary-detail`), `experiences-section` → `diaries-section` (`wm-diaries-section`); spec rinominati insieme.
- `track-properties*` e `ugc-track-properties*`: flag `showExperiences` → `showDiaries`.
- `home/home-layer/home-layer.component.camminiditalia.html`, `passport/passport-detail/…html`, `passport/passport-stage-detail/…html`: tag e titoli.
- `utils/localForage.ts`: `synchronizedExperiences` → `synchronizedDiaries` (`storeName: 'diaries'`), `clearExperiencesCache` → `clearDiariesCache`; `store/auth/auth.effects.ts`; `services/camera.service.ts`; `wm-core.module.ts`.
- `localization/i18n/{de,en,es,fr,it,pr,sq}.ts`: sostituire le 34 chiavi (it.ts righe 405-438) con le nuove. Nuovi testi italiani:
  - «Il mio diario» (era «Le tue esperienze»); «Il mio diario di questa tappa» («Le tue esperienze su questa tappa»); «Diari collegati» («Esperienze collegate»); «Racconta il tuo cammino» («Racconta la tua esperienza»); «Nuova pagina di diario» («Nuova esperienza»); «Modifica il diario» («Modifica esperienza»); «Eliminare questa pagina di diario?» («Eliminare questa esperienza?»); «Il tuo diario non è disponibile senza connessione.» («Le tue esperienze non sono disponibili senza connessione.»); «Il diario non è disponibile senza connessione.» («Le esperienze non sono disponibili senza connessione.»); «Non è stato possibile aggiornare il diario.» («Non è stato possibile aggiornare le esperienze.»). Le altre chiavi senza «esperienz» restano.
**Files (app):** `core/angular.json:198` e `core/tsconfig.spec.json:26` → `…/src/diaries`.

**Interfaces — Produces:** `DiaryService` con `create(draft: DiaryDraft): Observable<Diary>`, `update(id: number, draft: DiaryDraft): Observable<Diary>`, `remove(id: number): Observable<void>`, `refresh(): void`; componente `wm-diaries-section` con gli stessi input di oggi (`layerId`, `ecTrackId`, `ugcTrackId`, `layout`, `title`, `contextLabel`, `nav`, `guardHost`, `hideWhenEmpty`, `showCreate`, `carouselInset`).

- [ ] **Step 1:** rinomina file e simboli; aggiorna i 7 file di lingua.
- [ ] **Step 2:** `git grep -n -i "experienc" -- projects/wm-core/src` → nessun risultato; nell'app `git grep -n "experiences" -- core/angular.json core/tsconfig.spec.json` → nessun risultato.
- [ ] **Step 3:** dall'app `cd core && npm run test` → PASS, e il numero di spec eseguiti non diminuisce rispetto a prima del rinomino.
- [ ] **Step 4:** dall'app `npx ng build --configuration=camminiditalia` → build riuscita.
- [ ] **Step 5 (commit, dev):** `refactor(oc:8169): rinomina experience in diary` in wm-core; nell'app `refactor(oc:8169): spec dei diari e puntatore di wm-core`.

### Task 3: Consenso e Nova solo per l'Administrator (backend)

**Files (camminiditalia):** migration `…_create_diaries_table.php` (colonna `publication_consent` boolean default false), `app/Models/Diary.php` (`$fillable`, `$casts`, `toApiArray()` con `publication_consent`; `scopeVisibleTo` e `isVisibleTo`: solo Administrator), `app/Policies/DiaryPolicy.php` (`viewAny`: solo Administrator), `app/Http/Requests/{Store,Update}DiaryRequest.php` (`'publication_consent' => ['sometimes', 'boolean']`), `app/Services/DiaryService.php` (passa il campo in creazione e modifica), `app/Nova/Diary.php` (`Boolean::make(__('Consenso alla pubblicazione'), 'publication_consent')->readonly()` + filtro), Create `app/Nova/Filters/DiaryPublicationConsentFilter.php`; test in `tests/Feature/DiaryApiTest.php`, `DiaryPolicyTest.php`, `DiaryModelTest.php`.

- [ ] **Step 1: test che falliscono**
  - `test_store_salva_publication_consent`: POST con `publication_consent=1` → 201, `json('data.publication_consent') === true`, riga con `true`.
  - `test_store_senza_consenso_vale_false`: POST senza campo → `false`.
  - `test_update_cambia_publication_consent`: da `false` a `true` → 200 e valore `true`.
  - `test_validator_non_vede_i_diari`: utente Validator proprietario del layer, diario `is_public=true` → `Diary::visibleTo($validator)->count() === 0` e `viewAny` → false.
  - `test_administrator_vede_tutti_i_diari`: invariato, resta PASS.
- [ ] **Step 2:** `docker exec laravel-camminiditalia php artisan test --filter=Diary` → FAIL sui nuovi test.
- [ ] **Step 3:** implementa come da elenco file; aggiorna i test esistenti del Validator (`DiaryPolicyTest`, `DiaryModelTest`) alla nuova regola.
- [ ] **Step 4:** `migrate:fresh` (dev e testing), poi test → PASS; `composer format`.
- [ ] **Step 5 (commit, dev):** `feat(oc:8169): consenso alla pubblicazione e diari visibili solo agli amministratori`.

### Task 4: Spunta del consenso nel form (wm-core)

**Files:** `diaries/diary.model.ts` (`Diary.publicationConsent: boolean`, `DiaryDraft.publicationConsent: boolean`), `diaries/diary.service.ts` (`_toFormData`: `form.append('publication_consent', draft.publicationConsent ? '1' : '0')` in creazione e modifica; mappatura `publication_consent` → `publicationConsent`), `diaries/diary-form/diary-form.component.{ts,html,scss}` (`ion-checkbox` con label visibile, `labelPlacement="end"`, area di tocco ≥ 44px, non selezionata di default, valorizzata in modifica), i18n: nuova chiave «Cammini d'Italia può pubblicare il mio racconto sui suoi canali» nelle 7 lingue.

- [ ] **Step 1: test che falliscono** in `diary.service.spec.ts`: `it('invia publication_consent=1 quando la spunta è attiva')`, `it('legge publicationConsent dalla risposta')`; in `diary-form.component.spec.ts`: `it('la spunta del consenso parte non selezionata')`, `it('in modifica ripropone il consenso salvato')`.
- [ ] **Step 2:** `npm run test` → FAIL.
- [ ] **Step 3:** implementa.
- [ ] **Step 4:** `npm run test` → PASS.
- [ ] **Step 5 (commit, dev):** `feat(oc:8169): spunta del consenso alla pubblicazione nel form del diario`.

### Task 5: Diario del cammino nel Passaporto a cammino iniziato

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5--passaporto-a-cammino-iniziato)

**Files:** `passport/passport-detail/passport-detail.component.html` (oggi il blocco sta dentro `<ng-container *ngIf="vm.completed">`, riga 55), spec `passport-detail.component.spec.ts`.

- [ ] **Step 1: test che falliscono:** `it('mostra il diario del cammino con almeno una tappa riconosciuta')` (`completedStages: 1`, `completed: false` → `wm-diaries-section` presente); `it('non mostra il diario del cammino senza tappe riconosciute')` (`completedStages: 0` → assente); `it('a cammino completato il diario resta nel traguardo')`.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** sposta `wm-diaries-section` fuori dal blocco del traguardo con `*ngIf="vm.completed || vm.progress?.completedStages > 0"`, mantenendo la posizione attuale quando il cammino è completato (sotto «Condividi il traguardo»).
- [ ] **Step 4:** PASS.
- [ ] **Step 5 (commit, dev):** `feat(oc:8169): diario del cammino nel passaporto da cammino iniziato`.

### Task 6: Bozza del form salvata sul telefono

**Files:** Create `diaries/diary-draft.store.ts` (+ spec); `utils/localForage.ts` (istanza `deviceDiaryDrafts`, `name: 'device'`, `storeName: 'diaryDrafts'`); `diaries/diary-form/diary-form.component.ts`.

**Interfaces — Produces:**
- `type DiaryDraftKey = string` costruita da `diaryDraftKey(userId: number, ctx: {layerId?: number; ecTrackId?: number; diaryId?: number}): DiaryDraftKey` → `"u<userId>:d<diaryId>"` in modifica, altrimenti `"u<userId>:l<layerId>:t<ecTrackId|0>"`.
- `interface StoredDiaryDraft { key: DiaryDraftKey; userId: number; uuid: string; layerId?: number; ecTrackId?: number; diaryId?: number; description: string; photos: Blob[]; keepMediaIds?: number[]; publicationConsent: boolean; updatedAt: number }`
- `class DiaryDraftStore` (`providedIn: 'root'`): `save(draft: StoredDiaryDraft): Promise<void>`, `load(key: DiaryDraftKey): Promise<StoredDiaryDraft | null>`, `remove(key: DiaryDraftKey): Promise<void>`, `purgeOlderThan(days: number, now?: number): Promise<number>`.

- [ ] **Step 1: test che falliscono** (`diary-draft.store.spec.ts`): `diaryDraftKey` per cammino, tappa e modifica dà tre chiavi diverse; `save` + `load` ritrovano testo, foto (Blob) e uuid; `purgeOlderThan(90)` cancella una bozza con `updatedAt` di 91 giorni fa e tiene quella di 89. In `diary-form.component.spec.ts`: alla riapertura nello stesso contesto il form ripropone testo e uuid salvati; dopo pubblicazione riuscita e dopo «esci senza pubblicare» la bozza è rimossa.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** implementa lo store; nel form: carica la bozza all'apertura (uuid della bozza al posto di quello nuovo), salva il testo con `debounceTime(800)`, salva subito su aggiunta/rimozione foto e cambio della spunta, rimuove la bozza a pubblicazione, annullamento esplicito e passaggio in coda (Task 7).
- [ ] **Step 4:** PASS.
- [ ] **Step 5 (commit, dev):** `feat(oc:8169): bozza del diario salvata sul telefono`.

### Task 7: Coda di invio offline per i diari nuovi

**Files:** Create `diaries/diary-outbox.service.ts` (+ spec); `utils/localForage.ts` (istanza `deviceDiaryOutbox`, `name: 'device'`, `storeName: 'diaryOutbox'`); `diaries/diary-form/diary-form.component.ts` (offline → accoda); `diaries/diaries-section/diaries-section.component.{ts,html}` (elementi in coda in testa alla lista); app `core/src/app/pages/tabs/tabs.page.camminiditalia.ts` (avvio) e `core/src/app/components/settings/settings.component.ts:148` (avviso al logout); wm-core `profile/profile-popup/profile-popup.component.ts:34` (stesso avviso); i18n 7 lingue.

**Interfaces — Consumes:** `DiaryService.create`, `DiaryDraftStore.remove`, selector `online`, `isLogged`, `userId` dallo store auth.
**Interfaces — Produces:**
- `interface DiaryOutboxItem { uuid: string; userId: number; layerId: number; ecTrackId?: number; description: string; photos: Blob[]; publicationConsent: boolean; status: 'pending' | 'failed'; errorMessage?: string; attempts: number; nextAttemptAt: number; updatedAt: number }`
- `class DiaryOutboxService` (`providedIn: 'root'`): `start(): void` (idempotente), `enqueue(draft: DiaryDraft, userId: number): Promise<void>`, `items$(filter: {layerId?: number; ecTrackId?: number}): Observable<DiaryOutboxItem[]>` (solo dell'utente corrente), `pendingCount(userId: number): Promise<number>`, `discard(uuid: string): Promise<void>`, `toDraft(item: DiaryOutboxItem): DiaryDraft`.
- Esiti dell'invio: 2xx → rimozione + `DiaryService.refresh()`; 401 → resta `pending`, riparte al login; status 0 o 5xx → `attempts++`, `nextAttemptAt` da `DIARY_OUTBOX_BACKOFF_MINUTES`, poi solo al ritorno della rete; 422, 413, 404 → `failed` con `errorMessage`. Un solo invio alla volta.
- Trigger: `start()` legge la coda; se vuota non crea timer. Invia su transizione `online` falso→vero, su login, allo scadere di `nextAttemptAt`. `start()` esegue anche `purgeOlderThan(DIARY_RETENTION_DAYS)` su coda e bozze.
- Testi nuovi (it): «In attesa di invio», «Non inviato», «Sei offline: il diario partirà appena torni online», «Hai {{n}} diari in attesa di invio: se esci, partiranno al tuo prossimo accesso».

- [ ] **Step 1: test che falliscono** (`diary-outbox.service.spec.ts`, con `fakeAsync` e `HttpTestingController`):
  - `it('al ritorno della rete invia il diario in coda con lo stesso uuid e lo rimuove')`
  - `it('non invia gli elementi di un altro utente')` (Review Focus 1)
  - `it('su 422 marca failed con il messaggio e non ritenta')` (Review Focus 2)
  - `it('su 500 ritenta dopo 1 e poi 5 minuti')`
  - `it('su 401 resta pending e riparte dopo il login')`
  - `it('con due eventi online ravvicinati fa un solo POST')` (Review Focus 3)
  - `it('a coda vuota start non crea timer')`
  - `it('purge rimuove elementi più vecchi di 90 giorni')`
  - In `diaries-section.component.spec.ts`: l'elemento `pending` mostra «In attesa di invio» in un elemento con `role="status"`; il `failed` mostra «Non inviato» e al tocco apre il form con `toDraft(item)`.
  - In `diary-form.component.spec.ts`: offline, «Pubblica» su un diario nuovo chiama `enqueue`, rimuove la bozza, chiude il form e mostra il toast; in modifica offline resta il comportamento attuale.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** implementa servizio, integrazione nel form e nella sezione; in `tabs.page.camminiditalia.ts` inietta `DiaryOutboxService` e chiama `start()` nel costruttore; al logout (`settings.component.ts`, `profile-popup.component.ts`) se `pendingCount(userId) > 0` aggiungi il testo dell'avviso alla conferma.
- [ ] **Step 4:** PASS; build `--configuration=camminiditalia` riuscita.
- [ ] **Step 5 (commit, dev):** wm-core `feat(oc:8169): coda di invio offline dei diari`; app `feat(oc:8169): avvio della coda dei diari e avviso al logout`.

### Task 8: Pulsante solo vicino al cammino (opzione B)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-8--pulsante-solo-vicino-al-cammino)

**Files:** Create `diaries/active-route.service.ts` (+ spec); `utils/localForage.ts` (istanza `deviceActiveRoutes`, `name: 'device'`, `storeName: 'activeRoutes'`, chiave `layerId`, valore `lastSeenAt`); `home/home-layer/home-layer.component.camminiditalia.{ts,html}` e `track-properties/track-properties.component.camminiditalia.ts` (+ template condiviso: `[showCreate]`); app `tabs.page.camminiditalia.ts` (avvio).

**Interfaces — Consumes:** `GeolocationService.onLocationChange$` (`WmLocation`), layer della configurazione con `bbox` e `tracks` (`Hit.id`), `EcService.getEcTrack(id): Observable<WmFeature<LineString>>`, `GeoutilsService` per la distanza punto-linea.
**Interfaces — Produces:** `class ActiveRouteService` (`providedIn: 'root'`): `start(): void` (idempotente), `isActive$(layerId: number): Observable<boolean>`, `checkNow(location: WmLocation): Promise<void>`; funzione pura `isNearLine(location: [number, number], line: LineString, meters: number): boolean`; funzione pura `bboxContains(bbox: number[], location: [number, number], marginMeters: number): boolean`.

- [ ] **Step 1: test che falliscono** (`active-route.service.spec.ts`):
  - `isNearLine` vero a 600 m da una linea, falso a 1.500 m;
  - `bboxContains` con margine di 1.000 m: vero appena fuori dal rettangolo, falso a 5 km;
  - `checkNow` con posizione a 600 m da una tappa del cammino A → `isActive$(A)` vero, `isActive$(B)` falso (Review Focus 4);
  - senza rete e `getEcTrack` in errore → vale l'esito del `bbox`;
  - `lastSeenAt` di 91 giorni fa → non attivo; di 89 → attivo;
  - due posizioni nello stesso intervallo di throttle → un solo controllo.
  - In `track-properties` e `home-layer` (varianti camminiditalia): con `isActive$` falso il pulsante non c'è ma i diari già scritti sì.
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** implementa: `throttleTime(DIARY_PROXIMITY_THROTTLE_MS)` su `onLocationChange$`; filtro `bbox` su tutti i cammini; per i candidati carica le tappe con `getEcTrack` una alla volta, si ferma alla prima entro `DIARY_PROXIMITY_METERS`; salta i cammini già marcati oggi; controllo anche all'apertura del dettaglio se c'è una posizione. Nelle varianti camminiditalia passa `[showCreate]="isActive$ | async"` a `wm-diaries-section`. Nel Passaporto `showCreate` resta `true`. Avvio con `start()` nel costruttore di `tabs.page.camminiditalia.ts`.
- [ ] **Step 4:** PASS; build `--configuration=camminiditalia` riuscita.
- [ ] **Step 5 (commit, dev):** wm-core `feat(oc:8169): pulsante del diario solo vicino al cammino`; app `feat(oc:8169): avvio del controllo del cammino attivo`.

### Task 9: Puntatori, documentazione, verifica finale

- [ ] **Step 1:** aggiorna il puntatore di wm-core nell'app e nella webapp al commit finale di wm-core; in webapp `npx ng build --configuration=camminiditalia` riuscita.
- [ ] **Step 2:** aggiungi le sezioni «Ciclo 2» a `overview.md`, `plan.md`, `notes.md` del cantiere in wm-core e camminiditalia; aggiorna la pagina di conoscenza dei diari (oggi `docs/knowledge/8169-esperienze-utente-cammino-foto-descrizione.md`) e la frase superata sulla webapp.
- [ ] **Step 3:** suite backend completa → PASS; `npm run test` nell'app → PASS.
- [ ] **Step 4 (commit, dev):** `docs(oc:8169): ciclo 2 del diario` e puntatori.


### Ciclo 2, aggiunte 7-10 — piano

> Ticket: oc:8169 — overview: sezione «Ciclo 2, aggiunte» di overview.md

**Vincoli comuni**
- Tutto solo camminiditalia: varianti con `fileReplacements` (`core/angular.json`, configuration
  `camminiditalia`; la serve la eredita via `buildTarget`) o servizi avviati da
  `tabs.page.camminiditalia.ts`. Nessun controllo sullo shard nel codice condiviso.
- i18n wm-core: chiave = testo italiano, 7 lingue (de, en, es, fr, it, pr, sq). Testi dell'app in
  `core/src/assets/i18n`.
- Spec nuovi: aggiungerli a `core/tsconfig.spec.json` e ad `architect.test.options.include` di
  `core/angular.json`.
- Test frontend: `cd core && npx ng test --karma-config <scratchpad>/karma.local.cjs` con Node 22.
  Backend: `docker exec laravel-camminiditalia php artisan test --filter=<Test>`, poi `composer
  format` e PHPStan.
- Nessun commit automatico: i commit indicati sono per il dev (`feat(oc:8169): …`).

**Ordine**: Task 1 profilo → Task 2-3 valutazioni → Task 4 notifica → Task 5-6 scheda dati.

---

#### Task 1 — Tab «Il mio diario» nel profilo (app)

**File**
- Crea `core/src/app/pages/profile/profile-routing.module.camminiditalia.ts`: come quello condiviso
  più la figlia `{path: 'diary', component: ProfileDiaryTabComponent}`.
- Crea `core/src/app/pages/profile/tabs/profile-diary-tab/profile-diary-tab.component.{ts,html,scss}`
  (standalone, selettore `webmapp-profile-diary-tab`).
- Modifica `core/src/app/pages/profile/profile.page.camminiditalia.html`: «I tuoi dati» → «I miei
  dati», secondo `ion-tab-button` `tab="diary"` → `/profile/diary`, «Il mio diario».
- Modifica `core/angular.json`: sostituzione `profile-routing.module.ts` →
  `profile-routing.module.camminiditalia.ts` nella configuration `camminiditalia`.
- i18n app: «I miei dati», «Il mio diario», «Non hai ancora scritto nessun diario».

**Interfacce**
- Consuma `DiaryService` (elenco dei diari dell'utente, già usato da `wm-diaries-section`), lo
  store `ecTracks`/layer per il nome del cammino.
- Produce `ProfileDiaryTabComponent` con `groups$: Observable<{layerId: number; title: string}[]>`:
  un gruppo per `layerId` distinto dei diari dell'utente, in ordine di diario più recente.

**Passi**
1. Spec `profile-diary-tab.component.spec.ts`: con diari su layer 3 e 5 → due gruppi, il più recente
   primo; senza diari → il messaggio vuoto; ogni gruppo rende una `wm-diaries-section` con
   `layout="list"` e `showCreate=false`. Eseguirlo e vederlo fallire.
2. Implementare il componente e il routing; eseguire lo spec, poi la build `--configuration=camminiditalia`
   e quella di default (la default non deve includere il tab).
3. Commit: `feat(oc:8169): tab «Il mio diario» nel profilo camminiditalia`.

---

#### Task 2 — Valutazioni, backend (camminiditalia)

**File**
- Modifica `database/migrations/2026_10_20_100000_create_diaries_table.php`: `rating_route`,
  `rating_hospitality`, `rating_difficulty` `unsignedTinyInteger()->nullable()`. La migration non è
  mai andata in produzione: in sviluppo `migrate:rollback --step=2 && migrate` (mai `migrate:fresh`).
- `app/Models/Diary.php`: fillable e cast `integer`.
- Request Store e Update: `nullable|integer|between:1,5` per i tre campi; in Update `''`/`null`
  azzera.
- Risorsa/serializzazione del `DiaryController`: i tre campi nella risposta.
- `app/Nova/Diary.php`: tre campi `Number` in sola lettura.

**Passi**
1. Test in `tests/Feature/Diary*Test`: creazione con valutazioni 1 e 5 salvate; 0 e 6 → 422;
   modifica con `null` azzera; risposta API con i tre campi. Eseguirli e vederli fallire.
2. Implementare; test verdi, `composer format`, PHPStan.
3. Commit: `feat(oc:8169): valutazioni del diario`.

---

#### Task 3 — Valutazioni, frontend (wm-core)

**File**
- `diaries/diary.model.ts`: `ratingRoute`, `ratingHospitality`, `ratingDifficulty` (`number | null`)
  in `Diary` e `DiaryDraft`; `diary.service.ts` li manda in `_toFormData` (stringa vuota per `null`)
  e li legge dalla risposta.
- Crea `diaries/diary-rating/diary-rating.component.{ts,html,scss}`: standalone,
  `wm-diary-rating`, `@Input() value: number | null`, `@Input() readonly = false`,
  `@Input() size: 'form' | 'small'`, `@Output() valueChange`. SVG del timbro con `txn-boot`
  (path copiato da `wm-package/resources/defaults/icons.json`), bordo tratteggiato solo in `form`.
- `diary-form`: tre righe dopo le foto («Cammino» o «Tappa» se c'è `ecTrackId`, «Ospitalità»,
  «Difficoltà percepita»); bozza e coda offline portano i valori.
- `diary-detail`: solo le valutazioni date, `size="small"`, `readonly`.
- i18n wm-core: «Valutazioni», «Cammino», «Tappa», «Ospitalità», «Difficoltà percepita».

**Animazione** (solo `readonly=false`, solo al tocco): con Web Animations sui timbri da 1 a N che
diventano pieni, uno ogni 60 ms, `scale(1.4) rotate(-12deg)` → `scale(0.9)` → `scale(1) rotate(0)`,
250 ms, `easing: cubic-bezier(.2,.8,.3,1.2)`; quelli oltre N tornano vuoti con dissolvenza di
120 ms. Nessuna animazione al primo render né con `prefers-reduced-motion: reduce`. Al termine
`animation.cancel()` per non lasciare stili sopra a quelli del template (trappola nota delle
animazioni Web Animations in `.claude/rules/avvisi-gps`).

**Passi**
1. Spec `diary-rating.component.spec.ts`: tocco sul 3 → emette 3; tocco sul 3 con valore 3 → emette
   `null`; `readonly` non emette; al primo render con valore 4 non parte nessuna animazione
   (spia su `Element.prototype.animate`); al tocco sul 4 partono 4 animazioni; con
   `matchMedia('(prefers-reduced-motion: reduce)')` vero nessuna. Spec del form: i valori arrivano a
   `_toFormData` e nella bozza. Spec del dettaglio: con solo `ratingHospitality` si vede una riga.
   Eseguirli e vederli fallire.
2. Implementare; spec verdi, suite intera.
3. Commit: `feat(oc:8169): valutazioni con timbro animato nel form del diario`.

---

#### Task 4 — Notifica di cammino completato (wm-core + app)

**File**
- Crea `passport/route-completed-notice.service.ts`: `RouteCompletedNoticeService` con
  `start(): void` (idempotente), che ascolta `PassportService.passportRoutes$()` filtrando
  `completed`. Visti salvati in localforage, istanza nuova `device/completedRoutesSeen`, chiave per
  utente (`u<id>`), valore `number[]` di layerId.
  - Prima lettura per un utente senza chiave: segna come visti tutti i completati, nessuna scheda.
  - Poi: per ogni completato non visto, uno alla volta, `ToastController.create` con
    `position: 'top'`, `duration: 0`, `swipeGesture: 'vertical'`, `cssClass: 'wm-route-completed-toast'`,
    titolo «Hai completato {{name}}!», testo «Guarda il traguardo e racconta il cammino nel
    diario», bottoni «Più tardi» e «Vai al traguardo» (naviga al dettaglio del Passaporto del
    cammino, rotta già usata dal tab Passaporto). Segna visto alla chiusura, in ogni modo.
- `core/src/app/pages/tabs/tabs.page.camminiditalia.ts`: `start()` accanto agli altri servizi.
- Stile in `_passport-theme.scss`: angoli arrotondati, ombra, icona del timbro.
- i18n wm-core per i tre testi.

**Passi**
1. Spec `route-completed-notice.service.spec.ts`: prima esecuzione con due completati → nessun
   toast, visti salvati; un cammino nuovo completato → un toast; due nuovi → il secondo dopo la
   chiusura del primo; «Vai al traguardo» naviga; utente diverso → visti separati; logout → nessun
   toast. Eseguirli e vederli fallire.
2. Implementare; spec verdi, suite intera, build camminiditalia.
3. Commit: `feat(oc:8169): avviso di cammino completato`.

---

#### Task 5 — Scheda dati, backend (camminiditalia)

**File**
- `app/Services/StageProgressService.php`, `progressFor()`:
  - per tappa `user_distance`: km (arrotondati a 0,1) della somma
    `ST_Length(ugc_tracks.geometry::geography)/1000` delle tracce in `passport_stage_ugc_track` della
    sua `passport_stage`, **solo se** nessuna di quelle tracce è collegata ad altre `passport_stage`
    dell'utente; altrimenti `null`. Una query sola per tutte le tappe del cammino (niente N+1).
  - per tappa `walked_dates: string[]` (`Y-m-d`, ordinate, distinte): se ha tracce, la data di
    inizio di ciascuna (orario di inizio nelle `properties` della traccia se presente, altrimenti
    `created_at`), nel fuso `Europe/Rome`; se è validata da una richiesta cartacea
    (`certification_request_id`), ogni giorno da `start_date` a `end_date`; altrimenti `[]`. Le
    richieste caricate con una query sola.
  - nella risposta `user_distance_total`: somma delle tracce distinte collegate alle tappe validate
    del cammino + `distance` delle tappe validate senza tracce; `null` se nessuna tappa validata.

**Passi**
1. Test in `tests/Feature/StageProgress*Test`: tappa con una traccia propria → `user_distance` dalla
   geometria e `walked_dates` col suo giorno; traccia condivisa da due tappe → `user_distance`
   `null` su entrambe, nel totale contata una volta; tappa da richiesta cartacea dal 2026-05-01 al
   2026-05-03 → `user_distance` `null`, tre date; tappa validata senza tracce né richiesta → `[]`.
   Eseguirli e vederli fallire.
2. Implementare; test verdi, `composer format`, PHPStan.
3. Commit: `feat(oc:8169): km dell'utente nel progresso e date della certificazione`.

---

#### Task 6 — Scheda dati, frontend (wm-core)

**File**
- `passport.service.ts`: `userDistance?: number | null` e `walkedDates?: string[]` in
  `PassportStage` (da `user_distance`, `walked_dates`), `userDistanceTotal?: number | null` in
  `PassportProgress`. I tipi stanno in `@wm-types/passport` (submodule wm-types).
- Crea in `diaries/diary-route-data.utils.ts`:
  `diaryRouteData(progress: PassportProgress, diary: Diary): {km: number | null; from: string | null; to: string | null; days: number | null} | null`
  - diario di tappa: `null` se la tappa non è `completed`; km = `userDistance ?? distance`; date e
    giorni da `walkedDates` (vuoto → assenti).
  - diario del cammino: `null` se il cammino non è completato; km = `userDistanceTotal ??
    totalDistanceKm(stages)`; date e giorni dall'unione dei `walkedDates` di tutte le tappe, solo
    se nessuna tappa ha `walkedDates` vuoto, altrimenti assenti.
- `diary-detail`: blocco «Il tuo percorso» con le righe presenti, km con `passportKm`.
- i18n wm-core: «Il tuo percorso», «km», «giorni», «dal {{from}} al {{to}}».

**Passi**
1. Spec `diary-route-data.utils.spec.ts` con le fixture di `passport-progress.fixtures.ts`: tappa
   GPS con `userDistance` 12,3 e `distance` 14 → 12,3 e la sua data; tappa cartacea con tre date →
   14 km, tre giorni; tappa non completata → `null`; cammino con tappe GPS e cartacee →
   `userDistanceTotal`, giorni distinti dell'unione, prima e ultima data; una tappa senza date →
   giorni e date assenti, km presenti; cammino non completato → `null`; backend vecchio senza i
   campi nuovi → lunghezza del cammino, nessuna data. Eseguirli e
   vederli fallire.
2. Implementare; spec verdi, suite intera, build camminiditalia e default, build della webapp
   camminiditalia.
3. Commit: `feat(oc:8169): scheda dati del percorso nel diario`.

---

**Chiusura**: docs (sezioni del ciclo 2 in overview, plan, notes; knowledge page 8169), puntatori
dei submodule nell'app e nella webapp, review del branch intero, poi aggiornamento di oc:8169.

---

#### Task 7 — Profilo riorganizzato (app, solo camminiditalia)

**File**
- Crea `core/src/app/services/logout.service.ts`: `LogoutService.confirmLogout(): Promise<boolean>`
  con l'alert di oggi (avviso dei diari in coda compreso); `true` se l'utente ha confermato e il
  logout è partito. `SettingsComponent.logout()` lo usa e poi chiude la modale.
- `SettingsComponent`: `@Input() showLogout = true`; la voce «Esci» solo se `showLogout`.
- Crea `core/src/app/pages/profile/tabs/profile-account-tab/` (componente non standalone in
  `ProfileAccountTabModule`, come il tab del diario): sezioni Download (`wm-profile-data`),
  Modifica consenso dati (`wm-privacy-agree-button`), Esci (solo da loggato), Elimina account
  (`wm-profile-delete-button`), nel flusso, con margini laterali e spazio per la safe area.
- `profile-routing.module.camminiditalia.ts`: figlie `diary` e `account`, `''` → `diary`.
- `profile.page.camminiditalia.html`: tab «Il mio diario» (solo loggato) e «Account»; tolti i due
  pulsanti fissi in fondo.
- `profile.page.camminiditalia.ts`: al logout naviga a `/profile/account`; da non loggato,
  entrando in `/profile/diary`, va ad `account`; l'ingranaggio apre `SettingsComponent` con
  `showLogout: false`.
- i18n app: «Account», «Download», «Esci».

**Passi**
1. Spec `logout.service.spec.ts`: senza diari in coda il messaggio è quello base; con 2 diari in
   coda aggiunge l'avviso; conferma → `loadSignOuts` e `true`; annulla → nessuna azione e `false`.
   Spec `profile-account-tab.component.spec.ts`: da loggato quattro sezioni in quest'ordine; da non
   loggato solo i download; «Esci» chiama `confirmLogout`. Eseguirli e vederli fallire.
2. Implementare; spec verdi, suite intera, build camminiditalia e default (la default deve avere
   ancora «Esci» nell'ingranaggio).
3. Commit: `feat(oc:8169): profilo camminiditalia con le tab Il mio diario e Account`.

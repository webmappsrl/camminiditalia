> Ticket: oc:8676

# Passaporto camminatore: tappe percorse e validate (backend) — piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** esporre le tappe validate (`validated_ec_tracks`) all'app con due endpoint di progresso calcolato e in Nova con una sezione dedicata e una Lens riassuntiva, con una sola regola di calcolo.

**Architecture:** un servizio `App\Services\StageProgressService` contiene la regola «tappe che contano per layer» come query SQL riusabile (query builder), con la distanza corrente del package calcolata in SQL. Endpoint, risorsa Nova, filtri, Lens e scoping del Validator si costruiscono tutti su quella query. Nessuna migration, nessuna modifica a `wm-package`.

**Tech Stack:** Laravel 11, Nova 5, PostgreSQL/PostGIS, JWT (`auth:api`), PHPUnit Feature test con `DatabaseTransactions`.

**Spec:** [overview.md](overview.md)

## Global Constraints

- Repo unico: `camminiditalia`. Nessun file in `wm-package/` o `../wm-package/`.
- Comandi sempre nel container: `docker exec laravel-camminiditalia php artisan …`; test su DB `camminiditalia_testing`.
- **Nessun `git commit`, `git add`, `git push` durante l'esecuzione**: i commit indicati nei task sono istruzioni testuali per il dev, eseguite solo dopo il review-gate e la sua conferma esplicita. Convention: `feat(oc:8676): …`.
- Tappe che contano per un layer: EcTrack in `layerables` del layer **con** `ec_tracks.user_id = COALESCE(layers.user_id, config('camminiditalia.default_owner_id'))`.
- `layerable_type` delle tappe = morph class del modello `config('wm-package.ec_track_model')` (in DB `App\Models\EcTrack`), mai il nome di classe del package scritto a mano.
- Distanza corrente (come `HasDemClassification::classifyField()`): `manual_data.distance` se non vuoto; altrimenti `osm_data.distance` se `ec_tracks.osmid` non è null e il valore esiste; altrimenti `dem_data.distance`; altrimenti 0. Cast protetto: solo valori che corrispondono a `^-?[0-9]+(\.[0-9]+)?$`, gli altri valgono 0. Nessuna correzione dei valori anomali.
- Calcolo sempre sulle tappe di oggi: nessun completamento salvato.
- `percentage` = `intdiv(validated * 100, total)` (troncamento, mai 100 se non completato), 0 se `total = 0`. `completed` = `total > 0 && validated === total`.
- Date in risposta: `toIso8601String()`. Km arrotondati a 1 decimale.
- Validator: vede solo le validazioni di tappe che contano in un layer con `layers.user_id = <id del Validator>` (stesso criterio di `CertificationRequest::scopeVisibleTo()`). Administrator vede tutto. Altri ruoli: niente.
- Testi nuovi in `resources/lang/{it,en,fr,es,de}.json`, chiave base in inglese (come le chiavi esistenti), default `it`.
- Stile API come `CertificationRequestController`: `response()->json([...])` costruito a mano, route in `routes/api.php` con nomi `camminiditalia.api.…`.

## Review Focus

- Tappa condivisa fra due layer con proprietari diversi: conta solo nel layer del suo proprietario, e nell'altro è `not_validatable` (test nel Task 1).
- Layer senza `user_id`: il proprietario è `default_owner_id` sia in SQL sia in `LayerOwner::idFor()` (test di parità nel Task 1).
- Distanza in formato stringa non numerica (`"12,5"`, `""`): vale 0 senza mandare in errore la query (test nel Task 1).
- Utente che chiede il progresso di un layer dove non ha validato nulla: 200 con `validated: 0`, non 404 (test nel Task 2).
- Validator senza layer: elenco e Lens vuoti, nessun errore (test nel Task 3).

---

### Task 1: Servizio di calcolo `StageProgressService`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-servizio-di-calcolo) e, dopo la review, [notes.md](notes.md#dopo-la-review-regole-delle-tappe-nome-distanza-e-progress)

**Files:**
- Create: `app/Services/StageProgressService.php`
- Modify: `app/Models/User.php` (relazione `validatedEcTracks()`)
- Test: `tests/Feature/StageProgressServiceTest.php`

**Interfaces:**
- Produces:
  - `StageProgressService::countingTracksQuery(): \Illuminate\Database\Query\Builder` — righe `layer_id`, `ec_track_id`, `distance_km` (float) per ogni tappa che conta in ogni layer.
  - `StageProgressService::layerTracksQuery(int $layerId): \Illuminate\Database\Query\Builder` — tutte le tappe del layer con `ec_track_id`, `counts` (bool), `distance_km`.
  - `StageProgressService::progressFor(User $user, Layer $layer): array` — forma esatta della risposta di `GET /api/layer/{layer}/progress` (vedi Task 2).
  - `StageProgressService::passportFor(User $user): array<int, array>` — elementi di `GET /api/passport`.
  - `StageProgressService::summaryQuery(): \Illuminate\Database\Eloquent\Builder<ValidatedEcTrack>` — una riga per coppia `user_id`/`layer_id` con `id` (`MIN(validated_ec_tracks.id)`), `user_id`, `layer_id`, `validated`, `total`, `last_validated_at`; base della Lens.
  - `StageProgressService::scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, User $user): Builder` — applica lo scoping di ruolo a una query su `validated_ec_tracks`.
  - `App\Models\User::validatedEcTracks(): HasMany<ValidatedEcTrack>`.

- [ ] **Step 1: Scrivi i test che falliscono** in `tests/Feature/StageProgressServiceTest.php` (`DatabaseTransactions`, `LayerTestHelpers`, `RolesAndPermissionsService::seedDatabase()`, `Queue::fake()`; tappe con `EcTrack::factory()->create(['user_id' => …, 'properties' => [...]])` e associazione con `$layer->ecTracks()->attach($track->id)`):
  - `test_progress_counts_only_tracks_of_effective_owner` — layer di A con 3 tappe di A e 1 di B; utente valida 2 tappe di A → `validated 2`, `total 3`, `percentage 66`, `completed false`; la tappa di B ha `status 'not_validatable'`.
  - `test_layer_without_owner_uses_default_owner` — layer con `user_id` null, `config(['camminiditalia.default_owner_id' => $owner->id])`; le tappe di `$owner` contano.
  - `test_sql_owner_matches_layer_owner_helper` — per un layer con e senza `user_id`, l'insieme degli id da `countingTracksQuery()` coincide con `$layer->ecTracks()->where('ec_tracks.user_id', LayerOwner::idFor($layer))->pluck('ec_tracks.id')`.
  - `test_shared_track_same_owner_counts_in_both_layers` — una validazione, due layer dello stesso proprietario → `validated 1` in entrambi.
  - `test_shared_track_different_owners_counts_only_in_owner_layer` — stessa tappa in layer di A e di B, tappa di A → conta in A, `not_validatable` in B.
  - `test_completed_layer_returns_in_progress_after_new_track` — 2/2 `completed true`; aggiunta una terza tappa → `2/3`, `completed false`.
  - `test_distance_follows_package_priority` — tappe con: solo `manual_data.distance = "29"`; `osm_data.distance = 10` e `osmid` valorizzato; `osm_data.distance = 10` e `osmid` null con `dem_data.distance = 8`; solo `dem_data.distance = 5`; nessuna; `manual_data.distance = "12,5"` → `km_total` = 29 + 10 + 8 + 5 + 0 + 0 = 52.0.
  - `test_layer_without_tracks_has_zero_percentage` — `total 0`, `percentage 0`, `completed false`.
  - `test_passport_lists_only_layers_with_validations` — utente con validazioni in 1 layer su 2 → un solo elemento, con `last_validated_at` della validazione più recente.
  - `test_summary_query_groups_by_user_and_layer` — 2 utenti, 1 layer → 2 righe con `validated` e `total` corretti.
  - `test_scope_visible_to` — Administrator vede tutto; Validator vede solo le validazioni di tappe che contano nei layer con `user_id` = suo id, compresa una validazione `source 'gps'` senza `certification_request_id`; Guest e utente senza ruolo non vedono nulla.

- [ ] **Step 2: Esegui e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/StageProgressServiceTest.php`
Expected: FAIL, classe `App\Services\StageProgressService` non trovata.

- [ ] **Step 3: Implementa `StageProgressService` e `User::validatedEcTracks()`**

Una sola espressione SQL per la distanza, in un metodo privato `distanceSql(): string`, usata da `countingTracksQuery()` e `layerTracksQuery()`:

```sql
CASE
  WHEN NULLIF(ec_tracks.properties->'manual_data'->>'distance', '') IS NOT NULL
    THEN <num>(ec_tracks.properties->'manual_data'->>'distance')
  WHEN ec_tracks.osmid IS NOT NULL AND ec_tracks.properties->'osm_data'->>'distance' IS NOT NULL
    THEN <num>(ec_tracks.properties->'osm_data'->>'distance')
  ELSE <num>(ec_tracks.properties->'dem_data'->>'distance')
END
-- <num>(x) = CASE WHEN x ~ '^-?[0-9]+(\.[0-9]+)?$' THEN x::double precision ELSE 0 END
```

Proprietario come binding: `COALESCE(layers.user_id, ?)` con `config('camminiditalia.default_owner_id')`. Morph type come binding da `(new (config('wm-package.ec_track_model')))->getMorphClass()`. `progressFor()` e `passportFor()` fanno query aggregate (nessun caricamento di modelli tappa per tappa); `tracks` ordinato per `ec_track_id`.

- [ ] **Step 4: Esegui e verifica che passino**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/StageProgressServiceTest.php`
Expected: PASS

- [ ] **Step 5: Commit (istruzione per il dev)** — `feat(oc:8676): servizio di calcolo del progresso sulle tappe validate`

---

### Task 2: Endpoint `progress` e `passport`

**Files:**
- Create: `app/Http/Controllers/Api/StageProgressController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/StageProgressApiTest.php`

**Interfaces:**
- Consumes: `StageProgressService::progressFor()`, `::passportFor()` (Task 1).
- Produces: route `camminiditalia.api.layer.progress` (`GET /api/layer/{layer}/progress`, `whereNumber('layer')`, dentro il gruppo esistente `layer` con `auth:api`) e `camminiditalia.api.passport` (`GET /api/passport`, `auth:api`).

Forma esatta delle risposte:

```json
// GET /api/layer/{layer}/progress
{ "layer_id": 40, "validated": 6, "total": 13, "percentage": 46, "completed": false,
  "km_validated": 81.4, "km_total": 190.2,
  "tracks": [ { "id": 123, "status": "validated", "validated_at": "2026-09-30T10:00:00+00:00", "source": "manual" },
              { "id": 124, "status": "not_validated", "validated_at": null, "source": null },
              { "id": 522, "status": "not_validatable", "validated_at": null, "source": null } ] }

// GET /api/passport
{ "routes": [ { "layer_id": 40, "name": "Cammino degli Dei", "validated": 6, "total": 13, "percentage": 46,
                "completed": false, "km_validated": 81.4, "km_total": 190.2,
                "last_validated_at": "2026-09-30T10:00:00+00:00" } ] }
```

`name` = `$layer->getStringName()`. Il passaporto è dentro una chiave `routes` (oggetto, non array nudo) per poter aggiungere campi di primo livello in oc:8165.

- [ ] **Step 1: Scrivi i test che falliscono** (stesso setup di `CertificationRequestApiTest`):
  - `test_progress_without_auth_returns_401` e `test_passport_without_auth_returns_401`.
  - `test_progress_returns_expected_shape` — `assertExactJsonStructure` sulle chiavi sopra; valori di un caso 1 validata su 2.
  - `test_progress_for_unknown_layer_returns_404`.
  - `test_progress_with_no_validations_returns_zero` — 200, `validated 0`, tutte le tappe `not_validated`.
  - `test_progress_ignores_other_users_validations` — validazioni di un altro utente non compaiono.
  - `test_passport_returns_routes_of_logged_user_only`.

- [ ] **Step 2: Esegui e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/StageProgressApiTest.php`
Expected: FAIL con 404 sulle route.

- [ ] **Step 3: Implementa** `StageProgressController::progress(Request $request, Layer $layer): JsonResponse` e `::passport(Request $request): JsonResponse`, che delegano al servizio; aggiorna il commento in testa a `routes/api.php` con oc:8676.

- [ ] **Step 4: Esegui e verifica che passino** (stesso comando). Expected: PASS

- [ ] **Step 5: Scrivi il formato per la sessione frontend** in `docs/features/8676-passaporto-camminatore-tappe-percorse-e-validate-dellutente/api.md`: i due esempi sopra, il significato di ogni campo e dei tre `status`, le regole di `percentage`/`completed`/km, la regola «solo campi nuovi, mai rinominati».

- [ ] **Step 6: Commit (istruzione per il dev)** — `feat(oc:8676): endpoint progresso cammino e passaporto`

---

### Task 3: Sezione Nova «Tappe validate»

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-sezione-nova)

**Files:**
- Modify: `app/Nova/ValidatedEcTrack.php`
- Create: `app/Nova/Filters/ValidatedEcTrackSourceFilter.php`, `app/Nova/Filters/ValidatedEcTrackUserFilter.php`, `app/Nova/Filters/ValidatedEcTrackLayerFilter.php`
- Modify: `app/Providers/NovaServiceProvider.php` (voce nel `MenuSection` «Passport», righe 101-104)
- Test: `tests/Feature/ValidatedEcTrackNovaTest.php`

**Interfaces:**
- Consumes: `StageProgressService::scopeVisibleTo()`, `::countingTracksQuery()` (Task 1).
- Produces: `App\Nova\ValidatedEcTrack` nel menu; le classi filtro usate anche dalla Lens del Task 4.

Comportamento:
- `$displayInNavigation` rimosso (o `true`); voce `MenuItem::resource(ValidatedEcTrack::class)` sotto `CertificationRequest` nel `MenuSection` «Passport», che ha già il `canSee` Administrator/Validator.
- `scopeForUser()` delega a `StageProgressService::scopeVisibleTo()`; vale per index, detail e per il pannello `HasMany` del dettaglio richiesta (che passa da `indexQuery`).
- Campi in ordine: «User» (Administrator: link HTML al detail Nova della risorsa User, come la colonna layer di `HasLayerFilterAndLink`, con `htmlspecialchars()`; Validator: testo `UserDisplay::short()`), «Stage» (invariato), «Routes» (nomi dei layer in cui la tappa conta, separati da virgola, letti da `countingTracksQuery()` per `ec_track_id`), «Validated at» (`sortable()`), «Source» (invariato). Ordinamento di default per `validated_at desc`; «User» ordinabile per `user_id`.
- Filtri: origine (`manual`/`gps`, etichette «Paper passport»/«GPS»); camminatore (opzioni = utenti con almeno una validazione visibile al richiedente, etichetta `UserDisplay::short()`); cammino (opzioni = layer visibili al richiedente — Administrator tutti i layer con tappe, Validator `layers.user_id = id` — e `apply()` con `whereIn('ec_track_id', countingTracksQuery()->where('layer_id', $value)->select('ec_track_id'))`).
- Sola lettura invariata (`authorizedTo*` false).

- [ ] **Step 1: Scrivi i test che falliscono** (pattern di `CertificationRequestNovaTest`: `NovaRequest` + `indexQuery`, e chiamate HTTP a `/nova-api/validated-ec-tracks` con `actingAs`):
  - `test_administrator_sees_all_validations`.
  - `test_validator_sees_validations_of_tracks_counting_in_own_layers` — include una validazione `gps` senza richiesta, esclude una validazione su tappa di altro proprietario presente nel suo layer.
  - `test_validator_without_layers_sees_nothing`.
  - `test_guest_cannot_access_resource` — 403.
  - `test_request_detail_panel_uses_layer_scoping` — il `HasMany` del dettaglio richiesta mostra le stesse righe dello scoping per layer.
  - `test_user_field_is_link_only_for_administrator`.
  - `test_layer_filter_uses_counting_tracks_not_layer_id` — validazione con `layer_id` = layer A su tappa che conta anche in B: il filtro B la include.
  - `test_source_and_user_filters`.
  - `test_resource_is_read_only` — create/update/delete negati.

- [ ] **Step 2: Esegui e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/ValidatedEcTrackNovaTest.php`
Expected: FAIL

- [ ] **Step 3: Implementa** risorsa, filtri e voce di menu come descritto sopra.

- [ ] **Step 4: Esegui e verifica che passino, insieme ai test esistenti del passaporto**

Run: `docker exec laravel-camminiditalia php artisan test --filter='ValidatedEcTrack|CertificationRequest'`
Expected: PASS (i test di oc:8671 sul pannello della richiesta restano verdi o vanno aggiornati solo dove asserivano lo scoping per richiesta; ogni aggiornamento va annotato in `notes.md`).

- [ ] **Step 5: Commit (istruzione per il dev)** — `feat(oc:8676): sezione Nova tappe validate con filtri e scoping per cammino`

---

### Task 4: Lens «Per utente e cammino»

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-lens-riassuntiva)

**Files:**
- Create: `app/Nova/Lenses/ValidatedEcTrackSummary.php`
- Modify: `app/Nova/ValidatedEcTrack.php` (`lenses()`)
- Test: `tests/Feature/ValidatedEcTrackSummaryLensTest.php`

**Interfaces:**
- Consumes: `StageProgressService::summaryQuery()`, `::scopeVisibleTo()`, filtri del Task 3.

Comportamento:
- `query()` = `$request->withOrdering($request->withFilters(<summaryQuery() ristretta da scopeVisibleTo>))`; per il Validator, oltre allo scoping delle righe, solo i layer con `layers.user_id = id`.
- Campi: «User» (stessa regola link/testo del Task 3), «Route» (`getStringName()` del layer), «Stages» (`"{validated} / {total}"`), «Status» («Completed» / «In progress»), «Last validation» (`last_validated_at`).
- Filtri: origine non applicabile (riga aggregata) → solo camminatore e cammino.
- Nessuna action.

- [ ] **Step 1: Scrivi i test che falliscono**:
  - `test_lens_returns_one_row_per_user_and_route` — 2 utenti × 2 layer con validazioni → 4 righe con conteggi giusti.
  - `test_lens_status_completed_when_all_tracks_validated`.
  - `test_lens_for_validator_shows_only_own_routes`.
  - `test_lens_http_index_paginates` — `GET /nova-api/validated-ec-tracks/lens/validated-ec-track-summary` risponde 200 con le righe attese (verifica che paginazione e `GROUP BY` funzionino insieme).

- [ ] **Step 2: Esegui e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/ValidatedEcTrackSummaryLensTest.php`
Expected: FAIL

- [ ] **Step 3: Implementa** la Lens. Se la paginazione di Nova non regge il `GROUP BY`, avvolgi la query aggregata in una subquery (`ValidatedEcTrack::query()->fromSub(..., 'validated_ec_tracks')`) e annota la scelta in `notes.md`.

- [ ] **Step 4: Esegui e verifica che passino** (stesso comando). Expected: PASS

- [ ] **Step 5: Commit (istruzione per il dev)** — `feat(oc:8676): lens riassuntiva tappe validate per camminatore e cammino`

---

### Task 5: Traduzioni, verifica complessiva e documentazione

**Files:**
- Modify: `resources/lang/{it,en,fr,es,de}.json`
- Modify: `docs/knowledge/passaporto-validazione-credenziale-cartacea.md`
- Modify: `CLAUDE.md` (riga in `## Feature disponibili`, forma attuale del repo)

- [ ] **Step 1: Aggiungi le chiavi** nuove dei Task 3-4 («User», «Routes», «Route», «Stages», «Status», «Completed», «In progress», «Last validation», «Per user and route», etichette dei filtri) in tutti e cinque i file, senza toccare le chiavi esistenti.

- [ ] **Step 2: Verifica che nessuna chiave manchi**

Run: `for k in "User" "Routes" "Route" "Stages" "Status" "Completed" "In progress" "Last validation" "Per user and route"; do for f in resources/lang/*.json; do jq -e --arg k "$k" 'has($k)' "$f" >/dev/null || echo "manca '$k' in $f"; done; done` (aggiungi alla lista le etichette dei filtri effettivamente usate)
Expected: nessun output.

- [ ] **Step 3: Suite completa e PHPStan**

Run: `docker exec laravel-camminiditalia php artisan test` e `docker exec laravel-camminiditalia vendor/bin/phpstan analyse`
Expected: test tutti verdi; PHPStan senza errori sui file del diff.

- [ ] **Step 4: Aggiorna la pagina di conoscenza** (riscrittura di «Come funziona oggi»: rimuovere «nessuna API le espone ancora»; spostare in «Come ci siamo arrivati» l'esclusione dell'endpoint `progress` di oc:8653 e lo scoping per richiesta di oc:8671, con il motivo) e la riga in `CLAUDE.md` — nella fase update-context di wm-plan, con approvazione del dev.

- [ ] **Step 5: Commit (istruzione per il dev)** — `docs(oc:8676): traduzioni, pagina di conoscenza e CLAUDE.md`

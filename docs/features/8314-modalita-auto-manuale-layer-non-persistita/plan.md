> Ticket: oc:8314

# Modalità auto/manuale del layer non persistita lato backend — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Nessun commit o branch automatico.** I comandi `git commit`/`git push`/`git checkout -b` mostrati in questo piano sono istruzioni testuali per lo sviluppatore, non azioni da eseguire in autonomia. L'esecutore scrive solo i file; i commit avvengono dopo review esplicita del developer (vedi `wm-plan` → `execution: review-gate`).

**Goal:** Persistere la modalità auto/manuale del layer (`configuration->track_mode`/`poi_mode`) su entrambi i repo (wm-package + camminiditalia), correggendo l'ordine di scrittura rispetto al ricalcolo da tassonomia e la concorrenza di scrittura sulla colonna JSON condivisa.

**Architecture:** Un nuovo metodo `protected persistMode()` in `wm-package`'s `LayerFeatureController` centralizza la persistenza, chiamato sia dal `sync()` del package sia dall'override locale di camminiditalia (che non delega a `parent::sync()` per motivi di ownership — oc:8311). La scrittura vera e propria (`Layer::setTrackMode()`/`setPoiMode()`) usa `jsonb_set` in un singolo `UPDATE` SQL atomico, non un read-modify-write PHP, per evitare lost-update tra i due pannelli Nova (Tracce/POI) che scrivono sulla stessa colonna. Il frontend Vue invia il flag di modalità mode-only (senza `features`) sul verso auto→manuale, perché il pivot `layerables` contiene già la selezione corretta dall'ultima sincronizzazione automatica.

**Tech Stack:** Laravel 11, Nova 5, PostgreSQL/PostGIS (colonna `configuration` tipo `jsonb`), Vue 3 + TypeScript compilato con Laravel Mix, PHPUnit su DB `camminiditalia_testing`, ambiente Docker (`laravel-camminiditalia`).

**Spec:**
- `docs/features/8314-modalita-auto-manuale-layer-non-persistita/overview.md` (camminiditalia)
- `wm-package/docs/features/8314-modalita-auto-manuale-layer-non-persistita/overview.md` (wm-package)

## Global Constraints

- Tutti i comandi `php artisan`/`vendor/bin/*` vanno eseguiti dentro il container: `docker exec laravel-camminiditalia <comando>`.
- I test PHPUnit girano su `camminiditalia_testing`, DB separato — `RefreshDatabase` è sicuro (non tocca dati di sviluppo).
- **Tutti** i test PHP di questa feature vanno in `tests/Feature/LayerFeatureControllerTest.php` nel repo principale, **mai** in `wm-package/tests/` — precedente oc:8140: `Wm\WmPackage\Tests\TestCase` non è in `autoload-dev` di camminiditalia.
- `EcPoi::factory()->create()` richiede sempre `'properties' => []` esplicito, altrimenti `AbstractObserver` lancia `TypeError` (trappola nota, oc:8120).
- Nessuna modifica al comportamento distruttivo di `auto: true` (sovrascrive selezione manuale) — intenzionale, preesistente, presidiato da `ConfirmModal` lato Vue.
- Nessuna modifica a `getFeatures()` (né package né locale) — fuori scope, comportamento visibile già corretto perché le checkbox sono disabilitate lato Vue in base a `isManual`.
- Nessuna modifica a `LayerObserver::hasTaxonomyActivitiesChanged()` — il side-effect cross-relazione discusso in overview è un rischio accettato, non un bug da correggere in questo ciclo.
- Formattazione: `docker exec laravel-camminiditalia composer format` (Laravel Pint) prima di ogni commit; se tocca file del submodule, ripristinare modifiche non intenzionali con `git -C wm-package checkout -- .` per i soli file non voluti.
- `has_phpstan_ci: true` — eseguire `docker exec laravel-camminiditalia vendor/bin/phpstan analyse` prima del commit finale.

---

## File Structure

**wm-package (submodule):**

| File | Responsabilità |
|---|---|
| `src/Models/Layer.php` | `setTrackMode()`/`setPoiMode()`: scrittura atomica via `jsonb_set`, invece del read-modify-write PHP attuale |
| `src/Nova/Fields/LayerFeatures/src/Http/Controllers/LayerFeatureController.php` | Nuovo `protected persistMode()`; `sync()`: validazione `manual`/`auto` mutuamente esclusivi, chiamata a `persistMode()` prima di qualunque assign/sync sul pivot |
| `src/Nova/Fields/LayerFeatures/resources/js/composables/useFeatures.ts` | `handleSave`: aggiunge `manual: true` al payload esistente |
| `src/Nova/Fields/LayerFeatures/resources/js/components/LayerFeature.vue` | `handleToggleClick`: nuova chiamata POST mode-only sul verso auto→manuale |
| `src/Nova/Fields/LayerFeatures/dist/*` | Rebuild via `npm run prod` |

**camminiditalia (repo principale):**

| File | Responsabilità |
|---|---|
| `app/Http/Controllers/LayerFeatureController.php` | `sync()`: stessa validazione mutuamente esclusiva, chiamata a `persistMode()` ereditato, nessuna duplicazione di logica |
| `tests/Feature/LayerFeatureControllerTest.php` | Tutti i test della feature (7 casi, vedi Task 1 e Task 3) |
| gitlink `wm-package` | Aggiornato al nuovo commit del submodule dopo il commit lì |

---

## Task 1: `Layer::setTrackMode()`/`setPoiMode()` — scrittura atomica con `jsonb_set`

**Files:**
- Modify: `wm-package/src/Models/Layer.php:158-176`
- Test: `tests/Feature/LayerFeatureControllerTest.php` (repo principale)

**Interfaces:**
- Consumes: nessuna dipendenza da task precedenti (primo task)
- Produces: `Layer::setTrackMode(string $mode): void`, `Layer::setPoiMode(string $mode): void` — firma invariata, usate da Task 3 (`persistMode()`)

- [ ] **Step 1: Scrivi i test falliti in `tests/Feature/LayerFeatureControllerTest.php`**

Apri il file esistente e aggiungi in fondo alla classe (o al blocco di test più affine, es. vicino ai test esistenti su `sync`):

```php
    public function test_set_track_mode_persists_value_and_preserves_other_configuration_keys(): void
    {
        $layer = Layer::factory()->create([
            'configuration' => ['some_other_key' => 'kept'],
        ]);

        $layer->setTrackMode('manual');

        $fresh = $layer->fresh();
        $this->assertSame('manual', $fresh->configuration['track_mode']);
        $this->assertSame('kept', $fresh->configuration['some_other_key']);
    }

    public function test_set_track_mode_accepts_null_configuration(): void
    {
        $layer = Layer::factory()->create(['configuration' => null]);

        $layer->setTrackMode('manual');

        $fresh = $layer->fresh();
        $this->assertSame('manual', $fresh->configuration['track_mode']);
    }

    public function test_set_track_mode_and_set_poi_mode_do_not_lose_each_other_under_concurrent_writes(): void
    {
        $layer = Layer::factory()->create(['configuration' => null]);

        // Simula due richieste concorrenti: due istanze caricate PRIMA che
        // una delle due scriva, come accadrebbe con due pannelli Nova
        // (Tracce e POI) sulla stessa pagina che POSTano quasi in contemporanea.
        $layerFromRequestA = Layer::find($layer->id);
        $layerFromRequestB = Layer::find($layer->id);

        $layerFromRequestA->setTrackMode('manual');
        $layerFromRequestB->setPoiMode('manual');

        $fresh = $layer->fresh();
        $this->assertSame('manual', $fresh->configuration['track_mode']);
        $this->assertSame('manual', $fresh->configuration['poi_mode']);
    }
```

Verifica che il file importi già `use Wm\WmPackage\Models\Layer;` (dovrebbe già esserci, controllane la presenza in cima al file e aggiungila se manca).

- [ ] **Step 2: Esegui i test e verifica che falliscano**

```bash
docker exec laravel-camminiditalia php artisan test --filter=test_set_track_mode_persists_value_and_preserves_other_configuration_keys
docker exec laravel-camminiditalia php artisan test --filter=test_set_track_mode_accepts_null_configuration
docker exec laravel-camminiditalia php artisan test --filter=test_set_track_mode_and_set_poi_mode_do_not_lose_each_other_under_concurrent_writes
```

Expected: i primi due FALLISCONO con `Undefined array key "track_mode"` (perché `setTrackMode` oggi non scrive `configuration`... verifica: in realtà l'implementazione attuale FA scrivere `configuration['track_mode']`, ma con un read-modify-write PHP — quindi i primi due test potrebbero già passare con il codice attuale). Il terzo test (concorrenza) deve FALLIRE con l'implementazione attuale: `$fresh->configuration['track_mode']` risulterà `null`/assente, perché `$layerFromRequestB` ha letto `configuration` prima della scrittura di A e la sua `save()` sovrascrive l'intero blob perdendo `track_mode`.

Se i primi due test passano già con l'implementazione corrente, va bene — servono comunque come test di non-regressione per la riscrittura del Task successivo. Il terzo test è quello che deve necessariamente fallire ora.

- [ ] **Step 3: Riscrivi `setTrackMode()`/`setPoiMode()` con `jsonb_set`**

Apri `wm-package/src/Models/Layer.php`. Verifica che in cima al file sia già importato `use Illuminate\Support\Facades\DB;` (se manca, aggiungilo agli `use` esistenti). Sostituisci le righe 158-176:

```php
    public function isAutoTrackMode(): bool
    {
        return ($this->configuration['track_mode'] ?? 'auto') === 'auto';
    }

    public function setTrackMode(string $mode): void
    {
        DB::statement(
            "UPDATE layers SET configuration = jsonb_set(coalesce(configuration, '{}'::jsonb), '{track_mode}', to_jsonb(?::text)) WHERE id = ?",
            [$mode, $this->id]
        );

        $this->refresh();
    }

    public function isAutoPoiMode(): bool
    {
        return ($this->configuration['poi_mode'] ?? 'auto') === 'auto';
    }

    public function setPoiMode(string $mode): void
    {
        DB::statement(
            "UPDATE layers SET configuration = jsonb_set(coalesce(configuration, '{}'::jsonb), '{poi_mode}', to_jsonb(?::text)) WHERE id = ?",
            [$mode, $this->id]
        );

        $this->refresh();
    }
```

Nota: `DB::statement` con placeholder `?` usa i binding parametrizzati di PDO — nessuna concatenazione di stringhe, nessun rischio di SQL injection anche se `$mode` provenisse da input utente non validato.

- [ ] **Step 4: Esegui i test e verifica che passino**

```bash
docker exec laravel-camminiditalia php artisan test --filter=LayerFeatureControllerTest
```

Expected: PASS su tutti e tre i nuovi test.

- [ ] **Step 5: Commit**

```bash
git -C wm-package add src/Models/Layer.php
git -C wm-package commit -m "fix(oc:8314): scrittura atomica di track_mode/poi_mode con jsonb_set"

git add tests/Feature/LayerFeatureControllerTest.php
git commit -m "test(oc:8314): verifica persistenza atomica di track_mode/poi_mode"
```

---

## Task 2: Verifica stato submodule e aggiornamento gitlink preliminare

**Files:**
- Nessun file di codice modificato in questo task — solo verifica ambiente e allineamento gitlink dopo il Task 1

**Interfaces:**
- Consumes: commit di Task 1 già presente in `wm-package` (branch `develop` locale del submodule)
- Produces: gitlink del repo principale allineato, base pulita per i task successivi

- [ ] **Step 1: Verifica che il submodule sia su un branch reale, non detached HEAD**

```bash
git -C wm-package branch --show-current
```

Expected: `develop`. Se vuoto (detached HEAD), risolvi prima di continuare:

```bash
git -C wm-package checkout develop
git -C wm-package pull origin develop
```

- [ ] **Step 2: Verifica che il commit del Task 1 sia registrato**

```bash
git -C wm-package log --oneline -1
```

Expected: mostra il commit `fix(oc:8314): scrittura atomica di track_mode/poi_mode con jsonb_set`.

- [ ] **Step 3: Aggiorna il gitlink nel repo principale**

```bash
git status wm-package
```

Expected: mostra `wm-package` come modificato (il gitlink punta al nuovo commit). Non fare `git add` ancora — il gitlink verrà incluso nel commit finale dopo tutti i task su wm-package, per evitare commit intermedi con stato incoerente tra i due repo.

- [ ] **Step 4: Nessun commit in questo task**

Questo task è di sola verifica — si passa direttamente al Task 3.

---

## Task 3: `persistMode()` nel controller del package + integrazione nell'override locale

**Files:**
- Modify: `wm-package/src/Nova/Fields/LayerFeatures/src/Http/Controllers/LayerFeatureController.php`
- Modify: `app/Http/Controllers/LayerFeatureController.php` (repo principale)
- Test: `tests/Feature/LayerFeatureControllerTest.php` (repo principale)

**Interfaces:**
- Consumes: `Layer::setTrackMode(string $mode): void`, `Layer::setPoiMode(string $mode): void` (da Task 1)
- Produces: `protected function persistMode(Layer $layer, string $relationName, Request $request): void` — usato solo internamente dalle due classi `sync()`, nessun altro task lo consuma direttamente

**Nota sulla verifica via HTTP:** la route `POST /nova-vendor/layer-features/sync/{layerId}` è servita, su camminiditalia, **esclusivamente** dal controller locale (verificato con `php artisan route:list --path=layer-features` — l'override in `NovaServiceProvider` vince su quella del package). I test HTTP di questo task esercitano quindi sempre il controller locale, che a sua volta chiama `persistMode()` ereditato dal package: un solo giro di test copre entrambe le implementazioni.

- [ ] **Step 1: Scrivi i test falliti in `tests/Feature/LayerFeatureControllerTest.php`**

Aggiungi (adatta i nomi delle factory/route esistenti nel file al pattern già in uso — verifica come gli altri test del file autenticano l'utente e costruiscono il layer, es. `actingAs`, `Layer::factory()->for($user)`, ecc., e replica lo stesso pattern):

```php
    public function test_sync_with_manual_true_persists_manual_mode_for_tracks(): void
    {
        $owner = User::factory()->create();
        $layer = Layer::factory()->create(['user_id' => $owner->id]);
        $track = EcTrack::factory()->create(['user_id' => $owner->id]);
        $layer->ecTracks()->sync([$track->id]);

        $this->actingAs($owner)
            ->postJson("/nova-vendor/layer-features/sync/{$layer->id}", [
                'model' => EcTrack::class,
                'manual' => true,
            ])
            ->assertOk();

        $this->assertSame('manual', $layer->fresh()->configuration['track_mode']);
    }

    public function test_sync_with_auto_true_persists_auto_mode_explicitly(): void
    {
        $owner = User::factory()->create();
        $layer = Layer::factory()->create([
            'user_id' => $owner->id,
            'configuration' => ['track_mode' => 'manual'],
        ]);

        $this->actingAs($owner)
            ->postJson("/nova-vendor/layer-features/sync/{$layer->id}", [
                'model' => EcTrack::class,
                'auto' => true,
                'features' => [],
            ])
            ->assertOk();

        $this->assertSame('auto', $layer->fresh()->configuration['track_mode']);
    }

    public function test_sync_with_manual_true_and_no_features_does_not_touch_pivot(): void
    {
        $owner = User::factory()->create();
        $layer = Layer::factory()->create(['user_id' => $owner->id]);
        $trackA = EcTrack::factory()->create(['user_id' => $owner->id]);
        $trackB = EcTrack::factory()->create(['user_id' => $owner->id]);
        $layer->ecTracks()->sync([$trackA->id, $trackB->id]);

        $this->actingAs($owner)
            ->postJson("/nova-vendor/layer-features/sync/{$layer->id}", [
                'model' => EcTrack::class,
                'manual' => true,
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$trackA->id, $trackB->id],
            $layer->fresh()->ecTracks()->pluck('ec_tracks.id')->toArray()
        );
    }

    public function test_sync_rejects_auto_and_manual_together(): void
    {
        $owner = User::factory()->create();
        $layer = Layer::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)
            ->postJson("/nova-vendor/layer-features/sync/{$layer->id}", [
                'model' => EcTrack::class,
                'auto' => true,
                'manual' => true,
            ])
            ->assertStatus(422);

        $this->assertNull($layer->fresh()->configuration['track_mode'] ?? null);
    }
```

Nota: adatta i nomi dei parametri di autenticazione/autorizzazione (`$owner`, il controllo `403`) al pattern reale già usato negli altri test del file — leggi i test esistenti in `LayerFeatureControllerTest.php` prima di scrivere questi per allinearti a factory e helper già presenti (es. se il file usa già un trait per l'autenticazione admin, riusalo).

- [ ] **Step 2: Esegui i test e verifica che falliscano**

```bash
docker exec laravel-camminiditalia php artisan test --filter=LayerFeatureControllerTest
```

Expected: i 4 nuovi test FALLISCONO — `manual` non è un parametro validato/gestito, `configuration` resta vuota dopo la richiesta, e la richiesta con `auto`+`manual` insieme non produce 422 (nessuna regola `prohibits` esiste ancora).

- [ ] **Step 3: Implementa `persistMode()` e aggiorna `sync()` nel controller del package**

Apri `wm-package/src/Nova/Fields/LayerFeatures/src/Http/Controllers/LayerFeatureController.php`. Sostituisci il metodo `sync()` esistente con:

```php
    public function sync(Request $request, $layerId): JsonResponse
    {
        $layer = Layer::findOrFail($layerId);

        $validatedData = $request->validate([
            'features' => 'array',
            'model' => 'required|string',
            'auto' => ['boolean', 'prohibits:manual'],
            'manual' => ['boolean', 'prohibits:auto'],
        ]);

        // Creo un'istanza del modello per ottenere il nome della relazione
        $model = new $validatedData['model'];

        if (! method_exists($model, 'getLayerRelationName')) {
            return response()->json([
                'error' => "Il modello '{$validatedData['model']}' non implementa l'interfaccia LayerRelatedModel.",
            ], 400);
        }

        $relationName = $model->getLayerRelationName();

        if (! method_exists($layer, $relationName)) {
            return response()->json([
                'error' => "La relazione '{$relationName}' non esiste nel modello Layer.",
            ], 400);
        }

        $this->persistMode($layer, $relationName, $request);

        $isAutoRequest = ! empty($validatedData['auto']) && in_array($relationName, ['ecTracks', 'ecPois']);
        if ($isAutoRequest) {
            // In modalità automatica il pivot ecTracks/ecPois viene ricalcolato da taxonomy
            if ($relationName === 'ecTracks') {
                $this->layerService->assignTracksByTaxonomy($layer);
            } else {
                $this->layerService->assignPoisByTaxonomy($layer);
            }
        } elseif ($request->has('features')) {
            $layer->{$relationName}()->sync($validatedData['features'] ?? []);
        }
        // Nessun 'auto', nessun 'features': richiesta mode-only, il pivot non viene toccato.

        // I PBF non contengono mai contenuto POI: rigenerarli ha senso solo per ecTracks.
        if ($relationName === 'ecTracks') {
            $this->pbfGeneratorService->regeneratePbfsForLayer($layer);
        }

        $tableName = $model->getTable();
        $assignedIds = $layer->{$relationName}()->select($tableName.'.id')->pluck('id')->toArray();

        return response()->json([
            'message' => 'Features sincronizzate con successo',
            'assigned_ids' => $assignedIds,
        ], 200);
    }

    /**
     * Persiste la modalità (auto/manuale) sul layer per la relazione indicata.
     * Non contiene logica di autorizzazione né di calcolo degli ID selezionati:
     * resta utilizzabile da sottoclassi che riscrivono sync() con regole di
     * ownership proprie senza delegare a parent::sync() (es. camminiditalia).
     *
     * Va chiamato PRIMA di qualunque assign/sync sul pivot: assignTracksByTaxonomy()/
     * assignPoisByTaxonomy() leggono isAutoTrackMode()/isAutoPoiMode() e devono
     * vedere il valore già aggiornato, altrimenti il ricalcolo da tassonomia
     * viene bloccato dal guard sulla modalità precedente.
     */
    protected function persistMode(Layer $layer, string $relationName, Request $request): void
    {
        if ($request->boolean('manual')) {
            $mode = 'manual';
        } elseif ($request->boolean('auto')) {
            $mode = 'auto';
        } else {
            return;
        }

        if ($relationName === 'ecTracks') {
            $layer->setTrackMode($mode);
        } elseif ($relationName === 'ecPois') {
            $layer->setPoiMode($mode);
        }
    }
```

- [ ] **Step 4: Aggiorna il controller locale in camminiditalia**

Apri `app/Http/Controllers/LayerFeatureController.php`. Nel metodo `sync()`, sostituisci il blocco di validazione:

```php
            $validatedData = $request->validate([
                'features' => 'array',
                'model' => 'required|string',
                'auto' => 'boolean',
            ]);
```

con:

```php
            $validatedData = $request->validate([
                'features' => 'array',
                'model' => 'required|string',
                'auto' => ['boolean', 'prohibits:manual'],
                'manual' => ['boolean', 'prohibits:auto'],
            ]);
```

Poi, subito dopo il controllo `if (! method_exists($layer, $relationName)) { ... }` e prima del calcolo di `$isAutoRequest`, aggiungi la chiamata:

```php
            $this->persistMode($layer, $relationName, $request);

            $isAutoRequest = ! empty($validatedData['auto']) && in_array($relationName, ['ecTracks', 'ecPois']);

            if ($isAutoRequest) {
                $ownedIds = $model->newQuery()->where('user_id', $layerOwnerId)->pluck('id')->toArray();

                $layer->{$relationName}()->sync($ownedIds);
            } elseif ($request->has('features')) {
                $requestedIds = $validatedData['features'] ?? [];

                $ownedIds = $model->newQuery()->whereIn('id', $requestedIds)->where('user_id', $layerOwnerId)->pluck('id')->toArray();

                $layer->{$relationName}()->sync($ownedIds);
            }
```

(sostituisce l'attuale blocco `if ($isAutoRequest) { ... } else { ... }` che oggi esegue sempre `sync()` sul pivot anche nel ramo manuale senza `features`).

`persistMode()` è ereditato da `WmLayerFeatureController` (la classe base importata come `use Wm\WmPackage\Nova\Fields\LayerFeatures\Http\Controllers\LayerFeatureController as WmLayerFeatureController;`, già presente in cima al file) — nessun nuovo `use` necessario perché è un metodo `protected`, accessibile dalla sottoclasse.

- [ ] **Step 5: Esegui i test e verifica che passino**

```bash
docker exec laravel-camminiditalia php artisan test --filter=LayerFeatureControllerTest
```

Expected: PASS su tutti i test del file (i 3 di Task 1 + i 4 di questo task).

- [ ] **Step 6: Esegui l'intera suite per verificare l'assenza di regressioni**

```bash
docker exec laravel-camminiditalia php artisan test
```

Expected: tutti i test passano (nessuna regressione sui test esistenti di `LayerFeatureControllerTest`, `LayerActionsVisibilityTest`, `LayerOwnershipTransferTest`, ecc.).

- [ ] **Step 7: Commit**

```bash
git -C wm-package add src/Nova/Fields/LayerFeatures/src/Http/Controllers/LayerFeatureController.php
git -C wm-package commit -m "feat(oc:8314): persistMode() e validazione manual/auto mutuamente esclusivi in sync()"

git add app/Http/Controllers/LayerFeatureController.php tests/Feature/LayerFeatureControllerTest.php
git commit -m "feat(oc:8314): persisti la modalita auto/manuale nel controller locale"
```

---

## Task 4: Frontend Vue — invio del flag di modalità

**Files:**
- Modify: `wm-package/src/Nova/Fields/LayerFeatures/resources/js/composables/useFeatures.ts`
- Modify: `wm-package/src/Nova/Fields/LayerFeatures/resources/js/components/LayerFeature.vue`

**Interfaces:**
- Consumes: endpoint `POST /nova-vendor/layer-features/sync/{layerId}` già esistente, ora capace di accettare `manual` (Task 3)
- Produces: nessuna interfaccia consumata da altri task — è l'ultimo anello della catena frontend→backend

**Nota:** non esiste infrastruttura di test automatico per i componenti Vue in questo repo. La verifica di questo task è manuale (Step 5), da eseguire nell'ambiente Nova reale dopo il rebuild del dist (Task 5).

- [ ] **Step 1: Aggiungi `manual: true` al payload di `handleSave`**

Apri `wm-package/src/Nova/Fields/LayerFeatures/resources/js/composables/useFeatures.ts`. Trova `handleSave` (circa riga 195-219):

```typescript
    const handleSave = async (): Promise<void> => {
        try {
            isSaving.value = true;
            const layerId = props.field.layerId;

            if (!layerId) {
                throw new Error('LayerId is required for saving');
            }

            await Nova.request().post(`/nova-vendor/layer-features/sync/${layerId}`, {
                features: persistentSelectedIds.value,
                model: props.field.model,
            });
```

Modifica la chiamata `post` aggiungendo `manual: true`:

```typescript
            await Nova.request().post(`/nova-vendor/layer-features/sync/${layerId}`, {
                features: persistentSelectedIds.value,
                model: props.field.model,
                manual: true,
            });
```

Il resto della funzione (`Nova.success`, `props.field.value = ...`, `fetchFeatures()`) resta invariato.

- [ ] **Step 2: Aggiungi la chiamata di persistenza mode-only in `handleToggleClick`**

Apri `wm-package/src/Nova/Fields/LayerFeatures/resources/js/components/LayerFeature.vue`. Trova `handleToggleClick` (circa riga 324-338):

```typescript
        const handleToggleClick = async () => {
            if (isManual.value && persistentSelectedIds.value.length > 0) {
                showConfirmModal.value = true;
            } else {
                isManual.value = !isManual.value;
                if (isManual.value) {
                    try {
                        await fetchFeatures();
                    } catch (error) {
                        console.error("Error during toggle mode:", error);
                    }
                } else {
                    await handleModeChange();
                }
            }
        };
```

Sostituiscilo con:

```typescript
        const handleToggleClick = async () => {
            if (isManual.value && persistentSelectedIds.value.length > 0) {
                showConfirmModal.value = true;
            } else {
                isManual.value = !isManual.value;
                if (isManual.value) {
                    await persistManualMode();
                } else {
                    await handleModeChange();
                }
            }
        };

        const persistManualMode = async () => {
            try {
                isSaving.value = true;
                const layerId = props.field.layerId;
                await Nova.request().post(
                    `/nova-vendor/layer-features/sync/${layerId}`,
                    {
                        model: props.field.model,
                        manual: true,
                    }
                );
                await fetchFeatures();
            } catch (error) {
                console.error("Error during toggle mode:", error);
                Nova.error("Errore durante il cambio di modalità");
                isManual.value = false;
            } finally {
                isSaving.value = false;
            }
        };
```

Nota: la richiesta **non** include `features` — il pivot resta quello già scritto dall'ultima sincronizzazione automatica, coerentemente con il contratto mode-only implementato in Task 3. Su errore, `isManual.value` torna a `false` (stato precedente al click), coerente con il pattern di rollback già usato in `handleModeChange` per il verso opposto.

- [ ] **Step 3: Esporta `persistManualMode` se necessario**

Verifica il blocco `return { ... }` in fondo al `setup()` di `LayerFeature.vue` (circa riga 400-430): se `handleToggleClick` è già esportato e `persistManualMode` è usata solo internamente al componente, non serve esportarla. Verifica comunque che non ci siano errori di compilazione TypeScript riferiti a variabili non dichiarate.

- [ ] **Step 4: Verifica manuale (nessun test automatico disponibile)**

Questo step si esegue dopo il Task 5 (rebuild dist), in ambiente Nova reale:

1. Apri un layer in Nova, pannello "Ec Tracks", verifica che sia in modalità "auto" con delle tracce associate.
2. Clicca il toggle per passare a "manuale".
3. Apri gli strumenti sviluppatore del browser, tab Network: verifica che sia partita una richiesta `POST /nova-vendor/layer-features/sync/{id}` con payload `{ model: "...", manual: true }` (nessun campo `features`).
4. Ricarica la pagina: il toggle deve mostrare ancora "manuale" (non deve tornare su "auto").
5. Verifica che le tracce precedentemente associate in auto siano ancora nella lista "selezionate" del pannello manuale.

Documenta l'esito di questa verifica in `notes.md` (Task 8).

- [ ] **Step 5: Commit**

```bash
git -C wm-package add src/Nova/Fields/LayerFeatures/resources/js/composables/useFeatures.ts src/Nova/Fields/LayerFeatures/resources/js/components/LayerFeature.vue
git -C wm-package commit -m "feat(oc:8314): invia il flag di modalita al backend su toggle e salvataggio"
```

---

## Task 5: Rebuild del dist compilato

**Files:**
- Modify: `wm-package/src/Nova/Fields/LayerFeatures/dist/*` (rigenerato, non scritto a mano)

**Interfaces:**
- Consumes: sorgenti TypeScript/Vue modificati in Task 4
- Produces: asset compilati effettivamente serviti da Nova in produzione (nessun task successivo li consuma direttamente)

- [ ] **Step 1: Esegui il rebuild**

```bash
cd wm-package/src/Nova/Fields/LayerFeatures
npm install
npm run prod
cd -
```

Se il comando va eseguito dentro un container invece che sull'host, verifica il `package.json` del campo per lo script `prod` e adatta il comando di conseguenza (es. `docker exec laravel-camminiditalia npm run prod --prefix wm-package/src/Nova/Fields/LayerFeatures`), mantenendo `npm run prod` come script effettivo — non `npm run build` (decisione registrata in oc:8089, Laravel Mix).

- [ ] **Step 2: Verifica che il dist sia effettivamente cambiato**

```bash
git -C wm-package status --porcelain -- src/Nova/Fields/LayerFeatures/dist/
```

Expected: elenco di file modificati sotto `dist/` (almeno `mix-manifest.json` e i bundle JS). Se l'output è vuoto, il rebuild non ha prodotto modifiche — verifica di aver salvato i file di Task 4 prima di eseguire `npm run prod`.

- [ ] **Step 3: Commit**

```bash
git -C wm-package add src/Nova/Fields/LayerFeatures/dist/
git -C wm-package commit -m "chore(oc:8314): rebuild dist campo LayerFeatures"
```

---

## Task 6: Aggiornamento gitlink finale nel repo principale

**Files:**
- Modify: gitlink `wm-package` (repo principale)

**Interfaces:**
- Consumes: tutti i commit di wm-package (Task 1, 3, 4, 5)
- Produces: repo principale allineato al submodule aggiornato, base per Task 7

- [ ] **Step 1: Verifica il log dei commit del submodule**

```bash
git -C wm-package log --oneline -5
```

Expected: mostra in cima, nell'ordine, i commit di Task 5 (rebuild dist), Task 4 (frontend), Task 3 (persistMode), Task 1 (jsonb_set) — verifica che nessuno manchi.

- [ ] **Step 2: Verifica il diff del gitlink**

```bash
git diff --submodule=short -- wm-package
```

Expected: mostra l'avanzamento da `db707c8a` al nuovo HEAD del submodule.

- [ ] **Step 3: Push del submodule (se il developer conferma)**

Questo step **non va eseguito automaticamente**. È un'istruzione per il developer, da eseguire solo dopo la sua review esplicita del diff:

```bash
git -C wm-package push origin develop
```

- [ ] **Step 4: Stage del gitlink**

```bash
git add wm-package
```

Nessun commit qui — il gitlink verrà incluso nel commit finale insieme al resto delle modifiche del repo principale (Task 7), per avere un'unica unità di commit coerente lato camminiditalia.

---

## Task 7: Verifica finale e suite completa

**Files:**
- Nessuna modifica di codice — solo verifica

**Interfaces:**
- Consumes: tutto quanto prodotto dai task precedenti

- [ ] **Step 1: Esegui l'intera suite PHPUnit**

```bash
docker exec laravel-camminiditalia php artisan test
```

Expected: tutti i test passano, incluse le 7 nuove asserzioni di questa feature (3 di Task 1 + 4 di Task 3).

- [ ] **Step 2: Esegui Pint**

```bash
docker exec laravel-camminiditalia composer format
```

Se questo comando tocca file dentro `wm-package/` in modo non intenzionale, ripristina con:

```bash
git -C wm-package checkout -- .
```

lasciando solo le modifiche volute dei task precedenti.

- [ ] **Step 3: Esegui PHPStan**

```bash
docker exec laravel-camminiditalia vendor/bin/phpstan analyse
```

Expected: nessun nuovo errore sui file toccati da questa feature (`app/Http/Controllers/LayerFeatureController.php`, `tests/Feature/LayerFeatureControllerTest.php`). Eventuali errori preesistenti su file non toccati non bloccano — segui `wm-plan → review-gate: phpstan-check` per la gestione.

- [ ] **Step 4: Verifica manuale end-to-end (se non già fatta in Task 4)**

Ripeti la checklist dello Step 4 di Task 4 in ambiente Nova reale, e verifica in aggiunta:
1. Il ritorno da "manuale" ad "auto" tramite `ConfirmModal` funziona come prima (comportamento distruttivo invariato, fuori scope).
2. Un layer con `configuration` mai scritta prima (stato di produzione) si comporta correttamente al primo toggle.

- [ ] **Step 5: Nessun commit — questo task è di sola verifica**

Se emergono problemi, torna al task pertinente per correggerli prima di procedere alla `Fase: notes` e ai commit finali (gestiti dal workflow `wm-plan`, non da questo piano).

---

## Self-Review (svolta durante la scrittura di questo piano)

**Copertura requisiti overview → task:**

| Requisito | Task |
|---|---|
| `manual`/`auto` mutuamente esclusivi (422) | Task 3 |
| `persistMode()` protected, riusabile senza `parent::sync()` | Task 3 |
| Persistenza esplicita di `manual` e `auto` | Task 3 |
| Nessuna modifica se né `auto` né `manual` presenti | Task 3 (validazione `boolean` opzionale, `persistMode` fa `return` se nessuno dei due) |
| Ordine `persistMode()` prima dell'assign da tassonomia | Task 3, Step 3 (chiamata prima del blocco `if ($isAutoRequest)`) |
| Toggle auto→manuale mode-only, nessun `features` | Task 4 |
| Backend non tocca il pivot su richiesta mode-only | Task 3, Step 3 (`elseif ($request->has('features'))`) |
| `handleSave` invia `manual: true` | Task 4, Step 1 |
| `handleModeChange` continua a inviare `auto: true` | Invariato, nessuna modifica necessaria (verificato in overview) |
| Rollback UI su errore di persistenza | Task 4, Step 2 (`persistManualMode` catch → `isManual.value = false`) |
| Merge non distruttivo di `configuration` | Task 1 (`jsonb_set` modifica solo la chiave indicata) |
| `configuration` NULL gestita | Task 1 |
| `jsonb_set` invece di read-modify-write, concorrenza | Task 1 |
| `refresh()` dopo la scrittura | Task 1, Step 3 |
| Rebuild dist | Task 5 |
| Filtro ownership invariato | Task 3, Step 4 (nessuna modifica al blocco di autorizzazione esistente) |
| Test 1-7 (overview camminiditalia) | Task 1 (test 4,5,7) + Task 3 (test 1,2,3,6) |
| Gitlink aggiornato | Task 2 (verifica) + Task 6 (aggiornamento) |

Nessun gap rilevato.

**Scan placeholder:** nessun "TBD"/"implement later"/step senza codice — verificato manualmente riga per riga durante la stesura.

**Coerenza dei tipi/nomi:** `persistMode(Layer $layer, string $relationName, Request $request): void` usato identico in Task 3 Step 3 (package) e Step 4 (locale, ereditato); `setTrackMode(string $mode): void`/`setPoiMode(string $mode): void` invariati da Task 1 a Task 3.

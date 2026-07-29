> Ticket: oc:8311

# Visibilità POI/tracce non proprie nel pannello manuale di Layer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Impedire che un utente veda o sincronizzi POI/tracce (EC) non proprie nel pannello Nova di gestione layer, sia in lettura (`getFeatures`) sia in scrittura (`sync`), e sostituire la modalità `auto` con un'assegnazione basata su ownership invece che su tassonomia.

**Architecture:** Tutte le modifiche restano nell'override locale `App\Http\Controllers\LayerFeatureController` (estende `Wm\WmPackage\Nova\Fields\LayerFeatures\Http\Controllers\LayerFeatureController`), coerente col pattern già in uso per `getFeatures`. Nessuna modifica al submodule `wm-package`. Viene aggiunto un override locale di `sync()` con relativa route in `NovaServiceProvider`, che sovrascrive quella ereditata dal package.

**Tech Stack:** Laravel 11 (PHP 8.4), Nova, PostGIS, PHPUnit (Feature test, `RefreshDatabase`/`DatabaseTransactions`), Spatie permissions (ruoli `Administrator`, `Validator`, `Guest`).

> **⚠️ Correzione post-esecuzione (vedi Task 5):** i Task 1-4 sotto sono documentati come originariamente pianificati ed eseguiti, con il filtro ownership basato su **`Auth::user()->id`** (utente loggato). Durante il test manuale in ambiente locale (layer 130), questo approccio si è rivelato errato: un Administrator senza EC propri (comune in produzione — gestisce contenuti creati da Validator) vedeva 0 feature disponibili aprendo un layer altrui, pur esistendone centinaia nel sistema. Il Task 5 documenta la correzione: il filtro è ora basato su **`$layer->user_id`** (proprietario del layer), con l'aggiunta di un controllo di autorizzazione 403 e un'allowlist sul parametro `model`. Il codice attuale nel repo riflette il Task 5, non il codice originale mostrato nei Task 1-4 (mantenuto qui come registro storico del percorso seguito). Vedi anche `overview.md`, sezione "Cosa cambia", per la spiegazione completa.

## Global Constraints

- Nessuna eccezione di ruolo per il filtro `user_id`: si applica identicamente a `Administrator`, `Validator`, `Guest` — NON usare `hasRole('Administrator')` per bypassare i filtri introdotti in questo piano.
- Nessuna modifica al submodule `wm-package` in nessun task.
- Nessun filtro `app_id` da introdurre (il progetto ha una sola app).
- Tutti i comandi `php artisan`/test vanno eseguiti dentro il container: `docker exec laravel-camminiditalia php artisan test --filter=NomeTest`.
- Commit convention: `fix(oc:8311): ...`. I passi "Commit" del piano sono istruzioni testuali per l'utente — nessun commit va eseguito autonomamente durante l'implementazione.

---

## File Structure

- **Modifica:** `app/Http/Controllers/LayerFeatureController.php` — aggiunge il filtro `user_id` a `getFeatures()` (Task 1) e introduce un nuovo metodo `sync()` (Task 2 e 3).
- **Modifica:** `app/Providers/NovaServiceProvider.php` — registra la route `POST /nova-vendor/layer-features/sync/{layerId}` che sovrascrive quella ereditata dal package (Task 2).
- **Nuovo:** `tests/Feature/LayerFeatureControllerTest.php` — suite di test di regressione per `getFeatures` e `sync` (un metodo di test per step, aggiunto progressivamente nei Task 1-3).

---

### Task 1: Filtro `user_id` su `getFeatures()` (lettura, nessuna eccezione di ruolo)

**Files:**
- Modify: `app/Http/Controllers/LayerFeatureController.php:44-86`
- Test: `tests/Feature/LayerFeatureControllerTest.php` (nuovo file)

**Interfaces:**
- Consumes: `Wm\WmPackage\Models\Layer::ecPois()`/`ecTracks()` (morphedByMany esistenti), `App\Models\EcPoi`/`App\Models\EcTrack` con colonna `user_id` (nullable), relazione `associatedLayers()` su EC (da `Wm\WmPackage\Traits\EcFeatureTrait`).
- Produces: nessuna interfaccia nuova consumata da altri task — Task 2/3 toccano `sync()`, un metodo separato nello stesso file.

- [x] **Step 1: Scrivi il test che riproduce il bug (fallisce oggi)**

Crea `tests/Feature/LayerFeatureControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EcPoi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerFeatureControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function makePoi(?int $userId): EcPoi
    {
        return EcPoi::factory()->create(['user_id' => $userId, 'properties' => []]);
    }

    public function test_validator_does_not_see_ec_poi_of_another_validator_in_associated_features_edit_mode(): void
    {
        $validatorA = $this->makeUser('Validator');
        $validatorB = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validatorA->id]);

        $poiOfB = $this->makePoi($validatorB->id);
        $layer->ecPois()->attach($poiOfB->id);

        $response = $this->actingAs($validatorA)
            ->getJson('/nova-vendor/layer-features/features/'.$layer->id.'?model='.urlencode(EcPoi::class).'&view_mode=edit&manual=1');

        $response->assertOk();
        $ids = collect($response->json('features'))->pluck('id')->toArray();
        $this->assertNotContains($poiOfB->id, $ids);
    }

    public function test_validator_does_not_see_ec_poi_of_another_validator_in_details_mode(): void
    {
        $validatorA = $this->makeUser('Validator');
        $validatorB = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validatorA->id]);

        $poiOfB = $this->makePoi($validatorB->id);
        $layer->ecPois()->attach($poiOfB->id);

        $response = $this->actingAs($validatorA)
            ->getJson('/nova-vendor/layer-features/features/'.$layer->id.'?model='.urlencode(EcPoi::class).'&view_mode=details&manual=1');

        $response->assertOk();
        $ids = collect($response->json('features'))->pluck('id')->toArray();
        $this->assertNotContains($poiOfB->id, $ids);
    }

    public function test_administrator_does_not_see_ec_poi_not_owned_in_associated_features(): void
    {
        $admin = $this->makeUser('Administrator');
        $otherOwner = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $admin->id]);

        $poiOfOther = $this->makePoi($otherOwner->id);
        $layer->ecPois()->attach($poiOfOther->id);

        $response = $this->actingAs($admin)
            ->getJson('/nova-vendor/layer-features/features/'.$layer->id.'?model='.urlencode(EcPoi::class).'&view_mode=edit&manual=1');

        $response->assertOk();
        $ids = collect($response->json('features'))->pluck('id')->toArray();
        $this->assertNotContains($poiOfOther->id, $ids);
    }

    public function test_validator_sees_own_ec_poi_in_associated_features(): void
    {
        $validator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);

        $ownPoi = $this->makePoi($validator->id);
        $layer->ecPois()->attach($ownPoi->id);

        $response = $this->actingAs($validator)
            ->getJson('/nova-vendor/layer-features/features/'.$layer->id.'?model='.urlencode(EcPoi::class).'&view_mode=edit&manual=1');

        $response->assertOk();
        $ids = collect($response->json('features'))->pluck('id')->toArray();
        $this->assertContains($ownPoi->id, $ids);
    }
}
```

- [x] **Step 2: Esegui i test e verifica che i primi tre falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=LayerFeatureControllerTest`
Expected: `test_validator_does_not_see_ec_poi_of_another_validator_in_associated_features_edit_mode` FALLISCE (il POI di B è ancora incluso), `test_validator_does_not_see_ec_poi_of_another_validator_in_details_mode` FALLISCE, `test_administrator_does_not_see_ec_poi_not_owned_in_associated_features` FALLISCE, `test_validator_sees_own_ec_poi_in_associated_features` PASSA già.

- [x] **Step 3: Applica il filtro `user_id` a `getAssociatedFeatures()` e alla lista "altre disponibili", senza eccezione di ruolo**

In `app/Http/Controllers/LayerFeatureController.php`, sostituisci il blocco della closure `$getAssociatedFeatures` (righe 48-59):

```php
            // Funzione helper per caricare le features associate
            $getAssociatedFeatures = function () use ($model, $layerId, $search, $user) {
                $query = $model->newQuery();
                $query->whereHas('associatedLayers', function ($q) use ($layerId) {
                    $q->where('layer_id', $layerId);
                });

                if ($user) {
                    $query->where('user_id', $user->id);
                }

                if ($search) {
                    $query->where('name', 'like', "%{$search}%");
                }

                return $query->select(['id', 'name'])->orderBy('name', 'ASC');
            };
```

E sostituisci il blocco del filtro sulla lista "altre disponibili" (righe 78-81), rimuovendo l'eccezione per Administrator:

```php
                // Filtra per utente loggato (nessuna eccezione di ruolo)
                if ($user) {
                    $otherQuery->where('user_id', $user->id);
                }
```

- [x] **Step 4: Esegui di nuovo i test e verifica che passino tutti**

Run: `docker exec laravel-camminiditalia php artisan test --filter=LayerFeatureControllerTest`
Expected: PASS su tutti e 4 i test.

- [x] **Step 5: Commit**

```bash
git add app/Http/Controllers/LayerFeatureController.php tests/Feature/LayerFeatureControllerTest.php
git commit -m "fix(oc:8311): filtra per user_id le feature associate al layer senza eccezione di ruolo"
```

---

### Task 2: Override locale di `sync()` — filtro ownership sugli ID inviati (modalità manuale)

**Files:**
- Modify: `app/Http/Controllers/LayerFeatureController.php` (aggiunge nuovo metodo `sync()` in coda alla classe)
- Modify: `app/Providers/NovaServiceProvider.php:47-54`
- Test: `tests/Feature/LayerFeatureControllerTest.php` (aggiunge metodi di test)

**Interfaces:**
- Consumes: `Wm\WmPackage\Models\Layer::findOrFail()`, `$layer->{$relationName}()` (`MorphToMany` da `ecTracks()`/`ecPois()`), `$model->getLayerRelationName(): string`, `Wm\WmPackage\Services\PBFGeneratorService::regeneratePbfsForLayer(Layer $layer): void` (già iniettabile via constructor injection Laravel).
- Produces: `App\Http\Controllers\LayerFeatureController::sync(Request $request, $layerId): JsonResponse` — firma identica al parent, risposta JSON `{message, assigned_ids}` come oggi. Task 3 estende questo stesso metodo per il ramo `auto=true`.

- [x] **Step 1: Scrivi il test che riproduce il bug (fallisce oggi — la route esistente non filtra per ownership)**

Aggiungi in `tests/Feature/LayerFeatureControllerTest.php`:

```php
    public function test_validator_sync_filters_out_ec_poi_not_owned(): void
    {
        $validatorA = $this->makeUser('Validator');
        $validatorB = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validatorA->id]);

        $ownPoi = $this->makePoi($validatorA->id);
        $poiOfB = $this->makePoi($validatorB->id);

        $response = $this->actingAs($validatorA)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => EcPoi::class,
                'features' => [$ownPoi->id, $poiOfB->id],
            ]);

        $response->assertOk();
        $assignedIds = $response->json('assigned_ids');
        $this->assertContains($ownPoi->id, $assignedIds);
        $this->assertNotContains($poiOfB->id, $assignedIds);
        $this->assertTrue($layer->ecPois()->where('ec_pois.id', $ownPoi->id)->exists());
        $this->assertFalse($layer->ecPois()->where('ec_pois.id', $poiOfB->id)->exists());
    }

    public function test_administrator_sync_filters_out_ec_poi_not_owned(): void
    {
        $admin = $this->makeUser('Administrator');
        $otherOwner = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $admin->id]);

        $ownPoi = $this->makePoi($admin->id);
        $poiOfOther = $this->makePoi($otherOwner->id);

        $response = $this->actingAs($admin)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => EcPoi::class,
                'features' => [$ownPoi->id, $poiOfOther->id],
            ]);

        $response->assertOk();
        $assignedIds = $response->json('assigned_ids');
        $this->assertContains($ownPoi->id, $assignedIds);
        $this->assertNotContains($poiOfOther->id, $assignedIds);
    }
```

- [x] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=LayerFeatureControllerTest`
Expected: FALLISCONO entrambi i nuovi test (`sync` ereditato dal package associa anche `$poiOfB`/`$poiOfOther` perché non applica alcun filtro `user_id`).

- [x] **Step 3: Implementa `sync()` locale con filtro ownership (ramo non-auto) e mantieni la rigenerazione PBF**

Aggiungi in coda a `app/Http/Controllers/LayerFeatureController.php` (prima della chiusura `}` della classe), gli import necessari in testa al file, e il nuovo metodo:

`Auth` e `Log` sono già importati in cima al file (non duplicarli). Aggiungi solo:

```php
use Wm\WmPackage\Services\PBFGeneratorService;
```

(`LayerService` NON va importato: il nuovo `sync()` non richiama più `assignTracksByTaxonomy`/`assignPoisByTaxonomy`, quindi non serve.)

Aggiungi il metodo `sync()`:

```php
    public function sync(Request $request, $layerId): JsonResponse
    {
        try {
            $layer = Layer::findOrFail($layerId);

            $validatedData = $request->validate([
                'features' => 'array',
                'model' => 'required|string',
                'auto' => 'boolean',
            ]);

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

            /** @var \Wm\WmPackage\Models\User|null $user */
            $user = Auth::user();

            $isAutoRequest = ! empty($validatedData['auto']) && in_array($relationName, ['ecTracks', 'ecPois']);

            if ($isAutoRequest) {
                $ownedIds = $user
                    ? $model->newQuery()->where('user_id', $user->id)->pluck('id')->toArray()
                    : [];

                $layer->{$relationName}()->sync($ownedIds);
            } else {
                $requestedIds = $validatedData['features'] ?? [];

                $ownedIds = $user
                    ? $model->newQuery()->whereIn('id', $requestedIds)->where('user_id', $user->id)->pluck('id')->toArray()
                    : [];

                $layer->{$relationName}()->sync($ownedIds);
            }

            if ($relationName === 'ecTracks') {
                app(PBFGeneratorService::class)->regeneratePbfsForLayer($layer);
            }

            $tableName = $model->getTable();
            $assignedIds = $layer->{$relationName}()->select($tableName.'.id')->pluck('id')->toArray();

            return response()->json([
                'message' => 'Features sincronizzate con successo',
                'assigned_ids' => $assignedIds,
            ], 200);
        } catch (\Exception $e) {
            Log::error('LayerFeatureController::sync error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Errore interno del server: '.$e->getMessage(),
            ], 500);
        }
    }
```

Nota: il ramo `$isAutoRequest` qui sopra è già scritto per il comportamento finale del Task 3 (ownership invece di tassonomia) — non richiama `LayerService::assignTracksByTaxonomy`/`assignPoisByTaxonomy`. Il test del ramo auto viene aggiunto e verificato in Task 3; in questo step verifica solo che il ramo non-auto passi.

- [x] **Step 4: Registra la route locale che sovrascrive `sync` del package**

In `app/Providers/NovaServiceProvider.php`, modifica il blocco (righe 47-54):

```php
        // Queste route sovrascrivono quelle del wm-package permettendo
        // di filtrare per utente loggato senza modificare il package.
        // L'unica route ereditata invariata dal package è "index".
        Route::middleware(['nova'])
            ->prefix('nova-vendor/layer-features')
            ->group(function () {
                Route::get('/features/{layerId}', [LayerFeatureController::class, 'getFeatures']);
                Route::post('/sync/{layerId}', [LayerFeatureController::class, 'sync']);
            });
```

- [x] **Step 5: Esegui i test e verifica che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter=LayerFeatureControllerTest`
Expected: PASS su tutti i test (6 totali finora).

- [x] **Step 6: Commit**

```bash
git add app/Http/Controllers/LayerFeatureController.php app/Providers/NovaServiceProvider.php tests/Feature/LayerFeatureControllerTest.php
git commit -m "fix(oc:8311): override locale di sync() con filtro ownership sugli ID inviati"
```

---

### Task 3: Modalità `auto` basata su ownership invece che su tassonomia

**Files:**
- Modify: nessuna modifica di codice aggiuntiva (il ramo `$isAutoRequest` è già implementato nel Task 2, Step 3) — solo test.
- Test: `tests/Feature/LayerFeatureControllerTest.php` (aggiunge metodi di test)

**Interfaces:**
- Consumes: `sync()` da Task 2 (nessuna interfaccia nuova).
- Produces: nessuna interfaccia nuova per altri task.

- [x] **Step 1: Scrivi il test per la modalità `auto`**

Aggiungi in `tests/Feature/LayerFeatureControllerTest.php`:

```php
    public function test_sync_auto_true_assigns_only_owned_ec_pois_ignoring_taxonomy(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);

        $ownPoiA = $this->makePoi($validator->id);
        $ownPoiB = $this->makePoi($validator->id);
        $poiOfOther = $this->makePoi($otherValidator->id);

        $response = $this->actingAs($validator)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => EcPoi::class,
                'auto' => true,
            ]);

        $response->assertOk();
        $assignedIds = $response->json('assigned_ids');
        $this->assertContains($ownPoiA->id, $assignedIds);
        $this->assertContains($ownPoiB->id, $assignedIds);
        $this->assertNotContains($poiOfOther->id, $assignedIds);
    }

    public function test_sync_auto_true_replaces_pivot_with_only_callers_ec_pois(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);

        $poiOfOtherPreAssigned = $this->makePoi($otherValidator->id);
        $layer->ecPois()->attach($poiOfOtherPreAssigned->id);

        $ownPoi = $this->makePoi($validator->id);

        $response = $this->actingAs($validator)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => EcPoi::class,
                'auto' => true,
            ]);

        $response->assertOk();
        $assignedIds = $response->json('assigned_ids');
        $this->assertContains($ownPoi->id, $assignedIds);
        $this->assertNotContains($poiOfOtherPreAssigned->id, $assignedIds);
    }
```

- [x] **Step 2: Esegui i test**

Run: `docker exec laravel-camminiditalia php artisan test --filter=LayerFeatureControllerTest`
Expected: PASS su entrambi (il codice del Task 2 già implementa questo comportamento) — se falliscono, rileggi il ramo `$isAutoRequest` in `sync()` (Task 2, Step 3) e correggi prima di proseguire.

- [x] **Step 3: Commit**

```bash
git add tests/Feature/LayerFeatureControllerTest.php
git commit -m "test(oc:8311): copertura modalità auto basata su ownership"
```

---

### Task 4: Verifica rigenerazione PBF su sync di `ecTracks`

**Files:**
- Test: `tests/Feature/LayerFeatureControllerTest.php` (aggiunge un metodo di test)

**Interfaces:**
- Consumes: `sync()` da Task 2/3, `Wm\WmPackage\Services\PBFGeneratorService::regeneratePbfsForLayer(Layer $layer): void`.
- Produces: nessuna interfaccia nuova.

- [x] **Step 1: Verifica come sono strutturati EcTrack e la sua factory**

Run: `docker exec laravel-camminiditalia php artisan tinker --execute="echo (new \App\Models\EcTrack())->getLayerRelationName();"`
Expected: output `ecTracks`.

- [x] **Step 2: Scrivi il test con mock del servizio PBF**

Aggiungi in `tests/Feature/LayerFeatureControllerTest.php` (aggiungi l'import `use App\Models\EcTrack;` e `use Wm\WmPackage\Services\PBFGeneratorService;` in testa al file):

```php
    public function test_sync_ec_tracks_triggers_pbf_regeneration(): void
    {
        $validator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);
        $ownTrack = EcTrack::factory()->create(['user_id' => $validator->id, 'properties' => []]);

        $pbfMock = \Mockery::mock(PBFGeneratorService::class);
        $pbfMock->shouldReceive('regeneratePbfsForLayer')
            ->once()
            ->withArgs(fn (Layer $l) => $l->id === $layer->id);
        $this->app->instance(PBFGeneratorService::class, $pbfMock);

        $response = $this->actingAs($validator)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => EcTrack::class,
                'features' => [$ownTrack->id],
            ]);

        $response->assertOk();
    }

    public function test_sync_ec_pois_does_not_trigger_pbf_regeneration(): void
    {
        $validator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);
        $ownPoi = $this->makePoi($validator->id);

        $pbfMock = \Mockery::mock(PBFGeneratorService::class);
        $pbfMock->shouldNotReceive('regeneratePbfsForLayer');
        $this->app->instance(PBFGeneratorService::class, $pbfMock);

        $response = $this->actingAs($validator)
            ->postJson('/nova-vendor/layer-features/sync/'.$layer->id, [
                'model' => EcPoi::class,
                'features' => [$ownPoi->id],
            ]);

        $response->assertOk();
    }
```

- [x] **Step 3: Esegui i test**

Run: `docker exec laravel-camminiditalia php artisan test --filter=LayerFeatureControllerTest`
Expected: PASS su entrambi.

- [x] **Step 4: Esegui l'intera suite per verificare nessuna regressione**

Run: `docker exec laravel-camminiditalia php artisan test`
Expected: PASS su tutti i test del progetto (nessuna regressione su test esistenti che toccano `LayerFeatureController`, `EcPoi`, `EcTrack`, route Nova).

- [x] **Step 5: Commit**

```bash
git add tests/Feature/LayerFeatureControllerTest.php
git commit -m "test(oc:8311): verifica rigenerazione PBF su sync di ecTracks"
```

---

### Task 5: Correzione — filtro basato sul proprietario del layer, non sull'utente loggato

**Contesto:** dopo il completamento dei Task 1-4 e la review finale, un test manuale in ambiente locale (apertura del layer id 130 in Nova) ha mostrato 0 feature disponibili nel pannello Ec Pois/Ec Tracks, pur esistendo centinaia di EC nel sistema. Causa: il layer 130 è di proprietà di `user_id=2` (che possiede 325 EcPoi), ma gli account Administrator usati per operare su Nova (`user_id` 1 e 9) possiedono 0 EcPoi ciascuno — il filtro `Auth::user()->id` introdotto nei Task 1-2 mostrava quindi 0 risultati per l'Administrator, rompendo l'operatività reale (gli account Administrator, in produzione, spesso gestiscono contenuti creati da altri, non propri).

**Decisione presa col developer:** il filtro ownership deve essere sempre rispetto al **proprietario del layer** (`$layer->user_id`), non all'utente che effettua la richiesta. Per un Validator che apre il proprio layer non cambia nulla (`$layer->user_id` coincide col suo id); per un Administrator che apre il layer di un Validator, ora vede/gestisce gli EC di quel Validator.

**Files:**
- Modify: `app/Http/Controllers/LayerFeatureController.php` (sostituito il filtro `$user->id` con `$layer->user_id` in `getFeatures()` — sia `getAssociatedFeatures` che lista "altre disponibili" — e in `sync()` — sia ramo manuale che `auto=true`)
- Test: `tests/Feature/LayerFeatureControllerTest.php` (test Administrator riscritti, nuovi test aggiunti)

**Ulteriori correzioni emerse dalla review finale, applicate nello stesso ciclo:**
- [x] Controllo di autorizzazione: solo il proprietario del layer o un Administrator possono chiamare `getFeatures()`/`sync()` per un dato `layerId`, altrimenti `abort(403)` (fail-closed: `if (! $user || ($layer->user_id !== $user->id && ! $user->hasRole('Administrator'))) { abort(403); }`)
- [x] Allowlist sul parametro `model`: accetta `App\Models\EcPoi`, `App\Models\EcTrack`, `Wm\WmPackage\Models\EcPoi`, `Wm\WmPackage\Models\EcTrack` (il frontend Nova invia l'una o l'altra variante a seconda della config `wm-package.ec_poi_model`/`ec_track_model`) — istanziazione di classe arbitraria bloccata prima di `new $validatedData['model']`
- [x] Catch dedicato per `ValidationException`/`ModelNotFoundException` prima del catch generico `\Exception`, per non trasformare 422/404 in 500
- [x] Test: Validator che chiama `getFeatures`/`sync` su un layer non proprio riceve 403; `model` non in allowlist rifiutato con 400; Administrator che apre il layer di un Validator vede gli EC del Validator e non i propri

**Verifica:** `docker exec laravel-camminiditalia php artisan test --filter=LayerFeatureControllerTest` → 15 passed (33 assertions). Suite completa: 142 passed, nessuna regressione imputabile a queste modifiche.

- [x] **Commit** (istruzione testuale, non eseguita autonomamente)

```bash
git add app/Http/Controllers/LayerFeatureController.php app/Providers/NovaServiceProvider.php tests/Feature/LayerFeatureControllerTest.php CLAUDE.md docs/features/8311-visibilita-poi-tracce-non-proprie-pannello-manuale-layer/
git commit -m "fix(oc:8311): filtro ownership basato su layer owner, autorizzazione 403 e allowlist modello"
```

---

## Note post-implementazione (non task di codice)

- **Deploy in produzione:** se è attivo `route:cache`, eseguire `php artisan route:clear` (o `route:cache` di nuovo) subito dopo il deploy — altrimenti la nuova route `sync/{layerId}` locale non sostituisce quella ereditata dal package e il fix di sicurezza non è effettivamente attivo.
- **Fuori scope confermato in overview.md:** nessuna modifica al wm-package, nessun allineamento dell'override `getFeatures` alla logica taxonomy/auto-mode più recente del package, nessuna modifica a `LayerObserver` (oc:8080).
- **Fuori scope, tracciato come nota:** `regeneratePbfsForLayer` viene chiamato due volte per un singolo sync di `ecTracks` (una volta esplicitamente dal controller, una volta da `LayerableObserver::created()` nel wm-package) — comportamento preesistente nel package, non introdotto da questo fix. Da valutare in un ticket futuro.

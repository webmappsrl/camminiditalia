> Ticket: oc:8139

# Associazione automatica EcPoi al layer della traccia — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sincronizzare automaticamente i related poi delle tracce ai layer di appartenenza, con command di migrazione per i dati storici.

**Architecture:** `EcPoiEcTrackObserver` (wm-package) intercetta add/remove di un EcPoi da una EcTrack e sincronizza la relazione `ecPois` del layer. `LayerableObserver` (repo principale) gestisce il caso "traccia rimossa da layer". Un artisan command migra i dati storici disabilitando gli observer per evitare UPDATE ridondanti.

**Tech Stack:** Laravel 10, Eloquent MorphToMany, pivot model `Layerable`, artisan command con `withProgressBar`.

## Global Constraints

- Commit convention: `feat(oc:8139): ...` per nuove feature, `refactor(oc:8139): ...` per rinomina
- NO commit automatici — solo a esplicita approvazione dell'utente
- Tutti i comandi PHP vanno eseguiti dentro il container: `docker exec laravel-camminiditalia php artisan ...`
- Eseguire i test con: `docker exec laravel-camminiditalia php artisan test --filter=NomTest`
- La relazione `ecPois` usa la tabella pivot `layerables` (morph: `layerable_type = 'App\Models\EcPoi'`)
- La relazione `ecTracks` usa la stessa tabella `layerables` (morph: `layerable_type = 'App\Models\EcTrack'`)
- La pivot `ec_poi_ec_track` usa FK configurabile: `EcPoiEcTrack::getTrackForeignKeyName()` (default: `ec_track_id`)
- `associatedLayers()` è definita in `EcFeatureTrait` (wm-package) — disponibile su EcTrack e EcPoi

---

## File Map

| File | Repo | Azione |
|------|------|--------|
| `wm-package/src/Models/Layer.php` | wm-package | Modifica: rinomina `manualEcPois()` → `ecPois()`, aggiunge alias |
| `wm-package/src/Models/EcPoi.php` | wm-package | Modifica: `getLayerRelationName()` → `'ecPois'` |
| `wm-package/src/Nova/Layer.php` | wm-package | Modifica: `$with` array |
| `wm-package/src/Observers/EcPoiEcTrackObserver.php` | wm-package | Modifica: aggiunge sync layer su `created`/`deleted` |
| `app/Observers/LayerObserver.php` | principale | Modifica: aggiorna `manualEcPois` → `ecPois` |
| `app/Observers/LayerableObserver.php` | principale | Modifica: aggiunge `deleted()` per EcTrack rimossa da layer |
| `app/Console/Commands/SyncLayerEcPois.php` | principale | Crea: command artisan di migrazione |
| `tests/Feature/SyncLayerEcPoisCommandTest.php` | principale | Crea: test command |
| `tests/Feature/LayerEcPoiSyncObserverTest.php` | principale | Crea: test observer EcPoiEcTrack |
| `tests/Feature/LayerableObserverEcTrackRemovedTest.php` | principale | Crea: test LayerableObserver::deleted |

---

## Task 1: Rinomina `manualEcPois` → `ecPois` in wm-package

**Files:**
- Modify: `wm-package/src/Models/Layer.php`
- Modify: `wm-package/src/Models/EcPoi.php`
- Modify: `wm-package/src/Nova/Layer.php`

**Interfaces:**
- Produces: `Layer::ecPois()` (MorphToMany), `Layer::manualEcPois()` (alias), `EcPoi::getLayerRelationName()` → `'ecPois'`

- [ ] **Step 1: Rinomina il metodo in `Layer.php`**

In `wm-package/src/Models/Layer.php`, sostituisci il metodo `manualEcPois()`:

```php
public function ecPois(): MorphToMany
{
    return $this->morphedByMany(EcPoi::class, 'layerable')->using(Layerable::class);
}

/** @deprecated use ecPois() */
public function manualEcPois(): MorphToMany
{
    return $this->ecPois();
}
```

- [ ] **Step 2: Aggiorna `$with` in `wm-package/src/Nova/Layer.php`**

```php
public static $with = ['ecTracks', 'ecPois', 'appOwner', 'associatedApps'];
```

- [ ] **Step 3: Aggiorna `getLayerRelationName()` in `wm-package/src/Models/EcPoi.php`**

```php
public function getLayerRelationName(): string
{
    return 'ecPois';
}
```

- [ ] **Step 4: Aggiorna riferimento in `app/Observers/LayerObserver.php`**

Nel metodo `saved()`, cambia le due occorrenze di `manualEcPois` → `ecPois`:

```php
$poiIds = $layer->ecPois()->pluck('ec_pois.id')->toArray();

if (! empty($poiIds)) {
    $layer->ecPois()->update(['user_id' => $newOwnerId]);
}
```

- [ ] **Step 5: Verifica che i test esistenti passino ancora**

```bash
docker exec laravel-camminiditalia php artisan test
```

Atteso: tutti i test esistenti passano (la rinomina è retrocompatibile grazie all'alias).

- [ ] **Step 6: Commit**

```bash
git -C wm-package add src/Models/Layer.php src/Models/EcPoi.php src/Nova/Layer.php
git -C wm-package commit -m "refactor(oc:8139): rename manualEcPois to ecPois, keep alias for BC"

git add app/Observers/LayerObserver.php
git commit -m "refactor(oc:8139): update LayerObserver to use ecPois() instead of manualEcPois()"
```

---

## Task 2: Observer — EcPoi aggiunto/rimosso da EcTrack sincronizza il layer

**Files:**
- Modify: `wm-package/src/Observers/EcPoiEcTrackObserver.php`
- Create: `tests/Feature/LayerEcPoiSyncObserverTest.php`

**Interfaces:**
- Consumes: `Layer::ecPois()` (Task 1), `EcPoiEcTrack::getTrackForeignKeyName()`, `EcFeatureTrait::associatedLayers()`
- Produces: comportamento automatico — aggiunta EcPoi a EcTrack → EcPoi associato ai layer della traccia; rimozione → dissociato se orfano

- [ ] **Step 1: Scrivi i test**

Crea `tests/Feature/LayerEcPoiSyncObserverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EcPoi;
use App\Models\EcTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Wm\WmPackage\Models\Layer;

class LayerEcPoiSyncObserverTest extends TestCase
{
    use RefreshDatabase;

    private function makeLayer(): Layer
    {
        return Layer::factory()->create(['user_id' => 1]);
    }

    private function makeTrack(Layer $layer): EcTrack
    {
        $track = EcTrack::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach($track->id);
        return $track;
    }

    private function makePoi(): EcPoi
    {
        return EcPoi::factory()->create(['properties' => []]);
    }

    /** @test */
    public function attaching_poi_to_track_associates_it_with_layer(): void
    {
        $layer = $this->makeLayer();
        $track = $this->makeTrack($layer);
        $poi = $this->makePoi();

        $track->ecPois()->attach($poi->id);

        $this->assertTrue($layer->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }

    /** @test */
    public function detaching_poi_from_track_removes_it_from_layer_when_no_other_track_has_it(): void
    {
        $layer = $this->makeLayer();
        $track = $this->makeTrack($layer);
        $poi = $this->makePoi();
        $track->ecPois()->attach($poi->id);

        $track->ecPois()->detach($poi->id);

        $this->assertFalse($layer->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }

    /** @test */
    public function detaching_poi_from_one_track_keeps_it_in_layer_if_another_track_still_has_it(): void
    {
        $layer = $this->makeLayer();
        $track1 = $this->makeTrack($layer);
        $track2 = $this->makeTrack($layer);
        $poi = $this->makePoi();
        $track1->ecPois()->attach($poi->id);
        $track2->ecPois()->attach($poi->id);

        $track1->ecPois()->detach($poi->id);

        $this->assertTrue($layer->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }

    /** @test */
    public function poi_shared_between_tracks_of_different_layers_is_associated_with_all_layers(): void
    {
        $layerA = $this->makeLayer();
        $layerB = $this->makeLayer();
        $trackA = $this->makeTrack($layerA);
        $trackB = $this->makeTrack($layerB);
        $poi = $this->makePoi();

        $trackA->ecPois()->attach($poi->id);
        $trackB->ecPois()->attach($poi->id);

        $this->assertTrue($layerA->ecPois()->where('ec_pois.id', $poi->id)->exists());
        $this->assertTrue($layerB->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }
}
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
docker exec laravel-camminiditalia php artisan test --filter=LayerEcPoiSyncObserverTest
```

Atteso: FAIL (logica sync non ancora implementata).

- [ ] **Step 3: Implementa la logica in `EcPoiEcTrackObserver.php`**

```php
<?php

namespace Wm\WmPackage\Observers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\EcPoiEcTrack;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Models\EcTrackService;

class EcPoiEcTrackObserver
{
    public function created(EcPoiEcTrack $pivot): void
    {
        $this->updateEcTrackDataChain($pivot);
        $this->syncPoiToLayers($pivot, 'attach');
    }

    public function updated(EcPoiEcTrack $pivot): void
    {
        $this->updateEcTrackDataChain($pivot);
    }

    public function deleted(EcPoiEcTrack $pivot): void
    {
        $this->updateEcTrackDataChain($pivot);
        $this->syncPoiToLayers($pivot, 'detach');
    }

    /**
     * Sync the EcPoi with the layers of its EcTrack.
     * On attach: associate the POI with all layers of the track.
     * On detach: dissociate the POI from a layer only if no other track
     *            in that layer still has the POI as a related poi.
     *
     * NOTE: This observer fires in wm-package but relies on LayerableObserver
     * (registered in the consumer project) to handle ownership transfer on
     * the created layerable. This cross-layer dependency is intentional —
     * same pattern as oc:8080. If LayerableObserver is absent, ownership
     * transfer will not occur, but the association itself will.
     */
    private function syncPoiToLayers(EcPoiEcTrack $pivot, string $action): void
    {
        $fkName = EcPoiEcTrack::getTrackForeignKeyName();
        $ecTrackId = $pivot->getAttribute($fkName);
        $ecPoiId = $pivot->getAttribute('ec_poi_id');

        if (! $ecTrackId || ! $ecPoiId) {
            return;
        }

        $ecTrackModelClass = config('wm-package.ec_track_model', EcTrack::class);
        $ecTrack = $ecTrackModelClass::find($ecTrackId);

        if (! $ecTrack || ! method_exists($ecTrack, 'associatedLayers')) {
            return;
        }

        $layers = $ecTrack->associatedLayers;

        if ($layers->isEmpty()) {
            return;
        }

        foreach ($layers as $layer) {
            if ($action === 'attach') {
                $layer->ecPois()->syncWithoutDetaching([$ecPoiId]);
            } else {
                if (! $this->layerStillHasPoiViaOtherTrack($layer->id, $ecPoiId, $ecTrackId, $fkName)) {
                    $layer->ecPois()->detach($ecPoiId);
                }
            }
        }
    }

    private function layerStillHasPoiViaOtherTrack(int $layerId, int $ecPoiId, int $excludeTrackId, string $fkName): bool
    {
        $pivotTable = config('wm-package.ec_poi_track_pivot_table', 'ec_poi_ec_track');
        $ecTrackModelClass = config('wm-package.ec_track_model', EcTrack::class);
        $ecTrackMorphType = array_search($ecTrackModelClass, Relation::morphMap()) ?: $ecTrackModelClass;

        return DB::table($pivotTable)
            ->join('layerables', function ($join) use ($layerId, $fkName, $ecTrackMorphType, $pivotTable) {
                $join->on("layerables.layerable_id", '=', "{$pivotTable}.{$fkName}")
                    ->where('layerables.layerable_type', $ecTrackMorphType)
                    ->where('layerables.layer_id', $layerId);
            })
            ->where("{$pivotTable}.ec_poi_id", $ecPoiId)
            ->where("{$pivotTable}.{$fkName}", '!=', $excludeTrackId)
            ->exists();
    }

    private function updateEcTrackDataChain(EcPoiEcTrack $pivot): void
    {
        $ecTrackModelClass = config('wm-package.ec_track_model', EcTrack::class);
        $fkName = EcPoiEcTrack::getTrackForeignKeyName();

        $ecTrackId = $pivot->getAttribute($fkName);
        if ($ecTrackId) {
            $ecTrack = $ecTrackModelClass::find($ecTrackId);
            if ($ecTrack) {
                $ecTrackService = app(EcTrackService::class);
                $ecTrackService->updateDataChain($ecTrack);
            }
        }
    }
}
```

- [ ] **Step 4: Esegui i test**

```bash
docker exec laravel-camminiditalia php artisan test --filter=LayerEcPoiSyncObserverTest
```

Atteso: tutti e 4 i test passano.

- [ ] **Step 5: Commit**

```bash
git -C wm-package add src/Observers/EcPoiEcTrackObserver.php
git -C wm-package commit -m "feat(oc:8139): sync EcPoi to layer when attached/detached from EcTrack"

git add tests/Feature/LayerEcPoiSyncObserverTest.php
git commit -m "feat(oc:8139): add observer tests for EcPoi-layer sync"
```

---

## Task 3: LayerableObserver — EcTrack rimossa da layer rimuove i POI orfani

**Files:**
- Modify: `app/Observers/LayerableObserver.php`
- Create: `tests/Feature/LayerableObserverEcTrackRemovedTest.php`

**Interfaces:**
- Consumes: `Layer::ecPois()` (Task 1), `EcTrack::ecPois()`, `EcPoiEcTrack::getTrackForeignKeyName()`
- Produces: quando un `Layerable` di tipo EcTrack viene eliminato, i POI orfani vengono rimossi dal layer

- [ ] **Step 1: Scrivi i test**

Crea `tests/Feature/LayerableObserverEcTrackRemovedTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EcPoi;
use App\Models\EcTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Wm\WmPackage\Models\Layer;

class LayerableObserverEcTrackRemovedTest extends TestCase
{
    use RefreshDatabase;

    private function makeLayer(): Layer
    {
        return Layer::factory()->create(['user_id' => 1]);
    }

    private function makeTrack(Layer $layer): EcTrack
    {
        $track = EcTrack::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach($track->id);
        return $track;
    }

    private function makePoi(): EcPoi
    {
        return EcPoi::factory()->create(['properties' => []]);
    }

    /** @test */
    public function removing_track_from_layer_detaches_orphan_pois(): void
    {
        $layer = $this->makeLayer();
        $track = $this->makeTrack($layer);
        $poi = $this->makePoi();
        $layer->ecPois()->syncWithoutDetaching([$poi->id]);
        $track->ecPois()->attach($poi->id);

        $layer->ecTracks()->detach($track->id);

        $this->assertFalse($layer->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }

    /** @test */
    public function removing_track_from_layer_keeps_pois_still_in_other_tracks(): void
    {
        $layer = $this->makeLayer();
        $track1 = $this->makeTrack($layer);
        $track2 = $this->makeTrack($layer);
        $poi = $this->makePoi();
        $layer->ecPois()->syncWithoutDetaching([$poi->id]);
        $track1->ecPois()->attach($poi->id);
        $track2->ecPois()->attach($poi->id);

        $layer->ecTracks()->detach($track1->id);

        $this->assertTrue($layer->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }
}
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
docker exec laravel-camminiditalia php artisan test --filter=LayerableObserverEcTrackRemovedTest
```

Atteso: FAIL.

- [ ] **Step 3: Implementa `deleted()` in `LayerableObserver.php`**

```php
<?php

namespace App\Observers;

use App\Models\EcPoi;
use App\Models\EcTrack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\EcPoiEcTrack;
use Wm\WmPackage\Models\Layerable;

class LayerableObserver
{
    public function created(Layerable $layerable): void
    {
        $allowedTypes = [EcTrack::class, EcPoi::class];

        if (! in_array($layerable->layerable_type, $allowedTypes)) {
            return;
        }

        $layer = $layerable->layer;

        if (! $layer || is_null($layer->user_id)) {
            return;
        }

        $model = $layerable->model;

        if (! $model) {
            return;
        }

        $model->update(['user_id' => $layer->user_id]);

        Log::info('Layerable ownership transfer', [
            'layer_id' => $layer->id,
            'layer_owner_id' => $layer->user_id,
            'resource_type' => $layerable->layerable_type,
            'resource_id' => $layerable->layerable_id,
        ]);
    }

    public function deleted(Layerable $layerable): void
    {
        if ($layerable->layerable_type !== EcTrack::class) {
            return;
        }

        $layer = $layerable->layer;
        if (! $layer) {
            return;
        }

        $ecTrack = EcTrack::find($layerable->layerable_id);
        if (! $ecTrack) {
            return;
        }

        $trackPoiIds = $ecTrack->ecPois()->pluck('ec_pois.id')->toArray();
        if (empty($trackPoiIds)) {
            return;
        }

        $pivotTable = config('wm-package.ec_poi_track_pivot_table', 'ec_poi_ec_track');
        $fkName = EcPoiEcTrack::getTrackForeignKeyName();

        $poiIdsToRemove = array_values(array_filter($trackPoiIds, function ($poiId) use ($layer, $layerable, $pivotTable, $fkName) {
            return ! DB::table($pivotTable)
                ->join('layerables', function ($join) use ($layer, $fkName, $pivotTable) {
                    $join->on('layerables.layerable_id', '=', "{$pivotTable}.{$fkName}")
                        ->where('layerables.layerable_type', EcTrack::class)
                        ->where('layerables.layer_id', $layer->id);
                })
                ->where("{$pivotTable}.ec_poi_id", $poiId)
                ->where("{$pivotTable}.{$fkName}", '!=', $layerable->layerable_id)
                ->exists();
        }));

        if (! empty($poiIdsToRemove)) {
            $layer->ecPois()->detach($poiIdsToRemove);

            Log::info('LayerableObserver: removed orphan POIs from layer after track detach', [
                'layer_id' => $layer->id,
                'removed_ec_track_id' => $layerable->layerable_id,
                'poi_ids_removed' => $poiIdsToRemove,
            ]);
        }
    }
}
```

- [ ] **Step 4: Esegui i test**

```bash
docker exec laravel-camminiditalia php artisan test --filter=LayerableObserverEcTrackRemovedTest
```

Atteso: entrambi i test passano.

- [ ] **Step 5: Esegui la suite completa**

```bash
docker exec laravel-camminiditalia php artisan test
```

Atteso: nessuna regressione.

- [ ] **Step 6: Commit**

```bash
git add app/Observers/LayerableObserver.php tests/Feature/LayerableObserverEcTrackRemovedTest.php
git commit -m "feat(oc:8139): remove orphan POIs from layer when EcTrack is detached"
```

---

## Task 4: Command artisan `camminiditalia:sync-layer-ec-pois`

**Files:**
- Create: `app/Console/Commands/SyncLayerEcPois.php`
- Create: `tests/Feature/SyncLayerEcPoisCommandTest.php`

**Interfaces:**
- Consumes: `Layer::ecTracks()`, `EcTrack::ecPois()`, `Layer::ecPois()`, `Layerable::withoutObservers()`
- Produces: tutti i related poi delle tracce associate a ogni layer vengono inseriti in `layerables`; `user_id` dei POI aggiornato all'owner del layer

- [ ] **Step 1: Scrivi i test**

Crea `tests/Feature/SyncLayerEcPoisCommandTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EcPoi;
use App\Models\EcTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Wm\WmPackage\Models\Layer;

class SyncLayerEcPoisCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function command_associates_related_pois_of_tracks_to_their_layer(): void
    {
        $layer = Layer::factory()->create(['user_id' => 1]);
        $track = EcTrack::factory()->create(['properties' => []]);
        $poi = EcPoi::factory()->create(['properties' => [], 'user_id' => 99]);
        $layer->ecTracks()->attach($track->id);
        $track->ecPois()->attach($poi->id);

        $this->artisan('camminiditalia:sync-layer-ec-pois')->assertSuccessful();

        $this->assertTrue($layer->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }

    /** @test */
    public function command_updates_poi_user_id_to_layer_owner(): void
    {
        $ownerId = 5;
        $layer = Layer::factory()->create(['user_id' => $ownerId]);
        $track = EcTrack::factory()->create(['properties' => []]);
        $poi = EcPoi::factory()->create(['properties' => [], 'user_id' => 99]);
        $layer->ecTracks()->attach($track->id);
        $track->ecPois()->attach($poi->id);

        $this->artisan('camminiditalia:sync-layer-ec-pois')->assertSuccessful();

        $this->assertEquals($ownerId, $poi->fresh()->user_id);
    }

    /** @test */
    public function command_is_idempotent(): void
    {
        $layer = Layer::factory()->create(['user_id' => 1]);
        $track = EcTrack::factory()->create(['properties' => []]);
        $poi = EcPoi::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach($track->id);
        $track->ecPois()->attach($poi->id);

        $this->artisan('camminiditalia:sync-layer-ec-pois')->assertSuccessful();
        $this->artisan('camminiditalia:sync-layer-ec-pois')->assertSuccessful();

        $this->assertCount(1, $layer->ecPois()->where('ec_pois.id', $poi->id)->get());
    }

    /** @test */
    public function command_handles_poi_related_to_tracks_of_multiple_layers(): void
    {
        $layerA = Layer::factory()->create(['user_id' => 1]);
        $layerB = Layer::factory()->create(['user_id' => 2]);
        $trackA = EcTrack::factory()->create(['properties' => []]);
        $trackB = EcTrack::factory()->create(['properties' => []]);
        $poi = EcPoi::factory()->create(['properties' => []]);
        $layerA->ecTracks()->attach($trackA->id);
        $layerB->ecTracks()->attach($trackB->id);
        $trackA->ecPois()->attach($poi->id);
        $trackB->ecPois()->attach($poi->id);

        $this->artisan('camminiditalia:sync-layer-ec-pois')->assertSuccessful();

        $this->assertTrue($layerA->ecPois()->where('ec_pois.id', $poi->id)->exists());
        $this->assertTrue($layerB->ecPois()->where('ec_pois.id', $poi->id)->exists());
    }
}
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
docker exec laravel-camminiditalia php artisan test --filter=SyncLayerEcPoisCommandTest
```

Atteso: FAIL (command non esiste).

- [ ] **Step 3: Crea il command**

Crea `app/Console/Commands/SyncLayerEcPois.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\EcPoi;
use Illuminate\Console\Command;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Layerable;

class SyncLayerEcPois extends Command
{
    protected $signature = 'camminiditalia:sync-layer-ec-pois';

    protected $description = 'Associa tutti i related poi delle tracce ai layer di appartenenza e aggiorna i proprietari';

    public function handle(): int
    {
        $layers = Layer::with('ecTracks.ecPois')->get();

        $this->info("Sincronizzo {$layers->count()} layer...");

        $this->withProgressBar($layers, function (Layer $layer) {
            $poiIds = $layer->ecTracks
                ->flatMap(fn ($track) => $track->ecPois->pluck('id'))
                ->unique()
                ->values()
                ->toArray();

            // Disable LayerableObserver to avoid redundant user_id UPDATEs
            // (one per layerable created). Ownership is handled in bulk below.
            Layerable::withoutObservers(function () use ($layer, $poiIds) {
                $layer->ecPois()->syncWithoutDetaching($poiIds);
            });

            if (! empty($poiIds)) {
                $ownerId = $layer->user_id ?? config('camminiditalia.default_owner_id');
                EcPoi::whereIn('id', $poiIds)->update(['user_id' => $ownerId]);
            }
        });

        $this->newLine();
        $this->info('Sincronizzazione completata.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Esegui i test**

```bash
docker exec laravel-camminiditalia php artisan test --filter=SyncLayerEcPoisCommandTest
```

Atteso: tutti e 4 i test passano.

- [ ] **Step 5: Esegui la suite completa**

```bash
docker exec laravel-camminiditalia php artisan test
```

Atteso: nessuna regressione.

- [ ] **Step 6: Verifica manuale del command sul DB locale**

```bash
docker exec laravel-camminiditalia php artisan camminiditalia:sync-layer-ec-pois
```

Atteso: progress bar visibile, nessun errore, output finale "Sincronizzazione completata."

Verifica il risultato:

```bash
docker exec laravel-camminiditalia php artisan tinker --execute="echo DB::table('layerables')->where('layerable_type', 'App\\\Models\\\EcPoi')->count();"
```

Atteso: valore > 0.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/SyncLayerEcPois.php tests/Feature/SyncLayerEcPoisCommandTest.php
git commit -m "feat(oc:8139): add artisan command to sync layer ec pois from related tracks"
```

---

## Task 5: Verifica panel Nova EcPoi nel Layer

**Files:**
- Nessun file da creare/modificare (panel già ereditato da wm-package)

**Interfaces:**
- Consumes: `Layer::ecPois()` (Task 1), `LayerFeatureController::getFeatures()` (già model-agnostic)

- [ ] **Step 1: Verifica che il panel Nova sia visibile**

Apri il container e avvia l'app:

```bash
docker exec laravel-camminiditalia php artisan serve
```

Naviga su Nova → Layer → dettaglio di un layer con POI associati. Verifica che il panel "Ec Pois" sia visibile e mostri i POI corretti.

- [ ] **Step 2: Verifica il filtro per Validator**

Accedi come Validator. Verifica che nel pannello di selezione EcPoi vengano mostrati solo i POI del Validator loggato (non quelli di altri utenti). Questo comportamento è già garantito da `LayerFeatureController::getFeatures()` che filtra per `user_id` per i non-Administrator.

- [ ] **Step 3: Verifica sync da Nova**

Dal panel "Ec Pois" in un layer, aggiungi manualmente un POI (sync via Nova UI). Verifica che l'associazione venga salvata correttamente.

- [ ] **Step 4: Commit (solo se necessario — nessun file modificato atteso)**

Se emergono correzioni minori durante la verifica, committarle con:

```bash
git commit -m "fix(oc:8139): <descrizione fix>"
```

---

## Checklist finale

- [ ] Tutti i test passano: `docker exec laravel-camminiditalia php artisan test`
- [ ] Command eseguito sul DB locale senza errori
- [ ] Panel Nova EcPoi visibile e funzionante
- [ ] `manualEcPois()` alias presente in `Layer.php` per BC
- [ ] `docs/features/8139-associazione-automatica-ecpoi-al-layer-della-traccia/notes.md` compilato

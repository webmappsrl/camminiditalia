> Ticket: oc:8080

# Trasferimento ownership EcTrack al layer owner — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Quando un admin assegna o cambia il gestore di un layer, tutte le EcTrack e EcPoi associate vengono automaticamente riassegnate al nuovo owner tramite bulk UPDATE su `user_id`.

**Architecture:** Due observer locali in `app/Observers/`: `LayerObserver` (hook `saved()` per il cambio owner del layer) e `LayerableObserver` (hook `created()` per l'associazione di una nuova risorsa a un layer con owner). Entrambi usano bulk UPDATE diretto senza triggerare observer sulle tracce. Un file di config locale gestisce il fallback `default_owner_id`.

**Tech Stack:** Laravel 10+, Nova 5, PHP 8.2, PostgreSQL

## Global Constraints

- Nessuna modifica a `wm-package/` — tutto il codice va in `app/`
- Commit convention: `feat(oc:8080): ...`
- Nessun commit o branch automatici — i commit sono istruzioni testuali
- Bulk UPDATE via query builder (`->update()`), mai `->save()` sui singoli modelli
- Nessuna transazione esplicita
- Nessun Job asincrono

---

## File map

| File | Azione | Responsabilità |
|---|---|---|
| `config/camminiditalia.php` | Crea | Config locale con `default_owner_id` |
| `app/Observers/LayerObserver.php` | Crea | Bulk transfer al cambio `user_id` del layer |
| `app/Observers/LayerableObserver.php` | Crea | Transfer singolo quando una risorsa viene associata a un layer con owner |
| `app/Providers/AppServiceProvider.php` | Modifica | Registra i due nuovi observer |
| `app/Nova/Layer.php` | Modifica | Aggiunge `->help()` sul campo `layerOwner` |

---

### Task 1: Config `camminiditalia.php`

**Files:**
- Crea: `config/camminiditalia.php`

**Interfaces:**
- Produce: `config('camminiditalia.default_owner_id')` — usato da Task 2 e Task 3

- [ ] **Step 1: Crea il file di config**

```php
<?php

return [
    /*
     * ID dell'utente a cui vengono riassegnate le risorse quando un layer perde il proprio owner.
     * Configurabile via CAMMINIDITALIA_DEFAULT_OWNER_ID in .env.
     */
    'default_owner_id' => (int) env('CAMMINIDITALIA_DEFAULT_OWNER_ID', 2),
];
```

- [ ] **Step 2: Verifica che la config sia leggibile**

```bash
docker exec laravel-camminiditalia php artisan tinker --execute="echo config('camminiditalia.default_owner_id');"
```

Expected output: `2`

---

### Task 2: `app/Observers/LayerObserver.php`

**Files:**
- Crea: `app/Observers/LayerObserver.php`

**Interfaces:**
- Consuma: `config('camminiditalia.default_owner_id')` da Task 1
- Consuma: `Wm\WmPackage\Observers\LayerObserver` (parent)
- Consuma: `$layer->ecTracks()` — `MorphToMany` verso `App\Models\EcTrack`
- Consuma: `$layer->manualEcPois()` — `MorphToMany` verso `App\Models\EcPoi`
- Produce: nessun valore di ritorno — effetto collaterale su `ec_tracks` e `ec_pois`

- [ ] **Step 1: Crea l'observer**

```php
<?php

namespace App\Observers;

use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Observers\LayerObserver as WmLayerObserver;

class LayerObserver extends WmLayerObserver
{
    public function saved(Layer $layer): void
    {
        parent::saved($layer);

        if (! $layer->wasChanged('user_id')) {
            return;
        }

        $newOwnerId = $layer->user_id ?? config('camminiditalia.default_owner_id');
        $oldOwnerId = $layer->getOriginal('user_id');

        $trackIds = $layer->ecTracks()->pluck('ec_tracks.id')->toArray();
        $poiIds = $layer->manualEcPois()->pluck('ec_pois.id')->toArray();

        if (! empty($trackIds)) {
            $layer->ecTracks()->update(['user_id' => $newOwnerId]);
        }

        if (! empty($poiIds)) {
            $layer->manualEcPois()->update(['user_id' => $newOwnerId]);
        }

        Log::info('Layer ownership transfer', [
            'layer_id'      => $layer->id,
            'old_owner_id'  => $oldOwnerId,
            'new_owner_id'  => $newOwnerId,
            'track_ids'     => $trackIds,
            'poi_ids'       => $poiIds,
        ]);
    }
}
```

- [ ] **Step 2: Verifica che la classe sia autoloadabile**

```bash
docker exec laravel-camminiditalia php artisan tinker --execute="echo class_exists(App\Observers\LayerObserver::class) ? 'ok' : 'not found';"
```

Expected output: `ok`

---

### Task 3: `app/Observers/LayerableObserver.php`

**Files:**
- Crea: `app/Observers/LayerableObserver.php`

**Interfaces:**
- Consuma: `config('camminiditalia.default_owner_id')` da Task 1
- Consuma: `Wm\WmPackage\Models\Layerable` — ha `$layerable->layer` (Layer) e `$layerable->model` (EcTrack|EcPoi)
- Produce: nessun valore di ritorno — effetto collaterale su `ec_tracks` o `ec_pois`

Note: `layerable_type` in DB può essere `App\Models\EcTrack` o `App\Models\EcPoi`. L'observer agisce solo su questi due tipi.

- [ ] **Step 1: Crea l'observer**

```php
<?php

namespace App\Observers;

use App\Models\EcPoi;
use App\Models\EcTrack;
use Illuminate\Support\Facades\Log;
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
            'layer_id'       => $layer->id,
            'layer_owner_id' => $layer->user_id,
            'resource_type'  => $layerable->layerable_type,
            'resource_id'    => $layerable->layerable_id,
        ]);
    }
}
```

- [ ] **Step 2: Verifica che la classe sia autoloadabile**

```bash
docker exec laravel-camminiditalia php artisan tinker --execute="echo class_exists(App\Observers\LayerableObserver::class) ? 'ok' : 'not found';"
```

Expected output: `ok`

---

### Task 4: Registra gli observer in `AppServiceProvider`

**Files:**
- Modifica: `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Consuma: `App\Observers\LayerObserver` da Task 2
- Consuma: `App\Observers\LayerableObserver` da Task 3

- [ ] **Step 1: Aggiungi gli import**

In cima al file, dopo gli import esistenti:

```php
use App\Observers\LayerObserver;
use App\Observers\LayerableObserver;
use Wm\WmPackage\Models\Layerable;
```

- [ ] **Step 2: Registra gli observer nel metodo `boot()`**

Aggiungi alla fine del metodo `boot()` esistente:

```php
Layer::observe(LayerObserver::class);
Layerable::observe(LayerableObserver::class);
```

Il metodo `boot()` completo risultante:

```php
public function boot(): void
{
    Gate::policy(\Wm\WmPackage\Models\UgcPoi::class, UgcPoiPolicy::class);
    Gate::policy(Layer::class, LayerPolicy::class);
    Gate::policy(Role::class, RolePolicy::class);
    Gate::policy(Permission::class, PermissionPolicy::class);
    Gate::policy(TaxonomyPoiType::class, TaxonomyPoiTypePolicy::class);

    UgcPoi::observe(UgcObserver::class);
    UgcTrack::observe(UgcObserver::class);
    Layer::observe(LayerObserver::class);
    Layerable::observe(LayerableObserver::class);
}
```

- [ ] **Step 3: Verifica che non ci siano errori di bootstrap**

```bash
docker exec laravel-camminiditalia php artisan tinker --execute="echo 'ok';"
```

Expected output: `ok` (senza eccezioni)

---

### Task 5: Aggiungi `->help()` sul campo `layerOwner` in `App\Nova\Layer`

**Files:**
- Modifica: `app/Nova/Layer.php`

**Interfaces:**
- Consuma: campo `layerOwner` (BelongsTo, attribute `'layerOwner'`) già presente nel metodo `fields()` di `App\Nova\Layer`

- [ ] **Step 1: Modifica il blocco `array_map` che gestisce `layerOwner`**

Trova il blocco esistente in `fields()`:

```php
$fields = array_map(function ($field) use ($currentUser) {
    if ($field instanceof BelongsTo && $field->attribute === 'layerOwner') {
        // Show layerOwner field only to admins
        $field->canSee(function () use ($currentUser) {
            return $currentUser && $currentUser->hasRole('Administrator');
        });
    }

    return $field;
}, $fields);
```

Sostituiscilo con:

```php
$fields = array_map(function ($field) use ($currentUser) {
    if ($field instanceof BelongsTo && $field->attribute === 'layerOwner') {
        $field->canSee(function () use ($currentUser) {
            return $currentUser && $currentUser->hasRole('Administrator');
        });
        $field->help('⚠️ Modificando il gestore, tutte le tracce e i POI associati a questo layer verranno automaticamente trasferiti al nuovo gestore.');
    }

    return $field;
}, $fields);
```

- [ ] **Step 2: Verifica visivamente in Nova**

Apri il pannello Nova → Layer → modifica un layer qualsiasi. Verifica che sotto il campo "Owner" compaia il testo di avviso.

---

## Commit finale suggerito

Dopo aver verificato manualmente il comportamento in Nova:

```bash
git add config/camminiditalia.php \
        app/Observers/LayerObserver.php \
        app/Observers/LayerableObserver.php \
        app/Providers/AppServiceProvider.php \
        app/Nova/Layer.php
git commit -m "feat(oc:8080): trasferimento ownership EcTrack e EcPoi al cambio layer owner"
```

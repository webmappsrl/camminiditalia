> Ticket: oc:8140

# Plan — Fix: properties.layers su EcPoi valorizzato erroneamente per layer senza taxonomy_where

## Repo coinvolti
- **wm-package** — fix guard in `LayerService`
- **camminiditalia** (repo principale) — command di riallineamento

---

## Step 1 — Crea branch in wm-package

```bash
git -C wm-package checkout -b feature/oc-8140-fix-properties-layers-ecpoi-layer-senza-taxonomy-where
```

---

## Step 2 — Crea branch nel repo principale

```bash
git checkout -b feature/oc-8140-fix-properties-layers-ecpoi-layer-senza-taxonomy-where
```

---

## Step 3 — Fix in `LayerService` (wm-package)

**File:** `wm-package/src/Services/Models/LayerService.php`

### 3a — Aggiungi metodo privato `hasValidAutoModeFilter`

```php
private function hasValidAutoModeFilter(Layer $layer): bool
{
    return ! empty($layer->properties['taxonomy_where'] ?? [])
        || $layer->taxonomyActivities->isNotEmpty();
}
```

### 3b — Aggiungi guard all'inizio di `updateLayersPropertyOnLayeredFeature`

Subito dopo la firma del metodo, prima di qualsiasi query:

```php
public function updateLayersPropertyOnLayeredFeature(Layer $layer, string $ecModelClass): array
{
    if (! $this->hasRelatedManualModels($layer, $ecModelClass) && ! $this->hasValidAutoModeFilter($layer)) {
        return ['added' => [], 'deleted' => []];
    }

    // ... codice esistente invariato
}
```

**Nota:** la guard usa `$layer->taxonomyActivities->isNotEmpty()` (accesso come property, non come query diretta) così beneficia dell'eager loading nel command senza fare query aggiuntive.

---

## Step 4 — Test del guard in wm-package

**File da creare:** `wm-package/tests/Feature/LayerServiceUpdateLayersPropertyGuardTest.php`

Namespace: `Wm\WmPackage\Tests\Feature`
Usa: `DatabaseTransactions`, `LayerService`, `App`, `Layer`, `EcPoi` del package

**Casi da coprire:**

1. **`test_skips_update_when_layer_has_no_manual_models_and_no_taxonomy_filter`**
   - Layer senza `taxonomy_where` in properties, senza `taxonomyActivities`, senza EcPoi in layerables
   - EcPoi dello stesso app_id esistente
   - Chiama `updateLayersPropertyOnLayeredFeature($layer, EcPoi::class)`
   - Assert: ritorna `['added' => [], 'deleted' => []]`
   - Assert: `properties['layers']` dell'EcPoi non contiene l'ID del layer

2. **`test_updates_when_layer_has_manual_ec_poi`**
   - Layer senza taxonomy filter ma con 1 EcPoi in layerables
   - Chiama `updateLayersPropertyOnLayeredFeature($layer, EcPoi::class)`
   - Assert: `added` contiene l'ID dell'EcPoi manuale

3. **`test_updates_when_layer_has_taxonomy_where`**
   - Layer con `properties['taxonomy_where']` valorizzato
   - Chiama `updateLayersPropertyOnLayeredFeature($layer, EcPoi::class)`
   - Assert: il metodo non fa early return (ritorna array con chiavi `added`/`deleted` anche se entrambi vuoti per mancanza di match tassonomici)

4. **`test_updates_when_layer_has_taxonomy_activities`**
   - Layer con almeno una `taxonomyActivity` associata via pivot
   - Chiama `updateLayersPropertyOnLayeredFeature($layer, EcPoi::class)`
   - Assert: il metodo non fa early return

5. **`test_skips_update_for_ec_track_when_no_manual_models_and_no_filter`** *(regressione per EcTrack)*
   - Stesso scenario del test 1 ma con `EcTrack::class`
   - Assert: ritorna `['added' => [], 'deleted' => []]`

Esegui i test con:
```bash
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/LayerServiceUpdateLayersPropertyGuardTest.php
```

---

## Step 5 — Command di riallineamento (repo principale)

**File da creare:** `app/Console/Commands/FixEcPoiLayersProperty.php`

```
Signature: camminiditalia:fix-ec-poi-layers-property {--force : Bypassa il check di pre-deploy}
Description: Riallinea properties.layers su EcPoi e EcTrack per tutti i layer
```

### Logica del command

```
1. Self-check (saltato con --force):
   - Trova un layer senza taxonomy_where, senza taxonomyActivities, senza manuali
   - Chiama updateLayersPropertyOnLayeredFeature su di esso
   - Se 'added' non è vuoto → codice non patchato → abort con errore esplicito:
     "Il fix in wm-package non è deployato. Esegui prima il deploy, poi riesegui il command.
      Usa --force per bypassare questo controllo (solo se sai cosa fai)."

2. Carica tutti i layer con eager loading:
   Layer::with(['taxonomyActivities'])->get()

3. Per ogni layer × ogni model class in LayerService::getModelsWithLayersInProperties():
   - Chiama $layerService->updateLayersPropertyOnLayeredFeature($layer, $modelClass)
   - Logga: "[Layer {$layer->getStringName()}] {$modelClass}: +{added} / -{deleted}"

4. Output finale: "Riallineamento completato. Tot. layer: N, aggiunte: X, rimozioni: Y"
```

**Nota:** `$layer->getStringName()` invece di `$layer->name` (che restituisce stringa vuota per il cast).

---

## Step 6 — Registra il command

**File:** `routes/console.php` (Laravel 11 — non `app/Console/Kernel.php`)

Aggiungi:
```php
Artisan::command('camminiditalia:fix-ec-poi-layers-property ...', function () {
    // oppure registra via discover automatico se il command è in app/Console/Commands/
});
```

Il command viene auto-scoperto da Laravel 11 se in `app/Console/Commands/` — verifica che il bootstrap lo includa, altrimenti aggiungi esplicitamente in `routes/console.php`.

---

## Step 7 — Esegui i test di regressione

```bash
docker exec laravel-camminiditalia php artisan test
```

Verifica che nessun test esistente regredisca dopo la guard.

---

## Step 8 — Commit in wm-package

```bash
git -C wm-package add src/Services/Models/LayerService.php \
    tests/Feature/LayerServiceUpdateLayersPropertyGuardTest.php \
    docs/features/8140-fix-properties-layers-ecpoi-layer-senza-taxonomy-where/
git -C wm-package commit -m "fix(oc:8140): skip updateLayersProperty when layer has no filters or manual models"
```

---

## Step 9 — Commit nel repo principale

```bash
git add app/Console/Commands/FixEcPoiLayersProperty.php \
    docs/features/8140-fix-properties-layers-ecpoi-layer-senza-taxonomy-where/
git commit -m "fix(oc:8140): add FixEcPoiLayersProperty command for data realignment"
```

---

## Step 10 — PR verso `develop`

Apri due PR (una per wm-package, una per il repo principale) verso `develop`.

---

## Ordine di deploy in produzione

1. Deploy wm-package (fix guard)
2. Deploy repo principale (command)
3. **Backup DB** — `properties` è JSONB senza migration inversa, non c'è rollback automatico
4. Esegui `sync-layer-ec-pois` per assicurare che `layerables` abbia tutte le associazioni corrette:
   ```bash
   docker exec laravel-camminiditalia php artisan camminiditalia:sync-layer-ec-pois
   ```
5. Esegui `fix-ec-poi-layers-property` che usa `layerables` come sorgente di verità per aggiornare `properties['layers']`:
   ```bash
   docker exec laravel-camminiditalia php artisan camminiditalia:fix-ec-poi-layers-property
   ```
6. Verifica log output per audit

> **Perché questo ordine?** `sync-layer-ec-pois` popola `layerables` (pivot) dai POI delle tracce. `fix-ec-poi-layers-property` legge `layerables` per aggiornare `properties['layers']`. Se salti il punto 4, il punto 5 aggiorna `properties['layers']` basandosi su `layerables` potenzialmente incompleti.

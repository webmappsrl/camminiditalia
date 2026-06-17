> Ticket: oc:8089

# Fix UI layer owner: nascondere action Aggiungi alla home e correggere link occhio tracce

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nascondere `AddLayersToConfigHomeAction` ai non-Administrator e correggere il link icona occhio nella widget tracce del layer.

**Architecture:** Due fix indipendenti — il primo è un'aggiunta di una riga nell'override locale `App\Nova\Layer.php`; il secondo richiede una modifica al submodule `wm-package` (PHP + TypeScript) con rebuild del dist.

**Tech Stack:** Laravel Nova 5, PHP 8.2, TypeScript, AG Grid, Docker

## Global Constraints

- Tutti i comandi `php artisan` dentro `docker exec laravel-camminiditalia`
- Commit convention: `fix(oc:8089): ...`
- NO commit automatici — il developer esegue i commit manualmente dopo revisione
- Branch da creare prima di toccare qualsiasi file

---

### Task 1: Creare il branch di lavoro

**Files:**
- Nessun file modificato

- [ ] **Step 1: Crea il branch nel repo principale**

```bash
git -C /Users/bongiu/Documents/camminiditalia checkout -b fix/oc-8089-fix-ui-layer-owner
```

- [ ] **Step 2: Crea lo stesso branch nel submodule**

```bash
git -C /Users/bongiu/Documents/camminiditalia/wm-package checkout -b fix/oc-8089-fix-ui-layer-owner
```

- [ ] **Step 3: Verifica**

```bash
git -C /Users/bongiu/Documents/camminiditalia branch --show-current
git -C /Users/bongiu/Documents/camminiditalia/wm-package branch --show-current
```

Expected: `fix/oc-8089-fix-ui-layer-owner` in entrambi.

---

### Task 2: Fix `AddLayersToConfigHomeAction` — canSee + canRun

**Files:**
- Modify: `app/Nova/Layer.php`

**Interfaces:**
- Consumes: classe `Wm\WmPackage\Nova\Actions\AddLayersToConfigHomeAction` già importata nel parent `WmNovaLayer`
- Produces: action nascosta e non eseguibile per non-Administrator

- [ ] **Step 1: Scrivi il test fallente**

Crea `tests/Feature/LayerActionsVisibilityTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Layer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Wm\WmPackage\Nova\Actions\AddLayersToConfigHomeAction;

class LayerActionsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeLayer(): Layer
    {
        $owner = User::factory()->create();
        $owner->assignRole('Administrator');

        return Layer::factory()->create(['user_id' => $owner->id]);
    }

    public function test_validator_cannot_see_add_to_home_action(): void
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');
        $layer = $this->makeLayer();

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/layers/actions?resourceId='.$layer->id);

        $response->assertOk();

        $actionNames = collect($response->json('actions'))->pluck('uriKey')->toArray();
        $this->assertNotContains('add-layers-to-config-home-action', $actionNames);
    }

    public function test_administrator_can_see_add_to_home_action(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $layer = $this->makeLayer();

        $response = $this->actingAs($admin)
            ->getJson('/nova-api/layers/actions?resourceId='.$layer->id);

        $response->assertOk();

        $actionNames = collect($response->json('actions'))->pluck('uriKey')->toArray();
        $this->assertContains('add-layers-to-config-home-action', $actionNames);
    }

    public function test_validator_cannot_run_add_to_home_action(): void
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');
        $layer = $this->makeLayer();

        $response = $this->actingAs($validator)
            ->postJson('/nova-api/layers/action', [
                'action' => 'add-layers-to-config-home-action',
                'resources' => [$layer->id],
            ]);

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/LayerActionsVisibilityTest.php
```

Expected: tutti e 3 i test falliscono (il primo e il terzo perché l'action è ancora visibile/eseguibile ai Validator).

- [ ] **Step 3: Modifica `app/Nova/Layer.php`**

Aggiungi l'import in cima:

```php
use Wm\WmPackage\Nova\Actions\AddLayersToConfigHomeAction;
```

Nel metodo `actions()`, estendi il blocco `if` esistente (riga 67):

```php
// Restrict RegenerateLayerPbfAction, ExecuteEcTrackDataChainAction and AddLayersToConfigHomeAction to administrators only
if ($action instanceof RegenerateLayerPbfAction || $action instanceof ExecuteEcTrackDataChainAction || $action instanceof AddLayersToConfigHomeAction) {
    $action->canSee(function () use ($currentUser) {
        return $currentUser && $currentUser->hasRole('Administrator');
    });
    $action->canRun(function ($request, $model) use ($currentUser) {
        return $currentUser && $currentUser->hasRole('Administrator');
    });
}
```

Il metodo `actions()` completo dopo la modifica:

```php
public function actions(NovaRequest $request): array
{
    $actions = parent::actions($request);
    $currentUser = $request->user();

    // Restrict RegenerateLayerPbfAction, ExecuteEcTrackDataChainAction and AddLayersToConfigHomeAction to administrators only
    $actions = array_map(function ($action) use ($currentUser) {
        if ($action instanceof RegenerateLayerPbfAction || $action instanceof ExecuteEcTrackDataChainAction || $action instanceof AddLayersToConfigHomeAction) {
            $action->canSee(function () use ($currentUser) {
                return $currentUser && $currentUser->hasRole('Administrator');
            });
            $action->canRun(function ($request, $model) use ($currentUser) {
                return $currentUser && $currentUser->hasRole('Administrator');
            });
        }

        return $action;
    }, $actions);

    return $actions;
}
```

- [ ] **Step 4: Esegui i test per verificare che passino**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/LayerActionsVisibilityTest.php
```

Expected: tutti e 3 i test passano.

- [ ] **Step 5: Esegui la suite completa per regressioni**

```bash
docker exec laravel-camminiditalia php artisan test
```

Expected: nessun test precedentemente verde diventa rosso.

---

### Task 3: Fix link icona occhio nel submodule wm-package

**Files:**
- Modify: `wm-package/src/Nova/Fields/LayerFeatures/src/LayerFeatures.php`
- Modify: `wm-package/src/Nova/Fields/LayerFeatures/resources/js/composables/useGrid.ts`
- Modify: `wm-package/src/Nova/Fields/LayerFeatures/resources/js/components/LayerFeature.vue` (solo la chiamata a `useGrid`)
- Rebuild: `wm-package/src/Nova/Fields/LayerFeatures/dist/`

**Interfaces:**
- Consumes: `Nova::path()` da `Laravel\Nova\Nova`
- Produces: prop `novaPath` disponibile nel composable `useGrid`, link occhio corretto

- [ ] **Step 1: Modifica `LayerFeatures.php` — aggiungi `novaPath` al `withMeta`**

Aggiungi l'import:

```php
use Laravel\Nova\Nova;
```

Nel metodo `loadEcFeatures()`, aggiungi `novaPath` al `withMeta` esistente (dopo `trackMode`):

```php
$this->withMeta([
    'selectedEcFeaturesIds' => $selectedFeatureIds,
    'model' => $modelClass,
    'modelName' => $modelName,
    'layerId' => $layer->id,
    'modelClass' => $modelClass,
    'model_class' => $modelClass,
    'trackMode' => $layer->isAutoTrackMode() ? 'auto' : 'manual',
    'novaPath' => '/'.trim(Nova::path(), '/'),
]);
```

Il `trim` garantisce che non ci siano doppi slash anche se `Nova::path()` restituisce `/nova/` con slash finale.

- [ ] **Step 2: Modifica `useGrid.ts` — aggiungi `novaPath` all'interfaccia e usalo nel link**

Modifica l'interfaccia `UseGridProps` (riga 38):

```typescript
interface UseGridProps {
    resourceName?: string;
    modelName?: string;
    novaPath?: string;
}
```

Modifica il `cellRenderer` per l'icona occhio (riga 162–175), usando `novaPath` con fallback a `/nova`:

```typescript
cellRenderer: (params: { data: any }) => {
    const resourcePath = props.modelName ? camelToKebabCase(props.modelName) : 'ec-tracks';
    const novaPath = props.novaPath || '/nova';
    
    return `
        <a href="${novaPath}/resources/${resourcePath}/${params.data.id}"
           target="_blank"
           class="flex items-center justify-center"
           style="color: rgb(var(--colors-gray-500))">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
                <path d="M10 12.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z" />
                <path fill-rule="evenodd" d="M.664 10.59a1.651 1.651 0 010-1.186A10.004 10.004 0 0110 3c4.257 0 7.893 2.66 9.336 6.41.147.381.146.804 0 1.186A10.004 10.004 0 0110 17c-4.257 0-7.893-2.66-9.336-6.41zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" />
            </svg>
        </a>
    `;
}
```

- [ ] **Step 3: Modifica `LayerFeature.vue` — passa `novaPath` a `useGrid`**

Trova la chiamata a `useGrid` (intorno alla riga 222) e aggiungi `novaPath`:

```typescript
} = useGrid({
    resourceName: props.resourceName,
    modelName: props.field?.modelName,
    novaPath: props.field?.novaPath,
});
```

- [ ] **Step 4: Rebuild del dist**

```bash
cd /Users/bongiu/Documents/camminiditalia/wm-package/src/Nova/Fields/LayerFeatures && npm run build
```

Expected: il comando completa senza errori e aggiorna i file in `dist/`.

- [ ] **Step 5: Verifica visiva del link nel browser**

Apri il pannello Nova → Layer → dettaglio di un layer con tracce associate. Clicca sull'icona occhio di una traccia. Verifica che il link punti a `/nova/resources/ec-tracks/<id>` e che la pagina si apra correttamente.

---

### Task 4: Commit finale

- [ ] **Step 1: Verifica il diff nel repo principale**

```bash
git -C /Users/bongiu/Documents/camminiditalia diff --stat
```

- [ ] **Step 2: Verifica il diff nel submodule**

```bash
git -C /Users/bongiu/Documents/camminiditalia/wm-package diff --stat
```

- [ ] **Step 3: Commit nel submodule**

```bash
git -C /Users/bongiu/Documents/camminiditalia/wm-package add \
  src/Nova/Fields/LayerFeatures/src/LayerFeatures.php \
  src/Nova/Fields/LayerFeatures/resources/js/composables/useGrid.ts \
  src/Nova/Fields/LayerFeatures/resources/js/components/LayerFeature.vue \
  src/Nova/Fields/LayerFeatures/dist/
git -C /Users/bongiu/Documents/camminiditalia/wm-package commit -m "fix(oc:8089): add novaPath prop to LayerFeatures field for correct eye-link URL"
```

- [ ] **Step 4: Commit nel repo principale**

```bash
git -C /Users/bongiu/Documents/camminiditalia add \
  app/Nova/Layer.php \
  tests/Feature/LayerActionsVisibilityTest.php \
  wm-package
git -C /Users/bongiu/Documents/camminiditalia commit -m "fix(oc:8089): hide AddLayersToConfigHomeAction from non-Administrator and bump wm-package"
```

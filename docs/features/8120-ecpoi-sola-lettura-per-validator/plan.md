> Ticket: oc:8120

# EcPoi: sola lettura per Validator — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bloccare create/update/delete su EcPoi per i Validator (sola lettura) e per i Guest (nessun accesso Nova), mantenendo pieno accesso agli Administrator.

**Architecture:** Policy locale `App\Policies\EcPoiPolicy` registrata via `Gate::policy()` in `AppServiceProvider`, che sovrascrive quella del package. La risorsa Nova locale `App\Nova\EcPoi` nasconde i controlli di modifica con `authorizedToCreate/Update/Delete` e filtra le action con `canSee`+`canRun`.

**Tech Stack:** Laravel 10, Nova 5, Spatie Permission, PHPUnit

## Global Constraints

- Commit convention: `feat(oc:8120): ...` / `test(oc:8120): ...`
- Nessun commit automatico — solo istruzioni testuali
- I comandi `php artisan` vanno eseguiti dentro il container: `docker exec laravel-camminiditalia php artisan <cmd>`
- Ruoli disponibili: `Administrator`, `Validator`, `Guest`
- Pattern `before()`: `Administrator → true`, `non-Validator → false`, `Validator → null`
- Nova resource URI per EcPoi: `/nova-api/ec-pois`

---

### Task 1: Policy locale EcPoi + registrazione

**Files:**
- Create: `app/Policies/EcPoiPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/EcPoiPolicyTest.php`

**Interfaces:**
- Produces: `App\Policies\EcPoiPolicy` registrata su `Wm\WmPackage\Models\EcPoi::class`

- [ ] **Step 1: Scrivi il test fallente**

Crea `tests/Feature/EcPoiPolicyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EcPoi;
use App\Models\User;
use App\Policies\EcPoiPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class EcPoiPolicyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
    }

    private function makeEcPoi(?int $userId = null): EcPoi
    {
        return EcPoi::factory()->create($userId ? ['user_id' => $userId] : []);
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    // --- Policy attiva è quella locale, non del package ---

    public function test_local_ecpoi_policy_is_registered(): void
    {
        $policy = Gate::getPolicyFor(\Wm\WmPackage\Models\EcPoi::class);
        $this->assertInstanceOf(EcPoiPolicy::class, $policy);
    }

    // --- Administrator ---

    public function test_administrator_can_view_any_ec_poi(): void
    {
        $admin = $this->makeUser('Administrator');
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', \Wm\WmPackage\Models\EcPoi::class));
    }

    public function test_administrator_can_view_ec_poi(): void
    {
        $admin = $this->makeUser('Administrator');
        $ecPoi = $this->makeEcPoi();
        $this->assertTrue(Gate::forUser($admin)->allows('view', $ecPoi));
    }

    public function test_administrator_can_create_ec_poi(): void
    {
        $admin = $this->makeUser('Administrator');
        $this->assertTrue(Gate::forUser($admin)->allows('create', \Wm\WmPackage\Models\EcPoi::class));
    }

    public function test_administrator_can_update_ec_poi(): void
    {
        $admin = $this->makeUser('Administrator');
        $ecPoi = $this->makeEcPoi();
        $this->assertTrue(Gate::forUser($admin)->allows('update', $ecPoi));
    }

    public function test_administrator_can_delete_ec_poi(): void
    {
        $admin = $this->makeUser('Administrator');
        $ecPoi = $this->makeEcPoi();
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $ecPoi));
    }

    // --- Validator: sola lettura ---

    public function test_validator_can_view_any_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');
        $this->assertTrue(Gate::forUser($validator)->allows('viewAny', \Wm\WmPackage\Models\EcPoi::class));
    }

    public function test_validator_can_view_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');
        $ecPoi = $this->makeEcPoi();
        $this->assertTrue(Gate::forUser($validator)->allows('view', $ecPoi));
    }

    public function test_validator_cannot_create_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');
        $this->assertFalse(Gate::forUser($validator)->allows('create', \Wm\WmPackage\Models\EcPoi::class));
    }

    public function test_validator_cannot_update_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');
        $ecPoi = $this->makeEcPoi($validator->id);
        $this->assertFalse(Gate::forUser($validator)->allows('update', $ecPoi));
    }

    public function test_validator_cannot_delete_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');
        $ecPoi = $this->makeEcPoi($validator->id);
        $this->assertFalse(Gate::forUser($validator)->allows('delete', $ecPoi));
    }

    // --- Guest: nessun accesso Nova ---

    public function test_guest_cannot_view_any_ec_poi(): void
    {
        $guest = $this->makeUser('Guest');
        $this->assertFalse(Gate::forUser($guest)->allows('viewAny', \Wm\WmPackage\Models\EcPoi::class));
    }

    public function test_guest_cannot_view_ec_poi(): void
    {
        $guest = $this->makeUser('Guest');
        $ecPoi = $this->makeEcPoi();
        $this->assertFalse(Gate::forUser($guest)->allows('view', $ecPoi));
    }
}
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiPolicyTest.php
```

Atteso: FAIL — la policy del package non ha il comportamento atteso per Validator/Guest.

- [ ] **Step 3: Crea `app/Policies/EcPoiPolicy.php`**

```php
<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Wm\WmPackage\Models\EcPoi;

class EcPoiPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('Administrator')) {
            return true;
        }

        if (! $user->hasRole('Validator')) {
            return false;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EcPoi $ecPoi): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EcPoi $ecPoi): bool
    {
        return false;
    }

    public function delete(User $user, EcPoi $ecPoi): bool
    {
        return false;
    }
}
```

- [ ] **Step 4: Registra la policy in `AppServiceProvider`**

In `app/Providers/AppServiceProvider.php`, aggiungi in cima agli use:

```php
use App\Policies\EcPoiPolicy;
use Wm\WmPackage\Models\EcPoi;
```

Aggiungi nel metodo `boot()`, dopo le policy esistenti:

```php
Gate::policy(EcPoi::class, EcPoiPolicy::class);
```

Il metodo `boot()` risultante:

```php
public function boot(): void
{
    Gate::policy(\Wm\WmPackage\Models\UgcPoi::class, UgcPoiPolicy::class);
    Gate::policy(Layer::class, LayerPolicy::class);
    Gate::policy(Role::class, RolePolicy::class);
    Gate::policy(Permission::class, PermissionPolicy::class);
    Gate::policy(TaxonomyPoiType::class, TaxonomyPoiTypePolicy::class);
    Gate::policy(EcPoi::class, EcPoiPolicy::class);

    UgcPoi::observe(UgcObserver::class);
    UgcTrack::observe(UgcObserver::class);
    Layer::observe(LayerObserver::class);
    Layerable::observe(LayerableObserver::class);
}
```

- [ ] **Step 5: Esegui i test per verificare che passino**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiPolicyTest.php
```

Atteso: tutti PASS.

- [ ] **Step 6: Esegui la suite completa per verificare nessuna regressione**

```bash
docker exec laravel-camminiditalia php artisan test
```

Atteso: tutti PASS.

- [ ] **Step 7: Commit**

```
feat(oc:8120): add local EcPoiPolicy — Validator read-only, Guest blocked in Nova
```

File da includere:
- `app/Policies/EcPoiPolicy.php`
- `app/Providers/AppServiceProvider.php`
- `tests/Feature/EcPoiPolicyTest.php`

---

### Task 2: Override Nova EcPoi — nasconde controlli di modifica e filtra action

**Files:**
- Modify: `app/Nova/EcPoi.php`
- Test: `tests/Feature/EcPoiNovaActionsTest.php`

**Interfaces:**
- Consumes: `App\Policies\EcPoiPolicy` registrata (Task 1)
- Produces: risorsa Nova che nasconde Edit/Delete/Create ai Validator e filtra le action

- [ ] **Step 1: Scrivi i test fallenti**

Crea `tests/Feature/EcPoiNovaActionsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EcPoi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class EcPoiNovaActionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
    }

    private function makeEcPoi(): EcPoi
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        return EcPoi::factory()->create(['user_id' => $admin->id]);
    }

    public function test_validator_cannot_see_modifying_actions(): void
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');
        $ecPoi = $this->makeEcPoi();

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/ec-pois/actions?resourceId='.$ecPoi->id);

        $response->assertOk();

        $actionKeys = collect($response->json('actions'))->pluck('uriKey')->toArray();
        $this->assertNotContains('execute-ec-poi-data-chain-action', $actionKeys);
        $this->assertNotContains('upload-poi-file', $actionKeys);
        $this->assertNotContains('translate-model-action', $actionKeys);
    }

    public function test_validator_can_see_download_action(): void
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');
        $ecPoi = $this->makeEcPoi();

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/ec-pois/actions?resourceId='.$ecPoi->id);

        $response->assertOk();

        $actionKeys = collect($response->json('actions'))->pluck('uriKey')->toArray();
        $this->assertContains('download-ec-poi-action', $actionKeys);
    }

    public function test_validator_cannot_run_modifying_actions(): void
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');
        $ecPoi = $this->makeEcPoi();

        foreach (['execute-ec-poi-data-chain-action', 'upload-poi-file', 'translate-model-action'] as $actionKey) {
            $response = $this->actingAs($validator)
                ->postJson('/nova-api/ec-pois/action', [
                    'action' => $actionKey,
                    'resources' => (string) $ecPoi->id,
                ]);

            $this->assertContains($response->status(), [403, 404],
                "Action $actionKey should be blocked for Validator");
        }
    }

    public function test_administrator_can_see_all_actions(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $ecPoi = $this->makeEcPoi();

        $response = $this->actingAs($admin)
            ->getJson('/nova-api/ec-pois/actions?resourceId='.$ecPoi->id);

        $response->assertOk();

        $actionKeys = collect($response->json('actions'))->pluck('uriKey')->toArray();
        $this->assertContains('execute-ec-poi-data-chain-action', $actionKeys);
        $this->assertContains('download-ec-poi-action', $actionKeys);
        $this->assertContains('upload-poi-file', $actionKeys);
        $this->assertContains('translate-model-action', $actionKeys);
    }
}
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiNovaActionsTest.php
```

Atteso: FAIL — le action di modifica sono ancora visibili ai Validator.

- [ ] **Step 3: Aggiorna `app/Nova/EcPoi.php`**

```php
<?php

namespace App\Nova;

use App\Models\EcPoi as EcPoiModel;
use Illuminate\Http\Request;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Actions\DownloadEcPoiAction;
use Wm\WmPackage\Nova\Actions\ExecuteEcPoiDataChainAction;
use Wm\WmPackage\Nova\Actions\TranslateModelAction;
use Wm\WmPackage\Nova\Actions\UploadPoiFile;
use Wm\WmPackage\Nova\EcPoi as WmNovaEcPoi;

class EcPoi extends WmNovaEcPoi
{
    public static $model = EcPoiModel::class;

    public static function label(): string
    {
        return __('Pois');
    }

    public function authorizedToCreate(Request $request): bool
    {
        return $request->user()?->hasRole('Administrator') ?? false;
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return $request->user()?->hasRole('Administrator') ?? false;
    }

    public function authorizedToDelete(Request $request): bool
    {
        return $request->user()?->hasRole('Administrator') ?? false;
    }

    public function actions(NovaRequest $request): array
    {
        $isAdmin = $request->user()?->hasRole('Administrator');

        return [
            (new ExecuteEcPoiDataChainAction)
                ->canSee(fn () => $isAdmin)
                ->canRun(fn ($req, $model) => $isAdmin),
            new DownloadEcPoiAction,
            (new UploadPoiFile)
                ->standalone()
                ->canSee(fn () => $isAdmin)
                ->canRun(fn ($req, $model) => $isAdmin),
            (new TranslateModelAction)
                ->canSee(fn () => $isAdmin)
                ->canRun(fn ($req, $model) => $isAdmin),
        ];
    }
}
```

- [ ] **Step 4: Esegui i test per verificare che passino**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiNovaActionsTest.php
```

Atteso: tutti PASS.

- [ ] **Step 5: Esegui la suite completa**

```bash
docker exec laravel-camminiditalia php artisan test
```

Atteso: tutti PASS.

- [ ] **Step 6: Commit**

```
feat(oc:8120): restrict Nova EcPoi edit controls and actions to Administrator
```

File da includere:
- `app/Nova/EcPoi.php`
- `tests/Feature/EcPoiNovaActionsTest.php`

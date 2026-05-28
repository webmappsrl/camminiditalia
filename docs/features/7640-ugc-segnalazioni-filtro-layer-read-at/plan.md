# UGC Segnalazioni: Filtro Layer e Flag Letto/Non Letto — Piano di Implementazione

> Ticket: oc:7640

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Limitare la vista Nova UGC ai Validator (gestori) mostrando solo le segnalazioni dei propri layer, e introdurre un sistema letto/non letto con badge e action bulk.

**Architecture:** Tutto il codice va nel repo principale `camminiditalia`. Si estende `App\Nova\UgcPoi` con `indexQuery()` custom, si crea `App\Models\UgcPoi` per il campo `read_at`, si aggiunge `App\Policies\UgcPoiPolicy` registrata in `AppServiceProvider`. La policy locale sovrascrive quella del package via `Gate::policy()`.

**Tech Stack:** Laravel 11, Laravel Nova, Spatie Laravel Permission, PostgreSQL/PostGIS, Pest

---

## Mappa file

| File | Azione | Responsabilità |
|---|---|---|
| `database/migrations/xxxx_add_read_at_to_ugc_pois_table.php` | Crea | Aggiunge `read_at` e indice su `properties->>'layer_id'` |
| `app/Models/UgcPoi.php` | Crea | Estende modello package con `read_at` fillable e cast |
| `app/Policies/UgcPoiPolicy.php` | Crea | Policy custom con `before()` esplicito e `viewAny/view` per ruolo |
| `app/Providers/AppServiceProvider.php` | Modifica | Registra `UgcPoiPolicy` e rimuove bind implicito al package |
| `app/Nova/Actions/MarkAsRead.php` | Crea | Action bulk: `read_at = now()` |
| `app/Nova/Actions/MarkAsUnread.php` | Crea | Action bulk: `read_at = null` |
| `app/Nova/UgcPoi.php` | Modifica | `indexQuery()` con filtro ruolo, badge, actions |
| `tests/Feature/UgcPoiPolicyTest.php` | Crea | Test policy multi-ruolo |
| `tests/Feature/UgcPoiIndexQueryTest.php` | Crea | Test `indexQuery()` multi-ruolo |

---

## Task 1: Migration `read_at` e indice JSONB

**Files:**
- Crea: `database/migrations/2026_05_28_000001_add_read_at_to_ugc_pois_table.php`

- [ ] **Step 1: Crea la migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ugc_pois', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('properties');
        });

        DB::statement("CREATE INDEX ugc_pois_layer_id_idx ON ugc_pois ((properties->>'layer_id'))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ugc_pois_layer_id_idx');

        Schema::table('ugc_pois', function (Blueprint $table) {
            $table->dropColumn('read_at');
        });
    }
};
```

- [ ] **Step 2: Esegui la migration**

```bash
docker exec laravel-camminiditalia php artisan migrate
```

Output atteso: `Migrating: 2026_05_28_000001_add_read_at_to_ugc_pois_table` → `Migrated`

---

## Task 2: Modello `App\Models\UgcPoi`

**Files:**
- Crea: `app/Models/UgcPoi.php`

- [ ] **Step 1: Crea il modello locale**

```php
<?php

namespace App\Models;

use Wm\WmPackage\Models\UgcPoi as BaseUgcPoi;

class UgcPoi extends BaseUgcPoi
{
    protected $fillable = [
        'user_id',
        'app_id',
        'name',
        'geometry',
        'properties',
        'created_by',
        'read_at',
    ];

    protected $casts = [
        'properties' => 'array',
        'read_at' => 'datetime',
    ];
}
```

- [ ] **Step 2: Registra il modello locale in `config/wm-package.php`**

Apri `config/wm-package.php` e verifica/aggiungi:

```php
'ugc_poi_model' => App\Models\UgcPoi::class,
```

Se la chiave non esiste nel config, aggiungila. Se il file non esiste:

```bash
docker exec laravel-camminiditalia php artisan vendor:publish --tag=wm-package-config
```

- [ ] **Step 3: Aggiorna `AppServiceProvider` per usare il modello locale**

In `app/Providers/AppServiceProvider.php`, cambia l'import dell'observer e il bind del modello:

```php
// Cambia questa riga:
use Wm\WmPackage\Models\UgcPoi;
// In:
use App\Models\UgcPoi;
```

Il resto di `AppServiceProvider` rimane invariato.

---

## Task 3: Policy `App\Policies\UgcPoiPolicy`

**Files:**
- Crea: `app/Policies/UgcPoiPolicy.php`
- Modifica: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/UgcPoiPolicyTest.php`

- [ ] **Step 1: Scrivi il test fallente**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class UgcPoiPolicyTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
        if (WmApp::count() === 0) {
            WmApp::factory()->create();
        }
    }

    // --- Administrator ---

    public function test_administrator_can_view_any_ugc_poi(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', UgcPoi::class));
    }

    public function test_administrator_can_view_ugc_poi(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $poi = UgcPoi::factory()->create();
        $this->assertTrue(Gate::forUser($admin)->allows('view', $poi));
    }

    // --- Validator ---

    public function test_validator_can_view_any_ugc_poi(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $this->assertTrue(Gate::forUser($validator)->allows('viewAny', UgcPoi::class));
    }

    public function test_validator_can_view_ugc_poi(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $poi = UgcPoi::factory()->create();
        $this->assertTrue(Gate::forUser($validator)->allows('view', $poi));
    }

    // --- Guest ---

    public function test_guest_cannot_view_any_ugc_poi(): void
    {
        $guest = $this->createUserWithRole('Guest');
        $this->assertFalse(Gate::forUser($guest)->allows('viewAny', UgcPoi::class));
    }

    public function test_guest_cannot_view_ugc_poi(): void
    {
        $guest = $this->createUserWithRole('Guest');
        $poi = UgcPoi::factory()->create();
        $this->assertFalse(Gate::forUser($guest)->allows('view', $poi));
    }

    // --- Unauthenticated / no role ---

    public function test_user_without_role_cannot_view_any_ugc_poi(): void
    {
        $user = $this->createUserWithoutRole();
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', UgcPoi::class));
    }
}
```

- [ ] **Step 2: Esegui il test — deve fallire**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/UgcPoiPolicyTest.php
```

Output atteso: FAIL (policy del package ritorna `true` per tutti — i test Guest/no-role passano quando non dovrebbero).

- [ ] **Step 3: Crea la policy locale**

```php
<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Wm\WmPackage\Models\UgcPoi;

class UgcPoiPolicy
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
        return $user->hasRole('Validator');
    }

    public function view(User $user, UgcPoi $ugcPoi): bool
    {
        return $user->hasRole('Validator');
    }
}
```

- [ ] **Step 4: Registra la policy in `AppServiceProvider`**

In `app/Providers/AppServiceProvider.php`, aggiungi nell'`use` block:

```php
use App\Policies\UgcPoiPolicy;
```

E nel metodo `boot()`, aggiungi **come prima riga** (prima delle policy esistenti):

```php
Gate::policy(\Wm\WmPackage\Models\UgcPoi::class, UgcPoiPolicy::class);
```

Il file `boot()` finale deve apparire così:

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
}
```

- [ ] **Step 5: Esegui il test — deve passare**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/UgcPoiPolicyTest.php
```

Output atteso: PASS (tutti i test verdi).

- [ ] **Step 6: Commit**

```bash
git add app/Policies/UgcPoiPolicy.php app/Providers/AppServiceProvider.php tests/Feature/UgcPoiPolicyTest.php
git commit -m "feat(oc:7640): add UgcPoiPolicy with role-based access control"
```

---

## Task 4: `indexQuery()` in `App\Nova\UgcPoi`

**Files:**
- Modifica: `app/Nova/UgcPoi.php`
- Crea: `tests/Feature/UgcPoiIndexQueryTest.php`

- [ ] **Step 1: Scrivi il test fallente**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class UgcPoiIndexQueryTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
        if (WmApp::count() === 0) {
            WmApp::factory()->create();
        }
    }

    private function createReportPoi(int $layerId): UgcPoi
    {
        return UgcPoi::factory()->create([
            'properties' => [
                'form' => ['id' => 'report'],
                'layer_id' => $layerId,
            ],
        ]);
    }

    private function createPoiUgc(int $layerId): UgcPoi
    {
        return UgcPoi::factory()->create([
            'properties' => [
                'form' => ['id' => 'poi'],
                'layer_id' => $layerId,
            ],
        ]);
    }

    public function test_administrator_sees_all_ugc_pois(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $layer = $this->createLayer();
        $report = $this->createReportPoi($layer->id);
        $poi = $this->createPoiUgc($layer->id);

        $novaResource = new \App\Nova\UgcPoi(new UgcPoi);
        $query = $novaResource->indexQuery(
            new \Laravel\Nova\Http\Requests\NovaRequest,
            UgcPoi::query()
        );

        // Administrator: no filter applied — calls static method directly
        $this->assertNull(
            \App\Nova\UgcPoi::indexQueryForAdministrator(UgcPoi::query(), $admin)->toBase()->wheres[0] ?? null
        );
    }

    public function test_validator_sees_only_reports_of_owned_layers(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $ownedLayer = $this->createLayer($validator->id);
        $otherLayer = $this->createLayer();

        $ownedReport = $this->createReportPoi($ownedLayer->id);
        $otherReport = $this->createReportPoi($otherLayer->id);
        $ownedPoi = $this->createPoiUgc($ownedLayer->id);

        $results = \App\Nova\UgcPoi::filteredQueryForValidator($validator, UgcPoi::query())->get();

        $this->assertTrue($results->contains($ownedReport));
        $this->assertFalse($results->contains($otherReport));
        $this->assertFalse($results->contains($ownedPoi));
    }

    public function test_validator_without_layers_sees_empty_list(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $layer = $this->createLayer(); // owned by someone else
        $this->createReportPoi($layer->id);

        $results = \App\Nova\UgcPoi::filteredQueryForValidator($validator, UgcPoi::query())->get();

        $this->assertCount(0, $results);
    }
}
```

- [ ] **Step 2: Esegui il test — deve fallire**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/UgcPoiIndexQueryTest.php
```

Output atteso: FAIL (`filteredQueryForValidator` non esiste).

- [ ] **Step 3: Implementa `indexQuery()` in `App\Nova\UgcPoi`**

```php
<?php

namespace App\Nova;

use App\Models\User;
use App\Nova\Actions\MarkAsRead;
use App\Nova\Actions\MarkAsUnread;
use App\Nova\Traits\HidesAppFromIndexTrait;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\UgcPoi as UgcPoiModel;
use Wm\WmPackage\Nova\UgcPoi as WmNovaUgcPoi;

class UgcPoi extends WmNovaUgcPoi
{
    use HidesAppFromIndexTrait;

    public static function label(): string
    {
        return __('Pois');
    }

    public static function indexQuery(NovaRequest $request, $query): Builder
    {
        $user = $request->user();

        if ($user->hasRole('Administrator')) {
            return $query;
        }

        if ($user->hasRole('Validator')) {
            return static::filteredQueryForValidator($user, $query);
        }

        return $query->whereRaw('1=0');
    }

    public static function filteredQueryForValidator(User $user, Builder $query): Builder
    {
        $layerIds = $user->layers()->pluck('id')->toArray();

        if (empty($layerIds)) {
            return $query->whereRaw('1=0');
        }

        return $query
            ->whereRaw("properties->>'form'->>'id' = ?", ['report'])
            ->whereRaw("(properties->>'layer_id')::integer IN (" . implode(',', array_fill(0, count($layerIds), '?')) . ')', $layerIds);
    }

    public function fields(NovaRequest $request): array
    {
        $fields = parent::fields($request);

        array_unshift($fields, Badge::make(__('Status'), 'read_at')
            ->map([
                'unread' => 'danger',
                'read' => 'success',
            ])
            ->resolveUsing(function ($value) {
                return $value === null ? 'unread' : 'read';
            })
            ->label(function ($value, $resource) {
                return $resource->read_at === null
                    ? __('Non letto')
                    : __('Letto il') . ' ' . $resource->read_at->format('d/m/Y H:i');
            })
            ->onlyOnIndex()
        );

        return $fields;
    }

    public function actions(NovaRequest $request): array
    {
        return [
            ...parent::actions($request),
            new MarkAsRead,
            new MarkAsUnread,
        ];
    }
}
```

- [ ] **Step 4: Esegui il test — deve passare**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/UgcPoiIndexQueryTest.php
```

Output atteso: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Nova/UgcPoi.php tests/Feature/UgcPoiIndexQueryTest.php
git commit -m "feat(oc:7640): add role-based indexQuery and read_at badge to UgcPoi Nova resource"
```

---

## Task 5: Actions `MarkAsRead` e `MarkAsUnread`

**Files:**
- Crea: `app/Nova/Actions/MarkAsRead.php`
- Crea: `app/Nova/Actions/MarkAsUnread.php`

- [ ] **Step 1: Crea `MarkAsRead`**

```php
<?php

namespace App\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class MarkAsRead extends Action
{
    use InteractsWithQueue, Queueable;

    public function name(): string
    {
        return __('Segna come letto');
    }

    public function handle(ActionFields $fields, Collection $models): void
    {
        foreach ($models as $model) {
            $model->update(['read_at' => now()]);
        }
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
```

- [ ] **Step 2: Crea `MarkAsUnread`**

```php
<?php

namespace App\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class MarkAsUnread extends Action
{
    use InteractsWithQueue, Queueable;

    public function name(): string
    {
        return __('Segna come non letto');
    }

    public function handle(ActionFields $fields, Collection $models): void
    {
        foreach ($models as $model) {
            $model->update(['read_at' => null]);
        }
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
```

- [ ] **Step 3: Esegui tutti i test**

```bash
docker exec laravel-camminiditalia php artisan test
```

Output atteso: tutti i test precedenti passano, nessuna regressione.

- [ ] **Step 4: Commit**

```bash
git add app/Nova/Actions/MarkAsRead.php app/Nova/Actions/MarkAsUnread.php
git commit -m "feat(oc:7640): add MarkAsRead and MarkAsUnread bulk actions"
```

---

## Task 6: Modello locale e AppServiceProvider

**Files:**
- Crea: `app/Models/UgcPoi.php`
- Modifica: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Crea il modello locale**

```php
<?php

namespace App\Models;

use Wm\WmPackage\Models\UgcPoi as BaseUgcPoi;

class UgcPoi extends BaseUgcPoi
{
    protected $fillable = [
        'user_id',
        'app_id',
        'name',
        'geometry',
        'properties',
        'created_by',
        'read_at',
    ];

    protected $casts = [
        'properties' => 'array',
        'read_at' => 'datetime',
    ];
}
```

- [ ] **Step 2: Aggiorna `AppServiceProvider`**

Sostituisci l'import di `UgcPoi`:

```php
// Rimuovi:
use Wm\WmPackage\Models\UgcPoi;
// Aggiungi:
use App\Models\UgcPoi;
```

- [ ] **Step 3: Esegui tutti i test**

```bash
docker exec laravel-camminiditalia php artisan test
```

Output atteso: PASS senza regressioni.

- [ ] **Step 4: Commit**

```bash
git add app/Models/UgcPoi.php app/Providers/AppServiceProvider.php
git commit -m "feat(oc:7640): add local UgcPoi model with read_at cast"
```

---

## Self-review

**Spec coverage:**
- [x] `indexQuery()` con filtro Validator/Administrator/vuoto → Task 4
- [x] Migration `read_at` + indice JSONB → Task 1
- [x] Modello locale con `read_at` → Task 6
- [x] Badge letto/non letto → Task 4 (fields)
- [x] `MarkAsRead` → Task 5
- [x] `MarkAsUnread` → Task 5
- [x] `UgcPoiPolicy` con `before()` esplicito → Task 3
- [x] Registrazione policy → Task 3

**Placeholder scan:** nessun TBD, ogni step ha codice completo.

**Type consistency:** `filteredQueryForValidator(User $user, Builder $query)` definito e usato coerentemente nei Task 4 e nel test.

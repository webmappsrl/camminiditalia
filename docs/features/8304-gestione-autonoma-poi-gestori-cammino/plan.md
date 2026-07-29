> Ticket: oc:8304

# Gestione autonoma POI da parte dei gestori di cammino — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Abilitare i Validator (gestori di cammino) a creare e modificare i propri EcPoi in Nova, restando in sola lettura sul delete (esclusivo Administrator), con associazione al layer scelta/validata lato server.

**Architecture:** Due file toccati nel repo principale (`app/Policies/EcPoiPolicy.php`, `app/Nova/EcPoi.php`), nessuna migration, nessun submodule. Il filtro index per Validator è **già implementato** e non richiede codice nuovo: `Wm\WmPackage\Nova\AbstractEcResource::indexQuery()` filtra automaticamente per `user_id === $user->id` ogni utente non-Administrator, sfruttando la colonna `user_id` già presente su `ec_pois`. Il lavoro reale si concentra su: (1) autorizzazioni policy/Nova, (2) campo Select layer in creazione con validazione server-side, (3) attach automatico alla pivot `layerables`.

**Tech Stack:** Laravel 11, Laravel Nova 5, Pest/PHPUnit (namespace `Tests\TestCase`), Spatie Permission (ruoli `Administrator`/`Validator`/`Guest`).

## Global Constraints

- Nessuna migration DB — nessuno schema change necessario.
- Delete di EcPoi resta **esclusivo Administrator** — non toccare `EcPoiPolicy::delete` né `App\Nova\EcPoi::authorizedToDelete`.
- `Layer::getStringName()` va sempre usato per il nome leggibile del layer nelle opzioni Select — **mai** `$layer->name` (restituisce stringa vuota per via del cast, decisione già documentata in `CLAUDE.md`).
- L'associazione EcPoi↔Layer alla creazione passa sempre da `$layer->ecPois()->syncWithoutDetaching([$poi->id])` (relazione `MorphToMany` via pivot `layerables`) — mai scrittura diretta sulla tabella pivot.
- I test Feature del repo principale usano `Tests\TestCase` (namespace `Tests\Feature`), **non** `Wm\WmPackage\Tests\TestCase` — quest'ultima non è in `autoload-dev` di camminiditalia.
- `EcPoi::factory()->create()` richiede sempre `'properties' => []` esplicito nei test, altrimenti l'observer del package fallisce con `TypeError` (decisione già documentata in `CLAUDE.md`, oc:8120).
- Rischio accettato e fuori scope: POI condivisi tra più layer con owner diversi (last-write-wins su `user_id`, oc:8139) — nessuna gestione speciale richiesta, camminiditalia non ha questo caso d'uso operativo.
- Fuori scope: nessuna modifica alle Nova Action `ExecuteEcPoiDataChainAction`, `UploadPoiFile`, `TranslateModelAction`, `BulkEditAction` (restano solo Administrator) né a `DownloadEcPoiAction`.

---

## Task 1: EcPoiPolicy — create/update per Validator

**Files:**
- Modify: `app/Policies/EcPoiPolicy.php`
- Modify (test esistenti da aggiornare): `tests/Feature/EcPoiPolicyTest.php`

**Interfaces:**
- Consumes: `App\Models\User::layers()` (relazione `HasMany` già esistente su `Wm\WmPackage\Models\Layer`, via colonna `user_id`).
- Produces: `EcPoiPolicy::create(User $user): bool`, `EcPoiPolicy::update(User $user, EcPoi $ecPoi): bool` — usati da Task 2 (Nova resource) e da `Fase: execution` per verificare coerenza Gate/Nova.

- [ ] **Step 1: Aggiorna i test esistenti per il nuovo comportamento atteso**

Sostituisci in `tests/Feature/EcPoiPolicyTest.php` i due test `test_validator_cannot_create_ec_poi` e `test_validator_cannot_update_ec_poi` (il comportamento cambia: create/update ora sono permessi in determinate condizioni) con questi:

```php
    // --- Validator: create/update sui propri POI, scoping per layer ---

    public function test_validator_with_layer_can_create_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');
        \Wm\WmPackage\Models\Layer::factory()->create(['user_id' => $validator->id]);

        $this->assertTrue(Gate::forUser($validator)->allows('create', EcPoi::class));
    }

    public function test_validator_without_layer_cannot_create_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');

        $this->assertFalse(Gate::forUser($validator)->allows('create', EcPoi::class));
    }

    public function test_validator_can_update_own_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');
        $ecPoi = $this->makeEcPoi($validator->id);

        $this->assertTrue(Gate::forUser($validator)->allows('update', $ecPoi));
    }

    public function test_validator_cannot_update_ec_poi_of_another_user(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $ecPoi = $this->makeEcPoi($otherValidator->id);

        $this->assertFalse(Gate::forUser($validator)->allows('update', $ecPoi));
    }
```

Mantieni invariato `test_validator_cannot_delete_ec_poi` (il delete resta bloccato, nessuna modifica).

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiPolicyTest.php
```

Expected: FAIL su `test_validator_with_layer_can_create_ec_poi` e `test_validator_can_update_own_ec_poi` (i metodi `create`/`update` restituiscono ancora sempre `false` per Validator).

- [ ] **Step 3: Implementa la policy**

Sostituisci in `app/Policies/EcPoiPolicy.php` i metodi `create` e `update`:

```php
    public function create(User $user): bool
    {
        return $user->layers()->exists();
    }

    public function update(User $user, EcPoi $ecPoi): bool
    {
        return $ecPoi->user_id === $user->id;
    }
```

Il file completo risultante:

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
        return $user->layers()->exists();
    }

    public function update(User $user, EcPoi $ecPoi): bool
    {
        return $ecPoi->user_id === $user->id;
    }

    public function delete(User $user, EcPoi $ecPoi): bool
    {
        return false;
    }
}
```

- [ ] **Step 4: Esegui i test per verificare che passino**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiPolicyTest.php
```

Expected: PASS su tutti i test del file.

- [ ] **Step 5: Commit**

```bash
git add app/Policies/EcPoiPolicy.php tests/Feature/EcPoiPolicyTest.php
git commit -m "feat(oc:8304): consenti a Validator con layer di creare/aggiornare i propri EcPoi"
```

---

## Task 2: App\Nova\EcPoi — authorizedToCreate/Update per Validator

**Files:**
- Modify: `app/Nova/EcPoi.php`
- Test: `tests/Feature/EcPoiNovaAuthorizationTest.php` (nuovo file)

**Interfaces:**
- Consumes: `EcPoiPolicy::create`/`update` (Task 1) — Nova non chiama automaticamente la Policy per `authorizedToCreate`/`authorizedToUpdate` di una Resource: questi metodi vanno implementati esplicitamente e devono restare **coerenti** con la policy.
- Produces: `App\Nova\EcPoi::authorizedToCreate(Request $request): bool` (statico), `App\Nova\EcPoi::authorizedToUpdate(Request $request): bool` (istanza) — usati dal form Nova per mostrare/nascondere i bottoni Create/Update, e da Task 3 per condizionare la visibilità del campo layer.

- [ ] **Step 1: Scrivi il test che deve fallire**

Crea `tests/Feature/EcPoiNovaAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class EcPoiNovaAuthorizationTest extends TestCase
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

    public function test_validator_with_layer_can_see_creation_fields(): void
    {
        $validator = $this->makeUser('Validator');
        Layer::factory()->create(['user_id' => $validator->id]);

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/ec-pois/creation-fields');

        $response->assertOk();
    }

    public function test_validator_without_layer_cannot_see_creation_fields(): void
    {
        $validator = $this->makeUser('Validator');

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/ec-pois/creation-fields');

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_validator_can_update_own_ec_poi_via_nova(): void
    {
        $validator = $this->makeUser('Validator');
        Layer::factory()->create(['user_id' => $validator->id]);
        $ecPoi = EcPoi::factory()->create(['user_id' => $validator->id, 'properties' => []]);

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/ec-pois/'.$ecPoi->id.'/update-fields');

        $response->assertOk();
    }

    public function test_validator_cannot_update_ec_poi_of_another_user_via_nova(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $ecPoi = EcPoi::factory()->create(['user_id' => $otherValidator->id, 'properties' => []]);

        $response = $this->actingAs($validator)
            ->getJson('/nova-api/ec-pois/'.$ecPoi->id.'/update-fields');

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_administrator_can_see_creation_fields(): void
    {
        $admin = $this->makeUser('Administrator');

        $response = $this->actingAs($admin)
            ->getJson('/nova-api/ec-pois/creation-fields');

        $response->assertOk();
    }
}
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiNovaAuthorizationTest.php
```

Expected: FAIL su `test_validator_with_layer_can_see_creation_fields` e `test_validator_can_update_own_ec_poi_via_nova` (oggi `authorizedToCreate`/`authorizedToUpdate` restituiscono `false` per qualsiasi Validator).

- [ ] **Step 3: Implementa i metodi in App\Nova\EcPoi**

Sostituisci in `app/Nova/EcPoi.php` i metodi `authorizedToCreate` e `authorizedToUpdate`:

```php
    public static function authorizedToCreate(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        return $user->hasRole('Administrator') || ($user->hasRole('Validator') && $user->layers()->exists());
    }

    public function authorizedToUpdate(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('Administrator')) {
            return true;
        }

        return $user->hasRole('Validator') && $this->resource->user_id === $user->id;
    }
```

`authorizedToDelete` resta invariato (solo Administrator, nessuna modifica).

- [ ] **Step 4: Esegui i test per verificare che passino**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiNovaAuthorizationTest.php
```

Expected: PASS su tutti i test del file.

- [ ] **Step 5: Commit**

```bash
git add app/Nova/EcPoi.php tests/Feature/EcPoiNovaAuthorizationTest.php
git commit -m "feat(oc:8304): abilita create/update Nova per Validator con layer proprio"
```

---

## Task 3: Campo layer in creazione + validazione server-side + attach a layerables

**Files:**
- Modify: `app/Nova/EcPoi.php`
- Test: `tests/Feature/EcPoiLayerAssignmentTest.php` (nuovo file)

**Interfaces:**
- Consumes: `App\Nova\EcPoi::authorizedToCreate` (Task 2, per sapere se l'utente è un Validator autorizzato), `Layer::getStringName(): string`, `$user->layers(): HasMany`, `Layer::ecPois(): MorphToMany`.
- Produces: nessuna interfaccia consumata da altri task — è l'ultimo task del piano.

- [ ] **Step 1: Scrivi il test che deve fallire**

Crea `tests/Feature/EcPoiLayerAssignmentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class EcPoiLayerAssignmentTest extends TestCase
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

    private function createEcPoiPayload(): array
    {
        return [
            'name' => 'Test POI',
            'global' => '1',
        ];
    }

    public function test_validator_with_single_layer_creates_ec_poi_auto_assigned(): void
    {
        $validator = $this->makeUser('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);

        $response = $this->actingAs($validator)
            ->postJson('/nova-api/ec-pois', $this->createEcPoiPayload());

        $response->assertCreated();

        $poiId = $response->json('id') ?? $response->json('resource.id');
        $this->assertTrue($layer->ecPois()->where('ec_pois.id', $poiId)->exists());
    }

    public function test_validator_with_multiple_layers_must_choose_own_layer(): void
    {
        $validator = $this->makeUser('Validator');
        $layerA = Layer::factory()->create(['user_id' => $validator->id]);
        $layerB = Layer::factory()->create(['user_id' => $validator->id]);

        $payload = $this->createEcPoiPayload();
        $payload['properties->layer_id'] = (string) $layerB->id;

        $response = $this->actingAs($validator)
            ->postJson('/nova-api/ec-pois', $payload);

        $response->assertCreated();

        $poiId = $response->json('id') ?? $response->json('resource.id');
        $this->assertTrue($layerB->ecPois()->where('ec_pois.id', $poiId)->exists());
        $this->assertFalse($layerA->ecPois()->where('ec_pois.id', $poiId)->exists());
    }

    public function test_validator_cannot_assign_layer_not_owned(): void
    {
        $validator = $this->makeUser('Validator');
        Layer::factory()->create(['user_id' => $validator->id]);

        $otherValidator = $this->makeUser('Validator');
        $foreignLayer = Layer::factory()->create(['user_id' => $otherValidator->id]);

        $payload = $this->createEcPoiPayload();
        $payload['properties->layer_id'] = (string) $foreignLayer->id;

        $response = $this->actingAs($validator)
            ->postJson('/nova-api/ec-pois', $payload);

        $response->assertStatus(422);
    }
}
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiLayerAssignmentTest.php
```

Expected: FAIL (il campo layer non esiste ancora, quindi nessun attach a `layerables` e nessuna validazione avviene).

- [ ] **Step 3: Implementa il campo Select layer con validazione e l'attach automatico**

Aggiungi in cima a `app/Nova/EcPoi.php` gli import necessari:

```php
use Illuminate\Validation\Rule;
use Laravel\Nova\Fields\Select;
use Wm\WmPackage\Models\Layer;
```

Aggiungi il metodo `fields()` (override) che inserisce il campo Select solo per Validator, prima delle azioni. Il file risultante:

```php
<?php

namespace App\Nova;

use App\Models\EcPoi as EcPoiModel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Actions\BulkEditAction;
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

    public static function authorizedToCreate(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        return $user->hasRole('Administrator') || ($user->hasRole('Validator') && $user->layers()->exists());
    }

    public function authorizedToUpdate(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('Administrator')) {
            return true;
        }

        return $user->hasRole('Validator') && $this->resource->user_id === $user->id;
    }

    public function authorizedToDelete(Request $request): bool
    {
        return $request->user()?->hasRole('Administrator') ?? false;
    }

    public function fields(NovaRequest $request): array
    {
        $fields = parent::fields($request);

        if ($layerField = $this->layerAssignmentField($request)) {
            $fields[] = $layerField;
        }

        return $fields;
    }

    private function layerAssignmentField(NovaRequest $request): ?Select
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('Validator')) {
            return null;
        }

        $layers = $user->layers()->get();

        if ($layers->count() <= 1) {
            return null;
        }

        return Select::make(__('Layer'), 'properties->layer_id')
            ->options($layers->mapWithKeys(fn (Layer $layer) => [$layer->id => $layer->getStringName()])->toArray())
            ->rules('required', Rule::in($layers->pluck('id')->toArray()))
            ->onlyOnForms()
            ->hideWhenUpdating();
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
            (new BulkEditAction(self::class, ['global']))
                ->canSee(fn () => $isAdmin)
                ->canRun(fn ($req, $model) => $isAdmin),
        ];
    }
}
```

Nota: quando il Validator ha **un solo** layer, il campo non viene mostrato (`$layers->count() <= 1` → `null`) — serve comunque assegnare quel layer automaticamente. Aggiungi un Observer locale dedicato invece di gestirlo nel field (il field `layer_id` non è una colonna del modello, quindi non c'è nulla da "fillare" quando il campo è assente).

Crea `app/Observers/EcPoiValidatorLayerObserver.php`:

```php
<?php

namespace App\Observers;

use Wm\WmPackage\Models\EcPoi;

class EcPoiValidatorLayerObserver
{
    public function created(EcPoi $ecPoi): void
    {
        $user = $ecPoi->user_id ? \App\Models\User::find($ecPoi->user_id) : null;

        if (! $user || ! $user->hasRole('Validator')) {
            return;
        }

        $layers = $user->layers()->get();

        if ($layers->isEmpty()) {
            return;
        }

        if ($layers->count() === 1) {
            $layers->first()->ecPois()->syncWithoutDetaching([$ecPoi->id]);

            return;
        }

        $layerId = $ecPoi->properties['layer_id'] ?? null;

        if (is_numeric($layerId) && $layers->pluck('id')->contains((int) $layerId)) {
            $layers->firstWhere('id', (int) $layerId)?->ecPois()->syncWithoutDetaching([$ecPoi->id]);
        }
    }
}
```

Nota sull'attributo del campo: `properties->layer_id` non è una colonna reale di `EcPoi` (`ec_pois` non ha colonna `layer_id`) — è la stessa dot-notation su colonna JSON già usata da altri campi del package (es. `Text::make(__('Contact email'), 'properties->contact_email')` in `wm-package/src/Nova/EcPoi.php`). Nova, in fase di fill, converte l'arrow-notation in un `Arr::set()` sull'array `properties` del modello: il valore finisce in `$ecPoi->properties['layer_id']`, letto già così dall'Observer sotto. **Il nome del campo del form (request key) è letteralmente la stringa `properties->layer_id`**, non `layer_id` semplice — da usare esattamente così nei payload di test in Step 1.

Registra l'observer in `app/Providers/AppServiceProvider.php`, nel metodo `boot()` (accanto alle altre registrazioni `Gate::policy()`/observer esistenti):

```php
\Wm\WmPackage\Models\EcPoi::observe(\App\Observers\EcPoiValidatorLayerObserver::class);
```

- [ ] **Step 4: Esegui i test per verificare che passino**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiLayerAssignmentTest.php
```

Expected: PASS su tutti i test del file.

- [ ] **Step 5: Esegui l'intera suite dei file toccati per verificare che non ci siano regressioni**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/EcPoiPolicyTest.php tests/Feature/EcPoiNovaActionsTest.php tests/Feature/EcPoiNovaAuthorizationTest.php tests/Feature/EcPoiLayerAssignmentTest.php
```

Expected: PASS su tutti i file.

- [ ] **Step 6: Commit**

```bash
git add app/Nova/EcPoi.php app/Observers/EcPoiValidatorLayerObserver.php app/Providers/AppServiceProvider.php tests/Feature/EcPoiLayerAssignmentTest.php
git commit -m "feat(oc:8304): campo layer in creazione EcPoi per Validator, con validazione server-side e attach automatico a layerables"
```

---

## Self-Review

**1. Spec coverage** (requisiti da `overview.md`):
1. `EcPoiPolicy::create` → Task 1 ✅
2. `EcPoiPolicy::update` → Task 1 ✅
3. `EcPoiPolicy::delete` invariato → non toccato, verificato in Task 1 (test esistente mantenuto) ✅
4. `viewAny`/`view` invariati → non toccati ✅
5. `authorizedToCreate` → Task 2 ✅
6. `authorizedToUpdate` → Task 2 ✅
7. `authorizedToDelete` invariato → non toccato, riportato esplicitamente in Task 3 (file completo) ✅
8. Filtro index Validator (`user_id`) → **già implementato** da `AbstractEcResource::indexQuery()`, nessun task necessario — documentato nell'Architecture e da riportare in `notes.md` come deviazione positiva dal piano originale (meno codice del previsto) ✅
9. Campo layer condizionale (1 vs N layer) → Task 3 ✅
10. Validazione server-side `Rule::in` → Task 3 ✅
11. Attach a `layerables` → Task 3 (via Observer, non via field diretto — deviazione tecnica necessaria, documentare in `notes.md`) ✅

**2. Placeholder scan:** nessun placeholder — ogni step ha codice completo, comandi espliciti.

**3. Type consistency:** `authorizedToCreate(Request $request): bool` (statico) e `authorizedToUpdate(Request $request): bool` (istanza) usati identicamente in Task 2 e Task 3 (Task 3 riporta il file completo, coerente). `EcPoiValidatorLayerObserver::created(EcPoi $ecPoi): void` — nessun altro task ne dipende.

**Nota per Fase: notes** — la deviazione più rilevante da segnalare a fine esecuzione: l'attach a `layerables` per il caso "1 solo layer" non può avvenire nel field Nova stesso (nessun dato lato client da leggere quando il campo è nascosto), quindi si usa un Observer su `created` che legge `properties->layer_id` (quando presente) o assegna automaticamente l'unico layer. Verificare in esecuzione che l'ordine `created` → lettura `properties` funzioni correttamente dato che altri observer del package (`AbstractObserver`) intervengono sullo stesso evento — testare con attenzione l'interazione se il test Step 4 di Task 3 fallisce in modo anomalo.

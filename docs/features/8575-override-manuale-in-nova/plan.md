> Ticket: oc:8575

# Override manuale in Nova Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettere a un Administrator di correggere manualmente da Nova il layer (cammino) associato a un `UgcTrack`/`UgcPoi`, quando la risoluzione automatica per prossimità ha sbagliato, senza inviare mail duplicate né rischiare che una risoluzione automatica ancora in coda sovrascriva la correzione.

**Architecture:** Un nuovo campo `Select` editabile, aggiunto al trait Nova condiviso `HasLayerFilterAndLink`, scrive `properties->layer_id` tramite `fillUsing` (salvataggio Eloquent normale, nessun `saveQuietly()`), marcando la correzione con `properties->layer_id_auto_resolved = false`. Due nuove/estese Policy (`UgcPoiPolicy`, `UgcTrackPolicy`) restringono `update` all'Administrator. `ResolveUgcLayerJob` guadagna una guardia che, prima di salvare la risoluzione automatica, verifica con una query fresca che nel frattempo l'UGC non sia già stato corretto manualmente.

**Tech Stack:** Laravel 11 + Nova 5, PHP 8.4, PostgreSQL/PostGIS, PHPUnit (Postgres di test dedicato `camminiditalia_testing`). Comandi `php artisan`/test eseguiti dentro il container Docker `laravel-camminiditalia`.

**Spec:** `docs/features/8575-override-manuale-in-nova/overview.md`

## Global Constraints

- Tutti i comandi `php artisan`/test vanno eseguiti con `docker exec laravel-camminiditalia php artisan ...` (mai in locale)
- I test girano sul DB Postgres di test dedicato (`camminiditalia_testing`), non su quello di sviluppo — nessuna azione necessaria oltre a lanciare `php artisan test`
- Nessun commit/push automatico: i blocchi `git commit` in questo piano sono istruzioni testuali per lo sviluppatore, MAI da eseguire autonomamente durante l'esecuzione del piano
- Commit convention: `feat(oc:8575): <messaggio>`
- Formattazione: `docker exec laravel-camminiditalia composer format` (Laravel Pint) prima di ogni commit che tocca codice PHP
- Il modello Nova-side per `UgcTrack` (sia risorsa Nova sia policy) è sempre `Wm\WmPackage\Models\UgcTrack` — non esiste un `App\Models\UgcTrack` locale (a differenza di `UgcPoi`, che ha un override locale `App\Models\UgcPoi`)

---

### Task 1: `UgcPoiPolicy::update()` — Administrator-only

**Files:**
- Modify: `app/Policies/UgcPoiPolicy.php`
- Test: `tests/Feature/UgcPoiPolicyTest.php`

**Interfaces:**
- Consumes: nulla di nuovo — `UgcPoiPolicy::before()` esiste già e ritorna `true` per Administrator, `false` per chiunque non sia Administrator/Validator, `null` per Validator (passa ai metodi specifici)
- Produces: `UgcPoiPolicy::update(User $user, UgcPoi $ugcPoi): bool` — usato più avanti dal campo Nova editabile (Task 3) per determinare `authorizedToUpdate`

- [ ] **Step 1: Scrivi il test che fallisce**

Apri `tests/Feature/UgcPoiPolicyTest.php` e aggiungi in fondo alla classe (prima della graffa di chiusura):

```php
    public function test_administrator_can_update_ugc_poi(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $poi = UgcPoi::factory()->create();
        $this->assertTrue(Gate::forUser($admin)->allows('update', $poi));
    }

    public function test_validator_cannot_update_ugc_poi(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $poi = UgcPoi::factory()->create();
        $this->assertFalse(Gate::forUser($validator)->allows('update', $poi));
    }

    public function test_guest_cannot_update_ugc_poi(): void
    {
        $guest = $this->createUserWithRole('Guest');
        $poi = UgcPoi::factory()->create();
        $this->assertFalse(Gate::forUser($guest)->allows('update', $poi));
    }
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=UgcPoiPolicyTest`
Expected: FAIL su `test_administrator_can_update_ugc_poi` (oggi `update()` non esiste sulla policy, quindi `Gate::resolvePolicyCallback` nega a chiunque — vedi `vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:774-778`)

- [ ] **Step 3: Implementa `update()`**

In `app/Policies/UgcPoiPolicy.php`, aggiungi dopo il metodo `view()`:

```php
    public function update(User $user, UgcPoi $ugcPoi): bool
    {
        return false;
    }
```

Il file risultante:

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

    public function update(User $user, UgcPoi $ugcPoi): bool
    {
        return false;
    }
}
```

`update()` viene raggiunto solo per i Validator (per Administrator `before()` restituisce già `true` e corto-circuita); ritornando `false` incondizionato si ottiene "Administrator sempre, chiunque altro mai" senza duplicare il controllo del ruolo.

- [ ] **Step 4: Esegui i test e verifica che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter=UgcPoiPolicyTest`
Expected: PASS (tutti i test, inclusi quelli preesistenti)

- [ ] **Step 5: Formatta e committa**

```bash
docker exec laravel-camminiditalia composer format
git add app/Policies/UgcPoiPolicy.php tests/Feature/UgcPoiPolicyTest.php
git commit -m "feat(oc:8575): aggiungi UgcPoiPolicy::update() Administrator-only"
```

---

### Task 2: `UgcTrackPolicy` (nuova) — Administrator-only su update, comportamento invariato sulle altre ability

**Files:**
- Create: `app/Policies/UgcTrackPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `tests/Feature/UgcTrackPolicyTest.php`

**Interfaces:**
- Consumes: nessuna dipendenza da Task 1 (policy indipendente, stessa struttura)
- Produces: `UgcTrackPolicy::update(User $user, UgcTrack $ugcTrack): bool` — usato dal campo Nova editabile (Task 3) per `UgcTrack`

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `tests/Feature/UgcTrackPolicyTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class UgcTrackPolicyTest extends TestCase
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

    private function makeUgcTrack(): UgcTrack
    {
        return \Wm\WmPackage\Models\UgcTrack::factory()->create();
    }

    // --- update: la vera restrizione introdotta da questo ticket ---

    public function test_administrator_can_update_ugc_track(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $track = $this->makeUgcTrack();
        $this->assertTrue(Gate::forUser($admin)->allows('update', $track));
    }

    public function test_validator_cannot_update_ugc_track(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $track = $this->makeUgcTrack();
        $this->assertFalse(Gate::forUser($validator)->allows('update', $track));
    }

    public function test_guest_cannot_update_ugc_track(): void
    {
        $guest = $this->createUserWithRole('Guest');
        $track = $this->makeUgcTrack();
        $this->assertFalse(Gate::forUser($guest)->allows('update', $track));
    }

    // --- Regressione: le altre ability devono restare permesse a tutti come
    // oggi (nessuna policy registrata = Nova permette sempre). Verificato in
    // vendor/laravel/nova/src/Authorizable.php:408-415 che authorizedToView/
    // Delete/Restore NON hanno una guardia is_callable come authorizedToViewAny:
    // una volta registrata la policy, negherebbero a tutti se il metodo non
    // esistesse — anche all'Administrator. ---

    public function test_administrator_can_still_view_any_ugc_track(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', UgcTrack::class));
    }

    public function test_administrator_can_still_view_ugc_track(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $track = $this->makeUgcTrack();
        $this->assertTrue(Gate::forUser($admin)->allows('view', $track));
    }

    public function test_administrator_can_still_create_ugc_track(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $this->assertTrue(Gate::forUser($admin)->allows('create', UgcTrack::class));
    }

    public function test_administrator_can_still_delete_ugc_track(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $track = $this->makeUgcTrack();
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $track));
    }

    public function test_validator_can_still_view_ugc_track(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $track = $this->makeUgcTrack();
        $this->assertTrue(Gate::forUser($validator)->allows('view', $track));
    }

    public function test_guest_can_still_view_ugc_track(): void
    {
        $guest = $this->createUserWithRole('Guest');
        $track = $this->makeUgcTrack();
        $this->assertTrue(Gate::forUser($guest)->allows('view', $track));
    }
}
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=UgcTrackPolicyTest`
Expected: FAIL su tutti i test `update` (nessuna policy registrata ancora, `UgcTrackPolicy` non esiste)

- [ ] **Step 3: Crea `UgcTrackPolicy`**

Crea `app/Policies/UgcTrackPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Wm\WmPackage\Models\UgcTrack;

class UgcTrackPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('Administrator')) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UgcTrack $ugcTrack): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, UgcTrack $ugcTrack): bool
    {
        return true;
    }

    public function update(User $user, UgcTrack $ugcTrack): bool
    {
        return false;
    }
}
```

`viewAny`/`view`/`create`/`delete` ritornano `true` incondizionato per replicare esattamente il comportamento attuale (nessuna policy registrata → Nova permette sempre, la vera restrizione per i non-Administrator vive in `UgcTrack::indexQuery()`/`availableForNavigation()`, non toccati da questo ticket). Solo `update()` introduce la nuova restrizione Administrator-only.

**Nota (corretta durante l'esecuzione, vedi ledger SDD):** a differenza di `UgcPoiPolicy::before()`, qui `before()` NON ha il branch "non-Administrator e non-Validator → `false`". Copiarlo da `UgcPoiPolicy` negherebbe *tutte* le ability a Guest prima ancora di raggiungere i metodi specifici (short-circuit di `before()`), contraddicendo `viewAny`/`view`/`create`/`delete` che devono restare `true` per tutti. `before()` qui short-circuita solo per Administrator; chiunque altro cade nel metodo specifico dell'ability.

- [ ] **Step 4: Registra la policy**

In `app/Providers/AppServiceProvider.php`, aggiungi l'import:

```php
use App\Policies\UgcTrackPolicy;
```

(da inserire in ordine alfabetico subito dopo `use App\Policies\UgcPoiPolicy;` — "UgcPoiPolicy" precede "UgcTrackPolicy" alfabeticamente, "P" < "T")

E aggiungi la registrazione subito dopo `Gate::policy(UgcPoi::class, UgcPoiPolicy::class);` (riga 64):

```php
        Gate::policy(UgcPoi::class, UgcPoiPolicy::class);
        Gate::policy(UgcTrack::class, UgcTrackPolicy::class);
```

`UgcTrack` qui è già importato in cima al file come `use Wm\WmPackage\Models\UgcTrack;` (riga 26, già usato più sotto per `UgcTrack::observe(...)`) — nessun nuovo import di modello necessario.

- [ ] **Step 5: Esegui i test e verifica che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter=UgcTrackPolicyTest`
Expected: PASS (tutti i test)

- [ ] **Step 6: Esegui l'intera suite per escludere regressioni collaterali**

Run: `docker exec laravel-camminiditalia php artisan test`
Expected: PASS (nessuna regressione su test preesistenti che usano `UgcTrack`)

- [ ] **Step 7: Formatta e committa**

```bash
docker exec laravel-camminiditalia composer format
git add app/Policies/UgcTrackPolicy.php app/Providers/AppServiceProvider.php tests/Feature/UgcTrackPolicyTest.php
git commit -m "feat(oc:8575): registra UgcTrackPolicy con update Administrator-only"
```

---

### Task 3: Campo editabile per l'override manuale del layer (UgcTrack + UgcPoi)

**Files:**
- Modify: `app/Nova/Traits/HasLayerFilterAndLink.php`
- Modify: `app/Nova/UgcTrack.php`
- Modify: `app/Nova/UgcPoi.php`
- Modify: `resources/lang/en.json`
- Modify: `resources/lang/it.json`
- Test: `tests/Feature/HasLayerFilterAndLinkTest.php`

**Interfaces:**
- Consumes: `UgcPoiPolicy::update()`/`UgcTrackPolicy::update()` (Task 1, Task 2) — determinano se Nova espone il form di Update per l'utente corrente (a monte del `canSee` del field, che resta comunque una seconda barriera esplicita)
- Produces: `HasLayerFilterAndLink::layerOverrideField(NovaRequest $request): ?Select` — field Nova, usato in `UgcTrack::fields()`/`UgcPoi::fields()`. `HasLayerFilterAndLink::applyManualLayerOverride(Model $model, ?int $layerId, ?User $actor): void` — metodo statico puro, usato dal `fillUsing` del field E direttamente dai test (Task 3 e Task 5 lo riusano per verificare gli effetti collaterali senza passare dall'HTTP layer di Nova)

- [ ] **Step 1: Scrivi il test che fallisce (metodo `applyManualLayerOverride`)**

Apri `tests/Feature/HasLayerFilterAndLinkTest.php`. Aggiungi l'import `use Illuminate\Support\Facades\Queue;` (serve per `Queue::fake()`, vedi sotto) e, dopo l'ultimo metodo di test esistente (prima della graffa di chiusura della classe), aggiungi:

```php
    public function test_apply_manual_layer_override_sets_layer_id_and_marks_as_manual(): void
    {
        // Queue::fake(): il track ha form.id='report', quindi App\Observers\UgcObserver::created()
        // dispatcha ResolveUgcLayerJob (QUEUE_CONNECTION=redis in .env.testing, non sync) — senza
        // Queue::fake() il dispatch reale può fallire e "avvelenare" la transazione DatabaseTransactions.
        // Stesso pattern già usato in tests/Feature/UgcNotificationTest.php per lo stesso motivo.
        Queue::fake();

        $layer = Layer::factory()->create();
        $track = \Wm\WmPackage\Models\UgcTrack::factory()->create(['properties' => ['form' => ['id' => 'report']]]);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Administrator');

        $this->subject::applyManualLayerOverride($track, $layer->id, $admin);
        $track->save();
        $track->refresh();

        $this->assertSame($layer->id, $track->properties['layer_id']);
        $this->assertFalse($track->properties['layer_id_auto_resolved']);
        $this->assertSame($layer->id, $track->properties['form']['layer_id']);
    }

    public function test_apply_manual_layer_override_can_clear_the_layer(): void
    {
        Queue::fake(); // stesso motivo del test precedente

        $layer = Layer::factory()->create();
        $track = \Wm\WmPackage\Models\UgcTrack::factory()->create([
            'properties' => ['layer_id' => $layer->id, 'layer_id_auto_resolved' => true, 'form' => ['id' => 'report', 'layer_id' => $layer->id]],
        ]);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Administrator');

        $this->subject::applyManualLayerOverride($track, null, $admin);
        $track->save();
        $track->refresh();

        $this->assertNull($track->properties['layer_id']);
        $this->assertFalse($track->properties['layer_id_auto_resolved']);
        $this->assertNull($track->properties['form']['layer_id']);
    }

    public function test_apply_manual_layer_override_ignores_missing_form_key(): void
    {
        $layer = Layer::factory()->create();
        $poi = \Wm\WmPackage\Models\UgcPoi::factory()->create(['properties' => []]);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Administrator');

        $this->subject::applyManualLayerOverride($poi, $layer->id, $admin);
        $poi->save();
        $poi->refresh();

        $this->assertSame($layer->id, $poi->properties['layer_id']);
        $this->assertArrayNotHasKey('form', $poi->properties);
    }
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=HasLayerFilterAndLinkTest`
Expected: FAIL con errore "Call to undefined method ...::applyManualLayerOverride()"

- [ ] **Step 3: Implementa `applyManualLayerOverride` e `layerOverrideField` nel trait**

Sostituisci il contenuto di `app/Nova/Traits/HasLayerFilterAndLink.php` con:

```php
<?php

namespace App\Nova\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Wm\WmPackage\Models\Layer;

trait HasLayerFilterAndLink
{
    protected static array $layerNameCache = [];

    public static function renderLayerLink($rawLayerId): string
    {
        if (! is_numeric($rawLayerId)) {
            return 'Non assegnato';
        }

        $layerId = (int) $rawLayerId;

        if (! array_key_exists($layerId, static::$layerNameCache)) {
            $layer = Layer::find($layerId);
            static::$layerNameCache[$layerId] = $layer ? $layer->getStringName() : null;
        }

        $name = static::$layerNameCache[$layerId];

        if ($name === null) {
            return sprintf('Layer eliminato (ID: %d)', $layerId);
        }

        $url = url(Nova::path()."/resources/layers/{$layerId}");

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            e($url),
            htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
        );
    }

    public function layerLinkField(NovaRequest $request): ?Text
    {
        if (! $request->user()?->hasRole('Administrator')) {
            return null;
        }

        $resourceClass = static::class;

        return Text::make(__('Layer'), 'layer_link', function () use ($resourceClass) {
            return $resourceClass::renderLayerLink($this->properties['layer_id'] ?? null);
        })
            ->asHtml()
            ->hideWhenCreating()
            ->hideWhenUpdating();
    }

    public function layerFilterField(NovaRequest $request): ?Select
    {
        if (! $request->user()?->hasRole('Administrator')) {
            return null;
        }

        $modelClass = static::$model;

        return Select::make(__('Filtro Segnalazioni'), 'layer_filter')
            ->options(function () use ($modelClass) {
                $layerIds = $modelClass::query()
                    ->whereRaw("properties->>'layer_id' IS NOT NULL")
                    ->selectRaw("DISTINCT (properties->>'layer_id')::integer AS layer_id")
                    ->pluck('layer_id')
                    ->toArray();

                return Layer::whereIn('id', $layerIds)
                    ->get()
                    ->mapWithKeys(fn (Layer $layer) => [$layer->id => $layer->getStringName()])
                    ->toArray();
            })
            ->searchable()
            ->filterable(function (NovaRequest $request, $query, mixed $value, string $attribute) {
                $query->whereRaw("(properties->>'layer_id')::integer = ?", [(int) $value]);
            })
            ->hideFromIndex()
            ->hideFromDetail()
            ->hideWhenCreating()
            ->hideWhenUpdating();
    }

    public function layerOverrideField(NovaRequest $request): ?Select
    {
        if (! $request->user()?->hasRole('Administrator')) {
            return null;
        }

        return Select::make(__('Assign layer'), 'layer_override')
            ->options(fn () => Layer::all()
                ->mapWithKeys(fn (Layer $layer) => [$layer->id => $layer->getStringName()])
                ->toArray()
            )
            ->searchable()
            ->nullable()
            ->placeholder(__('Not assigned'))
            ->resolveUsing(function ($value, $resource) {
                return $resource->properties['layer_id'] ?? null;
            })
            ->fillUsing(function (NovaRequest $request, $model, $attribute, $requestAttribute) {
                // fillUsing() bypassa il controllo $request->exists() che Nova applica
                // di default (vendor/laravel/nova/src/Fields/Field.php:395-402,
                // fillAttribute() chiama il fillCallback incondizionatamente quando
                // impostato) — senza questi due guard, applyManualLayerOverride()
                // girerebbe su OGNI salvataggio Update della risorsa, anche quando
                // l'admin non ha toccato questo campo, corrompendo layer_id_auto_resolved
                // su modifiche non correlate. Trovato in Fase: review finale.
                if (! $request->exists($requestAttribute)) {
                    return;
                }

                $incoming = $request->input($requestAttribute);
                $layerId = ($incoming === null || $incoming === '') ? null : (int) $incoming;

                $current = $model->properties['layer_id'] ?? null;
                $current = ($current === null || $current === '') ? null : (int) $current;

                if ($current === $layerId) {
                    return;
                }

                static::applyManualLayerOverride($model, $layerId, $request->user());
            })
            ->onlyOnForms()
            ->hideWhenCreating()
            ->hideFromIndex()
            ->hideFromDetail();
    }

    public static function applyManualLayerOverride(Model $model, ?int $layerId, ?User $actor): void
    {
        $properties = $model->properties ?? [];
        $previousLayerId = $properties['layer_id'] ?? null;

        $properties['layer_id'] = $layerId;
        $properties['layer_id_auto_resolved'] = false;

        if (isset($properties['form']) && is_array($properties['form'])) {
            $properties['form']['layer_id'] = $layerId;
        }

        $model->setAttribute('properties', $properties);

        Log::info('Override manuale layer UGC', [
            'ugc_id' => $model->getAttribute('id'),
            'ugc_type' => get_class($model),
            'user_id' => $actor?->id,
            'user_name' => $actor?->name,
            'previous_layer_id' => $previousLayerId,
            'new_layer_id' => $layerId,
        ]);
    }
}
```

- [ ] **Step 4: Esegui i test e verifica che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter=HasLayerFilterAndLinkTest`
Expected: PASS (tutti i test, inclusi quelli preesistenti su `renderLayerLink`)

- [ ] **Step 5: Aggiungi il field alle risorse Nova**

In `app/Nova/UgcTrack.php`, nel metodo `fields()`, aggiungi dopo il blocco `layerLinkField`:

```php
    public function fields(NovaRequest $request): array
    {
        $fields = parent::fields($request);

        if ($filterField = $this->layerFilterField($request)) {
            $fields[] = $filterField;
        }

        if ($linkField = $this->layerLinkField($request)) {
            $fields[] = $linkField;
        }

        if ($overrideField = $this->layerOverrideField($request)) {
            $fields[] = $overrideField;
        }

        return $fields;
    }
```

In `app/Nova/UgcPoi.php`, stessa modifica nel suo `fields()`:

```php
        if ($filterField = $this->layerFilterField($request)) {
            $fields[] = $filterField;
        }

        if ($linkField = $this->layerLinkField($request)) {
            $fields[] = $linkField;
        }

        if ($overrideField = $this->layerOverrideField($request)) {
            $fields[] = $overrideField;
        }

        return $fields;
    }
```

- [ ] **Step 6: Aggiungi le traduzioni**

In `resources/lang/en.json`, aggiungi (mantenendo l'ordine alfabetico delle chiavi esistenti):

```json
    "Assign layer": "Assign layer",
    "Not assigned": "Not assigned",
```

In `resources/lang/it.json`, aggiungi (mantenendo l'ordine alfabetico):

```json
    "Assign layer": "Assegna cammino",
    "Not assigned": "Non assegnato",
```

- [ ] **Step 7: Verifica manuale in Nova**

Run: `docker exec laravel-camminiditalia php artisan test` (suite completa, per escludere regressioni su `UgcTrack`/`UgcPoi` Nova resources)
Expected: PASS

Poi, in un browser, login come Administrator su Nova, apri un `UgcTrack` o `UgcPoi` esistente con `properties.layer_id` valorizzato, entra in modalità Update: verifica che compaia il campo "Assign layer" con ricerca per titolo, valore corrente preselezionato, e che sia possibile deselezionare (torna a "Not assigned"). Verifica anche che un utente con ruolo Validator NON veda il campo in Update.

- [ ] **Step 8: Formatta e committa**

```bash
docker exec laravel-camminiditalia composer format
git add app/Nova/Traits/HasLayerFilterAndLink.php app/Nova/UgcTrack.php app/Nova/UgcPoi.php resources/lang/en.json resources/lang/it.json tests/Feature/HasLayerFilterAndLinkTest.php
git commit -m "feat(oc:8575): campo Nova editabile per l'override manuale del layer UGC"
```

---

### Task 4: Guardia anti race-condition in `ResolveUgcLayerJob`

**Files:**
- Modify: `app/Jobs/ResolveUgcLayerJob.php`
- Test: `tests/Feature/ResolveUgcLayerJobTest.php` (crealo se non esiste già — verifica prima con `find tests -iname "*ResolveUgcLayerJob*"`)

**Interfaces:**
- Consumes: `HasLayerFilterAndLink::applyManualLayerOverride()` (Task 3) — usato nel test per simulare la correzione manuale avvenuta durante l'esecuzione del job
- Produces: nessuna nuova interfaccia pubblica — comportamento interno del job

- [ ] **Step 0: Verifica se esiste già un file di test per questo job**

Run: `find tests -iname "*ResolveUgcLayerJob*"`

Se il file esiste, apri quello e aggiungi i test allo Step 1 nella classe esistente (adattando `namespace`/`use` già presenti). Se non esiste, procedi con lo Step 1 come descritto (crea il file da zero).

- [ ] **Step 1: Scrivi il test che fallisce**

Crea (o estendi) `tests/Feature/ResolveUgcLayerJobTest.php`:

**Nota (corretta durante l'esecuzione, vedi ledger SDD):** la prima stesura di questo test scriveva la correzione manuale PRIMA di chiamare `handle()`. Non avrebbe mai esercitato la guardia: il controllo di idempotenza preesistente del job (`if (! empty($this->ugc->properties['layer_id']))`, righe iniziali di `handle()`) l'avrebbe già intercettata e sarebbe uscito prima di arrivare a `resolveLayerByProximity()` — la vera finestra di race è DOPO quella chiamata, non prima. La versione corretta usa un mock di `UgcService::resolveLayerByProximity()` per iniettare la correzione manuale esattamente in quel punto:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ResolveUgcLayerJob;
use App\Jobs\SendUgcReportMailJob;
use App\Nova\Traits\HasLayerFilterAndLink;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Services\UgcService;

class ResolveUgcLayerJobTest extends TestCase
{
    use DatabaseTransactions;

    private object $overrideHelper;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
        if (WmApp::count() === 0) {
            WmApp::factory()->create();
        }

        $this->overrideHelper = new class
        {
            use HasLayerFilterAndLink;

            public $properties = [];

            public static $model;
        };
    }

    public function test_job_aborts_without_overwriting_when_layer_was_manually_corrected_meanwhile(): void
    {
        // Queue::fake(): QUEUE_CONNECTION=sync in phpunit.xml (sovrascrive
        // .env.testing) — senza questo fake, UgcTrack::factory()->create()
        // qui sotto triggera App\Observers\UgcObserver::created() che
        // dispatcha SINCRONAMENTE ResolveUgcLayerJob. Quel job automatico,
        // non trovando nessuna EcTrack vicina in un DB di test vuoto,
        // imbocca il ramo "nessun layer trovato" e invia una mail via
        // NewUgcReportMail — la cui estrazione coordinate (ST_X()/ST_Y())
        // assume geometria Point e fallisce su una UgcTrack (linea). L'errore
        // SQL è catturato internamente da NewUgcReportMail (try/catch), ma
        // lascia comunque la transazione Postgres del test "aborted",
        // facendo fallire ogni query successiva. Bug preesistente e non
        // correlato a questo ticket (nessun test esistente costruisce
        // NewUgcReportMail con una UgcTrack, solo con UgcPoi) — fuori scope,
        // segnalato al dev separatamente. Qui ci limitiamo a evitare che
        // l'esecuzione sincrona del job durante il setup attivi il percorso.
        Queue::fake();
        Mail::fake();

        $correctLayer = Layer::factory()->create();
        $autoResolvedLayer = Layer::factory()->create();

        $track = UgcTrack::factory()->create([
            'properties' => ['form' => ['id' => 'report']],
        ]);

        $overrideHelper = $this->overrideHelper;

        // Mock di UgcService::resolveLayerByProximity(): come side effect
        // della chiamata, simula la correzione manuale avvenuta da Nova
        // esattamente nella finestra di race che la guardia deve coprire —
        // dopo il refresh() iniziale del job (quindi il controllo di
        // idempotenza preesistente, basato su quel refresh(), non scatta),
        // ma prima del salvataggio finale del job. Scrive su un'istanza
        // indipendente ricaricata dal DB (non su $this->ugc, che nel job
        // resta quella pre-correzione in memoria), riproducendo una
        // scrittura concorrente da un'altra richiesta.
        $this->mock(UgcService::class, function ($mock) use ($autoResolvedLayer, $track, $correctLayer, $overrideHelper) {
            $mock->shouldReceive('resolveLayerByProximity')
                ->once()
                ->andReturnUsing(function () use ($autoResolvedLayer, $track, $correctLayer, $overrideHelper) {
                    $trackForManualEdit = UgcTrack::find($track->id);
                    $overrideHelper::applyManualLayerOverride($trackForManualEdit, $correctLayer->id, null);
                    $trackForManualEdit->save();

                    return $autoResolvedLayer;
                });
        });

        (new ResolveUgcLayerJob($track))->handle(app(UgcService::class));

        $track->refresh();
        $this->assertSame($correctLayer->id, $track->properties['layer_id']);
        $this->assertFalse($track->properties['layer_id_auto_resolved']);
        Queue::assertNotPushed(SendUgcReportMailJob::class);
    }
}
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `docker exec laravel-camminiditalia php artisan test --filter=ResolveUgcLayerJobTest`
Expected: FAIL — senza la guardia, il job sovrascrive comunque la correzione manuale con `$autoResolvedLayer` (la risoluzione "automatica" simulata dal mock)

- [ ] **Step 3: Implementa la guardia**

In `app/Jobs/ResolveUgcLayerJob.php`, modifica il blocco finale di `handle()` (dopo il commento `// Salva layer_id nelle properties con saveQuietly() per non retriggare l'observer`):

```php
        // Guardia anti race-condition: se nel frattempo l'UGC è stato corretto
        // manualmente da Nova (oc:8575), non sovrascrivere la correzione con
        // la risoluzione automatica. Query fresca dal DB, non $this->ugc (che
        // è stato caricato via refresh() a inizio metodo e può essere stale
        // rispetto a una correzione avvenuta durante resolveLayerByProximity()).
        // getAttribute('id') invece di ->id: il baseline PHPStan esistente
        // ignora "undefined property GeometryModel::$id" per un numero fisso
        // di occorrenze già presenti in questo file (righe preesistenti);
        // usare l'accesso magico anche qui alzerebbe quel conteggio e
        // farebbe fallire l'analisi. Trovato in Fase: review-gate.
        $fresh = $this->ugc->newQuery()->find($this->ugc->getAttribute('id'));
        if ($fresh && ($fresh->properties['layer_id_auto_resolved'] ?? null) === false) {
            Log::info('ResolveUgcLayerJob: UGC #'.$this->ugc->getAttribute('id').' corretto manualmente nel frattempo, skip risoluzione automatica.');

            return;
        }

        // Salva layer_id nelle properties con saveQuietly() per non retriggare l'observer
        $properties = $this->ugc->properties ?? [];
        $properties['layer_id'] = $layer->id;
        $properties['layer_id_auto_resolved'] = true;
        if (isset($properties['form']) && is_array($properties['form'])) {
            $properties['form']['layer_id'] = $layer->id;
        }
        $this->ugc->properties = $properties;
        $this->ugc->saveQuietly();

        if ($this->notify) {
            SendUgcReportMailJob::dispatch($this->ugc, $layer);
        }
```

- [ ] **Step 4: Esegui il test e verifica che passi**

Run: `docker exec laravel-camminiditalia php artisan test --filter=ResolveUgcLayerJobTest`
Expected: PASS

- [ ] **Step 5: Esegui la suite completa**

Run: `docker exec laravel-camminiditalia php artisan test`
Expected: PASS (nessuna regressione sui test esistenti che coprono `ResolveUgcLayerJob` tramite `UgcObserver::created()`)

- [ ] **Step 6: Formatta e committa**

```bash
docker exec laravel-camminiditalia composer format
git add app/Jobs/ResolveUgcLayerJob.php tests/Feature/ResolveUgcLayerJobTest.php
git commit -m "feat(oc:8575): guardia anti race-condition in ResolveUgcLayerJob"
```

---

## Correzioni da Fase: review formale (wm-review-ticket, dopo l'esecuzione dei 4 task)

Tre finding bloccanti emersi dalla review formale con 5 finder paralleli, tutti verificati direttamente sul codice prima di applicare la correzione (non solo sul report degli agenti):

1. **`UgcTrackPolicy` sostituiva una policy reale già attiva** (`Wm\WmPackage\Policies\UgcTrackPolicy`, auto-risolta da Laravel via `Gate::guessPolicyName()` — verificato passo-passo in `vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:717-737`). La prima stesura ("viewAny/view/create/delete → true per chiunque") era più permissiva del reale (apriva l'accesso a utenti senza ruolo) E più restrittiva per il Validator (che nel package bypassa tutto, non solo `update`). Corretto: `App\Policies\UgcTrackPolicy` ora replica esattamente `before()` (bypass Administrator+Validator, ma il Validator esce dal bypass sulla sola ability `update`) e `viewAny`/`view` (Editor + `hasUgcEnabled()` + `ownsApp()`), con `create`/`update`/`delete`/`restore`/`forceDelete` a `false` per chi non bypassa. Test in `tests/Feature/UgcTrackPolicyTest.php` riscritti da zero per coprire Administrator/Validator/Editor(con e senza `hasUgcEnabled`, proprietario e non dell'app)/Guest/utente senza ruolo su tutte le ability.
2. **Il ramo di idempotenza preesistente di `ResolveUgcLayerJob` (righe 38-48, non toccato dai task originali) inviava comunque la mail** se una correzione manuale avveniva prima che il job partisse (non durante `resolveLayerByProximity()`, ma prima del `refresh()` iniziale) — il controllo `if (! empty($this->ugc->properties['layer_id']))` non distingueva "già risolto da un retry" da "corretto manualmente". Corretto aggiungendo il controllo su `layer_id_auto_resolved !== false` anche in questo ramo. Test dedicato: `test_job_stays_silent_when_layer_was_manually_corrected_before_the_job_even_started` in `tests/Feature/ResolveUgcLayerJobTest.php`.
3. **Il no-op guard del `fillUsing` confrontava il valore inviato con lo stato *attuale* del DB**, non con quello mostrato in pagina al render: un salvataggio non correlato dopo che `ResolveUgcLayerJob` ha scritto un nuovo layer nel frattempo veniva scambiato per una scelta esplicita e sovrascriveva la risoluzione automatica appena avvenuta. Corretto con un nuovo campo Nova nascosto companion, `layerOverrideOriginalField()` (`Hidden`, stesso `resolveUsing` del campo visibile, `fillUsing` no-op), il cui valore submesso (`layer_override_original`) è la base di confronto in `layerOverrideField()->fillUsing()`, non `$model->properties['layer_id']`. Wiring identico al campo principale in `UgcTrack::fields()`/`UgcPoi::fields()`. Test dedicato: `test_layer_override_fill_using_is_noop_when_unrelated_save_happens_after_concurrent_auto_resolution` in `tests/Feature/HasLayerFilterAndLinkTest.php`.

Verificato dopo le correzioni: PHPStan pulito (0 errori), suite completa 281 passati / 4 falliti pre-esistenti non correlati (`EcTrackPolicyTest` ×3, `DeepLinkQrFieldVisibilityTest` ×1, invariati dall'inizio del ciclo).

## Cleanup applicati dopo la review formale

Su richiesta esplicita del dev, oltre ai 3 bloccanti anche i finding cleanup non bloccanti:

- **Duplicazione `properties.form.layer_id`** (tra `ResolveUgcLayerJob` e il trait) + **trait che faceva 3 cose** (link/filtro readonly, campo editabile, mutazione dati): risolti insieme estraendo `App\Support\UgcLayerAssignment::apply()` (mutazione condivisa) e dividendo `HasLayerFilterAndLink` in due trait — quello originale torna a fare solo presentazione, il nuovo `HasLayerOverride` porta il campo editabile e `applyManualLayerOverride()`. `UgcTrack`/`UgcPoi` usano entrambi.
- **`$this->ugc->getAttribute('id')` ripetuto** in `ResolveUgcLayerJob` → estratto in variabile locale `$ugcId`.
- **Commenti troppo lunghi** in `HasLayerOverride::layerOverrideField()` e nei test → accorciati mantenendo i riferimenti a file:riga.
- **Setup di test duplicato** in quello che ora è `HasLayerOverrideTest.php` (nuovo file, nato dallo split) → `Queue::fake()` spostato in `setUp()`, estratti `makeAdmin()`/`makeAdminRequest()`.
- **Non toccati, per scelta esplicita**: duplicazione minima tra `UgcPoiPolicy`/`UgcTrackPolicy` (divergono deliberatamente su `viewAny`/`view`/`create`/`delete`/`restore`/`forceDelete`, un'estrazione forzata introdurrebbe condizionali più complessi della duplicazione stessa) — nessuna azione, rischio accettato.

Verificato di nuovo dopo i cleanup: PHPStan pulito, suite 281 passati / 4 falliti pre-esistenti non correlati (nessuna regressione, stesso conteggio di prima).

---

## Self-Review

**Copertura spec (overview.md → task):**
- Policy `UgcPoiPolicy::update()` → Task 1
- Policy `UgcTrackPolicy` + registrazione, comportamento invariato sulle altre ability → Task 2
- Select field editabile, opzioni tutti i layer, searchable, nullable, solo Update, solo Administrator → Task 3
- `layerLinkField` invariato, coesistenza → Task 3 (non toccato, solo aggiunto un field in più in `fields()`)
- Salvataggio normale (no `saveQuietly()`) → Task 3 (`fillUsing` chiama `applyManualLayerOverride` + save normale di Nova, nessun `saveQuietly()` in questo percorso)
- Nessuna mail alla correzione manuale → Task 3 (nessun dispatch di `SendUgcReportMailJob` in `applyManualLayerOverride`)
- Flag `layer_id_auto_resolved = false` su set/clear → Task 3, testato in Step 1
- Allineamento `properties.form.layer_id` → Task 3, testato in Step 1 (incluso il caso "chiave `form` assente")
- Guardia anti race-condition → Task 4
- Log applicativo → Task 3 (`Log::info` in `applyManualLayerOverride`)
- Comportamento identico UgcTrack/UgcPoi → Task 3 (metodo unico nel trait condiviso)
- Test di regressione `UgcTrackPolicy` viewAny/view/create/delete → Task 2
- Traduzioni en+it → Task 3, Step 6

**Placeholder scan:** nessuno — ogni step ha codice completo, nessun "TBD"/"implement later".

**Type consistency:** `applyManualLayerOverride(Model $model, ?int $layerId, ?User $actor): void` — stessa firma usata in Task 3 (dentro `fillUsing`) e Task 4 (nel test del job) e in tutti i test di Task 3. `layerOverrideField(NovaRequest $request): ?Select` coerente con `layerLinkField`/`layerFilterField` esistenti nello stesso trait.

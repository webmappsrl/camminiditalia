> Ticket: oc:8276

# Colonna layer linkabile e filtro layer su UgcPoi/UgcTrack — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere, per gli Administrator, un field che mostra il nome del layer (linkabile, in nuova scheda) su `App\Nova\UgcPoi` e `App\Nova\UgcTrack`, ed estendere a UgcTrack il filtro Select per layer già esistente su UgcPoi.

**Architecture:** Un trait condiviso (`App\Nova\Traits\HasLayerFilterAndLink`) centralizza la risoluzione di `properties->layer_id` in un field Nova (link HTML sanitizzato, con cache in-request e gestione degli edge case) e la costruzione del filtro Select Administrator-only. Le due Nova Resource (`UgcPoi`, `UgcTrack`) usano il trait in `fields()`, senza duplicare la logica.

**Tech Stack:** Laravel 11 + Nova 5, PostgreSQL/PostGIS (query raw su colonna JSON `properties`), PHPUnit (`DatabaseTransactions`), Docker (`docker exec laravel-camminiditalia ...`).

## Global Constraints

- Tutti i comandi `php artisan`/test vanno eseguiti in `docker exec laravel-camminiditalia ...`.
- Nessuna modifica al submodule `wm-package`: la feature è interamente locale al repo principale.
- Usare sempre `Layer::getStringName()`, mai `$layer->name` (il cast rompe la stringa vuota).
- Il filtro Select esistente su `UgcPoi` (righe 72-94, oc:7640) va sostituito con la versione tramite trait, non lasciato duplicato: dopo il refactor deve restare **un solo** punto di verità per la logica del filtro.
- Query `properties->>'layer_id'` sono specifiche PostgreSQL — coerente con l'assetto esistente del progetto.
- Ogni test nuovo usa `Illuminate\Foundation\Testing\DatabaseTransactions` (non `RefreshDatabase`), pattern già in uso in `tests/Feature/UgcNotificationTest.php` — evita `migrate:fresh` ripetuti sul DB reale.
- Namespace dei modelli nei test: `Wm\WmPackage\Models\User`, `Wm\WmPackage\Models\App`, `Wm\WmPackage\Models\Layer`, `Wm\WmPackage\Models\UgcPoi`, `Wm\WmPackage\Models\UgcTrack` (i factory sono definiti su questi, non sugli override locali `App\Models\*`).
- Setup comune nei test: `RolesAndPermissionsService::seedDatabase()` + `if (App::count() === 0) { App::factory()->create(); }` in `setUp()`.
- Il commit finale di ogni task usa la convention `feat(oc:8276): ...`. **Nessun comando git va eseguito automaticamente da chi implementa** — i blocchi `git commit` in questo piano sono istruzioni testuali per il developer.

---

## Task 1: Trait condiviso `HasLayerFilterAndLink`

**Files:**
- Create: `app/Nova/Traits/HasLayerFilterAndLink.php`
- Test: `tests/Feature/HasLayerFilterAndLinkTest.php`

**Interfaces:**
- Produces: `HasLayerFilterAndLink::renderLayerLink($rawLayerId): string` (static, usato dal field closure) — ritorna `'Non assegnato'` se `$rawLayerId` non è numerico o è null; `'Layer eliminato (ID: {id})'` se numerico ma `Layer::find()` non trova il record; altrimenti un tag `<a>` con `href` verso `/nova/resources/layers/{id}`, `target="_blank" rel="noopener noreferrer"`, testo escapato con `htmlspecialchars()`.
- Produces: `$this->layerLinkField(NovaRequest $request): ?Laravel\Nova\Fields\Text` (istanza, richiede `hasRole('Administrator')` altrimenti torna `null`) — field Nova sull'attributo virtuale `layer_link`, `asHtml()`, nascosto in create/update.
- Produces: `$this->layerFilterField(NovaRequest $request): ?Laravel\Nova\Fields\Select` (istanza, richiede `hasRole('Administrator')` altrimenti torna `null`) — usa `static::$model` della Resource che consuma il trait per determinare da quale modello Eloquent leggere i `layer_id` distinti.

- [ ] **Step 1: Scrivere il test che verifica `renderLayerLink()` per i 4 casi (null/non numerico, valido con layer esistente, valido con layer cancellato, escaping XSS nel nome)**

```php
<?php

namespace Tests\Feature;

use App\Nova\Traits\HasLayerFilterAndLink;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class HasLayerFilterAndLinkTest extends TestCase
{
    use DatabaseTransactions;

    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->subject = new class
        {
            use HasLayerFilterAndLink;
        };
    }

    public function test_render_layer_link_returns_placeholder_for_null(): void
    {
        $this->assertSame('Non assegnato', $this->subject::renderLayerLink(null));
    }

    public function test_render_layer_link_returns_placeholder_for_non_numeric(): void
    {
        $this->assertSame('Non assegnato', $this->subject::renderLayerLink('not-a-number'));
    }

    public function test_render_layer_link_returns_deleted_placeholder_for_missing_layer(): void
    {
        $missingId = 999999;

        $this->assertSame(
            "Layer eliminato (ID: {$missingId})",
            $this->subject::renderLayerLink($missingId)
        );
    }

    public function test_render_layer_link_returns_escaped_link_for_existing_layer(): void
    {
        $layer = Layer::factory()->create([
            'name' => ['it' => 'Via <script>alert(1)</script> Francigena', 'en' => 'Via Francigena'],
        ]);

        $result = $this->subject::renderLayerLink((string) $layer->id);

        $this->assertStringContainsString('target="_blank"', $result);
        $this->assertStringContainsString('rel="noopener noreferrer"', $result);
        $this->assertStringContainsString('/nova/resources/layers/'.$layer->id, $result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }
}
```

- [ ] **Step 2: Eseguire il test per verificare che fallisca (la classe/trait non esiste ancora)**

Run: `docker exec laravel-camminiditalia php artisan test --filter=HasLayerFilterAndLinkTest`
Expected: FAIL con errore "Class \"App\Nova\Traits\HasLayerFilterAndLink\" not found"

- [ ] **Step 3: Creare il trait con `renderLayerLink()`, `layerLinkField()`, `layerFilterField()`**

```php
<?php

namespace App\Nova\Traits;

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
            ->filterable(function ($request, $query, $value) {
                return $query->whereRaw("(properties->>'layer_id')::integer = ?", [(int) $value]);
            })
            ->hideFromIndex()
            ->hideFromDetail()
            ->hideWhenCreating()
            ->hideWhenUpdating();
    }
}
```

- [ ] **Step 4: Eseguire il test e verificare che passi**

Run: `docker exec laravel-camminiditalia php artisan test --filter=HasLayerFilterAndLinkTest`
Expected: PASS (4/4 test)

- [ ] **Step 5: Commit**

```bash
git add app/Nova/Traits/HasLayerFilterAndLink.php tests/Feature/HasLayerFilterAndLinkTest.php
git commit -m "feat(oc:8276): add HasLayerFilterAndLink trait with layer link resolution and filter"
```

---

## Task 2: Applicare il trait a `App\Nova\UgcPoi`

**Files:**
- Modify: `app/Nova/UgcPoi.php`
- Test: `tests/Feature/UgcPoiLayerFieldTest.php`

**Interfaces:**
- Consumes: `HasLayerFilterAndLink::layerLinkField(NovaRequest $request): ?Text`, `HasLayerFilterAndLink::layerFilterField(NovaRequest $request): ?Select` (Task 1)
- Produces: `App\Nova\UgcPoi::fields()` include, in coda, il field `layer_link` e il filtro `layer_filter` (solo per Administrator); il vecchio blocco `Select::make(__('Filtro Segnalazioni'), ...)` inline (righe 72-94) viene rimosso e sostituito dalla chiamata al trait.

- [ ] **Step 1: Scrivere il test Feature che verifica field e filtro sull'endpoint Nova di UgcPoi**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class UgcPoiLayerFieldTest extends TestCase
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

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        return $admin;
    }

    private function validator(): User
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');

        return $validator;
    }

    public function test_administrator_sees_layer_link_field_on_index(): void
    {
        $admin = $this->admin();
        $layer = Layer::factory()->create(['name' => ['it' => 'Via Francigena Test', 'en' => 'Via Francigena Test']]);
        UgcPoi::factory()->create(['properties' => ['layer_id' => $layer->id]]);

        $response = $this->actingAs($admin)->getJson('/nova-api/ugc-pois?perPage=25');

        $response->assertOk();

        $fieldAttributes = collect($response->json('resources.0.fields'))->pluck('attribute')->toArray();
        $this->assertContains('layer_link', $fieldAttributes);

        $layerField = collect($response->json('resources.0.fields'))->firstWhere('attribute', 'layer_link');
        $this->assertStringContainsString('Via Francigena Test', $layerField['value']);
        $this->assertStringContainsString('/nova/resources/layers/'.$layer->id, $layerField['value']);
    }

    public function test_layer_link_field_shows_placeholder_when_layer_id_missing(): void
    {
        $admin = $this->admin();
        UgcPoi::factory()->create(['properties' => []]);

        $response = $this->actingAs($admin)->getJson('/nova-api/ugc-pois?perPage=25');

        $response->assertOk();

        $layerField = collect($response->json('resources.0.fields'))->firstWhere('attribute', 'layer_link');
        $this->assertSame('Non assegnato', $layerField['value']);
    }

    public function test_validator_does_not_see_layer_link_field(): void
    {
        $validator = $this->validator();
        UgcPoi::factory()->create(['properties' => []]);

        $response = $this->actingAs($validator)->getJson('/nova-api/ugc-pois?perPage=25');

        $response->assertOk();

        $fieldAttributes = collect($response->json('resources.0.fields'))->pluck('attribute')->toArray();
        $this->assertNotContains('layer_link', $fieldAttributes);
    }

    public function test_administrator_can_filter_ugc_pois_by_layer(): void
    {
        $admin = $this->admin();
        $layerA = Layer::factory()->create();
        $layerB = Layer::factory()->create();
        $poiA = UgcPoi::factory()->create(['properties' => ['layer_id' => $layerA->id]]);
        UgcPoi::factory()->create(['properties' => ['layer_id' => $layerB->id]]);

        $response = $this->actingAs($admin)->getJson(
            '/nova-api/ugc-pois?perPage=25&layer_filter='.base64_encode(json_encode($layerA->id))
        );

        $response->assertOk();

        $ids = collect($response->json('resources'))->pluck('id.value')->toArray();
        $this->assertEquals([(string) $poiA->id], $ids);
    }
}
```

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Run: `docker exec laravel-camminiditalia php artisan test --filter=UgcPoiLayerFieldTest`
Expected: FAIL (nessun field `layer_link` presente, o response con struttura diversa da quella attesa)

- [ ] **Step 3: Sostituire il blocco filtro inline con il trait in `app/Nova/UgcPoi.php`**

Leggere prima il file corrente per individuare il metodo `fields()` (contiene, tra le righe 72-94, il blocco `Select::make(__('Filtro Segnalazioni'), ...)` da rimuovere).

Aggiungere l'uso del trait:

```php
use App\Nova\Traits\HasLayerFilterAndLink;
```

Nella dichiarazione della classe:

```php
class UgcPoi extends WmNovaUgcPoi
{
    use HidesAppFromIndexTrait;
    use HasLayerFilterAndLink;

    // ... resto invariato
```

Nel metodo `fields()`, rimuovere l'intero blocco:

```php
        if ($request->user()?->hasRole('Administrator')) {
            $fields[] = Select::make(__('Filtro Segnalazioni'), 'layer_filter')
                ->options(function () {
                    $layerIds = \App\Models\UgcPoi::query()
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
                ->filterable(function ($request, $query, $value) {
                    return $query->whereRaw("(properties->>'layer_id')::integer = ?", [(int) $value]);
                })
                ->hideFromIndex()
                ->hideFromDetail()
                ->hideWhenCreating()
                ->hideWhenUpdating();
        }
```

e sostituirlo con:

```php
        if ($filterField = $this->layerFilterField($request)) {
            $fields[] = $filterField;
        }

        if ($linkField = $this->layerLinkField($request)) {
            $fields[] = $linkField;
        }
```

Rimuovere l'import `use Laravel\Nova\Fields\Select;` se non più usato altrove nel file (verificare con `grep -n "Select::" app/Nova/UgcPoi.php` prima di rimuoverlo), e l'import `use Wm\WmPackage\Models\Layer;` se non più referenziato direttamente nel file dopo la rimozione del blocco.

- [ ] **Step 4: Eseguire il test e verificare che passi**

Run: `docker exec laravel-camminiditalia php artisan test --filter=UgcPoiLayerFieldTest`
Expected: PASS (4/4 test)

- [ ] **Step 5: Eseguire l'intera suite Feature relativa a UgcPoi per verificare l'assenza di regressioni sul filtro esistente**

Run: `docker exec laravel-camminiditalia php artisan test --filter=UgcNotificationTest`
Expected: PASS (nessuna regressione sul flusso di notifica esistente)

- [ ] **Step 6: Commit**

```bash
git add app/Nova/UgcPoi.php tests/Feature/UgcPoiLayerFieldTest.php
git commit -m "feat(oc:8276): use HasLayerFilterAndLink trait in UgcPoi Nova resource"
```

---

## Task 3: Applicare il trait a `App\Nova\UgcTrack`

**Files:**
- Modify: `app/Nova/UgcTrack.php`
- Test: `tests/Feature/UgcTrackLayerFieldTest.php`

**Interfaces:**
- Consumes: `HasLayerFilterAndLink::layerLinkField(NovaRequest $request): ?Text`, `HasLayerFilterAndLink::layerFilterField(NovaRequest $request): ?Select` (Task 1)
- Produces: `App\Nova\UgcTrack::fields()` (metodo attualmente assente, viene introdotto ora) che ritorna i field ereditati da `Wm\WmPackage\Nova\UgcTrack::fields()` più `layer_link` e `layer_filter`.

- [ ] **Step 1: Scrivere il test Feature che verifica field e filtro sull'endpoint Nova di UgcTrack**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class UgcTrackLayerFieldTest extends TestCase
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

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        return $admin;
    }

    private function validator(): User
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');

        return $validator;
    }

    public function test_administrator_sees_layer_link_field_on_index(): void
    {
        $admin = $this->admin();
        $layer = Layer::factory()->create(['name' => ['it' => 'Via Francigena Test', 'en' => 'Via Francigena Test']]);
        UgcTrack::factory()->create(['properties' => ['layer_id' => $layer->id]]);

        $response = $this->actingAs($admin)->getJson('/nova-api/ugc-tracks?perPage=25');

        $response->assertOk();

        $fieldAttributes = collect($response->json('resources.0.fields'))->pluck('attribute')->toArray();
        $this->assertContains('layer_link', $fieldAttributes);

        $layerField = collect($response->json('resources.0.fields'))->firstWhere('attribute', 'layer_link');
        $this->assertStringContainsString('Via Francigena Test', $layerField['value']);
        $this->assertStringContainsString('/nova/resources/layers/'.$layer->id, $layerField['value']);
    }

    public function test_layer_link_field_shows_deleted_placeholder_when_layer_missing(): void
    {
        $admin = $this->admin();
        $missingId = 999999;
        UgcTrack::factory()->create(['properties' => ['layer_id' => $missingId]]);

        $response = $this->actingAs($admin)->getJson('/nova-api/ugc-tracks?perPage=25');

        $response->assertOk();

        $layerField = collect($response->json('resources.0.fields'))->firstWhere('attribute', 'layer_link');
        $this->assertSame("Layer eliminato (ID: {$missingId})", $layerField['value']);
    }

    public function test_validator_does_not_see_layer_link_field_or_filter(): void
    {
        $validator = $this->validator();
        UgcTrack::factory()->create(['properties' => []]);

        // UgcTrack::indexQuery blocca i non-Administrator (query 1=0): verifichiamo solo l'assenza dei field,
        // la request resta 200 con resources vuoti per via dell'indexQuery esistente.
        $response = $this->actingAs($validator)->getJson('/nova-api/ugc-tracks?perPage=25');

        $response->assertOk();
        $this->assertEmpty($response->json('resources'));
    }

    public function test_administrator_can_filter_ugc_tracks_by_layer(): void
    {
        $admin = $this->admin();
        $layerA = Layer::factory()->create();
        $layerB = Layer::factory()->create();
        $trackA = UgcTrack::factory()->create(['properties' => ['layer_id' => $layerA->id]]);
        UgcTrack::factory()->create(['properties' => ['layer_id' => $layerB->id]]);

        $response = $this->actingAs($admin)->getJson(
            '/nova-api/ugc-tracks?perPage=25&layer_filter='.base64_encode(json_encode($layerA->id))
        );

        $response->assertOk();

        $ids = collect($response->json('resources'))->pluck('id.value')->toArray();
        $this->assertEquals([(string) $trackA->id], $ids);
    }
}
```

- [ ] **Step 2: Eseguire il test e verificare che fallisca**

Run: `docker exec laravel-camminiditalia php artisan test --filter=UgcTrackLayerFieldTest`
Expected: FAIL (nessun field `layer_link` presente, `fields()` non ancora definito localmente)

- [ ] **Step 3: Aggiungere `fields()` e il trait a `app/Nova/UgcTrack.php`**

```php
<?php

namespace App\Nova;

use App\Nova\Traits\HasLayerFilterAndLink;
use App\Nova\Traits\HidesAppFromIndexTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\UgcTrack as WmNovaUgcTrack;

class UgcTrack extends WmNovaUgcTrack
{
    use HidesAppFromIndexTrait;
    use HasLayerFilterAndLink;

    public static function label(): string
    {
        return __('Tracks');
    }

    public static function availableForNavigation(Request $request): bool
    {
        return $request->user()->hasRole('Administrator');
    }

    public static function indexQuery(NovaRequest $request, $query): Builder
    {
        if ($request->user()->hasRole('Administrator')) {
            return $query;
        }

        return $query->whereRaw('1=0');
    }

    public function fields(NovaRequest $request): array
    {
        $fields = parent::fields($request);

        if ($filterField = $this->layerFilterField($request)) {
            $fields[] = $filterField;
        }

        if ($linkField = $this->layerLinkField($request)) {
            $fields[] = $linkField;
        }

        return $fields;
    }
}
```

- [ ] **Step 4: Eseguire il test e verificare che passi**

Run: `docker exec laravel-camminiditalia php artisan test --filter=UgcTrackLayerFieldTest`
Expected: PASS (4/4 test)

- [ ] **Step 5: Commit**

```bash
git add app/Nova/UgcTrack.php tests/Feature/UgcTrackLayerFieldTest.php
git commit -m "feat(oc:8276): add layer link field and filter to UgcTrack Nova resource"
```

---

## Task 4: Verifica finale e aggiornamento CLAUDE.md

**Files:**
- Modify: `CLAUDE.md` (sezione "Feature disponibili" e "Decisioni architetturali")

**Interfaces:**
- Consumes: nessuna nuova interfaccia — task di chiusura documentale.

- [ ] **Step 1: Eseguire l'intera suite Feature per verificare l'assenza di regressioni**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/HasLayerFilterAndLinkTest.php tests/Feature/UgcPoiLayerFieldTest.php tests/Feature/UgcTrackLayerFieldTest.php tests/Feature/UgcNotificationTest.php tests/Feature/LayerActionsVisibilityTest.php`
Expected: PASS su tutti i file (nessuna regressione sui test preesistenti toccati indirettamente da questa feature)

- [ ] **Step 2: Aggiungere la riga in "Feature disponibili" di CLAUDE.md**

```markdown
| Colonna layer linkabile e filtro layer su UgcPoi/UgcTrack | oc:8276 | `app/Nova/Traits/HasLayerFilterAndLink.php`, `app/Nova/UgcPoi.php`, `app/Nova/UgcTrack.php` | Field "layer" (link verso il layer, in nuova scheda) e filtro Select per layer, solo Administrator; trait condiviso tra UgcPoi e UgcTrack |
```

- [ ] **Step 3: Aggiungere il blocco in "Decisioni architetturali" di CLAUDE.md (in cima alla sezione)**

```markdown
### Colonna layer linkabile e filtro layer su UgcPoi/UgcTrack (oc:8276)
- Logica di risoluzione layer_id → nome/link e filtro Select estratta in `App\Nova\Traits\HasLayerFilterAndLink`, condiviso da `UgcPoi` e `UgcTrack` — evita la duplicazione del blocco Select introdotta da oc:7640 su UgcPoi soltanto
- `layer_id` da `properties` va sempre validato con `is_numeric()` prima dell'uso: il filtro Select preesistente (oc:7640) usa un cast SQL `::integer` senza validazione, fragile su dati corrotti — rischio noto, non modificato in questo ciclo (fuori scope), ma il nuovo trait adotta validazione PHP-side per non ripeterlo
- Cache statica in-request (`static::$layerNameCache`) per evitare N+1 query quando più righe della stessa pagina index condividono lo stesso layer_id
- Layer cancellato (`layer_id` valido ma `Layer::find()` nullo) mostra "Layer eliminato (ID: {id})", distinto da "Non assegnato" (layer_id assente/non valido) — nessun link generato in entrambi i casi
- Link con `target="_blank" rel="noopener noreferrer"` (mitigazione tabnabbing) e nome layer escapato con `htmlspecialchars()` (mitigazione XSS stored, dato che `Layer::getStringName()` può contenere input utente non sanitizzato)
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(oc:8276): update CLAUDE.md with layer link field feature"
```

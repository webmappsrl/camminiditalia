> Ticket: oc:8646

# Route shape modificabile manualmente — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> ⚠️ **Nessun commit automatico.** In questo progetto Webmapp i commit sono vietati durante l'esecuzione: gli step "Commit" sotto sono istruzioni testuali per il dev, da eseguire solo dopo il review-gate e la sua approvazione esplicita.

**Goal:** Permettere all'Administrator di forzare in Nova la tipologia del cammino (Lineare / Ad anello), con un override che il ricalcolo automatico non sovrascrive e che al frontend arriva come unico valore `shape`.

**Architecture:** L'override vive in `properties->attributes->shape_manual` (solo codice stringa, fuori da `CALCULATED_KEYS`). `LayerAttributesService` calcola il `shape` pubblico come "manuale se valido, altrimenti calcolato" sia nel ricalcolo (`computeCalculatedValues()`) sia in una scrittura sincrona dal campo Nova (`applyManualShape()`). `shape_manual` è escluso dai consumer pubblici tramite `config('wm-package.internal_attribute_keys')`.

**Tech Stack:** Laravel 11, Nova 5, PostgreSQL jsonb, PHPUnit (Feature test), Laravel Pint, PHPStan/Larastan.

**Spec:** `docs/features/8646-route-shape-del-layer-modificabile-manualmente-in-nova/overview.md`

## Global Constraints

- Tutto il codice nel repo principale `camminiditalia`; **nessuna modifica** al submodule `wm-package`.
- Comandi sempre nel container: `docker exec laravel-camminiditalia php artisan test --filter=<Nome>`; formattazione `docker exec laravel-camminiditalia composer format`.
- I test girano su `camminiditalia_testing` (`.env.testing`), mai sul DB di sviluppo.
- Il valore pubblico `shape` non vale mai `discontinuous` (invariante di oc:8463).
- `shape_manual` contiene **solo il codice** (`linear` | `roundtrip`), mai l'oggetto `{value, name}`.
- Le traduzioni di `shape` si costruiscono sempre con `LayerAttributesService::withTranslations()` + `RouteShape::labelIn()`.
- Il nome della chiave è la costante `LayerAttributesService::SHAPE_MANUAL_KEY`: mai la stringa `'shape_manual'` scritta a mano fuori dal service e da `config/wm-package.php`.
- Nessuna astrazione generica "chiave calcolata → chiave manuale".
- La card "Route shape" (`App\Nova\Layer::renderShapeCard()`) **non si modifica**.
- Ogni nuova stringa traducibile in tutte le lingue di `resources/lang/{it,en,fr,es,de}.json`.

## Review Focus

- `shape_manual` sporco nel DB (`discontinuous`, valore sconosciuto, oggetto `{value,name}` scritto a mano) → ignorato, `shape` torna al calcolato — test in Task 1.
- Layer senza tappe (`determineType()` null) con override → `shape` = override — test in Task 1.
- Ritorno ad "Automatica" dal form → `shape_manual` rimosso e `shape` subito ricalcolato — test in Task 3.
- Un ricalcolo automatico dopo l'override (job reale, non fake) non tocca il valore — test in Task 1.
- La costante `SHAPE_MANUAL_KEY` presente in `internal_attribute_keys` e assente da `config.json` e da `GET /api/app/webapp/{app}/layer/{layer}` — test in Task 2.

---

### Task 1: Override manuale nel calcolo di `shape` (`LayerAttributesService`)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-override-manuale-nel-calcolo-di-shape)

**Files:**
- Modify: `app/Services/LayerAttributesService.php` (costante vicino a `CALCULATED_KEYS` ~r.92; blocco shape in `computeCalculatedValues()` ~r.624-632; nuovi metodi dopo `persistManualValue()`)
- Test: `tests/Feature/LayerShapeOverrideTest.php` (nuovo)

**Interfaces:**
- Produces:
  - `public const SHAPE_MANUAL_KEY = 'shape_manual';`
  - `public function manualShape(Layer $layer): ?RouteShape` — legge dal DB (non dal modello in memoria) `properties->'attributes'->>'shape_manual'`; `null` se assente, non stringa, non valido o `discontinuous`.
  - `public function shapeValues(Layer $layer): array` — `['shape' => {value,name}, 'shape_discontinuous' => true]` (seconda chiave solo se la topologia è discontinua); `[]` se né calcolo né override danno un valore.
  - `public function applyManualShape(Layer $layer, ?string $code): bool` — persiste/rimuove `shape_manual`, riscrive subito `shape`/`shape_discontinuous`, restituisce `true` se `shape`/`shape_discontinuous` persistiti sono cambiati.

- [ ] **Step 1: Scrivi i test che falliscono**

`tests/Feature/LayerShapeOverrideTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\RouteShape;
use App\Jobs\RecalculateLayerAttributesJob;
use App\Services\LayerAttributesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

class LayerShapeOverrideTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Il LayerObserver accoda un ricalcolo a ogni save: con un worker
        // reale in ascolto sovrascriverebbe i valori preparati dal test.
        Queue::fake();
        Http::fake();
    }

    /**
     * Service con topologia fissata: evita di costruire tappe con geometrie
     * reali per ogni caso (stesso approccio di LayerConfigJsonShapeDiscontinuousTest).
     */
    private function serviceWithTopology(?RouteShape $calculated): LayerAttributesService
    {
        return new class($calculated) extends LayerAttributesService
        {
            public function __construct(private ?RouteShape $fixed) {}

            public function determineType(array $endpoints): ?RouteShape
            {
                return $this->fixed;
            }
        };
    }

    private function layerWithAttributes(array $attributes): Layer
    {
        $app = App::factory()->createQuietly();

        return Layer::factory()->create([
            'app_id' => $app->id,
            'properties' => ['attributes' => $attributes],
        ]);
    }

    private function storedAttributes(Layer $layer): array
    {
        $row = DB::selectOne(
            "SELECT COALESCE(properties->'attributes', '{}'::jsonb) AS a FROM layers WHERE id = ?",
            [$layer->id]
        );

        return json_decode((string) $row->a, true) ?? [];
    }

    public function test_manual_shape_wins_over_calculated_and_keeps_discontinuity_flag(): void
    {
        $service = $this->serviceWithTopology(RouteShape::DISCONTINUOUS);
        $layer = $this->layerWithAttributes([LayerAttributesService::SHAPE_MANUAL_KEY => 'roundtrip']);

        $values = $service->computeCalculatedValues($layer);

        $this->assertSame('roundtrip', $values['shape']['value']);
        $this->assertSame(RouteShape::ROUNDTRIP->labelIn('it'), $values['shape']['name']['it']);
        $this->assertTrue($values['shape_discontinuous']);
    }

    public function test_without_manual_shape_discontinuous_is_still_exposed_as_linear(): void
    {
        $service = $this->serviceWithTopology(RouteShape::DISCONTINUOUS);
        $layer = $this->layerWithAttributes([]);

        $values = $service->computeCalculatedValues($layer);

        $this->assertSame('linear', $values['shape']['value']);
    }

    /**
     * @dataProvider dirtyManualValues
     */
    public function test_invalid_manual_values_are_ignored(mixed $dirty): void
    {
        $service = $this->serviceWithTopology(RouteShape::DISCONTINUOUS);
        $layer = $this->layerWithAttributes([LayerAttributesService::SHAPE_MANUAL_KEY => $dirty]);

        $this->assertNull($service->manualShape($layer));
        $this->assertSame('linear', $service->computeCalculatedValues($layer)['shape']['value']);
    }

    public static function dirtyManualValues(): array
    {
        return [
            'discontinuous' => ['discontinuous'],
            'sconosciuto' => ['spiral'],
            'oggetto tradotto' => [['value' => 'roundtrip', 'name' => ['it' => 'Ad anello']]],
            'stringa vuota' => [''],
        ];
    }

    public function test_manual_shape_applies_even_without_stages(): void
    {
        $service = $this->serviceWithTopology(null);
        $layer = $this->layerWithAttributes([LayerAttributesService::SHAPE_MANUAL_KEY => 'roundtrip']);

        $values = $service->computeCalculatedValues($layer);

        $this->assertSame('roundtrip', $values['shape']['value']);
        $this->assertArrayNotHasKey('shape_discontinuous', $values);
    }

    public function test_persisting_calculated_values_preserves_the_manual_key(): void
    {
        $service = $this->serviceWithTopology(RouteShape::LINEAR);
        $layer = $this->layerWithAttributes([LayerAttributesService::SHAPE_MANUAL_KEY => 'roundtrip']);

        $service->persistCalculatedValues($layer, $service->computeCalculatedValues($layer));

        $stored = $this->storedAttributes($layer);
        $this->assertSame('roundtrip', $stored[LayerAttributesService::SHAPE_MANUAL_KEY]);
        $this->assertSame('roundtrip', $stored['shape']['value']);
    }

    public function test_apply_manual_shape_writes_code_and_final_shape_synchronously(): void
    {
        $service = $this->serviceWithTopology(RouteShape::DISCONTINUOUS);
        $layer = $this->layerWithAttributes([]);
        $service->persistCalculatedValues($layer, $service->computeCalculatedValues($layer));

        $changed = $service->applyManualShape($layer, 'roundtrip');

        $stored = $this->storedAttributes($layer);
        $this->assertTrue($changed);
        $this->assertSame('roundtrip', $stored[LayerAttributesService::SHAPE_MANUAL_KEY]);
        $this->assertSame('roundtrip', $stored['shape']['value']);
        $this->assertSame(RouteShape::ROUNDTRIP->labelIn('en'), $stored['shape']['name']['en']);
        $this->assertTrue($stored['shape_discontinuous']);

        $this->assertFalse($service->applyManualShape($layer, 'roundtrip'), 'Stesso valore: nessuna variazione da propagare.');
    }

    public function test_apply_manual_shape_null_restores_the_calculated_value(): void
    {
        $service = $this->serviceWithTopology(RouteShape::DISCONTINUOUS);
        $layer = $this->layerWithAttributes([]);
        $service->applyManualShape($layer, 'roundtrip');

        $changed = $service->applyManualShape($layer, null);

        $stored = $this->storedAttributes($layer);
        $this->assertTrue($changed);
        $this->assertArrayNotHasKey(LayerAttributesService::SHAPE_MANUAL_KEY, $stored);
        $this->assertSame('linear', $stored['shape']['value']);
    }

    public function test_apply_manual_shape_rejects_discontinuous_as_override(): void
    {
        $service = $this->serviceWithTopology(RouteShape::LINEAR);
        $layer = $this->layerWithAttributes([]);

        $service->applyManualShape($layer, 'discontinuous');

        $stored = $this->storedAttributes($layer);
        $this->assertArrayNotHasKey(LayerAttributesService::SHAPE_MANUAL_KEY, $stored);
        $this->assertSame('linear', $stored['shape']['value']);
    }

    public function test_recalculation_job_does_not_change_an_override(): void
    {
        $service = $this->serviceWithTopology(RouteShape::DISCONTINUOUS);
        $this->app->instance(LayerAttributesService::class, $service);
        $layer = $this->layerWithAttributes([]);
        $service->applyManualShape($layer, 'roundtrip');

        (new RecalculateLayerAttributesJob($layer->id))->handle($service);

        $this->assertSame('roundtrip', $this->storedAttributes($layer)['shape']['value']);
    }
}
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=LayerShapeOverrideTest`
Expected: FAIL — `Undefined constant App\Services\LayerAttributesService::SHAPE_MANUAL_KEY`.

- [ ] **Step 3: Implementa nel service**

In `app/Services/LayerAttributesService.php`, subito dopo `CALCULATED_KEYS` (e aggiorna il suo docblock: le chiavi manuali sono `walking_network`, `season`, `shape_manual`):

```php
    /**
     * Override manuale della tipologia (oc:8646): solo il codice RouteShape
     * (linear|roundtrip), mai l'oggetto tradotto — le etichette si
     * ricostruiscono a ogni scrittura di `shape`, così una correzione di
     * traduzione arriva anche ai layer con override. Fuori da CALCULATED_KEYS:
     * il ricalcolo non la tocca mai. Chiave interna: esclusa dai consumer
     * pubblici via config('wm-package.internal_attribute_keys').
     */
    public const SHAPE_MANUAL_KEY = 'shape_manual';
```

In `computeCalculatedValues()`, sostituisci il blocco `$shape = ...` / `if ($shape !== null) { ... }` con:

```php
        $values = [...$values, ...$this->shapeValues($layer)];
```

Aggiungi dopo `persistManualValue()`:

```php
    /**
     * Override manuale valido, letto dal DB e non dal modello in memoria: il
     * campo Nova lo scrive via SQL dopo il save Eloquent, quindi il modello
     * può averne una copia vecchia. `discontinuous` non è un override
     * ammesso (non è una tipologia pubblica, oc:8463).
     */
    public function manualShape(Layer $layer): ?RouteShape
    {
        $row = DB::selectOne(
            "SELECT jsonb_typeof(properties->'attributes'->?) AS t,
                    properties->'attributes'->>? AS v
             FROM layers WHERE id = ?",
            [self::SHAPE_MANUAL_KEY, self::SHAPE_MANUAL_KEY, $layer->id]
        );

        if ($row === null || $row->t !== 'string') {
            return null;
        }

        $shape = RouteShape::tryFrom((string) $row->v);

        return $shape === RouteShape::DISCONTINUOUS ? null : $shape;
    }

    /**
     * Valori di tipologia persistiti in properties->attributes: `shape`
     * pubblico (override manuale se presente, altrimenti calcolato, mai
     * `discontinuous`) e il flag interno `shape_discontinuous`, che resta
     * sempre quello della topologia reale delle tappe.
     *
     * @return array<string, mixed>
     */
    public function shapeValues(Layer $layer): array
    {
        $values = [];
        $calculated = $this->determineType($this->trackEndpoints($layer));
        $public = $this->manualShape($layer)
            ?? ($calculated === RouteShape::DISCONTINUOUS ? RouteShape::LINEAR : $calculated);

        if ($public !== null) {
            $values['shape'] = $this->withTranslations($public->value, fn (string $locale) => $public->labelIn($locale));
        }

        if ($calculated === RouteShape::DISCONTINUOUS) {
            $values['shape_discontinuous'] = true;
        }

        return $values;
    }

    /**
     * Imposta (o rimuove, con null / valore non ammesso) l'override manuale
     * e riscrive SUBITO `shape`, senza aspettare il job in coda: la detail
     * Nova mostrata dopo il salvataggio deve già riflettere la scelta.
     * Tocca solo le chiavi di tipologia, non le altre CALCULATED_KEYS (che
     * richiedono chiamate esterne, es. wheres()).
     *
     * @return bool true se `shape`/`shape_discontinuous` persistiti sono
     *              cambiati: il chiamante deve allora rigenerare il config,
     *              perché la scrittura SQL non fa scattare gli observer e il
     *              job di ricalcolo troverà i valori già allineati.
     */
    public function applyManualShape(Layer $layer, ?string $code): bool
    {
        $shape = $code === null ? null : RouteShape::tryFrom($code);
        if ($shape === RouteShape::DISCONTINUOUS) {
            $shape = null;
        }

        $this->persistManualValue($layer, self::SHAPE_MANUAL_KEY, $shape?->value);

        $shapeKeys = ['shape', 'shape_discontinuous'];
        $values = $this->shapeValues($layer);

        $stored = DB::selectOne(
            "SELECT COALESCE(properties->'attributes', '{}'::jsonb) AS attributes FROM layers WHERE id = ?",
            [$layer->id]
        );
        $decoded = json_decode((string) ($stored->attributes ?? '{}'), true);
        $storedShape = array_intersect_key(is_array($decoded) ? $decoded : [], array_flip($shapeKeys));

        if ($this->canonicalize($storedShape) === $this->canonicalize($values)) {
            return false;
        }

        $payload = $values === []
            ? '{}'
            : json_encode($values, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        $this->writeAttributes(
            $layer,
            "(COALESCE(properties->'attributes', '{}'::jsonb) - ?::text[]) || ?::jsonb",
            ['{'.implode(',', $shapeKeys).'}', $payload]
        );

        return true;
    }
```

Verifica che `persistCalculatedValues()` usi `JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION` per `$payload` (r.~662-667); se usa flag diversi, allinea `applyManualShape()` agli stessi flag.

- [ ] **Step 4: Esegui i test e verifica che passino, più la regressione sugli attributi**

Run: `docker exec laravel-camminiditalia php artisan test --filter='LayerShapeOverrideTest|LayerAttributes|RecalculateLayerAttributesJobTest|LayerConfigJsonShapeDiscontinuousTest|LayerAttributeFieldsTest'`
Expected: PASS tutti.

- [ ] **Step 5: Commit (istruzione per il dev, dopo il review-gate)**

```bash
git add app/Services/LayerAttributesService.php tests/Feature/LayerShapeOverrideTest.php
git commit -m "feat(oc:8646): override manuale della tipologia nel calcolo di shape"
```

---

### Task 2: `shape_manual` mai esposto al frontend

**Files:**
- Modify: `config/wm-package.php:18` (e commento sopra)
- Test: `tests/Feature/LayerConfigJsonShapeManualTest.php` (nuovo)

**Interfaces:**
- Consumes: `LayerAttributesService::SHAPE_MANUAL_KEY`, `applyManualShape()` (Task 1).
- Produces: nessuna interfaccia nuova.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
<?php

namespace Tests\Feature;

use App\Enums\RouteShape;
use App\Services\LayerAttributesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\App\AppConfigService;

class LayerConfigJsonShapeManualTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
    }

    private function discontinuousService(): LayerAttributesService
    {
        return new class extends LayerAttributesService
        {
            public function determineType(array $endpoints): ?RouteShape
            {
                return RouteShape::DISCONTINUOUS;
            }
        };
    }

    public function test_manual_key_is_declared_internal(): void
    {
        $this->assertContains(
            LayerAttributesService::SHAPE_MANUAL_KEY,
            config('wm-package.internal_attribute_keys')
        );
    }

    public function test_config_json_exposes_only_the_final_shape(): void
    {
        $app = App::factory()->createQuietly();
        $layer = Layer::factory()->create(['app_id' => $app->id, 'properties' => []]);
        $service = $this->discontinuousService();
        $service->persistCalculatedValues($layer, $service->computeCalculatedValues($layer));
        $service->applyManualShape($layer, 'roundtrip');
        $layer->refresh();

        $config = (new AppConfigService($app))->config();
        $item = collect($config['MAP']['layers'])->firstWhere('id', $layer->id);

        $this->assertSame('roundtrip', $item['attributes']['shape']['value']);
        $this->assertArrayNotHasKey(LayerAttributesService::SHAPE_MANUAL_KEY, $item['attributes']);
        $this->assertArrayNotHasKey('shape_discontinuous', $item['attributes']);
    }

    public function test_layer_api_exposes_only_the_final_shape(): void
    {
        $app = App::factory()->createQuietly();
        $layer = Layer::factory()->create(['app_id' => $app->id, 'properties' => []]);
        $service = $this->discontinuousService();
        $service->persistCalculatedValues($layer, $service->computeCalculatedValues($layer));
        $service->applyManualShape($layer, 'roundtrip');

        $response = $this->getJson('/api/app/webapp/'.$app->id.'/layer/'.$layer->id);

        $response->assertOk();
        $attributes = $response->json('properties.attributes');
        $this->assertSame('roundtrip', $attributes['shape']['value']);
        $this->assertArrayNotHasKey(LayerAttributesService::SHAPE_MANUAL_KEY, $attributes);
    }
}
```

- [ ] **Step 2: Esegui e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=LayerConfigJsonShapeManualTest`
Expected: FAIL su `test_manual_key_is_declared_internal` e sulle due `assertArrayNotHasKey(shape_manual)`.
(Se `test_layer_api_exposes_only_the_final_shape` fallisce con 404, verifica il prefisso reale con `docker exec laravel-camminiditalia php artisan route:list --path=layer` e correggi l'URL del test.)

- [ ] **Step 3: Aggiorna la config**

`config/wm-package.php`:

```php
     | shape_discontinuous: flag interno letto solo da App\Nova\Layer per
     | l'alert di discontinuità del cammino, mai da esporre pubblicamente.
     | shape_manual: override manuale della tipologia (oc:8646), deve restare
     | uguale a App\Services\LayerAttributesService::SHAPE_MANUAL_KEY (lo
     | verifica LayerConfigJsonShapeManualTest). Al frontend arriva solo
     | `shape`, già risolto con l'override.
     */
    'internal_attribute_keys' => ['shape_discontinuous', 'shape_manual'],
```

- [ ] **Step 4: Esegui e verifica che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter='LayerConfigJsonShape'`
Expected: PASS (anche `LayerConfigJsonShapeDiscontinuousTest`).

- [ ] **Step 5: Commit (istruzione per il dev, dopo il review-gate)**

```bash
git add config/wm-package.php tests/Feature/LayerConfigJsonShapeManualTest.php
git commit -m "feat(oc:8646): escludi shape_manual da config.json e API layer"
```

---

### Task 3: Campo "Route shape" nel form di edit di Nova

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-campo-route-shape-nel-form-di-edit)

**Files:**
- Modify: `app/Nova/Layer.php` (pannello `$manualAttributesPanel`, dopo il campo `Seasons` ~r.247)
- Modify: `resources/lang/{it,en,fr,es,de}.json`
- Test: `tests/Feature/LayerShapeOverrideFieldTest.php` (nuovo)

**Interfaces:**
- Consumes: `LayerAttributesService::SHAPE_MANUAL_KEY`, `applyManualShape(Layer, ?string): bool` (Task 1).
- Produces: attributo Nova `properties->attributes->shape_manual`.

- [ ] **Step 1: Scrivi i test che falliscono**

```php
<?php

namespace Tests\Feature;

use App\Enums\RouteShape;
use App\Models\User;
use App\Nova\Layer as NovaLayer;
use App\Services\LayerAttributesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Resources\MergeValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerShapeOverrideFieldTest extends TestCase
{
    use DatabaseTransactions;

    private const ATTRIBUTE = 'properties->attributes->'.LayerAttributesService::SHAPE_MANUAL_KEY;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();

        // Topologia fissata a discontinua per tutte le risoluzioni via container
        // (il campo Nova usa app(LayerAttributesService::class)).
        $this->app->instance(LayerAttributesService::class, new class extends LayerAttributesService
        {
            public function determineType(array $endpoints): ?RouteShape
            {
                return RouteShape::DISCONTINUOUS;
            }
        });
    }

    private function shapeField(): ?Field
    {
        $found = null;
        $walk = function ($items) use (&$walk, &$found) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $walk($item);
                } elseif ($item instanceof MergeValue) {
                    $walk($item->data);
                } elseif ($item instanceof Field && $item->attribute === self::ATTRIBUTE) {
                    $found = $item;
                }
            }
        };
        $walk((new NovaLayer(new Layer))->fields(NovaRequest::create('/')));

        return $found;
    }

    private function adminAndLayer(): array
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $app = App::factory()->createQuietly();
        $layer = Layer::factory()->create(['user_id' => $admin->id, 'app_id' => $app->id]);

        return [$admin, $layer];
    }

    private function stored(Layer $layer): array
    {
        $row = DB::selectOne(
            "SELECT COALESCE(properties->'attributes', '{}'::jsonb) AS a FROM layers WHERE id = ?",
            [$layer->id]
        );

        return json_decode((string) $row->a, true) ?? [];
    }

    public function test_field_is_only_on_update_form_with_linear_and_roundtrip_options(): void
    {
        $field = $this->shapeField();

        $this->assertNotNull($field, 'Campo Route shape assente dal pannello Manual attributes.');
        $this->assertSame(['linear', 'roundtrip'], collect($field->meta['options'])->pluck('value')->all());
        $this->assertFalse($field->showOnIndex);
        $this->assertFalse($field->showOnDetail);
        $this->assertFalse($field->showOnCreation);
        $this->assertTrue($field->showOnUpdate);
    }

    public function test_choosing_roundtrip_updates_shape_immediately_and_regenerates_config(): void
    {
        [$admin, $layer] = $this->adminAndLayer();

        $this->actingAs($admin)
            ->putJson('/nova-api/layers/'.$layer->id, [
                'name' => $layer->name,
                self::ATTRIBUTE => 'roundtrip',
            ])
            ->assertStatus(200);

        $stored = $this->stored($layer);
        $this->assertSame('roundtrip', $stored[LayerAttributesService::SHAPE_MANUAL_KEY]);
        $this->assertSame('roundtrip', $stored['shape']['value'], 'shape deve essere aggiornato senza eseguire il job in coda.');
        Queue::assertPushed(UpdateAppConfigJob::class);
    }

    public function test_back_to_automatic_removes_override_and_restores_calculated_shape(): void
    {
        [$admin, $layer] = $this->adminAndLayer();
        app(LayerAttributesService::class)->applyManualShape($layer, 'roundtrip');

        $this->actingAs($admin)
            ->putJson('/nova-api/layers/'.$layer->id, [
                'name' => $layer->name,
                self::ATTRIBUTE => '',
            ])
            ->assertStatus(200);

        $stored = $this->stored($layer);
        $this->assertArrayNotHasKey(LayerAttributesService::SHAPE_MANUAL_KEY, $stored);
        $this->assertSame('linear', $stored['shape']['value']);
    }

    public function test_discontinuous_cannot_be_forced_from_the_form(): void
    {
        [$admin, $layer] = $this->adminAndLayer();

        $this->actingAs($admin)
            ->putJson('/nova-api/layers/'.$layer->id, [
                'name' => $layer->name,
                self::ATTRIBUTE => 'discontinuous',
            ])
            ->assertStatus(200);

        $stored = $this->stored($layer);
        $this->assertArrayNotHasKey(LayerAttributesService::SHAPE_MANUAL_KEY, $stored);
        $this->assertSame('linear', $stored['shape']['value']);
    }

    public function test_translations_exist_in_every_language(): void
    {
        foreach (['it', 'en', 'fr', 'es', 'de'] as $locale) {
            $json = json_decode(file_get_contents(lang_path($locale.'.json')), true);
            $this->assertArrayHasKey('Automatic', $json, "Manca 'Automatic' in {$locale}.json");
            $this->assertArrayHasKey(
                'Leave on Automatic to use the shape computed from the stages; choose a value to correct it manually.',
                $json,
                "Manca l'help del campo Route shape in {$locale}.json"
            );
        }
    }
}
```

Nota per l'esecutore: se la forma di `$field->meta['options']` di Nova 5 non è `[{label, value}]`, adatta **solo l'estrazione** dei valori nella prima asserzione (l'aspettativa resta `['linear', 'roundtrip']`). Se `lang_path()` non punta a `resources/lang`, usa `resource_path('lang/'.$locale.'.json')`.

- [ ] **Step 2: Esegui e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=LayerShapeOverrideFieldTest`
Expected: FAIL — campo assente e chiavi di traduzione mancanti.

- [ ] **Step 3: Aggiungi il campo in `App\Nova\Layer`**

Nel pannello `$manualAttributesPanel`, dopo il `Multiselect` Seasons (verifica che `use App\Enums\RouteShape;` e `use Wm\WmPackage\Jobs\UpdateAppConfigJob;` siano importati; `RouteShape` lo è già perché usato da `renderShapeCard()`):

```php
            // Override manuale della tipologia (oc:8646): il calcolo dalle
            // tappe può sbagliare (es. un anello con un tratto staccato
            // risulta discontinuo → Lineare). Solo in edit: su un layer nuovo
            // non c'è ancora un calcolo da correggere. Nessuna voce
            // "Discontinuo": non è una tipologia pubblica (oc:8463). Il
            // placeholder "Automatica" corrisponde a null = nessun override.
            Select::make(__('Route shape'), 'properties->attributes->'.LayerAttributesService::SHAPE_MANUAL_KEY)
                ->options([
                    RouteShape::LINEAR->value => RouteShape::LINEAR->label(),
                    RouteShape::ROUNDTRIP->value => RouteShape::ROUNDTRIP->label(),
                ])
                ->nullable()
                ->placeholder(__('Automatic'))
                ->resolveUsing(fn ($value) => is_string($value) ? $value : null)
                // Closure eseguita da Nova dopo il save del modello, come per
                // Portata/Stagioni. applyManualShape() scrive subito anche
                // `shape`, così la detail mostrata dopo il salvataggio è già
                // aggiornata senza aspettare la coda. Se `shape` è cambiato
                // serve rigenerare il config esplicitamente: la scrittura SQL
                // non fa scattare gli observer e il RecalculateLayerAttributesJob
                // accodato da LayerObserver troverà i valori già allineati
                // (calculatedValuesAreUnchanged → skip). afterCommit: la closure
                // gira dentro la transazione di Nova, il worker deve leggere
                // lo stato già committato.
                ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
                    $value = $request->input($requestAttribute);
                    $value = is_string($value) && $value !== '' ? $value : null;

                    return function () use ($model, $value) {
                        /** @var \Wm\WmPackage\Models\Layer $model */
                        if (app(LayerAttributesService::class)->applyManualShape($model, $value)) {
                            UpdateAppConfigJob::dispatch($model->app_id)->afterCommit();
                        }
                    };
                })
                ->help(__('Leave on Automatic to use the shape computed from the stages; choose a value to correct it manually.'))
                ->onlyOnForms()
                ->hideWhenCreating(),
```

- [ ] **Step 4: Aggiungi le traduzioni**

Aggiungi le due chiavi in ciascun file, mantenendo il JSON valido:

| File | `"Automatic"` | `"Leave on Automatic to use the shape computed from the stages; choose a value to correct it manually."` |
|---|---|---|
| `resources/lang/it.json` | `"Automatica"` | `"Lascia Automatica per usare la tipologia calcolata dalle tappe; scegli un valore per correggerla a mano."` |
| `resources/lang/en.json` | `"Automatic"` | `"Leave on Automatic to use the shape computed from the stages; choose a value to correct it manually."` |
| `resources/lang/fr.json` | `"Automatique"` | `"Laissez Automatique pour utiliser la typologie calculée à partir des étapes ; choisissez une valeur pour la corriger manuellement."` |
| `resources/lang/es.json` | `"Automática"` | `"Deja Automática para usar la tipología calculada a partir de las etapas; elige un valor para corregirla manualmente."` |
| `resources/lang/de.json` | `"Automatisch"` | `"Auf Automatisch lassen, um die aus den Etappen berechnete Streckenform zu verwenden; einen Wert wählen, um sie manuell zu korrigieren."` |

Verifica che `"Route shape"`, `"Linear"` e `"Roundtrip"` esistano già in tutti e cinque i file (`grep -c '"Route shape"\|"Linear"\|"Roundtrip"' resources/lang/*.json` → 3 per file); se mancano in qualche lingua, aggiungile.

- [ ] **Step 5: Esegui e verifica che passino, più la regressione**

Run: `docker exec laravel-camminiditalia php artisan test --filter='LayerShapeOverride|LayerAttributeFieldsTest|LayerAttributesStatePanelTest|LayerPolicyTest'`
Expected: PASS tutti.

- [ ] **Step 6: Commit (istruzione per il dev, dopo il review-gate)**

```bash
git add app/Nova/Layer.php resources/lang/*.json tests/Feature/LayerShapeOverrideFieldTest.php
git commit -m "feat(oc:8646): campo Route shape modificabile nel form di edit del layer"
```

---

### Task 4: Verifica finale

**Files:** nessuna modifica di codice attesa.

- [ ] **Step 1: Formattazione**

Run: `docker exec laravel-camminiditalia composer format`
Expected: nessun errore; eventuali file riformattati sono solo quelli toccati nei task 1-3.

- [ ] **Step 2: Suite completa**

Run: `docker exec laravel-camminiditalia php artisan test`
Expected: 0 falliti.

- [ ] **Step 3: PHPStan** (lo esegue il review-gate di wm-plan; qui solo per anticipare eventuali errori)

Run: `docker exec laravel-camminiditalia vendor/bin/phpstan analyse --error-format=table app/Services/LayerAttributesService.php app/Nova/Layer.php`
Expected: nessun errore nuovo.

- [ ] **Step 4: Verifica manuale in Nova (DB di sviluppo, con Horizon attivo)**

1. Apri in Nova il layer 56 "Cammino Minerario di Santa Barbara": la card "Route shape" mostra Lineare con l'alert "Discontinuous".
2. Edit → "Route shape" = Ad anello → Salva: la detail mostra subito "Ad anello" (alert ancora presente, scelta consapevole).
3. Dopo ~10 s: `GET /api/app/webapp/{app}/layer/56` e il `config.json` dell'App riportano `shape.value = "roundtrip"` e nessuna chiave `shape_manual`.
4. Esegui da Nova `RecalculateAppLayerAttributesAction` sull'App: il layer 56 resta "Ad anello".
5. Edit → "Route shape" = Automatica → Salva: la detail torna a "Lineare"; nel `config.json` rigenerato torna `linear`.
6. Lascia il layer 56 com'era prima della verifica (Automatica): l'override sui dati reali lo imposterà il cliente.

> Ticket: oc:8463

# RouteShape: esporre Discontinuo come Lineare al frontend, tenere l'alert solo in Nova — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Nessun commit o branch automatico:** i comandi `git commit`/`git push` mostrati in questo piano sono istruzioni testuali per lo sviluppatore, non azioni da eseguire autonomamente. La creazione del branch e l'esecuzione dei commit restano governate dal workflow Webmapp (`execution: branch`, `execution: review-gate`), non da questo piano.

**Goal:** Il valore pubblico `shape` di un Layer non deve mai contenere `discontinuous` (un cammino discontinuo va mostrato come `linear`); l'informazione di discontinuità resta visibile solo come alert in Nova, tramite un nuovo flag interno `shape_discontinuous` mai esposto ai consumer pubblici.

**Architecture:** Il calcolo (repo camminiditalia, `LayerAttributesService`) separa il valore pubblico (`shape`, sempre `linear`/`roundtrip`) dal flag interno (`shape_discontinuous`, booleano). Due canali di esposizione pubblica nel submodule `wm-package` (`AppConfigService::config_section_map()` per `config.json`, `AppController::layer()` per l'endpoint REST) fanno oggi passthrough integrale di `properties`: entrambi vengono corretti con un helper condiviso su `Wm\WmPackage\Models\Layer` che rimuove `shape_discontinuous` prima dell'esposizione. Nova (`App\Nova\Layer::renderShapeCard()`) legge il nuovo flag invece di derivare la discontinuità dal valore pubblico di `shape`.

**Tech Stack:** Laravel 12 / PHP 8.4 (camminiditalia, PHPUnit), Laravel/Nova (submodule wm-package, Pest), PostgreSQL/PostGIS.

**Spec:**
- `docs/features/8463-routeshape-discontinuo-come-lineare/overview.md` (repo principale)
- `wm-package/docs/features/8463-routeshape-discontinuo-come-lineare/overview.md` (submodule)

## Global Constraints

- Ordine di deploy obbligato: le Task 1-2 (wm-package) vanno mergiate e il submodule bumpato nel repo principale (Task 3) PRIMA che il repo principale inizi a scrivere `shape_discontinuous` (Task 4) — un ordine invertito lascia il flag esposto pubblicamente per la finestra intermedia (rischio già materializzato in team, episodio maphub).
- `shape_discontinuous` è un booleano semplice, mai tradotto (nessuna mappa multilingua `withTranslations()`).
- L'alert Nova resta visibile a tutti i ruoli che vedono la scheda Layer (Administrator e Validator/gestore) — nessuna nuova restrizione di ruolo.
- Nessun nuovo comando Artisan di ricalcolo bulk: si riusa `App\Nova\Actions\RecalculateAppLayerAttributesAction` esistente.
- PHP >8.1 minimo in wm-package: mai `const` dentro un trait (non rilevante qui, nessun trait toccato).
- `composer format` (Pint) in wm-package riformatta l'intero repo: controllare `git status` dopo e scartare file fuori scope.
- Commit convention: `fix(oc:8463): ...` in entrambi i repo.

---

## Fase A — wm-package (da mergiare e deployare PRIMA della Fase C)

### Task 1: Helper condiviso + esclusione in `AppConfigService::config_section_map()`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-helper-generico-invece-che-specifico-di-layer)

**Files:**
- Modify: `wm-package/src/Models/Layer.php` (fine della classe, prima della graffa di chiusura finale, riga 563)
- Modify: `wm-package/src/Services/Models/App/AppConfigService.php:372-379`
- Test: `wm-package/tests/Feature/AppConfigLayerShapeDiscontinuousTest.php` (nuovo file)

**Interfaces:**
- Produces: `Wm\WmPackage\Models\Layer::withoutInternalAttributes(array $attributes): array` — rimuove le chiavi di `properties->attributes` riservate a un consumo interno (oggi solo `shape_discontinuous`) da un array `attributes` già estratto, e lo restituisce. Usato anche dalla Task 2.

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `wm-package/tests/Feature/AppConfigLayerShapeDiscontinuousTest.php`:

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\App\AppConfigService;

it('never exposes shape_discontinuous in MAP.layers of config.json', function () {
    /** @var App $app */
    $app = App::factory()->create();

    Layer::factory()->create([
        'app_id' => $app->id,
        'properties' => [
            'attributes' => [
                'shape' => ['value' => 'linear', 'name' => ['it' => 'Lineare', 'en' => 'Linear']],
                'shape_discontinuous' => true,
            ],
        ],
    ]);

    $config = (new AppConfigService($app))->config();
    $layerItem = collect($config['MAP']['layers'])->first();

    expect($layerItem['attributes'])->toHaveKey('shape')
        ->and($layerItem['attributes'])->not->toHaveKey('shape_discontinuous');
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run (dentro il container `php-forestas`): `vendor/bin/pest tests/Feature/AppConfigLayerShapeDiscontinuousTest.php`
Expected: FAIL — `$layerItem['attributes']` contiene ancora `shape_discontinuous`.

- [ ] **Step 3: Aggiungi l'helper su `Layer`**

In `wm-package/src/Models/Layer.php`, subito prima della graffa finale di chiusura della classe (dopo il metodo che chiude con `'features' => $this->getAdditionalFeaturesForMap(),\n        ];\n    }`), aggiungi:

```php

    /**
     * Rimuove da un array properties->attributes già estratto le chiavi
     * destinate a un consumo esclusivamente interno (mai al frontend
     * pubblico). Punto unico usato da ogni serializzazione pubblica del
     * layer (AppConfigService::config_section_map(), AppController::layer()),
     * per evitare che un futuro flag interno vada escluso a mano in più
     * punti (oc:8463).
     */
    public static function withoutInternalAttributes(array $attributes): array
    {
        unset($attributes['shape_discontinuous']);

        return $attributes;
    }
```

- [ ] **Step 4: Applica l'helper in `config_section_map()`**

In `wm-package/src/Services/Models/App/AppConfigService.php`, il blocco attuale (righe 372-379) è:

```php
            foreach ($this->app->layers as $layer) {
                $item = $layer->toArray();
                if (isset($item['properties']) && is_array($item['properties'])) {
                    foreach ($item['properties'] as $key => $value) {
                        $item[$key] = $value;
                    }
                }
                unset($item['properties']);
```

Sostituiscilo con:

```php
            foreach ($this->app->layers as $layer) {
                $item = $layer->toArray();
                if (isset($item['properties']) && is_array($item['properties'])) {
                    foreach ($item['properties'] as $key => $value) {
                        $item[$key] = $value;
                    }
                }
                unset($item['properties']);
                if (isset($item['attributes']) && is_array($item['attributes'])) {
                    $item['attributes'] = Layer::withoutInternalAttributes($item['attributes']);
                }
```

`Layer` è già importato in questo file (`use Wm\WmPackage\Models\Layer;`, riga 14) — nessun nuovo import necessario.

- [ ] **Step 5: Esegui il test e verifica che passi**

Run: `vendor/bin/pest tests/Feature/AppConfigLayerShapeDiscontinuousTest.php`
Expected: PASS

- [ ] **Step 6: Formatta e verifica il diff**

```bash
composer format
git status
```
Verifica che `git status` mostri solo i file di questa task (`src/Models/Layer.php`, `src/Services/Models/App/AppConfigService.php`, il nuovo test) — Pint su questo repo riformatta l'intero albero, scarta ogni altro file toccato.

- [ ] **Step 7: Commit**

```bash
git add src/Models/Layer.php src/Services/Models/App/AppConfigService.php tests/Feature/AppConfigLayerShapeDiscontinuousTest.php
git commit -m "fix(oc:8463): escludi shape_discontinuous dal config.json esposto"
```

---

### Task 2: Esclusione nel secondo canale — `AppController::layer()`

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-helper-generico-invece-che-specifico-di-layer)

**Files:**
- Modify: `wm-package/src/Http/Controllers/Api/AppController.php:445-446`
- Test: `wm-package/tests/Feature/Api/AppLayerEndpointShapeDiscontinuousTest.php` (nuovo file)

**Interfaces:**
- Consumes: `Wm\WmPackage\Models\Layer::withoutInternalAttributes(array $attributes): array` (Task 1)

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `wm-package/tests/Feature/Api/AppLayerEndpointShapeDiscontinuousTest.php`:

```php
<?php

declare(strict_types=1);

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

it('never exposes shape_discontinuous from the layer() API endpoint', function () {
    /** @var App $app */
    $app = App::factory()->create();

    /** @var Layer $layer */
    $layer = Layer::factory()->create([
        'app_id' => $app->id,
        'properties' => [
            'attributes' => [
                'shape' => ['value' => 'linear', 'name' => ['it' => 'Lineare', 'en' => 'Linear']],
                'shape_discontinuous' => true,
            ],
        ],
    ]);

    $response = $this->getJson("/api/app/webapp/{$app->id}/layer/{$layer->id}");

    $response->assertOk();
    expect($response->json('properties.attributes'))->toHaveKey('shape')
        ->and($response->json('properties.attributes'))->not->toHaveKey('shape_discontinuous');
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `vendor/bin/pest tests/Feature/Api/AppLayerEndpointShapeDiscontinuousTest.php`
Expected: FAIL — `properties.attributes` nella risposta contiene ancora `shape_discontinuous`.

- [ ] **Step 3: Applica l'helper in `AppController::layer()`**

In `wm-package/src/Http/Controllers/Api/AppController.php`, il codice attuale (righe 445-446) è:

```php
        $json = [];
        $json = $layer->toArray();
```

Sostituiscilo con:

```php
        $json = [];
        $json = $layer->toArray();
        if (isset($json['properties']['attributes']) && is_array($json['properties']['attributes'])) {
            $json['properties']['attributes'] = Layer::withoutInternalAttributes($json['properties']['attributes']);
        }
```

`Layer` è già importato in questo file (`use Wm\WmPackage\Models\Layer;`, riga 11).

Nota sul nesting: a differenza di `config_section_map()` (Task 1), qui `toArray()` NON appiattisce `properties` — resta annidato (`$json['properties']['attributes']`), non `$json['attributes']`. Path diverso, stessa funzione helper.

- [ ] **Step 4: Esegui il test e verifica che passi**

Run: `vendor/bin/pest tests/Feature/Api/AppLayerEndpointShapeDiscontinuousTest.php`
Expected: PASS

- [ ] **Step 5: Esegui l'intera suite del package per verificare l'assenza di regressioni**

Run: `composer test`
Expected: tutti i test passano (in particolare `AppConfigServiceImportedPropertiesTest`, `AppLayerFiltersTest`, `AppConfigEndpointTest` — non toccati da questa task ma sullo stesso file).

- [ ] **Step 6: Formatta e verifica il diff**

```bash
composer format
git status
```

- [ ] **Step 7: Commit**

```bash
git add src/Http/Controllers/Api/AppController.php tests/Feature/Api/AppLayerEndpointShapeDiscontinuousTest.php
git commit -m "fix(oc:8463): escludi shape_discontinuous dall'endpoint layer()"
```

**A questo punto: aprire la PR di wm-package, farla mergiare e rilasciare, PRIMA di procedere alla Fase B.** Vedi vincolo di ordine in "Global Constraints".

---

## Fase B — bump del submodule nel repo principale

### Task 3: Aggiornare il puntatore del submodule wm-package

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-bump-submodule-mai-eseguito-come-task-a-s)

**Files:**
- Modify: `camminiditalia/wm-package` (gitlink del submodule)

**Interfaces:**
- Consumes: le modifiche mergiate in wm-package nelle Task 1-2 (deve essere già sul branch di wm-package usato in produzione/CI, non solo sul branch di lavoro locale).

- [ ] **Step 1: Verifica che il submodule punti al commit con le Task 1-2**

```bash
cd wm-package && git log --oneline -3 && cd ..
```
Expected: il commit più recente è quello del messaggio "fix(oc:8463): escludi shape_discontinuous dall'endpoint layer()" (Task 2) o un merge commit che lo contiene.

- [ ] **Step 2: Registra il bump nel repo principale**

```bash
git add wm-package
git commit -m "fix(oc:8463): aggiorna wm-package con l'esclusione di shape_discontinuous"
```

---

## Fase C — repo principale (camminiditalia)

### Task 4: `LayerAttributesService` non persiste mai `discontinuous` pubblicamente

**Files:**
- Modify: `app/Services/LayerAttributesService.php:92` (CALCULATED_KEYS)
- Modify: `app/Services/LayerAttributesService.php:624-627` (computeCalculatedValues)
- Test: `tests/Unit/Services/LayerAttributesServiceShapeDiscontinuousTest.php` (nuovo file)

**Interfaces:**
- Produces: `properties->attributes->shape` sempre `linear`/`roundtrip` (mai `discontinuous`); `properties->attributes->shape_discontinuous` (booleano, presente solo quando `true`) — consumato dalla Task 5.

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `tests/Unit/Services/LayerAttributesServiceShapeDiscontinuousTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Enums\RouteShape;
use App\Services\LayerAttributesService;
use Tests\TestCase;
use Wm\WmPackage\Models\Layer;

class LayerAttributesServiceShapeDiscontinuousTest extends TestCase
{
    public function test_discontinuous_topology_is_persisted_as_linear_with_internal_flag(): void
    {
        $service = new class extends LayerAttributesService
        {
            public function determineType(array $endpoints): ?RouteShape
            {
                return RouteShape::DISCONTINUOUS;
            }
        };

        $layer = new Layer();
        $values = $service->computeCalculatedValues($layer);

        $this->assertSame('linear', $values['shape']['value']);
        $this->assertTrue($values['shape_discontinuous']);
    }

    public function test_non_discontinuous_topology_never_sets_the_internal_flag(): void
    {
        $service = new class extends LayerAttributesService
        {
            public function determineType(array $endpoints): ?RouteShape
            {
                return RouteShape::ROUNDTRIP;
            }
        };

        $layer = new Layer();
        $values = $service->computeCalculatedValues($layer);

        $this->assertSame('roundtrip', $values['shape']['value']);
        $this->assertArrayNotHasKey('shape_discontinuous', $values);
    }
}
```

Nota: il test sovrascrive `determineType()` con un'anonima classe figlia per isolare la logica di persistenza dal calcolo geometrico reale (`trackEndpoints()`/`ST_Union`/chiamata HTTP osmfeatures) — coerente con lo scope del ticket (non tocca la rilevazione della discontinuità, solo cosa viene persistito).

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `docker exec laravel-camminiditalia php artisan test tests/Unit/Services/LayerAttributesServiceShapeDiscontinuousTest.php`
Expected: FAIL — `$values['shape']['value']` vale ancora `'discontinuous'`, `$values['shape_discontinuous']` non esiste.

- [ ] **Step 3: Aggiorna `CALCULATED_KEYS`**

In `app/Services/LayerAttributesService.php:92`, sostituisci:

```php
    public const CALCULATED_KEYS = ['distance', 'stage_count', 'shape', 'taxonomy_where', 'themes'];
```

con:

```php
    public const CALCULATED_KEYS = ['distance', 'stage_count', 'shape', 'shape_discontinuous', 'taxonomy_where', 'themes'];
```

- [ ] **Step 4: Aggiorna `computeCalculatedValues()`**

In `app/Services/LayerAttributesService.php:624-627`, sostituisci:

```php
        $shape = $this->determineType($this->trackEndpoints($layer));
        if ($shape !== null) {
            $values['shape'] = $this->withTranslations($shape->value, fn (string $locale) => $shape->labelIn($locale));
        }
```

con:

```php
        $shape = $this->determineType($this->trackEndpoints($layer));
        if ($shape !== null) {
            $publicShape = $shape === RouteShape::DISCONTINUOUS ? RouteShape::LINEAR : $shape;
            $values['shape'] = $this->withTranslations($publicShape->value, fn (string $locale) => $publicShape->labelIn($locale));

            if ($shape === RouteShape::DISCONTINUOUS) {
                $values['shape_discontinuous'] = true;
            }
        }
```

`RouteShape` è già importato in questo file (`use App\Enums\RouteShape;`, riga 5).

- [ ] **Step 5: Esegui il test e verifica che passi**

Run: `docker exec laravel-camminiditalia php artisan test tests/Unit/Services/LayerAttributesServiceShapeDiscontinuousTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/LayerAttributesService.php tests/Unit/Services/LayerAttributesServiceShapeDiscontinuousTest.php
git commit -m "fix(oc:8463): non persistere mai discontinuous nel valore pubblico shape"
```

---

### Task 5: L'alert Nova legge il nuovo flag, non più il valore pubblico

**Files:**
- Modify: `app/Nova/Layer.php:327-345` (buildAttributesStateHtml)
- Modify: `app/Nova/Layer.php:452-476` (renderShapeCard)
- Modify: `tests/Feature/LayerAttributesStatePanelTest.php:132-157` (test esistente)

**Interfaces:**
- Consumes: `properties->attributes->shape_discontinuous` (Task 4)

- [ ] **Step 1: Aggiorna il test esistente perché rifletta il nuovo schema (rosso atteso)**

In `tests/Feature/LayerAttributesStatePanelTest.php`, sostituisci il metodo `test_layer_with_discontinuous_type_shows_value_with_explanation` (righe 132-157) con:

```php
    public function test_layer_with_discontinuous_type_shows_value_with_explanation(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Administrator');

        $layer = LayerModel::factory()->create([
            'user_id' => $owner->id,
            'properties' => [
                'attributes' => [
                    'shape' => [
                        'value' => 'linear',
                        'name' => ['it' => 'Lineare', 'en' => 'Linear'],
                    ],
                    'shape_discontinuous' => true,
                ],
            ],
        ]);

        $html = $this->buildHtmlFor($layer);

        $this->assertStringContainsString('Discontinuous', $html);
        $this->assertStringContainsString('the route segments are not connected to each other', $html);
        // "Discontinuo" è un valore vero, non un'assenza: non deve comparire
        // vicino al badge/testo di "non calcolabile" usato dagli altri
        // filtri (distanza/durata/regioni) quando manca il dato.
        $this->assertStringNotContainsString('Tipologia</span></div><div style="font-size:14px;color:#0f172a;line-height:1.5;word-break:break-word;"><span style="color:#92400e;font-weight:600;">non calcolabile', $html);
    }
```

Nota: il layer ora ha `shape=linear` (mai `discontinuous`, coerente con la Task 4) + `shape_discontinuous=true`; le asserzioni sull'HTML restano identiche perché il testo del badge — vedi Step 3 sotto — userà `__('Discontinuous')` a prescindere dal valore ora sempre "pubblico" di `shape`.

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `docker exec laravel-camminiditalia php artisan test --filter=test_layer_with_discontinuous_type_shows_value_with_explanation`
Expected: FAIL — con il codice attuale, `renderShapeCard()` decide il badge dal valore di `shape` (ora `'linear'`), quindi mostra `<strong>Linear</strong>` senza la spiegazione di discontinuità.

- [ ] **Step 3: Aggiorna `buildAttributesStateHtml()` per passare anche il nuovo flag**

In `app/Nova/Layer.php:340`, sostituisci:

```php
            $this->renderShapeCard($routeAttributes['shape'] ?? null),
```

con:

```php
            $this->renderShapeCard($routeAttributes['shape'] ?? null, (bool) ($routeAttributes['shape_discontinuous'] ?? false)),
```

- [ ] **Step 4: Aggiorna `renderShapeCard()` per decidere il warning dal flag, non dall'enum**

In `app/Nova/Layer.php:452-476`, il codice attuale è:

```php
    private function renderShapeCard(mixed $type): string
    {
        $type = self::attributeCode($type);

        if ($type === null) {
            return $this->renderCard(__('Route shape'), 'warn', $this->notCalculableHtml(__('not computable: no stages associated')));
        }

        $enum = RouteShape::tryFrom($type);

        if ($enum === null) {
            return $this->renderCard(__('Route shape'), 'error', $this->unrecognizedHtml($type));
        }

        if ($enum === RouteShape::DISCONTINUOUS) {
            $valueHtml = '<strong>'.htmlspecialchars($enum->label(), ENT_QUOTES, 'UTF-8').'</strong>'
                .'<br><span style="color:#78716c;font-size:12px;">'
                .htmlspecialchars(__('the route segments are not connected to each other'), ENT_QUOTES, 'UTF-8')
                .'</span>';

            return $this->renderCard(__('Route shape'), 'warn', $valueHtml);
        }

        return $this->renderCard(__('Route shape'), 'ok', '<strong>'.htmlspecialchars($enum->label(), ENT_QUOTES, 'UTF-8').'</strong>');
    }
```

Sostituiscilo con:

```php
    private function renderShapeCard(mixed $type, bool $isDiscontinuous): string
    {
        $type = self::attributeCode($type);

        if ($type === null) {
            return $this->renderCard(__('Route shape'), 'warn', $this->notCalculableHtml(__('not computable: no stages associated')));
        }

        $enum = RouteShape::tryFrom($type);

        if ($enum === null) {
            return $this->renderCard(__('Route shape'), 'error', $this->unrecognizedHtml($type));
        }

        if ($isDiscontinuous) {
            $valueHtml = '<strong>'.htmlspecialchars(__('Discontinuous'), ENT_QUOTES, 'UTF-8').'</strong>'
                .'<br><span style="color:#78716c;font-size:12px;">'
                .htmlspecialchars(__('the route segments are not connected to each other'), ENT_QUOTES, 'UTF-8')
                .'</span>';

            return $this->renderCard(__('Route shape'), 'warn', $valueHtml);
        }

        return $this->renderCard(__('Route shape'), 'ok', '<strong>'.htmlspecialchars($enum->label(), ENT_QUOTES, 'UTF-8').'</strong>');
    }
```

Design: il badge di warning usa sempre la label letterale `__('Discontinuous')` (già tradotta in `resources/lang/it.json`/`en.json`, invariata da questo ticket), non `$enum->label()` — perché `$type`/`$enum` ora vale sempre `linear`/`roundtrip` (mai più `discontinuous`, Task 4): usare `$enum->label()` qui mostrerebbe "Linear" insieme al testo "i tratti non sono collegati fra loro", un messaggio contraddittorio per chi deve intervenire sui dati. Questo mantiene il testo del badge identico a quello di oggi, senza più dipendere dal valore ora sempre pubblico di `shape`.

- [ ] **Step 5: Esegui il test e verifica che passi**

Run: `docker exec laravel-camminiditalia php artisan test --filter=test_layer_with_discontinuous_type_shows_value_with_explanation`
Expected: PASS

- [ ] **Step 6: Esegui l'intera suite `LayerAttributesStatePanelTest` per verificare l'assenza di regressioni sugli altri casi**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/LayerAttributesStatePanelTest.php`
Expected: tutti i test passano, incluso `test_layer_with_all_filters_populated_shows_formatted_values` (shape=roundtrip, nessun flag discontinuo — deve continuare a mostrare "Roundtrip" col badge "ok").

- [ ] **Step 7: Commit**

```bash
git add app/Nova/Layer.php tests/Feature/LayerAttributesStatePanelTest.php
git commit -m "fix(oc:8463): l'alert Nova di discontinuita' legge il nuovo flag interno"
```

---

### Task 6: Test di integrazione end-to-end sul `config.json` finale

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-blocco-ambientale-reale-non-del-test)

**Files:**
- Test: `tests/Feature/LayerConfigJsonShapeDiscontinuousTest.php` (nuovo file)

**Interfaces:**
- Consumes: `LayerAttributesService::computeCalculatedValues()` (Task 4), `AppConfigService::config_section_map()` con l'esclusione applicata (Task 1, disponibile solo dopo il bump del submodule in Task 3).

Questo test copre esplicitamente l'accoppiamento cross-repo sulla stringa `shape_discontinuous` duplicata tra camminiditalia (che la scrive) e wm-package (che la esclude): se un rename/typo disallinea i due lati, questo test fallisce invece di lasciare la CI verde con la produzione rotta (rischio trovato in Fase: challenge).

- [ ] **Step 1: Verifica che il submodule sia aggiornato (precondizione)**

```bash
cd wm-package && git log --oneline -1 && cd ..
```
Expected: mostra il commit di Task 2 ("fix(oc:8463): escludi shape_discontinuous dall'endpoint layer()") o un discendente — se non lo mostra, la Task 3 non è stata completata: questo task non può procedere.

- [ ] **Step 2: Scrivi il test che fallisce (se il fix di Task 4 non fosse presente)**

Crea `tests/Feature/LayerConfigJsonShapeDiscontinuousTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\LayerAttributesService;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer as LayerModel;
use Wm\WmPackage\Services\Models\App\AppConfigService;

class LayerConfigJsonShapeDiscontinuousTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stesso motivo di LayerAttributesStatePanelTest::setUp(): evita che
        // il RecalculateLayerAttributesJob accodato da LayerObserver::saved()
        // sovrascriva, con un worker reale in ascolto, i valori impostati a
        // mano da questo test.
        Queue::fake();
    }

    public function test_a_discontinuous_layer_never_exposes_shape_discontinuous_in_config_json(): void
    {
        $app = App::factory()->create();

        $layer = LayerModel::factory()->create([
            'app_id' => $app->id,
            'properties' => [],
        ]);

        $service = new class extends LayerAttributesService
        {
            public function determineType(array $endpoints): ?\App\Enums\RouteShape
            {
                return \App\Enums\RouteShape::DISCONTINUOUS;
            }
        };

        $values = $service->computeCalculatedValues($layer);
        $service->persistCalculatedValues($layer, $values);
        $layer->refresh();

        $config = (new AppConfigService($app))->config();
        $layerItem = collect($config['MAP']['layers'])->firstWhere('id', $layer->id);

        $this->assertSame('linear', $layerItem['attributes']['shape']['value']);
        $this->assertArrayNotHasKey('shape_discontinuous', $layerItem['attributes']);
    }
}
```

- [ ] **Step 3: Esegui il test e verifica che passi**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/LayerConfigJsonShapeDiscontinuousTest.php`
Expected: PASS (dato che Task 1-2-4 sono già implementate nei rispettivi repo). Se fallisce con `shape_discontinuous` ancora presente in `$layerItem['attributes']`, verifica prima di tutto che il submodule sia stato bumpato al commit corretto (Step 1).

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/LayerConfigJsonShapeDiscontinuousTest.php
git commit -m "fix(oc:8463): test end-to-end su config.json per layer discontinui"
```

---

## Fase D — Procedura operativa post-deploy (non codice)

### Task 7: Ricalcolo dei 118 layer esistenti e verifica

Da eseguire dopo che sia wm-package (Fase A) sia camminiditalia (Fase C) sono stati deployati in produzione, nell'ordine indicato nei "Global Constraints".

- [ ] **Step 1: Lancia il ricalcolo da Nova**

Da Nova, sulla risorsa `App` (l'unica App del progetto), esegui l'azione bulk `RecalculateAppLayerAttributesAction` ("Recalculate layer attributes" o label equivalente in UI). Questa dispatcha un `RecalculateLayerAttributesJob` per ciascuno dei 118 layer più un `UpdateAppConfigJob` finale.

- [ ] **Step 2: Attendi il completamento di tutti i job di ricalcolo**

Da Horizon (`docker exec laravel-camminiditalia php artisan horizon:status` o dashboard `/horizon`), verifica che la coda usata da `RecalculateLayerAttributesJob` sia tornata a zero job in attesa/in esecuzione. Non procedere allo Step 3 prima di questa conferma: `UpdateAppConfigJob` viene dispatchato senza attendere gli altri job (race condition preesistente, vedi overview → Rischi), quindi una verifica troppo anticipata può dare un falso negativo.

- [ ] **Step 3: Verifica che nessun layer sia rimasto con `shape=discontinuous`**

```bash
docker exec laravel-camminiditalia php artisan tinker --execute="var_dump(\DB::table('layers')->whereRaw(\"properties->'attributes'->>'shape' = 'discontinuous'\")->pluck('id')->all());"
```
Expected: array vuoto (`array(0) {}`).

- [ ] **Step 4: Se lo Step 3 trova residui, rilancia manualmente la rigenerazione del config**

```bash
docker exec laravel-camminiditalia php artisan tinker --execute="\Wm\WmPackage\Jobs\UpdateAppConfigJob::dispatch(\Wm\WmPackage\Models\App::first()->id);"
```
Poi ripeti lo Step 3 dopo che il job è stato processato (verifica su Horizon).

---

## Self-Review

**Copertura spec:**
- Requisiti 1-8 e 11-12 del briefing → coperti da Task 1, 2, 4, 5, 7.
- Requisito 6 (test end-to-end cross-repo) → Task 6.
- Requisito 9/10 wm-package (nessun impatto sulle altre chiavi, test sul nesting reale) → verificato dagli `expect(...)->toHaveKey('shape')` nei test delle Task 1-2 (le altre chiavi di `attributes` non vengono toccate dall'helper, che fa solo `unset()` mirato).
- Vincolo di ordine di deploy → sezione "Global Constraints" + nota esplicita a fine Fase A.
- Procedura di ricalcolo + verifica → Task 7, come da overview (non codice).

**Scansione placeholder:** nessun "TBD"/"da definire" nei passi di codice; ogni step ha snippet completo o comando eseguibile.

**Coerenza dei tipi/nomi:** `Layer::withoutInternalAttributes(array $attributes): array` — stesso nome e firma in Task 1 (produce) e Task 2 (consuma). `renderShapeCard(mixed $type, bool $isDiscontinuous): string` — stessa firma nel punto di modifica (Step 4) e nel punto di chiamata aggiornato (Step 3), entrambi in Task 5.

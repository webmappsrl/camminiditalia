> Ticket: oc:8180

# Filtri avanzati sui cammini — Piano camminiditalia (backend)

> **Documento storico — la nomenclatura qui è superata.** Questo è il piano approvato
> prima dell'implementazione. Durante il lavoro sono stati rinominati: `properties->filters`
> → `properties->attributes`, `type` → `shape`, `duration` → `stage_count`, `network` →
> `walking_network`, il valore `loop` → `roundtrip`, e le classi `*Filters*` → `*Attributes*`.
> Sono anche cambiate la forma dei valori (ora `{value, name}` con le traduzioni) e quella
> di `taxonomy_where` (`[{value, name}]`, sole regioni). **Il contratto valido è in
> `overview.md`**; le deviazioni sono elencate in `notes.md`. Non è stato riscritto per
> conservare la tracciabilità di ciò che era stato approvato.


> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **⚠️ NESSUN commit e NESSUN branch automatico.** I comandi `git` presenti sono istruzioni testuali per il developer: non eseguirli. La fase di commit è gestita dal developer dopo la review del diff.

**Goal:** Calcolare e persistere in `properties->filters` di ogni `Layer` i valori di filtro di un cammino (lunghezza, numero tappe, tipologia anello/lineare, regioni attraversate) e permettere la scelta manuale di Portata, Stagioni e Temi in Nova.

**Architecture:** Un service locale calcola i valori aggregando le `EcTrack` associate al layer; un job locale in coda orchestra il calcolo, scrive con un solo `jsonb_set` e rigenera la config dell'app; due observer locali dispatchano il job quando cambia l'associazione tracce↔layer o la geometria di una traccia; una Nova Action sull'App accoda il ricalcolo di tutti i suoi layer.

**Tech Stack:** Laravel 11 + PostgreSQL/PostGIS, Nova 5, code Redis/Horizon, enum da `wm-package`.

**Spec:** `docs/features/8180-filtri-avanzati-sui-cammini/overview.md` (e, per gli enum, `wm-package/docs/features/8180-filtri-avanzati-sui-cammini/overview.md`)

## Global Constraints

- **Tutti i comandi PHP vanno nel container:** `docker exec laravel-camminiditalia php artisan ...`
- **La geometria del `Layer` NON è utilizzabile**: `layers.geometry` è un `polygon` che rappresenta il *bounding box* del layer ed è nullable. Ogni calcolo geometrico usa la geometria delle `EcTrack` associate (`multiLineStringz` reali).
- **Non usare il job `UpdateModelWithGeometryTaxonomyWhere`** del package: fa `saveQuietly()` sul modello ricevuto, quindi con un modello temporaneo inserirebbe una riga. Si chiama direttamente `OsmfeaturesClient::getWheresByGeojson()`.
- **Non modificare `wm-package`** oltre ai due enum previsti nel piano del package.
- **Scrittura su `properties` solo via `jsonb_set` in SQL**, mai con `$layer->save()`/`saveQuietly()`: sulla stessa colonna scrivono già `setNameAttribute`, l'override di `Layer::save()`, le traduzioni Spatie di `title`/`subtitle`/`description` e job del package, tutti in read-modify-write dell'intero blob. Un salvataggio Eloquent può cancellare traduzioni redazionali non ricalcolabili. La colonna è `jsonb` (verificato in migration e in DB). **Precedente da rispecchiare:** `Layer::setTrackMode()`/`setPoiMode()` in `wm-package/src/Models/Layer.php` (oc:8314) usano già `DB::statement` + `jsonb_set` + `refresh()` per lo stesso motivo — leggerle prima di scrivere il Task 4.
- **Guardia sui test (oc:8092):** `tests/TestCase::refreshApplication()` lancia un'eccezione se il DB attivo non è `camminiditalia_testing`. Se compare quell'errore, non è un bug del task: eseguire `docker exec laravel-camminiditalia php artisan config:clear` (config cache stantia).
- **Nessun filtro su `user_id`** nel calcolo: 35 layer su 118 hanno tracce con owner diverso dal proprietario del layer (bug noto oc:8314) e devono comunque avere valori corretti.
- **`layerable_type` in DB è `App\Models\EcTrack`** (chiave della morph map, da `config('wm-package.ec_track_model')` che vale `App\Models\EcTrack`): usare sempre `config('wm-package.ec_track_model', 'App\Models\EcTrack')` nelle query raw sul pivot, mai `::class` del modello del package.
- **Chiavi assenti, non valori di default:** se un valore non è calcolabile (nessuna tappa associata, geometrie nulle, topologia anomala) la relativa chiave **non** viene scritta in `filters`. Un layer vuoto non deve comparire in un filtro "0-10 km".
- **Tolleranza di chiusura geometrica:** `0.001` gradi (≈111 m in latitudine, ≈82 m in longitudine a 42°N) — stesso valore hardcoded in `GeometryComputationService::isRoundtrip()`, il cui commento "300 metri" è errato. Definita come costante del service locale, non nel package.
- **Identificatori di `type` in inglese** (`loop`/`linear`), coerenti con i valori degli enum `Season`/`OsmWalkingNetwork`; la resa in italiano ("Anello"/"Lineare") è responsabilità del consumer/UI, non del dato persistito.
- **Enum letti sempre con `tryFrom()`**, mai `from()`: un valore legacy o corrotto in `properties` non deve far esplodere il detail Nova con `ValueError`.

---

### Task 1: Service — lunghezza totale e numero di tappe

**Files:**
- Create: `app/Services/LayerFilterValuesService.php`
- Test: `tests/Feature/LayerFilterValuesServiceTest.php`

**Interfaces:**
- Consumes: nulla
- Produces:
  - `App\Services\LayerFilterValuesService::totalDistance(Layer $layer): ?float`
  - `App\Services\LayerFilterValuesService::stageCount(Layer $layer): ?int`

- [ ] **Step 1: Scrivere i test che falliscono**

In `tests/Feature/LayerFilterValuesServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Services\LayerFilterValuesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerFilterValuesServiceTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_total_distance_sums_distances_of_all_associated_tracks(): void
    {
        $layer = $this->createLayer();

        $first = EcTrack::factory()->create([
            'properties' => ['manual_data' => ['distance' => 10.5]],
        ]);
        $second = EcTrack::factory()->create([
            'properties' => ['manual_data' => ['distance' => 4.5]],
        ]);
        $layer->ecTracks()->attach([$first->id, $second->id]);

        $this->assertSame(15.0, app(LayerFilterValuesService::class)->totalDistance($layer->fresh()));
    }

    public function test_total_distance_ignores_track_ownership(): void
    {
        $layer = $this->createLayer();
        $otherOwnerTrack = EcTrack::factory()->create([
            'user_id' => 99999,
            'properties' => ['manual_data' => ['distance' => 7.0]],
        ]);
        $layer->ecTracks()->attach($otherOwnerTrack->id);

        $this->assertSame(7.0, app(LayerFilterValuesService::class)->totalDistance($layer->fresh()));
    }

    public function test_total_distance_is_null_when_layer_has_no_tracks(): void
    {
        $layer = $this->createLayer();

        $this->assertNull(app(LayerFilterValuesService::class)->totalDistance($layer));
    }

    public function test_stage_count_returns_number_of_associated_tracks(): void
    {
        $layer = $this->createLayer();
        $tracks = EcTrack::factory()->count(3)->create(['properties' => []]);
        $layer->ecTracks()->attach($tracks->pluck('id')->toArray());

        $this->assertSame(3, app(LayerFilterValuesService::class)->stageCount($layer->fresh()));
    }

    public function test_stage_count_is_null_when_layer_has_no_tracks(): void
    {
        $layer = $this->createLayer();

        $this->assertNull(app(LayerFilterValuesService::class)->stageCount($layer));
    }
}
```

- [ ] **Step 2: Eseguire i test per verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/LayerFilterValuesServiceTest.php`
Expected: FAIL con `Class "App\Services\LayerFilterValuesService" not found`

- [ ] **Step 3: Creare il service con i due metodi**

In `app/Services/LayerFilterValuesService.php`:

```php
<?php

namespace App\Services;

use Wm\WmPackage\Models\Layer;

/**
 * Calcola i valori di filtro di un cammino (Layer) aggregando le sue tappe
 * (EcTrack associate via pivot layerables).
 *
 * Specifico di camminiditalia: qui un Layer È un cammino, cioè un'unica
 * lunga traccia suddivisa in tappe. Le primitive generiche riusabili
 * (enum, verifica di chiusura geometrica) vivono in wm-package.
 */
class LayerFilterValuesService
{
    /**
     * Somma delle distanze delle tappe, in km.
     *
     * Nessun filtro su user_id: la lunghezza è una proprietà oggettiva del
     * cammino, indipendente da chi possiede le singole tracce (35 layer su
     * 118 hanno tracce con owner diverso — bug noto oc:8314).
     */
    public function totalDistance(Layer $layer): ?float
    {
        $tracks = $layer->ecTracks()->get();

        if ($tracks->isEmpty()) {
            return null;
        }

        $total = 0.0;
        foreach ($tracks as $track) {
            $total += (float) ($track->classifyField($track, 'distance')['currentValue'] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * Numero di tappe del cammino.
     *
     * La "Durata" del filtro è il numero di tappe (una tappa = una
     * giornata di cammino), non il tempo di percorrenza: i valori
     * duration_forward/backward delle EcTrack stanno su un pivot
     * per-attività come stringhe e sono distinti per senso di marcia,
     * quindi non aggregabili in modo affidabile.
     */
    public function stageCount(Layer $layer): ?int
    {
        $count = $layer->ecTracks()->count();

        return $count > 0 ? $count : null;
    }
}
```

- [ ] **Step 4: Eseguire i test per verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/LayerFilterValuesServiceTest.php`
Expected: PASS (5 test)

- [ ] **Step 5: Commit** *(istruzione per il developer — non eseguire)*

```bash
git add app/Services/LayerFilterValuesService.php tests/Feature/LayerFilterValuesServiceTest.php
git commit -m "feat(oc:8180): compute layer total distance and stage count"
```

---

### Task 2: Service — estremi delle tappe e tipologia anello/lineare

**Files:**
- Modify: `app/Services/LayerFilterValuesService.php`
- Test: `tests/Feature/LayerFilterValuesTypeTest.php`

**Interfaces:**
- Consumes: `LayerFilterValuesService` (Task 1)
- Produces:
  - costanti `LayerFilterValuesService::TYPE_LOOP = 'loop'`, `TYPE_LINEAR = 'linear'`, `CLOSURE_TOLERANCE_DEGREES = 0.001`
  - `trackEndpoints(Layer $layer): array` — lista di `['track_id' => int, 'start' => [float,float], 'end' => [float,float]]`
  - `determineType(array $endpoints): ?string` — `'loop'`, `'linear'` o `null` (topologia anomala)

**Perché così:** non esiste alcun ordinamento delle tappe (`layerables` non ha colonna d'ordine, `ecTracks()` non applica `orderBy`, `ec_tracks` non ha `order`/`stage` — verificato). L'inizio e la fine del cammino si determinano quindi topologicamente: un cammino lineare ha esattamente **due estremi liberi** (estremi di tappa che non combaciano con l'estremo di nessun'altra tappa), un anello **zero**. I due estremi liberi vengono poi passati a `isRoundtrip()` del package, che riceve così due soli punti e non incappa nel proprio difetto sulle geometrie multi-parte.

- [ ] **Step 1: Scrivere i test che falliscono**

In `tests/Feature/LayerFilterValuesTypeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\LayerFilterValuesService;
use Tests\TestCase;

class LayerFilterValuesTypeTest extends TestCase
{
    private function service(): LayerFilterValuesService
    {
        return app(LayerFilterValuesService::class);
    }

    public function test_single_track_with_coincident_ends_is_a_loop(): void
    {
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.0, 43.0]],
        ];

        $this->assertSame(LayerFilterValuesService::TYPE_LOOP, $this->service()->determineType($endpoints));
    }

    public function test_single_track_with_distant_ends_is_linear(): void
    {
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [12.5, 41.9]],
        ];

        $this->assertSame(LayerFilterValuesService::TYPE_LINEAR, $this->service()->determineType($endpoints));
    }

    public function test_chain_of_three_tracks_is_linear(): void
    {
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]],
            ['track_id' => 2, 'start' => [11.2, 42.8], 'end' => [11.5, 42.5]],
            ['track_id' => 3, 'start' => [11.5, 42.5], 'end' => [12.5, 41.9]],
        ];

        $this->assertSame(LayerFilterValuesService::TYPE_LINEAR, $this->service()->determineType($endpoints));
    }

    public function test_closed_chain_of_three_tracks_is_a_loop(): void
    {
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]],
            ['track_id' => 2, 'start' => [11.2, 42.8], 'end' => [11.5, 42.5]],
            ['track_id' => 3, 'start' => [11.5, 42.5], 'end' => [11.0, 43.0]],
        ];

        $this->assertSame(LayerFilterValuesService::TYPE_LOOP, $this->service()->determineType($endpoints));
    }

    public function test_endpoints_within_tolerance_are_treated_as_joined(): void
    {
        // 0.0005 gradi di scarto: sotto la tolleranza di 0.001
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]],
            ['track_id' => 2, 'start' => [11.2005, 42.8005], 'end' => [12.5, 41.9]],
        ];

        $this->assertSame(LayerFilterValuesService::TYPE_LINEAR, $this->service()->determineType($endpoints));
    }

    public function test_disconnected_tracks_produce_null_type(): void
    {
        // Due tappe che non si toccano: 4 estremi liberi, topologia anomala
        $endpoints = [
            ['track_id' => 1, 'start' => [11.0, 43.0], 'end' => [11.2, 42.8]],
            ['track_id' => 2, 'start' => [9.0, 45.0], 'end' => [9.5, 45.5]],
        ];

        $this->assertNull($this->service()->determineType($endpoints));
    }

    public function test_empty_endpoints_produce_null_type(): void
    {
        $this->assertNull($this->service()->determineType([]));
    }
}
```

- [ ] **Step 2: Eseguire i test per verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/LayerFilterValuesTypeTest.php`
Expected: FAIL con `Undefined constant LayerFilterValuesService::TYPE_LOOP`

- [ ] **Step 3: Aggiungere costanti, `trackEndpoints()` e `determineType()` al service**

Aggiungere in cima alla classe `LayerFilterValuesService`, dopo l'apertura:

```php
    public const TYPE_LOOP = 'loop';

    public const TYPE_LINEAR = 'linear';

    /**
     * Tolleranza con cui due estremi si considerano coincidenti.
     *
     * 0.001 gradi ≈ 111 m in latitudine e ≈ 82 m in longitudine a 42°N.
     * Stesso valore usato internamente da
     * GeometryComputationService::isRoundtrip(), il cui commento
     * "diff < 300 metri" è errato.
     */
    public const CLOSURE_TOLERANCE_DEGREES = 0.001;
```

Aggiungere i metodi (import necessari in testa al file: `use Illuminate\Support\Facades\DB;` e `use Wm\WmPackage\Services\GeometryComputationService;`):

```php
    /**
     * Estremi (primo e ultimo punto) di ogni tappa del cammino.
     *
     * Usa ST_GeometryN invece di ST_LineMerge: il merge di tappe non
     * contigue restituirebbe ancora una MultiLineString, rendendo
     * ST_StartPoint nullo. Le geometrie sono forzate a 2D (le EcTrack
     * sono multiLineStringz).
     *
     * @return array<int, array{track_id: int, start: array{0: float, 1: float}, end: array{0: float, 1: float}}>
     */
    public function trackEndpoints(Layer $layer): array
    {
        $trackType = config('wm-package.ec_track_model', 'App\Models\EcTrack');

        $rows = DB::select(
            'SELECT id,
                    ST_X(ST_StartPoint(ST_GeometryN(g, 1))) AS start_x,
                    ST_Y(ST_StartPoint(ST_GeometryN(g, 1))) AS start_y,
                    ST_X(ST_EndPoint(ST_GeometryN(g, ST_NumGeometries(g)))) AS end_x,
                    ST_Y(ST_EndPoint(ST_GeometryN(g, ST_NumGeometries(g)))) AS end_y
             FROM (
                SELECT t.id, ST_Force2D(t.geometry::geometry) AS g
                FROM ec_tracks t
                JOIN layerables l
                  ON l.layerable_id = t.id
                 AND l.layerable_type = ?
                WHERE l.layer_id = ?
                  AND t.geometry IS NOT NULL
             ) AS tracks',
            [$trackType, $layer->id]
        );

        $endpoints = [];
        foreach ($rows as $row) {
            if ($row->start_x === null || $row->start_y === null || $row->end_x === null || $row->end_y === null) {
                continue;
            }

            $endpoints[] = [
                'track_id' => (int) $row->id,
                'start' => [(float) $row->start_x, (float) $row->start_y],
                'end' => [(float) $row->end_x, (float) $row->end_y],
            ];
        }

        return $endpoints;
    }

    /**
     * Tipologia del cammino: anello o lineare.
     *
     * Determinazione topologica, indipendente dall'ordine delle tappe
     * (che nel DB non esiste): un estremo è "libero" se non combacia con
     * l'estremo di nessun'altra tappa. Zero estremi liberi = anello; due
     * = i veri capi del cammino, confrontati con isRoundtrip(); qualunque
     * altro numero indica buchi o diramazioni, e restituisce null perché
     * la tipologia non è determinabile.
     *
     * @param  array<int, array{track_id: int, start: array{0: float, 1: float}, end: array{0: float, 1: float}}>  $endpoints
     */
    public function determineType(array $endpoints): ?string
    {
        if ($endpoints === []) {
            return null;
        }

        $points = [];
        foreach ($endpoints as $endpoint) {
            $points[] = ['track_id' => $endpoint['track_id'], 'coord' => $endpoint['start']];
            $points[] = ['track_id' => $endpoint['track_id'], 'coord' => $endpoint['end']];
        }

        $free = [];
        foreach ($points as $index => $point) {
            $joined = false;
            foreach ($points as $otherIndex => $other) {
                if ($index === $otherIndex || $point['track_id'] === $other['track_id']) {
                    continue;
                }
                if ($this->coordinatesMatch($point['coord'], $other['coord'])) {
                    $joined = true;
                    break;
                }
            }
            if (! $joined) {
                $free[] = $point['coord'];
            }
        }

        if ($free === []) {
            return self::TYPE_LOOP;
        }

        if (count($free) !== 2) {
            return null;
        }

        return GeometryComputationService::make()->isRoundtrip([$free[0], $free[1]])
            ? self::TYPE_LOOP
            : self::TYPE_LINEAR;
    }

    private function coordinatesMatch(array $first, array $second): bool
    {
        return abs($first[0] - $second[0]) < self::CLOSURE_TOLERANCE_DEGREES
            && abs($first[1] - $second[1]) < self::CLOSURE_TOLERANCE_DEGREES;
    }
```

- [ ] **Step 4: Eseguire i test per verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/LayerFilterValuesTypeTest.php`
Expected: PASS (7 test)

- [ ] **Step 5: Verificare `trackEndpoints()` su dati reali**

Run:
```bash
docker exec laravel-camminiditalia php artisan tinker --execute="\$l = \Wm\WmPackage\Models\Layer::whereHas('ecTracks')->first(); print_r(app(\App\Services\LayerFilterValuesService::class)->trackEndpoints(\$l)); echo app(\App\Services\LayerFilterValuesService::class)->determineType(app(\App\Services\LayerFilterValuesService::class)->trackEndpoints(\$l)) ?? 'NULL';"
```
Expected: una lista di estremi con coordinate plausibili (longitudine ~6-19, latitudine ~36-47 per l'Italia) e un tipo. **Annotare quanti layer reali producono `NULL`**: se sono molti, la tolleranza va rivalutata con il developer prima di procedere (rischio dichiarato nell'overview).

- [ ] **Step 6: Commit** *(istruzione per il developer — non eseguire)*

```bash
git add app/Services/LayerFilterValuesService.php tests/Feature/LayerFilterValuesTypeTest.php
git commit -m "feat(oc:8180): determine layer loop/linear type from track endpoints"
```

---

### Task 3: Service — geometria aggregata e regioni attraversate

**Files:**
- Modify: `app/Services/LayerFilterValuesService.php`
- Test: `tests/Feature/LayerFilterValuesWheresTest.php`

**Interfaces:**
- Consumes: `LayerFilterValuesService` (Task 1, 2)
- Produces:
  - `aggregatedGeojsonFeature(Layer $layer): ?array` — Feature GeoJSON `['type' => 'Feature', 'properties' => [], 'geometry' => [...]]`
  - `wheres(Layer $layer): ?array` — risultato di `OsmfeaturesClient::getWheresByGeojson()`, o `null`

**Perché così:** `getWheresByGeojson(array $geojson)` riceve una **Feature** GeoJSON e ne azzera le `properties` (verificato in `OsmfeaturesClient.php:11-16`), quindi la Feature si costruisce a mano dalla geometria aggregata. Il job del package non è utilizzabile perché farebbe `saveQuietly()` su un modello temporaneo, inserendo una riga.

- [ ] **Step 1: Scrivere i test che falliscono**

In `tests/Feature/LayerFilterValuesWheresTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Services\LayerFilterValuesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerFilterValuesWheresTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_aggregated_geojson_feature_is_null_without_tracks(): void
    {
        Http::fake();
        $layer = $this->createLayer();

        $this->assertNull(app(LayerFilterValuesService::class)->aggregatedGeojsonFeature($layer));
    }

    public function test_aggregated_geojson_feature_has_empty_properties_and_a_geometry(): void
    {
        Http::fake();
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach($track->id);

        $feature = app(LayerFilterValuesService::class)->aggregatedGeojsonFeature($layer->fresh());

        $this->assertSame('Feature', $feature['type']);
        $this->assertSame([], $feature['properties']);
        $this->assertArrayHasKey('type', $feature['geometry']);
        $this->assertArrayHasKey('coordinates', $feature['geometry']);
    }

    public function test_wheres_posts_the_aggregated_geometry_to_osmfeatures(): void
    {
        Http::fake([
            '*' => Http::response([
                'features' => [[
                    'properties' => [
                        'osmfeatures_id' => 'R42',
                        'osm_tags' => ['admin_level' => '4', 'name' => 'Toscana'],
                    ],
                ]],
            ], 200),
        ]);

        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach($track->id);

        $wheres = app(LayerFilterValuesService::class)->wheres($layer->fresh());

        $this->assertNotNull($wheres);
        $this->assertNotEmpty($wheres);
        Http::assertSent(function ($request) {
            return isset($request['geojson']['geometry'])
                && $request['geojson']['type'] === 'Feature';
        });
    }

    public function test_wheres_is_null_without_tracks(): void
    {
        Http::fake();
        $layer = $this->createLayer();

        $this->assertNull(app(LayerFilterValuesService::class)->wheres($layer));
        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Eseguire i test per verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/LayerFilterValuesWheresTest.php`
Expected: FAIL con `Call to undefined method ...::aggregatedGeojsonFeature()`

- [ ] **Step 3: Aggiungere i due metodi al service**

Import aggiuntivo in testa al file: `use Wm\WmPackage\Http\Clients\OsmfeaturesClient;`

```php
    /**
     * Feature GeoJSON con la geometria aggregata di tutte le tappe.
     *
     * ST_Union produce una MultiLineString unica; ST_Force2D perché le
     * EcTrack sono 3D e la quota è irrilevante per l'intersezione con le
     * aree amministrative. Le properties sono vuote per scelta:
     * getWheresByGeojson() le azzera comunque per ridurre il payload.
     */
    public function aggregatedGeojsonFeature(Layer $layer): ?array
    {
        $trackType = config('wm-package.ec_track_model', 'App\Models\EcTrack');

        $row = DB::selectOne(
            'SELECT ST_AsGeoJSON(ST_Union(ST_Force2D(t.geometry::geometry))) AS geojson
             FROM ec_tracks t
             JOIN layerables l
               ON l.layerable_id = t.id
              AND l.layerable_type = ?
             WHERE l.layer_id = ?
               AND t.geometry IS NOT NULL',
            [$trackType, $layer->id]
        );

        if ($row === null || $row->geojson === null) {
            return null;
        }

        $geometry = json_decode($row->geojson, true);

        if (! is_array($geometry) || ! isset($geometry['type'])) {
            return null;
        }

        return [
            'type' => 'Feature',
            'properties' => [],
            'geometry' => $geometry,
        ];
    }

    /**
     * Aree amministrative (regioni e comuni) attraversate dal cammino.
     *
     * Chiama direttamente il client osmfeatures sulla geometria aggregata
     * delle tappe: la geometria del Layer NON è utilizzabile perché è il
     * suo bounding box (nullable), che intersecherebbe regioni non
     * attraversate dal percorso.
     */
    public function wheres(Layer $layer): ?array
    {
        $feature = $this->aggregatedGeojsonFeature($layer);

        if ($feature === null) {
            return null;
        }

        $wheres = app(OsmfeaturesClient::class)->getWheresByGeojson($feature);

        return $wheres === [] ? null : $wheres;
    }
```

- [ ] **Step 4: Eseguire i test per verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/LayerFilterValuesWheresTest.php`
Expected: PASS (4 test)

- [ ] **Step 5: Commit** *(istruzione per il developer — non eseguire)*

```bash
git add app/Services/LayerFilterValuesService.php tests/Feature/LayerFilterValuesWheresTest.php
git commit -m "feat(oc:8180): derive layer wheres from aggregated track geometry"
```

---

### Task 4: Service — composizione e scrittura di `properties->filters`

**Files:**
- Modify: `app/Services/LayerFilterValuesService.php`
- Test: `tests/Feature/LayerFilterValuesPersistenceTest.php`

**Interfaces:**
- Consumes: `LayerFilterValuesService` (Task 1-3)
- Produces:
  - `computeCalculatedValues(Layer $layer): array` — sotto-insieme di `['distance', 'duration', 'type', 'taxonomy_where']`, senza le chiavi non calcolabili
  - `persistCalculatedValues(Layer $layer, array $values): void` — scrive con `jsonb_set`, preservando le chiavi manuali già presenti in `filters` e tutti i sibling di `properties`

**Attenzione:** `filters` contiene sia valori calcolati (questi) sia valori manuali scelti in Nova (`network`, `season`). La scrittura deve fare **merge** con quelli manuali, non sostituire l'intero oggetto `filters`.

- [ ] **Step 1: Scrivere i test che falliscono**

In `tests/Feature/LayerFilterValuesPersistenceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Services\LayerFilterValuesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerFilterValuesPersistenceTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_calculated_values_omit_keys_that_cannot_be_computed(): void
    {
        $layer = $this->createLayer();

        $values = app(LayerFilterValuesService::class)->computeCalculatedValues($layer);

        $this->assertArrayNotHasKey('distance', $values);
        $this->assertArrayNotHasKey('duration', $values);
        $this->assertArrayNotHasKey('type', $values);
        $this->assertArrayNotHasKey('taxonomy_where', $values);
    }

    public function test_persist_preserves_other_properties_keys(): void
    {
        $layer = $this->createLayer();
        DB::update(
            "UPDATE layers SET properties = ?::jsonb WHERE id = ?",
            [json_encode(['title' => ['it' => 'Cammino di prova'], 'name' => 'prova']), $layer->id]
        );

        app(LayerFilterValuesService::class)->persistCalculatedValues($layer, ['distance' => 12.5]);

        $properties = json_decode(DB::selectOne('SELECT properties FROM layers WHERE id = ?', [$layer->id])->properties, true);

        $this->assertSame(['it' => 'Cammino di prova'], $properties['title']);
        $this->assertSame('prova', $properties['name']);
        $this->assertSame(12.5, $properties['filters']['distance']);
    }

    public function test_persist_preserves_manual_filter_keys(): void
    {
        $layer = $this->createLayer();
        DB::update(
            "UPDATE layers SET properties = ?::jsonb WHERE id = ?",
            [json_encode(['filters' => ['network' => 'nwn', 'season' => ['spring']]]), $layer->id]
        );

        app(LayerFilterValuesService::class)->persistCalculatedValues($layer, ['duration' => 4]);

        $filters = json_decode(DB::selectOne('SELECT properties FROM layers WHERE id = ?', [$layer->id])->properties, true)['filters'];

        $this->assertSame('nwn', $filters['network']);
        $this->assertSame(['spring'], $filters['season']);
        $this->assertSame(4, $filters['duration']);
    }

    public function test_persist_removes_stale_calculated_keys_when_no_longer_computable(): void
    {
        $layer = $this->createLayer();
        DB::update(
            "UPDATE layers SET properties = ?::jsonb WHERE id = ?",
            [json_encode(['filters' => ['distance' => 99.0, 'network' => 'iwn']]), $layer->id]
        );

        // Nessun valore calcolabile: la chiave stale va rimossa, network resta
        app(LayerFilterValuesService::class)->persistCalculatedValues($layer, []);

        $filters = json_decode(DB::selectOne('SELECT properties FROM layers WHERE id = ?', [$layer->id])->properties, true)['filters'];

        $this->assertArrayNotHasKey('distance', $filters);
        $this->assertSame('iwn', $filters['network']);
    }

    public function test_calculated_values_include_distance_and_duration_with_tracks(): void
    {
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => ['manual_data' => ['distance' => 8.0]]]);
        $layer->ecTracks()->attach($track->id);

        $values = app(LayerFilterValuesService::class)->computeCalculatedValues($layer->fresh());

        $this->assertSame(8.0, $values['distance']);
        $this->assertSame(1, $values['duration']);
    }
}
```

- [ ] **Step 2: Eseguire i test per verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/LayerFilterValuesPersistenceTest.php`
Expected: FAIL con `Call to undefined method ...::computeCalculatedValues()`

- [ ] **Step 3: Aggiungere i due metodi al service**

```php
    /**
     * Chiavi calcolate di properties->filters.
     *
     * Le chiavi non calcolabili sono OMESSE, non impostate a 0/null: un
     * layer senza tappe non deve comparire in un filtro "0-10 km"
     * (stesso criterio del guard di oc:8140).
     */
    public function computeCalculatedValues(Layer $layer): array
    {
        $values = [];

        $distance = $this->totalDistance($layer);
        if ($distance !== null) {
            $values['distance'] = $distance;
        }

        $duration = $this->stageCount($layer);
        if ($duration !== null) {
            $values['duration'] = $duration;
        }

        $type = $this->determineType($this->trackEndpoints($layer));
        if ($type !== null) {
            $values['type'] = $type;
        }

        $wheres = $this->wheres($layer);
        if ($wheres !== null) {
            $values['taxonomy_where'] = $wheres;
        }

        return $values;
    }

    /**
     * Scrive le chiavi calcolate in properties->filters via jsonb_set.
     *
     * Non si usa il salvataggio Eloquent: su properties scrivono già
     * setNameAttribute, l'override di Layer::save(), le traduzioni Spatie
     * e job del package, tutti in read-modify-write dell'intero blob —
     * un save() concorrente durante un ricalcolo massivo cancellerebbe
     * traduzioni redazionali non ricalcolabili.
     *
     * Le chiavi manuali di filters (network, season) sono preservate; le
     * chiavi calcolate non più calcolabili vengono rimosse per non
     * lasciare valori stale.
     */
    public function persistCalculatedValues(Layer $layer, array $values): void
    {
        $row = DB::selectOne('SELECT properties FROM layers WHERE id = ?', [$layer->id]);
        $properties = $row !== null && $row->properties !== null
            ? (json_decode($row->properties, true) ?: [])
            : [];

        $existingFilters = is_array($properties['filters'] ?? null) ? $properties['filters'] : [];

        foreach (self::CALCULATED_KEYS as $key) {
            unset($existingFilters[$key]);
        }

        $filters = array_merge($existingFilters, $values);

        DB::update(
            "UPDATE layers
             SET properties = jsonb_set(COALESCE(properties, '{}'::jsonb), '{filters}', ?::jsonb, true)
             WHERE id = ?",
            [json_encode($filters, JSON_UNESCAPED_UNICODE), $layer->id]
        );
    }
```

E aggiungere la costante accanto alle altre:

```php
    /**
     * Chiavi di filters gestite dal calcolo automatico. Le altre
     * (network, season) sono manuali e non vanno mai toccate.
     */
    public const CALCULATED_KEYS = ['distance', 'duration', 'type', 'taxonomy_where'];
```

- [ ] **Step 4: Eseguire i test per verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/LayerFilterValuesPersistenceTest.php`
Expected: PASS (5 test)

- [ ] **Step 5: Eseguire tutti i test del service**

Run: `docker exec laravel-camminiditalia php artisan test --filter=LayerFilterValues`
Expected: PASS (21 test complessivi dei Task 1-4)

- [ ] **Step 6: Commit** *(istruzione per il developer — non eseguire)*

```bash
git add app/Services/LayerFilterValuesService.php tests/Feature/LayerFilterValuesPersistenceTest.php
git commit -m "feat(oc:8180): persist calculated layer filters via jsonb_set"
```

---

### Task 5: Job di ricalcolo

**Files:**
- Create: `app/Jobs/RecalculateLayerFiltersJob.php`
- Test: `tests/Feature/RecalculateLayerFiltersJobTest.php`

**Interfaces:**
- Consumes: `LayerFilterValuesService::computeCalculatedValues()`, `persistCalculatedValues()` (Task 4)
- Produces: `App\Jobs\RecalculateLayerFiltersJob` — costruttore `__construct(public int $layerId)`, `uniqueId(): string`, `handle(LayerFilterValuesService $service): void`

**Perché in coda:** il calcolo include una chiamata HTTP a osmfeatures (lenta, fallibile) e una `ST_Union` PostGIS; l'observer che lo dispatcha gira dentro una request Nova. `ShouldBeUnique` evita ricalcoli duplicati quando più tracce vengono associate allo stesso layer in sequenza.

- [ ] **Step 1: Scrivere i test che falliscono**

In `tests/Feature/RecalculateLayerFiltersJobTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\RecalculateLayerFiltersJob;
use App\Models\EcTrack;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class RecalculateLayerFiltersJobTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_handle_writes_filters_and_dispatches_config_regeneration(): void
    {
        Bus::fake([UpdateAppConfigJob::class]);

        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => ['manual_data' => ['distance' => 3.0]]]);
        $layer->ecTracks()->attach($track->id);

        (new RecalculateLayerFiltersJob($layer->id))->handle(app(\App\Services\LayerFilterValuesService::class));

        $filters = json_decode(DB::selectOne('SELECT properties FROM layers WHERE id = ?', [$layer->id])->properties, true)['filters'];
        $this->assertSame(3.0, $filters['distance']);

        Bus::assertDispatched(UpdateAppConfigJob::class);
    }

    public function test_handle_is_a_noop_when_layer_no_longer_exists(): void
    {
        Bus::fake([UpdateAppConfigJob::class]);

        (new RecalculateLayerFiltersJob(999999))->handle(app(\App\Services\LayerFilterValuesService::class));

        Bus::assertNotDispatched(UpdateAppConfigJob::class);
    }

    public function test_unique_id_is_scoped_to_the_layer(): void
    {
        $this->assertSame('recalculate-layer-filters-42', (new RecalculateLayerFiltersJob(42))->uniqueId());
    }
}
```

- [ ] **Step 2: Eseguire i test per verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/RecalculateLayerFiltersJobTest.php`
Expected: FAIL con `Class "App\Jobs\RecalculateLayerFiltersJob" not found`

- [ ] **Step 3: Creare il job**

In `app/Jobs/RecalculateLayerFiltersJob.php`:

```php
<?php

namespace App\Jobs;

use App\Services\LayerFilterValuesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\Layer;

/**
 * Ricalcola tutti i valori di filtro calcolati di un cammino (Layer).
 *
 * Un solo job per layer: i valori condividono la stessa aggregazione
 * delle tappe (costruirla è la parte costosa) e la scrittura è una sola
 * operazione atomica.
 *
 * Riceve l'ID e non il modello: SerializesModels con un modello
 * serializzato porterebbe in coda uno snapshot di properties che a
 * runtime sarebbe già stale.
 */
class RecalculateLayerFiltersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $layerId) {}

    public function uniqueId(): string
    {
        return "recalculate-layer-filters-{$this->layerId}";
    }

    public function handle(LayerFilterValuesService $service): void
    {
        $layer = Layer::find($this->layerId);

        if ($layer === null) {
            Log::info('RecalculateLayerFiltersJob: layer non trovato, skip', ['layer_id' => $this->layerId]);

            return;
        }

        $values = $service->computeCalculatedValues($layer);
        $service->persistCalculatedValues($layer, $values);

        Log::info('RecalculateLayerFiltersJob: filtri ricalcolati', [
            'layer_id' => $layer->id,
            'keys' => array_keys($values),
        ]);

        // Obbligatorio: persistCalculatedValues() scrive via SQL diretto,
        // quindi non scatta LayerObserver::saved() del package, che
        // rigenera la config solo su wasChanged('properties'). Senza
        // questo dispatch i valori resterebbero corretti in DB e assenti
        // nel config.json servito all'app.
        if ($layer->app_id !== null) {
            UpdateAppConfigJob::dispatch($layer->app_id);
        }
    }
}
```

- [ ] **Step 4: Eseguire i test per verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/RecalculateLayerFiltersJobTest.php`
Expected: PASS (3 test)

- [ ] **Step 5: Commit** *(istruzione per il developer — non eseguire)*

```bash
git add app/Jobs/RecalculateLayerFiltersJob.php tests/Feature/RecalculateLayerFiltersJobTest.php
git commit -m "feat(oc:8180): add queued job recalculating layer filters"
```

---

### Task 6: Observer su associazione/dissociazione tappe

**Files:**
- Create: `app/Observers/LayerFiltersObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/LayerFiltersObserverTest.php`

**Interfaces:**
- Consumes: `RecalculateLayerFiltersJob` (Task 5)
- Produces: `App\Observers\LayerFiltersObserver` con `created(Layerable $layerable)` e `deleted(Layerable $layerable)`

**Perché una classe separata da `LayerableObserver`:** quest'ultimo esce subito (`return`) se `$layer->user_id` è `null` (riga 23) e se la traccia dissociata non ha POI (riga 60) — guard corretti per la sua logica di ownership/POI, ma che scarterebbero i casi più comuni per il ricalcolo dei filtri. Un observer dedicato ha guard propri, sui soli dati che servono al calcolo. Laravel supporta più observer sullo stesso modello.

- [ ] **Step 1: Scrivere i test che falliscono**

In `tests/Feature/LayerFiltersObserverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\RecalculateLayerFiltersJob;
use App\Models\EcPoi;
use App\Models\EcTrack;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerFiltersObserverTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_attaching_a_track_dispatches_the_recalculation(): void
    {
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);

        $layer->ecTracks()->attach($track->id);

        Queue::assertPushed(RecalculateLayerFiltersJob::class, fn ($job) => $job->layerId === $layer->id);
    }

    public function test_attaching_a_track_dispatches_even_when_layer_has_no_owner(): void
    {
        $layer = $this->createLayer();
        $layer->forceFill(['user_id' => null])->saveQuietly();
        $track = EcTrack::factory()->create(['properties' => []]);

        $layer->ecTracks()->attach($track->id);

        Queue::assertPushed(RecalculateLayerFiltersJob::class, fn ($job) => $job->layerId === $layer->id);
    }

    public function test_detaching_a_track_without_pois_dispatches_the_recalculation(): void
    {
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach($track->id);
        Queue::fake();

        $layer->ecTracks()->detach($track->id);

        Queue::assertPushed(RecalculateLayerFiltersJob::class, fn ($job) => $job->layerId === $layer->id);
    }

    public function test_attaching_a_poi_does_not_dispatch_the_recalculation(): void
    {
        $layer = $this->createLayer();
        $poi = EcPoi::factory()->create(['properties' => []]);
        Queue::fake();

        $layer->ecPois()->attach($poi->id);

        Queue::assertNotPushed(RecalculateLayerFiltersJob::class);
    }
}
```

- [ ] **Step 2: Eseguire i test per verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/LayerFiltersObserverTest.php`
Expected: FAIL — nessun job accodato

- [ ] **Step 3: Creare l'observer**

In `app/Observers/LayerFiltersObserver.php`:

```php
<?php

namespace App\Observers;

use App\Jobs\RecalculateLayerFiltersJob;
use Wm\WmPackage\Models\Layerable;

/**
 * Accoda il ricalcolo dei filtri di un cammino quando cambia l'insieme
 * delle sue tappe.
 *
 * Classe separata da LayerableObserver di proposito: i guard di
 * quest'ultimo (esce se il layer non ha owner, o se la traccia non ha
 * POI) servono alla sua logica di ownership/POI e scarterebbero i casi
 * più comuni per il ricalcolo. Qui si valida solo ciò che serve al
 * calcolo: il tipo di risorsa e l'esistenza del layer.
 */
class LayerFiltersObserver
{
    public function created(Layerable $layerable): void
    {
        $this->dispatchRecalculation($layerable);
    }

    public function deleted(Layerable $layerable): void
    {
        $this->dispatchRecalculation($layerable);
    }

    private function dispatchRecalculation(Layerable $layerable): void
    {
        $trackType = config('wm-package.ec_track_model', 'App\Models\EcTrack');

        // Solo le tappe influenzano i filtri calcolati: i POI no.
        if ($layerable->layerable_type !== $trackType) {
            return;
        }

        if ($layerable->layer_id === null) {
            return;
        }

        RecalculateLayerFiltersJob::dispatch((int) $layerable->layer_id);
    }
}
```

- [ ] **Step 4: Registrare l'observer**

In `app/Providers/AppServiceProvider.php`, aggiungere l'import:

```php
use App\Observers\LayerFiltersObserver;
```

e, nel metodo `boot()`, subito dopo `Layerable::observe(LayerableObserver::class);`:

```php
        Layerable::observe(LayerFiltersObserver::class);
```

- [ ] **Step 5: Eseguire i test per verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/LayerFiltersObserverTest.php`
Expected: PASS (4 test)

- [ ] **Step 6: Verificare che non ci siano regressioni sugli observer esistenti**

Run: `docker exec laravel-camminiditalia php artisan test --filter=LayerOwnershipTransfer`
Expected: PASS (nessuna regressione sul trasferimento ownership di oc:8080)

- [ ] **Step 7: Commit** *(istruzione per il developer — non eseguire)*

```bash
git add app/Observers/LayerFiltersObserver.php app/Providers/AppServiceProvider.php tests/Feature/LayerFiltersObserverTest.php
git commit -m "feat(oc:8180): recalculate layer filters on track attach/detach"
```

---

### Task 7: Ricalcolo alla modifica di geometria di una tappa

**Files:**
- Create: `app/Observers/EcTrackGeometryFiltersObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/EcTrackGeometryFiltersObserverTest.php`

**Interfaces:**
- Consumes: `RecalculateLayerFiltersJob` (Task 5)
- Produces: `App\Observers\EcTrackGeometryFiltersObserver` con `saved(EcTrack $track)`

- [ ] **Step 1: Scrivere i test che falliscono**

In `tests/Feature/EcTrackGeometryFiltersObserverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\RecalculateLayerFiltersJob;
use App\Models\EcTrack;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class EcTrackGeometryFiltersObserverTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_changing_track_geometry_recalculates_every_layer_containing_it(): void
    {
        $first = $this->createLayer();
        $second = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);
        $first->ecTracks()->attach($track->id);
        $second->ecTracks()->attach($track->id);

        Queue::fake();
        $track->geometry = \DB::raw("ST_GeomFromText('MULTILINESTRINGZ((11 43 0, 11.1 43.1 0))', 4326)");
        $track->save();

        Queue::assertPushed(RecalculateLayerFiltersJob::class, fn ($job) => $job->layerId === $first->id);
        Queue::assertPushed(RecalculateLayerFiltersJob::class, fn ($job) => $job->layerId === $second->id);
    }

    public function test_changing_only_a_non_geometry_field_does_not_recalculate(): void
    {
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create(['properties' => []]);
        $layer->ecTracks()->attach($track->id);

        Queue::fake();
        $track->ref = 'nuovo-ref';
        $track->save();

        Queue::assertNotPushed(RecalculateLayerFiltersJob::class);
    }
}
```

- [ ] **Step 2: Eseguire i test per verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/EcTrackGeometryFiltersObserverTest.php`
Expected: FAIL — nessun job accodato

- [ ] **Step 3: Creare l'observer**

In `app/Observers/EcTrackGeometryFiltersObserver.php`:

```php
<?php

namespace App\Observers;

use App\Jobs\RecalculateLayerFiltersJob;
use Illuminate\Support\Facades\DB;

/**
 * Accoda il ricalcolo dei filtri dei cammini che contengono una tappa la
 * cui geometria è cambiata: lunghezza e tipologia dipendono dalla
 * geometria, quindi un tracciato ridisegnato dopo l'associazione li
 * renderebbe stali.
 *
 * Il layer_id si legge dal pivot con una query diretta e non via
 * relazione Eloquent: serve solo l'id, non i modelli.
 */
class EcTrackGeometryFiltersObserver
{
    public function saved($track): void
    {
        if (! $track->wasChanged('geometry')) {
            return;
        }

        $trackType = config('wm-package.ec_track_model', 'App\Models\EcTrack');

        $layerIds = DB::table('layerables')
            ->where('layerable_type', $trackType)
            ->where('layerable_id', $track->id)
            ->pluck('layer_id');

        foreach ($layerIds as $layerId) {
            RecalculateLayerFiltersJob::dispatch((int) $layerId);
        }
    }
}
```

- [ ] **Step 4: Registrare l'observer**

In `app/Providers/AppServiceProvider.php`, aggiungere l'import:

```php
use App\Observers\EcTrackGeometryFiltersObserver;
```

e in `boot()`, accanto alle altre registrazioni:

```php
        EcTrack::observe(EcTrackGeometryFiltersObserver::class);
        \App\Models\EcTrack::observe(EcTrackGeometryFiltersObserver::class);
```

*(Doppia registrazione per lo stesso motivo per cui `EcPoi` è registrato due volte alla riga 56: il progetto ha sia il modello del package sia l'override locale.)*

- [ ] **Step 5: Eseguire i test per verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/EcTrackGeometryFiltersObserverTest.php`
Expected: PASS (2 test)

- [ ] **Step 6: Commit** *(istruzione per il developer — non eseguire)*

```bash
git add app/Observers/EcTrackGeometryFiltersObserver.php app/Providers/AppServiceProvider.php tests/Feature/EcTrackGeometryFiltersObserverTest.php
git commit -m "feat(oc:8180): recalculate layer filters when a stage geometry changes"
```

---

### Task 8: Campi Nova su Layer — Temi, Portata, Stagioni

**Files:**
- Modify: `app/Nova/Layer.php`
- Test: `tests/Feature/LayerFilterFieldsTest.php`

**Interfaces:**
- Consumes: `Wm\WmPackage\Enums\OsmWalkingNetwork`, `Wm\WmPackage\Enums\Season` (piano wm-package, Task 1-2)
- Produces: tre campi Nova su `App\Nova\Layer`: `taxonomyThemes`, `properties->filters->network`, `properties->filters->season`

**Attenzione:** i campi vanno in `App\Nova\Layer` (override locale) e **non** in `Wm\WmPackage\Nova\Layer`, condivisa da tutti gli shard Webmapp. Verificato che `taxonomyThemes` è neutro: `assignTracksByTaxonomy()` usa solo `taxonomyActivities`/`taxonomyWheres`, e `query_string` viene rimosso dalla config (`AppConfigService.php:383`).

- [ ] **Step 1: Scrivere i test che falliscono**

In `tests/Feature/LayerFilterFieldsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Nova\Layer as NovaLayer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Enums\OsmWalkingNetwork;
use Wm\WmPackage\Enums\Season;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerFilterFieldsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    private function fieldAttributes(): array
    {
        $resource = new NovaLayer(new \Wm\WmPackage\Models\Layer);
        $fields = $resource->fields(NovaRequest::create('/'));

        $attributes = [];
        array_walk_recursive($fields, function ($item) use (&$attributes) {
            if (is_object($item) && property_exists($item, 'attribute')) {
                $attributes[] = $item->attribute;
            }
        });

        return $attributes;
    }

    public function test_network_and_season_filter_fields_exist(): void
    {
        $attributes = $this->fieldAttributes();

        $this->assertContains('properties->filters->network', $attributes);
        $this->assertContains('properties->filters->season', $attributes);
    }

    public function test_network_options_come_from_the_osm_enum(): void
    {
        $this->assertSame(
            ['lwn', 'rwn', 'nwn', 'iwn'],
            array_keys(OsmWalkingNetwork::toArray())
        );
    }

    public function test_season_options_come_from_the_season_enum(): void
    {
        $this->assertSame(
            ['spring', 'summer', 'autumn', 'winter'],
            array_keys(Season::toArray())
        );
    }
}
```

*(Nota: la struttura dei campi Nova in questo progetto è annidata in Tab/Panel; `array_walk_recursive` con controllo su `attribute` è il modo robusto di appiattirla, come già fatto in `tests/Feature/AppHomeLayerSortButtonTest.php`. Se la struttura reale non è attraversabile così, adattare l'helper leggendo quel test esistente prima di modificare l'asserzione.)*

- [ ] **Step 2: Eseguire i test per verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/LayerFilterFieldsTest.php`
Expected: FAIL — gli attributi dei campi filtro non sono presenti

- [ ] **Step 3: Aggiungere i campi in `App\Nova\Layer`**

Import da aggiungere in testa a `app/Nova/Layer.php`:

```php
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Select;
use Outl1ne\NovaMultiselectField\Multiselect;
use Wm\WmPackage\Enums\OsmWalkingNetwork;
use Wm\WmPackage\Enums\Season;
```

Nel metodo `fields()` (dopo `parent::fields($request)`, seguendo la struttura già presente nel file), aggiungere i tre campi:

```php
            MorphToMany::make(__('Themes'), 'taxonomyThemes', \Wm\WmPackage\Nova\TaxonomyTheme::class)
                ->searchable(),

            Select::make(__('Network'), 'properties->filters->network')
                ->options(OsmWalkingNetwork::toArray())
                ->displayUsingLabels()
                ->nullable()
                ->hideFromIndex()
                ->help(__('Scale of the walking network this route belongs to (OSM network tag)')),

            Multiselect::make(__('Seasons'), 'properties->filters->season')
                ->options(Season::toArray())
                ->nullable()
                ->hideFromIndex()
                ->help(__('Seasons in which this route is preferably walked')),
```

- [ ] **Step 4: Eseguire i test per verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/LayerFilterFieldsTest.php`
Expected: PASS (3 test)

- [ ] **Step 5: Verificare dal vivo in Nova**

Aprire il detail e l'edit di un Layer in Nova e verificare:
- il select "Network" mostra le label tradotte (Rete locale/regionale/nazionale/internazionale), non i valori grezzi
- il multiselect "Seasons" permette selezione multipla
- salvando, i valori finiscono in `properties->filters->network` / `->season` **senza cancellare** le chiavi calcolate:

```bash
docker exec laravel-camminiditalia php artisan tinker --execute="\$l = \Wm\WmPackage\Models\Layer::find(<ID>); print_r(\$l->properties['filters'] ?? []);"
```

⚠️ Se il salvataggio da Nova cancella le chiavi calcolate (`distance`, `duration`, `type`, `taxonomy_where`), **fermarsi e segnalarlo**: significa che Nova sta riscrivendo l'intero oggetto `filters` invece del solo path, e serve un `fillUsing` che faccia merge (stesso problema affrontato in `BulkEditAction` per i path `properties->*`).

- [ ] **Step 6: Aggiungere le traduzioni delle etichette dei campi**

In `resources/lang/it.json` del repo principale:

```json
"Themes": "Temi",
"Network": "Portata",
"Seasons": "Stagioni",
"Scale of the walking network this route belongs to (OSM network tag)": "Scala della rete escursionistica a cui appartiene il cammino (tag OSM network)",
"Seasons in which this route is preferably walked": "Stagioni in cui il cammino è preferibilmente percorribile"
```

- [ ] **Step 7: Commit** *(istruzione per il developer — non eseguire)*

```bash
git add app/Nova/Layer.php resources/lang/it.json tests/Feature/LayerFilterFieldsTest.php
git commit -m "feat(oc:8180): add themes, network and seasons filter fields to Nova Layer"
```

---

### Task 9: Nova Action sull'App per il ricalcolo massivo

**Files:**
- Create: `app/Nova/Actions/RecalculateAppLayerFiltersAction.php`
- Modify: `app/Nova/App.php`
- Test: `tests/Feature/RecalculateAppLayerFiltersActionTest.php`

**Interfaces:**
- Consumes: `RecalculateLayerFiltersJob` (Task 5)
- Produces: `App\Nova\Actions\RecalculateAppLayerFiltersAction` con `name()` e `handle(ActionFields $fields, Collection $models)`

**Sostituisce il command artisan di backfill:** il ricalcolo iniziale dei 118 layer esistenti si lancia da Nova. L'azione **accoda** un job per layer (non calcola in-process) perché ogni layer comporta una `ST_Union` PostGIS e una chiamata HTTP a osmfeatures: eseguirli tutti nella request Nova sarebbe un timeout garantito.

- [ ] **Step 1: Scrivere i test che falliscono**

In `tests/Feature/RecalculateAppLayerFiltersActionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\RecalculateLayerFiltersJob;
use App\Nova\Actions\RecalculateAppLayerFiltersAction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Fields\ActionFields;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class RecalculateAppLayerFiltersActionTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_action_queues_one_job_per_layer_of_the_app(): void
    {
        $app = App::first();
        $first = $this->createLayer();
        $second = $this->createLayer();

        Queue::fake();
        (new RecalculateAppLayerFiltersAction)->handle(
            new ActionFields(new Collection, new Collection),
            new Collection([$app])
        );

        Queue::assertPushed(RecalculateLayerFiltersJob::class, fn ($job) => $job->layerId === $first->id);
        Queue::assertPushed(RecalculateLayerFiltersJob::class, fn ($job) => $job->layerId === $second->id);
    }

    public function test_action_returns_a_message_with_the_queued_count(): void
    {
        $app = App::first();
        $this->createLayer();

        $result = (new RecalculateAppLayerFiltersAction)->handle(
            new ActionFields(new Collection, new Collection),
            new Collection([$app])
        );

        $this->assertNotEmpty($result);
    }
}
```

- [ ] **Step 2: Eseguire i test per verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/RecalculateAppLayerFiltersActionTest.php`
Expected: FAIL con `Class "App\Nova\Actions\RecalculateAppLayerFiltersAction" not found`

- [ ] **Step 3: Creare l'action**

In `app/Nova/Actions/RecalculateAppLayerFiltersAction.php`:

```php
<?php

namespace App\Nova\Actions;

use App\Jobs\RecalculateLayerFiltersJob;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Models\App as AppModel;

/**
 * Accoda il ricalcolo dei filtri per tutti i cammini di un'app.
 *
 * Sostituisce il command artisan di backfill: il primo popolamento dei
 * layer esistenti si lancia da qui. Accoda un job per layer invece di
 * calcolare in-process perché ogni layer comporta una ST_Union PostGIS e
 * una chiamata HTTP a osmfeatures.
 */
class RecalculateAppLayerFiltersAction extends Action
{
    use InteractsWithQueue, Queueable;

    public function name(): string
    {
        return __('Recalculate route filters');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $queued = 0;

        foreach ($models as $app) {
            if (! $app instanceof AppModel) {
                continue;
            }

            foreach ($app->layers()->pluck('id') as $layerId) {
                RecalculateLayerFiltersJob::dispatch((int) $layerId);
                $queued++;
            }

            Log::info('RecalculateAppLayerFiltersAction: ricalcolo accodato', [
                'app_id' => $app->id,
                'layers' => $queued,
            ]);
        }

        return Action::message(__('Filter recalculation queued for :count routes', ['count' => $queued]));
    }
}
```

- [ ] **Step 4: Registrare l'action in `App\Nova\App`**

`app/Nova/App.php` non fa override di `actions()`: aggiungerlo, chiamando sempre il parent e appendendo la nuova action (import: `use App\Nova\Actions\RecalculateAppLayerFiltersAction;`, `use Laravel\Nova\Http\Requests\NovaRequest;`, `use Wm\WmPackage\Services\RolesAndPermissionsService;`):

```php
    public function actions(NovaRequest $request): array
    {
        $superAdminOnly = fn (NovaRequest $req) => RolesAndPermissionsService::allows($req);

        return array_merge(parent::actions($request), [
            (new RecalculateAppLayerFiltersAction)
                ->onlyOnDetail()
                ->canSee($superAdminOnly)
                ->canRun($superAdminOnly)
                ->confirmText(__('Recalculate the filter values of every route of this app? The work is queued and may take a while.'))
                ->confirmButtonText(__('Yes, recalculate'))
                ->cancelButtonText(__('Cancel')),
        ]);
    }
```

- [ ] **Step 5: Eseguire i test per verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/RecalculateAppLayerFiltersActionTest.php`
Expected: PASS (2 test)

- [ ] **Step 6: Aggiungere le traduzioni**

In `resources/lang/it.json`:

```json
"Recalculate route filters": "Ricalcola filtri dei cammini",
"Filter recalculation queued for :count routes": "Ricalcolo filtri accodato per :count cammini",
"Recalculate the filter values of every route of this app? The work is queued and may take a while.": "Ricalcolare i valori di filtro di tutti i cammini di questa app? Il lavoro viene accodato e può richiedere tempo.",
"Yes, recalculate": "Sì, ricalcola"
```

- [ ] **Step 7: Eseguire il ricalcolo reale e verificare l'esito**

```bash
# lanciare l'action dal detail dell'App in Nova, poi:
docker exec laravel-camminiditalia php artisan tinker --execute="
\$total = \Wm\WmPackage\Models\Layer::count();
\$withFilters = \Wm\WmPackage\Models\Layer::whereRaw(\"properties->'filters' IS NOT NULL\")->count();
\$withType = \Wm\WmPackage\Models\Layer::whereRaw(\"properties->'filters'->>'type' IS NOT NULL\")->count();
echo \"layer: {\$total}, con filters: {\$withFilters}, con type: {\$withType}\";
"
```
Expected: `con filters` ≈ numero di layer con almeno una tappa. **Annotare quanti layer restano senza `type`**: è la misura dell'anomalia topologica (tappe non contigue) e va riportata in `notes.md` e comunicata al developer.

- [ ] **Step 8: Eseguire la suite completa e PHPStan**

Run: `docker exec laravel-camminiditalia php artisan test`
Expected: nessun test rotto rispetto al baseline

Run: `docker exec laravel-camminiditalia vendor/bin/phpstan analyse`
Expected: nessun errore nuovo sui file toccati (il baseline copre il debito preesistente)

- [ ] **Step 9: Commit** *(istruzione per il developer — non eseguire)*

```bash
git add app/Nova/Actions/RecalculateAppLayerFiltersAction.php app/Nova/App.php resources/lang/it.json tests/Feature/RecalculateAppLayerFiltersActionTest.php
git commit -m "feat(oc:8180): add Nova action queueing filter recalculation for all app routes"
```

---

## Copertura della spec

| Requisito dell'overview | Task |
|---|---|
| Service che calcola Lunghezza, Durata, Tipologia dalle tappe | 1, 2 |
| Tipologia sul tracciato aggregato, non sulla singola tappa | 2 |
| `jsonb_set` che preserva i sibling di `properties` | 4 |
| Dispatch di `UpdateAppConfigJob` dopo il ricalcolo | 5 |
| Observer dedicato su `Layerable` con guard propri | 6 |
| Hook su modifica geometria di una tappa | 7 |
| Campi Nova Temi / Portata / Stagioni con `tryFrom` difensivo | 8 |
| Nova Action di ricalcolo su tutti i layer dell'App | 9 |
| Chiavi assenti (non `0`/`null`) quando non calcolabili | 4 (test dedicati) |
| Nessun filtro su `user_id` | 1 (test dedicato) |
| Nessuna modifica a wm-package oltre ai due enum | vincolo globale |
| Enum `OsmWalkingNetwork` e `Season` | piano wm-package |

## Punti da verificare durante l'esecuzione (non risolvibili a priori)

1. **Tolleranza `0.001` a scala di cammino** — Task 2 Step 5 misura quanti layer reali risultano `NULL`. Se sono molti, discutere col developer prima di procedere.
2. **Nova e i path `properties->filters->*`** — Task 8 Step 5 verifica dal vivo che il salvataggio dei campi manuali non cancelli le chiavi calcolate. Se lo fa, serve un `fillUsing` con merge.
3. **Struttura dei campi Nova annidata** — Task 8 Step 1 usa un helper di appiattimento; se non funziona, allinearlo a quello di `tests/Feature/AppHomeLayerSortButtonTest.php`.

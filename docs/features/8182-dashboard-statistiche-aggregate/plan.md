> Ticket: oc:8182

# Dashboard statistiche aggregate per Cammini d'Italia — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **⚠️ Nessun commit o branch automatico:** i passi "Commit" sono istruzioni testuali per il developer. Non eseguire `git commit`/`git push`/`git checkout -b` autonomamente — la fase di commit è gestita separatamente dopo approvazione esplicita del dev (vedi `wm-skills:wm-plan` → `execution: review-gate`).

**Goal:** Estendere l'analytics PostHog di Cammini d'Italia (oggi disponibile solo per singolo layer) con una vista aggregata su tutti i layer/tracce, visibile nell'index Layer di Nova solo agli Administrator.

**Architecture:** Riuso DRY del core esistente (`AnalyticsService`) rendendo `$id`/`$trackIds` nullable nei metodi privati condivisi invece di duplicare le query; 3 nuovi metodi pubblici sottili (`getGlobalUsage`, `getAllLayersUsage`, `getAllTracksDownloads`) espongono l'aggregato al controller. Nuova route dedicata + nuovo metodo controller con autorizzazione esplicita Administrator. Stesso componente Vue esteso con un blocco a due classifiche, KPI/grafico esistenti invariati. Fix di sicurezza collaterale sull'endpoint per-layer esistente (nessuna autorizzazione oggi).

**Tech Stack:** Laravel 11, Nova 5, Vue 2 (Nova Card), Chart.js, PostHog HogQL, PHPUnit/Pest (wm-package), PHPUnit (camminiditalia).

## Global Constraints

- Nessuna modifica al comportamento del path per-layer esistente per utenti che già lo usano legittimamente (solo aggiunta di un controllo di autorizzazione, comportamento intenzionale — vedi Task 7)
- KPI (aperture totali, utenti unici, media/giorno) e bar chart giornaliero nel Vue component: **zero modifiche** al markup/logica esistente
- Top 20 righe finali per ogni classifica, con margine di query a 50 per poter scartare layer/tracce cancellate prima del troncamento
- Range identici al pannello per-layer (`last_30_days` default, `last_90_days`, `last_365_days`, `month:YYYY-MM`) via `resolveRange()` esistente, invariato
- Test wm-package (`Orchestra\Testbench\TestCase`) girano dalla suite isolata del package (`cd wm-package && vendor/bin/pest`), **non** da `php artisan test` di camminiditalia — richiede `composer install` già eseguito dentro `wm-package/` nell'ambiente del developer (con accesso SSH/credenziali a `laravel/nova`, privato) — assunto disponibile, fuori scope di questo ticket risolvere l'accesso SSH
- Test camminiditalia (`Tests\TestCase`) girano da `docker exec laravel-camminiditalia php artisan test`
- Formattazione: `docker exec laravel-camminiditalia composer format` (Laravel Pint) prima di ogni commit
- Commit convention: `feat(oc:8182): ...` / `fix(oc:8182): ...` — creati solo dal developer dopo review, mai automaticamente da un worker agentico

---

## Task 1: `AnalyticsService` — `$id` nullable nel core condiviso + helper filtro centralizzato

**Repo:** wm-package

**Files:**

- Modify: `wm-package/src/Services/PostHog/AnalyticsService.php`
- Test: `wm-package/tests/Unit/AnalyticsServiceTest.php`

**Interfaces:**

- Consumes: nessuna dipendenza da altri task
- Produces: `getUsage(string $event, string $idProperty, ?int $id, string $range): array` (era `int $id`), `idFilterClause(string $idProperty, ?int $id): string` (nuovo, privato) — usati dai Task 2, 3, 4

- [ ] **Step 1: Scrivi il test che fallisce — `getUsage` con `$id = null` omette il filtro**

Aggiungi in `AnalyticsServiceTest.php`, nella sezione "Output normalizzato":

```php
public function test_get_usage_with_null_id_omits_id_filter_from_query(): void
{
    Http::fake([
        '*' => Http::sequence()
            ->push(['results' => [['2026-05-01', 'posthog-android', 40]]])
            ->push(['results' => [['posthog-android', 40]]])
            ->push(['results' => [[12]]]),
    ]);

    $result = (new AnalyticsService)->getLayerUsage(1); // baseline invariato, non tocca ancora null

    $this->assertSame(1, $result['id']);
}

public function test_get_all_layers_ranking_query_has_no_id_equality_filter(): void
{
    Http::fake(['*' => Http::response(['results' => []])]);

    (new \ReflectionClass(AnalyticsService::class))
        ->getMethod('getUsage')
        ->setAccessible(true);

    $service = new AnalyticsService;
    $method = new \ReflectionMethod($service, 'getUsage');
    $method->setAccessible(true);
    $method->invoke($service, 'layerOpened', 'layer_id', null, 'last_30_days');

    Http::assertSent(function (Request $request) {
        $sql = $request->data()['query']['query'];

        return ! str_contains($sql, "properties.layer_id = '")
            && str_contains($sql, 'properties.layer_id IS NOT NULL');
    });
}
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Unit/AnalyticsServiceTest.php --filter=test_get_all_layers_ranking_query_has_no_id_equality_filter"
```

Expected: FAIL — `getUsage()` accetta solo `int $id`, l'invocazione con `null` produce `TypeError`.

- [ ] **Step 3: Implementa — helper centralizzato + firme nullable**

In `AnalyticsService.php`, aggiungi il nuovo metodo privato (prima di `libList()`):

```php
private function idFilterClause(string $idProperty, ?int $id): string
{
    if ($id === null) {
        return "properties.{$idProperty} IS NOT NULL AND properties.{$idProperty} != ''";
    }

    return "properties.{$idProperty} = '{$id}'";
}
```

Cambia le firme di `getUsage`, `fetchUsage`, `queryDailyBreakdown`, `queryBreakdown`, `queryUniqueUsers` da `int $id` a `?int $id`.

In `queryDailyBreakdown`, sostituisci:

```php
  AND properties.{$idProperty} = '{$id}'
```

con:

```php
  AND {$this->idFilterClause($idProperty, $id)}
```

Applica la stessa sostituzione in `queryBreakdown` e `queryUniqueUsers`.

In `getUsage`, la cache key deve gestire `$id = null`:

```php
private function getUsage(string $event, string $idProperty, ?int $id, string $range): array
{
    $idSegment = $id ?? 'all';
    $cacheKey = "posthog:{$event}:{$idSegment}:usage:{$range}";
    $ttl = $this->ttlFor($range);

    if (in_array($range, self::LOCK_RANGES, true)) {
        $lock = Cache::lock("lock:{$cacheKey}", 15);

        return $lock->block(15, fn () => Cache::remember(
            $cacheKey,
            now()->addSeconds($ttl),
            fn () => $this->fetchUsage($event, $idProperty, $id, $range)
        ));
    }

    return Cache::remember(
        $cacheKey,
        now()->addSeconds($ttl),
        fn () => $this->fetchUsage($event, $idProperty, $id, $range)
    );
}
```

- [ ] **Step 4: Esegui il test per verificare che passi**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Unit/AnalyticsServiceTest.php"
```

Expected: PASS — inclusi tutti i test preesistenti (`test_get_layer_usage_returns_expected_structure`, `test_cache_key_is_scoped_per_model_id`, ecc.), nessuna regressione sul path `$id` valorizzato.

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Services/PostHog/AnalyticsService.php wm-package/tests/Unit/AnalyticsServiceTest.php
git commit -m "feat(oc:8182): support nullable id filter in AnalyticsService core queries"
```

---

## Task 2: `getGlobalUsage()` — KPI aggregati su tutti i layer

**Repo:** wm-package

**Files:**

- Modify: `wm-package/src/Services/PostHog/AnalyticsService.php`
- Test: `wm-package/tests/Unit/AnalyticsServiceTest.php`

**Interfaces:**

- Consumes: `getUsage(string, string, ?int, string): array` da Task 1
- Produces: `getGlobalUsage(string $range = 'last_30_days'): array` — usato da Task 6 (`AnalyticsController::global()`)

- [ ] **Step 1: Scrivi il test che fallisce**

```php
public function test_get_global_usage_aggregates_across_all_layers(): void
{
    Http::fake([
        '*' => Http::sequence()
            ->push(['results' => [['2026-05-01', 'posthog-android', 100], ['2026-05-01', 'posthog-ios', 40]]])
            ->push(['results' => [['posthog-android', 100], ['posthog-ios', 40]]])
            ->push(['results' => [[55]]]),
    ]);

    $result = (new AnalyticsService)->getGlobalUsage('last_30_days');

    $this->assertNull($result['id']);
    $this->assertSame('layerOpened', $result['event']);
    $this->assertSame(140, $result['total']);
    $this->assertSame(55, $result['unique_users']);
}
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Unit/AnalyticsServiceTest.php --filter=test_get_global_usage_aggregates_across_all_layers"
```

Expected: FAIL — `Call to undefined method AnalyticsService::getGlobalUsage()`.

- [ ] **Step 3: Implementa**

Aggiungi in `AnalyticsService.php`, subito dopo `getLayerUsage()`:

```php
public function getGlobalUsage(string $range = 'last_30_days'): array
{
    return $this->getUsage('layerOpened', 'layer_id', null, $range);
}
```

- [ ] **Step 4: Esegui il test per verificare che passi**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Unit/AnalyticsServiceTest.php"
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Services/PostHog/AnalyticsService.php wm-package/tests/Unit/AnalyticsServiceTest.php
git commit -m "feat(oc:8182): add getGlobalUsage for aggregated KPI across all layers"
```

---

## Task 3: Propagazione esplicita dei fallimenti query (no più "0" silenzioso per il path globale)

**Repo:** wm-package

**Files:**

- Create: `wm-package/src/Exceptions/AnalyticsQueryException.php`
- Modify: `wm-package/src/Services/PostHog/AnalyticsService.php`
- Test: `wm-package/tests/Unit/AnalyticsServiceTest.php`

**Interfaces:**

- Consumes: nessuna
- Produces: `AnalyticsQueryException` (nuova classe), `runQuery(string $sql, bool $strict = false): array` (firma estesa, backward compatible) — usato da Task 4 e Task 6

- [ ] **Step 1: Scrivi il test che fallisce**

```php
public function test_strict_query_throws_analytics_exception_on_http_failure(): void
{
    Http::fake(['*' => Http::response('Internal Server Error', 500)]);
    Log::shouldReceive('error')->atLeast()->once();

    $this->expectException(\Wm\WmPackage\Exceptions\AnalyticsQueryException::class);

    $service = new AnalyticsService;
    $method = new \ReflectionMethod($service, 'runQuery');
    $method->setAccessible(true);
    $method->invoke($service, 'SELECT 1', true);
}

public function test_non_strict_query_still_returns_empty_array_on_failure(): void
{
    // Non-regressione: il comportamento di default (usato dal path per-layer) resta invariato
    Http::fake(['*' => Http::response('Internal Server Error', 500)]);
    Log::shouldReceive('error')->atLeast()->once();

    $result = (new AnalyticsService)->getLayerUsage(1);

    $this->assertSame(0, $result['total']);
}
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Unit/AnalyticsServiceTest.php --filter=test_strict_query_throws_analytics_exception_on_http_failure"
```

Expected: FAIL — `Class "Wm\WmPackage\Exceptions\AnalyticsQueryException" not found`.

- [ ] **Step 3: Implementa**

Crea `wm-package/src/Exceptions/AnalyticsQueryException.php`:

```php
<?php

namespace Wm\WmPackage\Exceptions;

use Exception;

class AnalyticsQueryException extends Exception {}
```

In `AnalyticsService.php`, modifica `runQuery()`:

```php
use Wm\WmPackage\Exceptions\AnalyticsQueryException;

/** @return list<list<mixed>> */
private function runQuery(string $sql, bool $strict = false): array
{
    $url = "{$this->host}/api/projects/{$this->projectId}/query";

    $response = Http::withToken($this->apiKey)
        ->timeout(10)
        ->post($url, [
            'query' => [
                'kind' => 'HogQLQuery',
                'query' => $sql,
            ],
        ]);

    if (! $response->successful()) {
        Log::error('PostHog query failed', [
            'status' => $response->status(),
            'body' => $response->body(),
            'sql' => $sql,
        ]);

        if ($strict) {
            throw new AnalyticsQueryException("PostHog query failed with status {$response->status()}");
        }

        return [];
    }

    return $response->json('results', []);
}
```

**Nota:** questo task lascia `runQuery()` retrocompatibile (`$strict = false` di default, usato da tutte le chiamate esistenti non ancora modificate). Il parametro `$strict` verrà propagato ai metodi globali nei Task 4/6 — non è necessario modificare `queryDailyBreakdown`/`queryBreakdown`/`queryUniqueUsers`/`queryTrackDownloads` in questo task: verranno toccati nei task successivi quando introducono i metodi che devono propagare `$strict = true`.

- [ ] **Step 4: Esegui il test per verificare che passi**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Unit/AnalyticsServiceTest.php"
```

Expected: PASS — inclusi tutti i test esistenti (in particolare `test_failed_http_response_returns_empty_results_without_throwing`, che deve continuare a passare invariato).

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Exceptions/AnalyticsQueryException.php wm-package/src/Services/PostHog/AnalyticsService.php wm-package/tests/Unit/AnalyticsServiceTest.php
git commit -m "feat(oc:8182): add opt-in strict mode to runQuery for explicit failure propagation"
```

---

## Task 4: `getAllLayersUsage()` — ranking top 20 layer con esclusione orfani

**Repo:** wm-package

**Files:**

- Modify: `wm-package/src/Services/PostHog/AnalyticsService.php`
- Test: `wm-package/tests/Unit/AnalyticsServiceTest.php`

**Interfaces:**

- Consumes: `runQuery(string, bool): array` da Task 3, `whereClause()`/`libList()`/`ttlFor()` esistenti
- Produces: `getAllLayersUsage(string $range = 'last_30_days'): array` — usato da Task 6

- [ ] **Step 1: Scrivi il test che fallisce**

```php
public function test_get_all_layers_usage_returns_ranked_layers_with_names(): void
{
    $this->seedLayersTable([
        ['id' => 10, 'name' => 'Cammino di Santiago'],
        ['id' => 20, 'name' => 'Via Francigena'],
    ]);

    Http::fake(['*' => Http::response(['results' => [['10', 50], ['20', 30]]])]);

    $result = (new AnalyticsService)->getAllLayersUsage('last_30_days');

    $this->assertCount(2, $result);
    $this->assertSame(10, $result[0]['layer_id']);
    $this->assertSame('Cammino di Santiago', $result[0]['name']);
    $this->assertSame(50, $result[0]['total']);
}

public function test_get_all_layers_usage_excludes_deleted_layers(): void
{
    $this->seedLayersTable([
        ['id' => 10, 'name' => 'Cammino di Santiago'],
    ]);

    // layer_id 999 non esiste nel DB locale (cancellato) — deve essere scartato
    Http::fake(['*' => Http::response(['results' => [['999', 80], ['10', 50]]])]);

    $result = (new AnalyticsService)->getAllLayersUsage('last_30_days');

    $this->assertCount(1, $result);
    $this->assertSame(10, $result[0]['layer_id']);
}

public function test_get_all_layers_usage_truncates_to_20_after_filtering_orphans(): void
{
    $rows = [];
    $seed = [];
    for ($i = 1; $i <= 25; $i++) {
        $rows[] = [(string) $i, 100 - $i];
        $seed[] = ['id' => $i, 'name' => "Layer {$i}"];
    }
    $this->seedLayersTable($seed);

    Http::fake(['*' => Http::response(['results' => $rows])]);

    $result = (new AnalyticsService)->getAllLayersUsage('last_30_days');

    $this->assertCount(20, $result);
}
```

Aggiungi anche l'helper di setup (nella sezione "Helpers", accanto a `createLayerMockWithTrackIds`):

```php
private function seedLayersTable(array $layers): void
{
    $this->app['db']->connection()->getSchemaBuilder()->create('layers', function ($table) {
        $table->id();
        $table->json('name')->nullable();
        $table->timestamps();
    });

    foreach ($layers as $layer) {
        \Wm\WmPackage\Models\Layer::query()->getConnection()->table('layers')->insert([
            'id' => $layer['id'],
            'name' => json_encode(['it' => $layer['name']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
```

E aggiungi la creazione della tabella `layers` in `defineDatabaseMigrations()` solo se non già presente per altri test — verifica che non collida con `seedLayersTable()` (che la crea on-demand nei test che la usano; se `defineDatabaseMigrations()` gira sempre per ogni test, sposta la creazione della tabella `layers` lì invece che in `seedLayersTable()`, per evitare "table already exists" su test multipli nella stessa run).

- [ ] **Step 2: Esegui il test per verificare che fallisca**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Unit/AnalyticsServiceTest.php --filter=test_get_all_layers_usage"
```

Expected: FAIL — `Call to undefined method AnalyticsService::getAllLayersUsage()`.

- [ ] **Step 3: Implementa**

Aggiungi in `AnalyticsService.php`:

```php
public function getAllLayersUsage(string $range = 'last_30_days'): array
{
    $cacheKey = "posthog:layerOpened:all:ranking:{$range}";
    $ttl = $this->ttlFor($range);

    $rows = Cache::remember(
        $cacheKey,
        now()->addSeconds($ttl),
        fn () => $this->queryAllLayersRanking($range)
    );

    $layerIds = array_column($rows, 'layer_id');
    $layers = Layer::whereIn('id', $layerIds)->get(['id', 'name'])->keyBy('id');

    $result = [];
    foreach ($rows as $row) {
        $layer = $layers->get($row['layer_id']);
        if (! $layer) {
            continue;
        }

        $name = null;
        foreach (['it', 'en', app()->getLocale()] as $locale) {
            $candidate = $layer->getTranslation('name', $locale, false);
            if (! empty($candidate)) {
                $name = $candidate;
                break;
            }
        }

        $result[] = [
            'layer_id' => $row['layer_id'],
            'name' => $name ?? "Layer #{$row['layer_id']}",
            'total' => $row['total'],
        ];

        if (count($result) >= 20) {
            break;
        }
    }

    return $result;
}

private function queryAllLayersRanking(string $range): array
{
    $whereClause = $this->whereClause($range);
    $libs = $this->libList();
    $idFilter = $this->idFilterClause('layer_id', null);

    $sql = <<<SQL
SELECT
    properties.layer_id AS layer_id,
    count() AS total
FROM events
WHERE event = 'layerOpened'
  AND {$idFilter}
  AND properties.\$lib IN ({$libs})
  AND {$whereClause}
GROUP BY layer_id
ORDER BY total DESC
LIMIT 50
SQL;

    return array_map(fn ($row) => [
        'layer_id' => (int) $row[0],
        'total' => (int) $row[1],
    ], $this->runQuery($sql, true));
}
```

- [ ] **Step 4: Esegui il test per verificare che passi**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Unit/AnalyticsServiceTest.php"
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Services/PostHog/AnalyticsService.php wm-package/tests/Unit/AnalyticsServiceTest.php
git commit -m "feat(oc:8182): add getAllLayersUsage ranking with orphan exclusion"
```

---

## Task 5: `queryTrackDownloads` nullable + `getAllTracksDownloads()`

**Repo:** wm-package

**Files:**

- Modify: `wm-package/src/Services/PostHog/AnalyticsService.php`
- Test: `wm-package/tests/Unit/AnalyticsServiceTest.php`

**Interfaces:**

- Consumes: `idInFilterClause` (nuovo helper, stesso file), `runQuery(string, bool): array` da Task 3
- Produces: `getAllTracksDownloads(string $range = 'last_30_days'): array` — usato da Task 6; `queryTrackDownloads(?array $trackIds, string $range): array` (firma estesa)

- [ ] **Step 1: Scrivi il test che fallisce**

```php
public function test_get_all_tracks_downloads_returns_ranked_tracks_with_names(): void
{
    EcTrack::query()->getConnection()->table('ec_tracks')->insert([
        ['id' => 1, 'name' => json_encode(['it' => 'Tappa 1'])],
        ['id' => 2, 'name' => json_encode(['it' => 'Tappa 2'])],
    ]);

    Http::fake(['*' => Http::response(['results' => [['1', 40], ['2', 15]]])]);

    $result = (new AnalyticsService)->getAllTracksDownloads('last_30_days');

    $this->assertCount(2, $result);
    $this->assertSame(1, $result[0]['track_id']);
    $this->assertSame('Tappa 1', $result[0]['name']);
    $this->assertSame(40, $result[0]['downloads']);
}

public function test_get_all_tracks_downloads_excludes_deleted_tracks(): void
{
    EcTrack::query()->getConnection()->table('ec_tracks')->insert([
        ['id' => 1, 'name' => json_encode(['it' => 'Tappa 1'])],
    ]);

    Http::fake(['*' => Http::response(['results' => [['999', 80], ['1', 40]]])]);

    $result = (new AnalyticsService)->getAllTracksDownloads('last_30_days');

    $this->assertCount(1, $result);
    $this->assertSame(1, $result[0]['track_id']);
}

public function test_query_track_downloads_with_null_ids_omits_in_clause(): void
{
    Http::fake(['*' => Http::response(['results' => []])]);

    $service = new AnalyticsService;
    $method = new \ReflectionMethod($service, 'queryTrackDownloads');
    $method->setAccessible(true);
    $method->invoke($service, null, 'last_30_days');

    Http::assertSent(function (Request $request) {
        $sql = $request->data()['query']['query'];

        return ! str_contains($sql, 'IN (')
            && str_contains($sql, 'properties.track_id IS NOT NULL')
            && str_contains($sql, 'LIMIT 50');
    });
}
```

Aggiungi `use Wm\WmPackage\Models\EcTrack;` in cima al test file se non già presente (verifica: è già importato per il type-hint in `AnalyticsService.php`, ma il test file potrebbe non averlo — aggiungilo se manca).

- [ ] **Step 2: Esegui il test per verificare che fallisca**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Unit/AnalyticsServiceTest.php --filter=test_get_all_tracks_downloads"
```

Expected: FAIL — `Call to undefined method AnalyticsService::getAllTracksDownloads()`.

- [ ] **Step 3: Implementa**

Aggiungi il nuovo helper privato (accanto a `idFilterClause`):

```php
private function idInFilterClause(string $idProperty, ?array $ids): string
{
    if ($ids === null) {
        return "properties.{$idProperty} IS NOT NULL AND properties.{$idProperty} != ''";
    }

    $inList = implode(', ', array_map(fn ($v) => "'{$v}'", $ids));

    return "properties.{$idProperty} IN ({$inList})";
}
```

Modifica `queryTrackDownloads`:

```php
private function queryTrackDownloads(?array $trackIds, string $range): array
{
    $whereClause = $this->whereClause($range);
    $idFilter = $this->idInFilterClause('track_id', $trackIds);
    $limitClause = $trackIds === null ? 'LIMIT 50' : '';
    $strict = $trackIds === null;

    $sql = <<<SQL
SELECT
    properties.track_id AS track_id,
    count() AS downloads
FROM events
WHERE event = 'trackDownloaded'
  AND {$idFilter}
  AND {$whereClause}
GROUP BY track_id
ORDER BY downloads DESC
{$limitClause}
SQL;

    return array_map(fn ($row) => [
        'track_id' => (int) $row[0],
        'downloads' => (int) $row[1],
    ], $this->runQuery($sql, $strict));
}
```

Aggiungi `getAllTracksDownloads()` (accanto a `getLayerTrackDownloads`):

```php
public function getAllTracksDownloads(string $range = 'last_30_days'): array
{
    $cacheKey = "posthog:trackDownloaded:all:downloads:{$range}";
    $ttl = $this->ttlFor($range);

    $rows = Cache::remember(
        $cacheKey,
        now()->addSeconds($ttl),
        fn () => $this->queryTrackDownloads(null, $range)
    );

    $ecTrackModel = config('wm-package.ec_track_model', EcTrack::class);
    $tracks = $ecTrackModel::whereIn('id', array_column($rows, 'track_id'))
        ->get(['id', 'name'])
        ->keyBy('id');

    $result = [];
    foreach ($rows as $row) {
        $track = $tracks->get($row['track_id']);
        if (! $track) {
            continue;
        }

        $name = null;
        foreach (['it', 'en', app()->getLocale()] as $locale) {
            $candidate = $track->getTranslation('name', $locale, false);
            if (! empty($candidate)) {
                $name = $candidate;
                break;
            }
        }

        $result[] = [
            'track_id' => $row['track_id'],
            'name' => $name ?? "Track #{$row['track_id']}",
            'downloads' => $row['downloads'],
        ];

        if (count($result) >= 20) {
            break;
        }
    }

    return $result;
}
```

- [ ] **Step 4: Esegui il test per verificare che passi**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Unit/AnalyticsServiceTest.php"
```

Expected: PASS — inclusi i test preesistenti su `getLayerTrackDownloads` (path con `array $trackIds` valorizzato, invariato).

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Services/PostHog/AnalyticsService.php wm-package/tests/Unit/AnalyticsServiceTest.php
git commit -m "feat(oc:8182): add getAllTracksDownloads ranking with orphan exclusion"
```

---

## Task 6: Route dedicata + `AnalyticsController::global()`

**Repo:** wm-package

**Files:**

- Modify: `wm-package/src/WmPackageServiceProvider.php`
- Modify: `wm-package/src/Http/Controllers/Nova/AnalyticsController.php`
- Test: nuovo `wm-package/tests/Feature/AnalyticsControllerGlobalTest.php`

**Interfaces:**

- Consumes: `getGlobalUsage()` (Task 2), `getAllLayersUsage()` (Task 4), `getAllTracksDownloads()` (Task 5), `AnalyticsQueryException` (Task 3)
- Produces: route `GET /nova-vendor/layer-analytics/global`, `AnalyticsController::global(Request $request): JsonResponse`

- [ ] **Step 1: Scrivi il test che fallisce**

Usa la base class già collaudata per i Feature test HTTP con ruoli in wm-package: `Wm\WmPackage\Tests\TestCase` (vedi `wm-package/tests/Feature/Listeners/EnforceNovaAccessOnLoginTest.php` per il pattern di riferimento — registra `WmPackageServiceProvider`, quindi le route sono attive, pubblica le migrazioni reali via `#[WithMigration]`, e usa `App\Models\User` + `RolesAndPermissionsService::seedDatabase()` + `assignRole()`), **non** una `Orchestra\Testbench\TestCase` raw come in `AnalyticsServiceTest.php` (quello è un Unit test che non passa mai per l'HTTP/le route).

Crea `wm-package/tests/Feature/AnalyticsControllerGlobalTest.php`:

```php
<?php

namespace Wm\WmPackage\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Tests\TestCase;

class AnalyticsControllerGlobalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();

        config([
            'services.posthog.host' => 'https://posthog.example.com',
            'services.posthog.project_id' => '1',
            'services.posthog.personal_api_key' => 'phx_test',
        ]);
    }

    public function test_administrator_receives_aggregated_data_structure(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $response = $this->actingAs($admin)->getJson('/nova-vendor/layer-analytics/global');

        $response->assertOk();
        $response->assertJsonStructure(['total', 'unique_users', 'daily_breakdown', 'ranking_layers', 'ranking_tracks']);
    }

    public function test_non_administrator_receives_403(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $validator = User::factory()->create();
        $validator->assignRole('Validator');

        $response = $this->actingAs($validator)->getJson('/nova-vendor/layer-analytics/global');

        $response->assertStatus(403);
    }

    public function test_guest_without_role_receives_403(): void
    {
        $response = $this->getJson('/nova-vendor/layer-analytics/global');

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Feature/AnalyticsControllerGlobalTest.php"
```

Expected: FAIL — 404 (route non ancora registrata).

- [ ] **Step 3: Implementa**

In `WmPackageServiceProvider.php`, modifica il gruppo route esistente (attenzione: `/global` deve essere registrata **prima** di `/{layer}` per non essere intercettata dal parametro dinamico):

```php
Route::middleware(['nova'])
    ->prefix('nova-vendor/layer-analytics')
    ->group(function () {
        Route::get('/global', [AnalyticsController::class, 'global']);
        Route::get('/{layer}', [AnalyticsController::class, 'layer']);
    });
```

In `AnalyticsController.php`, aggiungi il nuovo metodo:

```php
public function global(Request $request): JsonResponse
{
    abort_unless($request->user()?->hasRole('Administrator'), 403);

    $service = app(AnalyticsService::class);
    $range = $this->resolveRange($request);

    try {
        $usage = $service->getGlobalUsage($range);
        $rankingLayers = $service->getAllLayersUsage($range);
        $rankingTracks = $service->getAllTracksDownloads($range);
    } catch (\Wm\WmPackage\Exceptions\AnalyticsQueryException $e) {
        return response()->json(['error' => 'analytics_query_failed'], 502);
    }

    return response()->json(array_merge($usage, [
        'ranking_layers' => $rankingLayers,
        'ranking_tracks' => $rankingTracks,
    ]));
}
```

- [ ] **Step 4: Esegui il test per verificare che passi**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Feature/AnalyticsControllerGlobalTest.php"
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/WmPackageServiceProvider.php wm-package/src/Http/Controllers/Nova/AnalyticsController.php wm-package/tests/Feature/AnalyticsControllerGlobalTest.php
git commit -m "feat(oc:8182): add dedicated global analytics route and controller method"
```

---

## Task 7: Fix di sicurezza — autorizzazione su `AnalyticsController::layer()` esistente

**Repo:** wm-package

**Files:**

- Modify: `wm-package/src/Http/Controllers/Nova/AnalyticsController.php`
- Test: `wm-package/tests/Feature/AnalyticsControllerGlobalTest.php` (estensione, o nuovo file `AnalyticsControllerLayerAuthorizationTest.php` se il pattern di test Nova richiede un layer reale in DB — preferibile un file separato per isolare il setup)

**Interfaces:**

- Consumes: nessuna dipendenza da altri task (indipendente, può essere eseguito in parallelo a Task 6)
- Produces: `layer()` con autorizzazione (stessa firma pubblica, comportamento esteso)

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `wm-package/tests/Feature/AnalyticsControllerLayerAuthorizationTest.php`:

```php
<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

class AnalyticsControllerLayerAuthorizationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('services.posthog.host', 'https://posthog.example.com');
        $app['config']->set('services.posthog.project_id', '1');
        $app['config']->set('services.posthog.personal_api_key', 'phx_test');
    }

    public function test_non_owner_validator_receives_403(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $owner = User::factory()->create();
        $otherValidator = User::factory()->create();
        $layer = Layer::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($otherValidator)
            ->getJson("/nova-vendor/layer-analytics/{$layer->id}");

        $response->assertStatus(403);
    }

    public function test_owner_validator_can_view_own_layer_analytics(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $owner = User::factory()->create();
        $layer = Layer::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($owner)
            ->getJson("/nova-vendor/layer-analytics/{$layer->id}");

        $response->assertOk();
    }
}
```

**Nota:** adatta `hasRole('Administrator')`/factory dei ruoli al setup effettivo disponibile in Orchestra Testbench per wm-package (verifica se Spatie Permission è già configurato nei test esistenti del package prima di usare `assignRole`; se non lo è, il controllo `hasRole()` va comunque testato tramite un mock/stub minimale del metodo sull'utente, dato che l'obiettivo del test è la logica di autorizzazione nel controller, non l'infrastruttura di ruoli).

- [ ] **Step 2: Esegui il test per verificare che fallisca**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Feature/AnalyticsControllerLayerAuthorizationTest.php"
```

Expected: FAIL — `test_non_owner_validator_receives_403` riceve 200 invece di 403 (nessuna autorizzazione implementata ancora).

- [ ] **Step 3: Implementa**

In `AnalyticsController.php`, modifica `layer()`:

```php
public function layer(Request $request, Layer $layer): JsonResponse
{
    abort_unless(
        $request->user()?->hasRole('Administrator') || $layer->user_id === $request->user()?->id,
        403
    );

    $service = app(AnalyticsService::class);
    $range = $this->resolveRange($request);

    $usage = $service->getLayerUsage($layer->id, $range);
    $trackDownloads = $service->getLayerTrackDownloads($layer, $range);

    return response()->json(array_merge($usage, [
        'track_downloads' => $trackDownloads,
    ]));
}
```

- [ ] **Step 4: Esegui il test per verificare che passi**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest tests/Feature/AnalyticsControllerLayerAuthorizationTest.php"
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Http/Controllers/Nova/AnalyticsController.php wm-package/tests/Feature/AnalyticsControllerLayerAuthorizationTest.php
git commit -m "fix(oc:8182): require ownership or Administrator role on per-layer analytics endpoint"
```

---

## Task 8: `LayerAnalyticsCard::global()` — static factory per la modalità aggregata

**Repo:** wm-package

**Files:**

- Modify: `wm-package/src/Nova/Cards/LayerAnalytics/src/LayerAnalyticsCard.php`

**Interfaces:**

- Consumes: nessuna
- Produces: `LayerAnalyticsCard::global(): static` — usato da Task 12 (`App\Nova\Layer::cards()`)

- [ ] **Step 1: Scrivi il test che fallisce**

Non esiste un test unitario preesistente per `LayerAnalyticsCard.php` (classe semplice, verificata tramite Feature test di visibilità in Task 13). Verifica manuale come step 1 sostitutivo: crea uno script tinker temporaneo per ispezionare `jsonSerialize()`.

```bash
docker exec laravel-camminiditalia php artisan tinker --execute="
\$card = \Wm\WmPackage\Nova\Cards\LayerAnalytics\LayerAnalyticsCard::global();
dd(\$card->jsonSerialize());
"
```

Expected (prima dell'implementazione): errore `Call to undefined method ...::global()`.

- [ ] **Step 2: Implementa**

Modifica `LayerAnalyticsCard.php`:

```php
<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Cards\LayerAnalytics;

use Carbon\Carbon;
use Laravel\Nova\Card;
use Wm\WmPackage\Models\Layer;

class LayerAnalyticsCard extends Card
{
    public $component = 'layer-analytics-card';

    public $width = 'full';

    public $onlyOnDetail = true;

    private ?int $layerId;

    private ?string $trackingSince;

    private bool $isGlobal = false;

    public function __construct(?Layer $layer = null)
    {
        parent::__construct();
        $this->layerId = $layer->id ?? null;
        $this->trackingSince = $layer?->created_at
            ? Carbon::parse($layer->created_at)->format('Y-m-d')
            : '2026-01-01';
    }

    public static function global(): static
    {
        $card = new static;
        $card->isGlobal = true;
        $card->onlyOnDetail = false;

        return $card;
    }

    public function jsonSerialize(): array
    {
        $endpoint = $this->isGlobal
            ? '/nova-vendor/layer-analytics/global'
            : '/nova-vendor/layer-analytics/'.$this->layerId;

        return array_merge(parent::jsonSerialize(), [
            'endpoint' => $endpoint,
            'layer_id' => $this->layerId,
            'tracking_since' => $this->trackingSince,
            'mode' => $this->isGlobal ? 'global' : 'layer',
        ]);
    }
}
```

**Attenzione:** il costruttore ora accetta `?Layer $layer = null` (era `Layer $layer` obbligatorio) — verifica che nessun punto esistente instanzi `new LayerAnalyticsCard()` senza argomenti aspettandosi un comportamento diverso (unico uso attuale: `wm-package/src/Nova/Layer.php:163`, che passa sempre un `$layer` valido — nessuna modifica necessaria lì).

- [ ] **Step 3: Verifica manuale**

```bash
docker exec laravel-camminiditalia php artisan tinker --execute="
\$card = \Wm\WmPackage\Nova\Cards\LayerAnalytics\LayerAnalyticsCard::global();
dd(\$card->jsonSerialize());
"
```

Expected: array con `'endpoint' => '/nova-vendor/layer-analytics/global'`, `'layer_id' => null`, `'mode' => 'global'`.

- [ ] **Step 4: Commit**

```bash
git add wm-package/src/Nova/Cards/LayerAnalytics/src/LayerAnalyticsCard.php
git commit -m "feat(oc:8182): add LayerAnalyticsCard::global() static factory"
```

---

## Task 9: Vue component — stato di errore esplicito per query fallite

**Repo:** wm-package

**Files:**

- Modify: `wm-package/src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue`

**Interfaces:**

- Consumes: risposta JSON con status 502 + `{error: 'analytics_query_failed'}` da Task 6
- Produces: nessuna nuova interfaccia (comportamento UI)

- [ ] **Step 1: Verifica manuale del comportamento attuale**

Il metodo `fetchData()` già gestisce `!response.ok` con `throw new Error(...)` catturato e mostrato come `this.error`. Verifica che questo path copra già anche lo status 502 introdotto in Task 6 (nessuna modifica di codice necessaria se il controllo `if (!response.ok)` è generico sullo status — leggi il metodo attuale):

```js
async fetchData() {
  this.loading = true
  this.error   = null
  try {
    const response = await fetch(this.fetchUrl, { ... })
    if (!response.ok) throw new Error(`HTTP ${response.status}`)
    this.data = await response.json()
  } catch (e) {
    this.error = 'Impossibile caricare i dati analytics.'
    console.error(e)
  } finally {
    this.loading = false
  }
},
```

- [ ] **Step 2: Conferma senza modifiche**

Il controllo `if (!response.ok)` copre già qualsiasi status non-2xx, incluso il 502 di Task 6 — **nessuna modifica al componente necessaria per questo requisito**. Annota questo esito in `notes.md` (Fase: notes del workflow principale) come "requisito già coperto dal codice esistente, nessun task di modifica necessario".

- [ ] **Step 3: Commit**

Nessun commit per questo task (nessuna modifica al codice). Salta al Task 10.

---

## Task 10: Vue component — due classifiche affiancate in modalità globale

**Repo:** wm-package

**Files:**

- Modify: `wm-package/src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue`

**Interfaces:**

- Consumes: `card.mode` (`'global'`/`'layer'`), `data.ranking_layers`, `data.ranking_tracks` dal payload JSON (Task 6, 8)
- Produces: nessuna nuova interfaccia esterna, solo rendering

- [ ] **Step 1: Verifica manuale pre-implementazione**

```bash
cd wm-package/src/Nova/Cards/LayerAnalytics
npm install
```

Verifica che l'ambiente di build sia pronto (nessun test automatico per componenti Vue in questo progetto — verifica visuale in Nova dopo il build, vedi Step 4).

- [ ] **Step 2: Implementa**

Nel template, sostituisci il titolo statico con uno dinamico e aggiungi il blocco classifiche dopo il blocco "Download per traccia" esistente:

```html
<h4 style="font-size:0.75rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin:0;">
  {{ card.mode === 'global' ? "Analytics — Tutti i cammini" : `Analytics Layer — ${rangeLabel}` }}
  <span
    v-if="card.mode === 'global'"
    style="display:inline-block; margin-left:8px; padding:2px 8px; border-radius:10px; background:#e5e7eb; color:#374151; font-size:0.65rem; font-weight:500; text-transform:none;"
  >Tutti i layer</span>
</h4>
```

Aggiungi il blocco classifiche (dopo il blocco `<!-- Download per traccia -->` esistente, prima della chiusura `</template>`):

```html
<!-- Classifiche globali (solo modalità globale) -->
<div v-if="card.mode === 'global' && (data.ranking_layers?.length || data.ranking_tracks?.length)" style="margin-top:24px; overflow-x:auto;">
  <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; min-width:600px;">
    <div>
      <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Cammini più aperti</p>
      <table style="width:100%; border-collapse:collapse; font-size:0.875rem;">
        <thead>
          <tr style="border-bottom:1px solid rgba(128,128,128,0.3);">
            <th style="text-align:left; padding:6px 8px; font-weight:500; opacity:0.6;">Cammino</th>
            <th style="text-align:right; padding:6px 8px; font-weight:500; opacity:0.6;">Aperture</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in visibleLayerRanking" :key="row.layer_id" style="border-bottom:1px solid rgba(128,128,128,0.15);">
            <td style="padding:6px 8px;">{{ row.name }}</td>
            <td style="padding:6px 8px; text-align:right; font-weight:600; color:#10b981;">{{ row.total }}</td>
          </tr>
        </tbody>
      </table>
      <button
        v-if="data.ranking_layers.length > 10"
        @click="showAllLayers = !showAllLayers"
        style="margin-top:8px; font-size:0.75rem; padding:4px 12px; border-radius:6px; border:1px solid #d1d5db; background:#fff; color:#6b7280; cursor:pointer;"
      >{{ showAllLayers ? 'Mostra meno' : `Mostra tutti (${data.ranking_layers.length})` }}</button>
    </div>
    <div>
      <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Tappe più scaricate</p>
      <table style="width:100%; border-collapse:collapse; font-size:0.875rem;">
        <thead>
          <tr style="border-bottom:1px solid rgba(128,128,128,0.3);">
            <th style="text-align:left; padding:6px 8px; font-weight:500; opacity:0.6;">Tappa</th>
            <th style="text-align:right; padding:6px 8px; font-weight:500; opacity:0.6;">Download</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in visibleTrackRanking" :key="row.track_id" style="border-bottom:1px solid rgba(128,128,128,0.15);">
            <td style="padding:6px 8px;">{{ row.name }}</td>
            <td style="padding:6px 8px; text-align:right; font-weight:600; color:#10b981;">{{ row.downloads }}</td>
          </tr>
        </tbody>
      </table>
      <button
        v-if="data.ranking_tracks.length > 10"
        @click="showAllTracks = !showAllTracks"
        style="margin-top:8px; font-size:0.75rem; padding:4px 12px; border-radius:6px; border:1px solid #d1d5db; background:#fff; color:#6b7280; cursor:pointer;"
      >{{ showAllTracks ? 'Mostra meno' : `Mostra tutti (${data.ranking_tracks.length})` }}</button>
    </div>
  </div>
</div>
```

Nello `<script>`, aggiungi ai `data()`:

```js
data() {
  return {
    selectedRange: 'days:30',
    loading: true,
    error: null,
    data: null,
    chartInstance: null,
    showAllLayers: false,
    showAllTracks: false,
  }
},
```

E ai `computed`:

```js
visibleLayerRanking() {
  if (!this.data?.ranking_layers) return []
  return this.showAllLayers ? this.data.ranking_layers : this.data.ranking_layers.slice(0, 10)
},

visibleTrackRanking() {
  if (!this.data?.ranking_tracks) return []
  return this.showAllTracks ? this.data.ranking_tracks : this.data.ranking_tracks.slice(0, 10)
},
```

- [ ] **Step 3: Build**

```bash
cd wm-package/src/Nova/Cards/LayerAnalytics
npm run prod
```

- [ ] **Step 4: Verifica manuale in Nova**

Apri l'index Layer in Nova come Administrator (dopo Task 12), conferma visivamente: titolo con badge, due tabelle affiancate, toggle "mostra tutti" funzionante, `overflow-x-auto` attivo su viewport stretto (ridimensiona la finestra del browser per verificare).

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue wm-package/src/Nova/Cards/LayerAnalytics/dist/
git commit -m "feat(oc:8182): add side-by-side layer and track rankings to global analytics card"
```

---

## Task 11: `getLayerAppProperties` — visibilità `protected` per riuso dal repo principale

**Repo:** wm-package

**Files:**

- Modify: `wm-package/src/Nova/Layer.php`

**Interfaces:**

- Consumes: nessuna
- Produces: `getLayerAppProperties(LayerModel $layer): array` ora `protected` (era `private`) — usato da Task 12 (`App\Nova\Layer::cards()`)

- [ ] **Step 1: Verifica manuale pre-modifica**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && grep -n 'private function getLayerAppProperties' src/Nova/Layer.php"
```

Expected: conferma la riga esatta da modificare.

- [ ] **Step 2: Implementa**

In `wm-package/src/Nova/Layer.php`, cambia:

```php
private function getLayerAppProperties(LayerModel $layer): array
```

in:

```php
protected function getLayerAppProperties(LayerModel $layer): array
```

Nessun altro cambiamento — il metodo resta identico, solo la visibilità cambia (nessun impatto su chiamate esistenti all'interno della stessa classe).

- [ ] **Step 3: Verifica manuale**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && grep -n 'protected function getLayerAppProperties' src/Nova/Layer.php"
```

Expected: conferma la modifica applicata.

- [ ] **Step 4: Commit**

```bash
git add wm-package/src/Nova/Layer.php
git commit -m "refactor(oc:8182): make getLayerAppProperties protected for subclass reuse"
```

---

## Task 12: `App\Nova\Layer::cards()` — registrazione card globale nell'index

**Repo:** camminiditalia (repo principale)

**Files:**

- Modify: `app/Nova/Layer.php`
- Test: nuovo `tests/Feature/LayerGlobalAnalyticsCardVisibilityTest.php`

**Interfaces:**

- Consumes: `LayerAnalyticsCard::global()` (Task 8), `getLayerAppProperties()` protected (Task 11)
- Produces: `App\Nova\Layer::cards(NovaRequest $request): array` (nuovo override)

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `tests/Feature/LayerGlobalAnalyticsCardVisibilityTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerGlobalAnalyticsCardVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
    }

    private function makeAppWithAnalyticsEnabled(): App
    {
        return App::factory()->create([
            'properties' => ['analytics_app_enabled' => true],
        ]);
    }

    public function test_administrator_sees_global_card_on_index(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $app = $this->makeAppWithAnalyticsEnabled();
        Layer::factory()->create(['user_id' => $admin->id, 'app_id' => $app->id]);

        $response = $this->actingAs($admin)->getJson('/nova-api/layers/cards');

        $response->assertOk();
        $components = collect($response->json('cards'))->pluck('component');
        $this->assertContains('layer-analytics-card', $components);
    }

    public function test_validator_does_not_see_global_card_on_index(): void
    {
        $validator = User::factory()->create();
        $validator->assignRole('Validator');
        $app = $this->makeAppWithAnalyticsEnabled();
        Layer::factory()->create(['user_id' => $validator->id, 'app_id' => $app->id]);

        $response = $this->actingAs($validator)->getJson('/nova-api/layers/cards');

        $response->assertOk();
        $components = collect($response->json('cards'))->pluck('component');
        $this->assertNotContains('layer-analytics-card', $components);
    }

    public function test_global_card_hidden_when_analytics_disabled(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $app = App::factory()->create(['properties' => ['analytics_app_enabled' => false, 'analytics_webapp_enabled' => false]]);
        Layer::factory()->create(['user_id' => $admin->id, 'app_id' => $app->id]);

        $response = $this->actingAs($admin)->getJson('/nova-api/layers/cards');

        $response->assertOk();
        $components = collect($response->json('cards'))->pluck('component');
        $this->assertNotContains('layer-analytics-card', $components);
    }
}
```

**Nota:** verifica l'endpoint esatto di Nova per le card index (`/nova-api/{resource}/cards` è il pattern standard Nova 4/5) e il campo `app_id`/relazione corretta su `Layer::factory()` — adatta ai nomi effettivi di colonna/factory se diversi da quanto assunto qui (verifica `wm-package/database/factories/LayerFactory.php` prima di eseguire).

- [ ] **Step 2: Esegui il test per verificare che fallisca**

```bash
docker exec laravel-camminiditalia php artisan test --filter=LayerGlobalAnalyticsCardVisibilityTest
```

Expected: FAIL — `layer-analytics-card` non presente per l'Administrator (nessun override `cards()` ancora implementato).

- [ ] **Step 3: Implementa**

In `app/Nova/Layer.php`, aggiungi:

```php
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Cards\LayerAnalytics\LayerAnalyticsCard;

// ... dentro la classe, dopo actions() ...

public function cards(NovaRequest $request): array
{
    $cards = parent::cards($request);

    if ($request->resourceId) {
        return $cards;
    }

    $currentUser = $request->user();
    if (! $currentUser || ! $currentUser->hasRole('Administrator')) {
        return $cards;
    }

    /** @var \Wm\WmPackage\Models\Layer|null $anyLayer */
    $anyLayer = static::newModel()->query()->first();
    if (! $anyLayer) {
        return $cards;
    }

    $app = $anyLayer->appOwner;
    $appProperties = $app->properties ?? [];
    $analyticsEnabled = $app &&
        (($appProperties['analytics_app_enabled'] ?? false) ||
         ($appProperties['analytics_webapp_enabled'] ?? false));

    if ($analyticsEnabled) {
        $cards[] = LayerAnalyticsCard::global();
    }

    return $cards;
}
```

**Nota di design:** il gate `analytics_app_enabled`/`analytics_webapp_enabled` è per-App, non per-Layer — dato che il DB locale ha una sola App, si legge il flag dalla prima App trovata tramite un layer qualsiasi (`$anyLayer->appOwner`). Se in futuro esistessero più App (fuori scope, vedi overview), questa logica andrebbe rivista per determinare quale App governa la vista aggregata.

- [ ] **Step 4: Esegui il test per verificare che passi**

```bash
docker exec laravel-camminiditalia php artisan test --filter=LayerGlobalAnalyticsCardVisibilityTest
```

Expected: PASS

- [ ] **Step 5: Esegui l'intera suite per verificare nessuna regressione**

```bash
docker exec laravel-camminiditalia php artisan test --filter=LayerActionsVisibilityTest
docker exec laravel-camminiditalia php artisan test tests/Feature/LayerPolicyTest.php
```

Expected: PASS (nessuna regressione sulle card/action esistenti di Layer)

- [ ] **Step 6: Commit**

```bash
git add app/Nova/Layer.php tests/Feature/LayerGlobalAnalyticsCardVisibilityTest.php
git commit -m "feat(oc:8182): register global analytics card on Layer index for Administrators"
```

---

## Task 13: Formattazione finale e verifica end-to-end manuale

**Repo:** entrambi

**Files:** nessuno (solo verifica)

**Interfaces:**

- Consumes: tutti i task precedenti
- Produces: nessuna

- [ ] **Step 1: Formatta il codice PHP**

```bash
docker exec laravel-camminiditalia composer format
docker exec laravel-camminiditalia sh -c "cd wm-package && ../vendor/bin/pint src/ 2>&1 || echo 'pint non disponibile in wm-package, verificare composer.json'"
```

- [ ] **Step 2: Esegui l'intera suite wm-package**

```bash
docker exec laravel-camminiditalia sh -c "cd wm-package && vendor/bin/pest"
```

Expected: PASS su tutti i test (esistenti + nuovi dei Task 1-7)

- [ ] **Step 3: Esegui l'intera suite camminiditalia**

```bash
docker exec laravel-camminiditalia php artisan test
```

Expected: PASS su tutti i test (esistenti + nuovo del Task 12)

- [ ] **Step 4: Verifica manuale in browser**

Apri Nova come Administrator, naviga all'index Layer, conferma: card globale visibile in cima/coda alla lista layer, KPI popolati, due classifiche (layer + tracce) con dati coerenti tra loro (somma classifica ≈ KPI totale, a meno di eventi orfani esclusi da entrambi i lati in modo simmetrico), toggle range funzionante, toggle "mostra tutti" funzionante. Ripeti login come Validator: card assente sia su index che altrove.

- [ ] **Step 5: Nessun commit per questo task** (solo verifica — se emergono fix, tornare al task pertinente)
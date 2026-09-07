> Ticket: oc:8464

# Analytics shard name per query PostHog — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **⚠️ Vincolo Webmapp:** nessun commit/push/branch automatico durante l'esecuzione. I comandi `git` nei task sono istruzioni testuali per lo sviluppatore, non azioni da eseguire autonomamente — vedi `Fase: execution` di `wm-plan`.

**Goal:** Disaccoppiare il filtro shard usato dalle query PostHog (dashboard analytics Nova) dallo shard usato per storage/link, introducendo una nuova chiave di config opzionale `analytics_shard_name` con fallback a runtime sul comportamento esistente.

**Architecture:** `AnalyticsService::shardNameClause()` (wm-package) legge `config('wm-package.analytics_shard_name')` con priorità; se vuota dopo `trim()`, ricade su `config('wm-package.shard_name')` esattamente come oggi. Nessun'altra parte del sistema (storage, link Nova) viene toccata. Documentazione (`.env-example`, docblock) copre i rischi accettati senza guardie di codice.

**Tech Stack:** Laravel (wm-package, submodule), PHPUnit/Orchestra Testbench (`tests/Unit/AnalyticsServiceTest.php`).

**Spec:**
- `wm-package/docs/features/8464-shard-name-fallback-app-name/overview.md`
- `docs/features/8464-shard-name-fallback-app-name/overview.md`

## Global Constraints

- Nessuna modifica a `SHARD_NAME`, al suo fallback su `APP_NAME`, o a qualunque `.env` con valori attivi — solo righe commentate in `.env-example`.
- Nessuna modifica a `StorageService`, `Nova\Layer::$shard`, `EcTrackApiLinksCard` — restano ancorati a `shard_name`.
- Nessun backport su `develop`/`main` di wm-package — fix confinato al branch `RDO_ass_cammini_italia_2026_2` (già checked out nel submodule).
- Nessuna guardia applicativa (blocco per ambiente) né logging aggiuntivo — mitigazioni dei rischi solo testuali (commenti/docblock).
- Il fallback `analytics_shard_name` → `shard_name` deve essere risolto **a runtime dentro il metodo**, non pre-calcolato nel file di config — `tests/Unit/AnalyticsServiceTest.php` fissa `wm-package.shard_name` via `config([...])` a runtime nel `setUp()`/nei singoli test, e questo comportamento non deve rompersi.
- Commit convention: `fix(oc:8464): ...`.

---

## Task 1: Nuova chiave di config `analytics_shard_name`

**Files:**
- Modify: `wm-package/config/wm-package.php:6` (subito dopo `shard_name`)

**Interfaces:**
- Produce: `config('wm-package.analytics_shard_name')`, `string|null`, default `null` (nessun fallback pre-calcolato qui — il fallback vive in `AnalyticsService::shardNameClause()`, Task 2).

- [ ] **Step 1: Aggiungi la chiave**

In `wm-package/config/wm-package.php`, subito dopo la riga `'shard_name' => env('SHARD_NAME', env('APP_NAME')),`:

```php
    'shard_name' => env('SHARD_NAME', env('APP_NAME')),
    'analytics_shard_name' => env('ANALYTICS_SHARD_NAME'),
```

- [ ] **Step 2: Verifica manuale**

```bash
docker exec laravel-camminiditalia php artisan tinker --execute="dump(config('wm-package.analytics_shard_name'));"
```

Atteso: `NULL` (nessuna `ANALYTICS_SHARD_NAME` impostata in `.env` di default).

- [ ] **Step 3: Commit**

```bash
cd wm-package
git add config/wm-package.php
git commit -m "fix(oc:8464): add analytics_shard_name config key"
```

---

## Task 2: `AnalyticsService::shardNameClause()` — fallback a runtime + docblock dei rischi

**Files:**
- Modify: `wm-package/src/Services/PostHog/AnalyticsService.php:497-519` (metodo `shardNameClause()` e il suo docblock)
- Test: `wm-package/tests/Unit/AnalyticsServiceTest.php`

**Interfaces:**
- Consuma: `config('wm-package.analytics_shard_name')` (Task 1), `config('wm-package.shard_name')` (esistente, invariato).
- Produce: `shardNameClause(): string` — stessa firma e stesso contratto di ritorno di oggi (clausola SQL `AND (...)` o stringa vuota), usata da `whereClause()` e da tutte le query pubbliche del service (nessuna di queste va toccata).

- [ ] **Step 1: Scrivi i test falliti**

In `wm-package/tests/Unit/AnalyticsServiceTest.php`, nella sezione `// Filtro shard_name (oc:8354)` (dopo `test_where_clause_filters_by_configured_shard_name`, riga 541), aggiungi:

```php
    public function test_where_clause_uses_analytics_shard_name_when_configured(): void
    {
        config([
            'wm-package.shard_name' => 'camminiditaliadev',
            'wm-package.analytics_shard_name' => 'camminiditalia',
        ]);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getLayerUsage(1);

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains($sql, "properties.shard_name._value = 'camminiditalia'")
                && str_contains($sql, "properties.shard_name = 'camminiditalia'")
                && ! str_contains($sql, 'camminiditaliadev');
        });
    }

    public function test_where_clause_falls_back_to_shard_name_when_analytics_shard_name_not_configured(): void
    {
        config([
            'wm-package.shard_name' => 'camminiditalia',
            'wm-package.analytics_shard_name' => null,
        ]);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getLayerUsage(1);

        Http::assertSent(function (Request $request) {
            $sql = $request->data()['query']['query'];

            return str_contains($sql, "properties.shard_name._value = 'camminiditalia'")
                && str_contains($sql, "properties.shard_name = 'camminiditalia'");
        });
    }

    public function test_where_clause_falls_back_to_shard_name_when_analytics_shard_name_is_whitespace(): void
    {
        config([
            'wm-package.shard_name' => 'camminiditalia',
            'wm-package.analytics_shard_name' => '   ',
        ]);
        Cache::flush();
        Http::fake(['*' => Http::response(['results' => []])]);

        (new AnalyticsService)->getLayerUsage(1);

        Http::assertSent(fn (Request $request) => str_contains(
            $request->data()['query']['query'],
            "properties.shard_name._value = 'camminiditalia'"
        ));
    }
```

- [ ] **Step 2: Esegui i test e verifica che i primi due falliscano**

```bash
docker exec laravel-camminiditalia vendor/bin/phpunit wm-package/tests/Unit/AnalyticsServiceTest.php --filter test_where_clause_uses_analytics_shard_name_when_configured
docker exec laravel-camminiditalia vendor/bin/phpunit wm-package/tests/Unit/AnalyticsServiceTest.php --filter test_where_clause_falls_back_to_shard_name_when_analytics_shard_name_not_configured
docker exec laravel-camminiditalia vendor/bin/phpunit wm-package/tests/Unit/AnalyticsServiceTest.php --filter test_where_clause_falls_back_to_shard_name_when_analytics_shard_name_is_whitespace
```

Atteso:
- Il primo test FALLISCE (`shardNameClause()` legge ancora solo `shard_name`, quindi la query userà `camminiditaliadev` invece di `camminiditalia`).
- Il secondo e il terzo PASSANO già oggi (comportamento di fallback implicito, dato che `config('wm-package.analytics_shard_name')` non esiste ancora come chiave letta da nessun codice) — è normale, confermano solo che non li stiamo rompendo.

- [ ] **Step 3: Implementa il fallback a runtime + arricchisci il docblock**

Sostituisci in `wm-package/src/Services/PostHog/AnalyticsService.php` il metodo e il suo docblock (righe 497-519):

```php
    /**
     * Filtra gli eventi per shard (config('wm-package.analytics_shard_name'), con fallback su
     * config('wm-package.shard_name')) per evitare che eventi di altri consumer wm-package sullo
     * stesso progetto PostHog condiviso si mescolino nelle metriche. La property ha due forme
     * osservate empiricamente su dati reali di produzione: annidata (properties.shard_name._value,
     * formato attuale eventi mobile camminiditalia) e flat (properties.shard_name, osservato su
     * eventi storici di altri shard) — l'OR copre entrambe. Config vuoto disabilita il filtro
     * (fail-open, comportamento pre-fix) invece di produrre una clausola che non fa mai match e
     * azzererebbe silenziosamente ogni metrica.
     *
     * `analytics_shard_name` (env ANALYTICS_SHARD_NAME, oc:8464) esiste SOLO per disaccoppiare il
     * filtro delle query PostHog dallo shard di storage/link (config('wm-package.shard_name'), usato
     * da StorageService, Nova\Layer, EcTrackApiLinksCard — MAI da toccare qui). Caso d'uso: un
     * ambiente non-prod (shard_name diverso da produzione, es. 'camminiditaliadev') che deve
     * interrogare temporaneamente i dati reali di produzione su PostHog (es. 'camminiditalia').
     *
     * Limiti noti, accettati e NON mitigati con logica applicativa (solo documentati, decisione
     * esplicita del dev in fase di challenge oc:8464):
     * - NON impostare mai in produzione: se valorizzata con lo shard di un altro cliente Webmapp
     *   sullo stesso progetto PostHog condiviso, la dashboard mostrerebbe dati cross-tenant (stesso
     *   rischio chiuso da oc:8354, riaperto da questo canale di config se usato in modo improprio).
     *   Nessuna guardia di codice (es. blocco su ambiente production) — solo questo avviso.
     * - Nessun log quando l'override è attivo (a differenza del warning sotto per il caso "vuoto");
     *   non c'è quindi segnale diagnostico su quale shard sia stato effettivamente usato in una query.
     * - Il confronto è case-sensitive e senza validazione: un typo o un mismatch di maiuscole produce
     *   silenziosamente zero risultati, non un errore.
     * - Se mai impostata in un ambiente con `config:cache` attivo, richiede un `config:cache` esplicito
     *   dopo la modifica del `.env` per avere effetto.
     */
    private function shardNameClause(): string
    {
        $shardName = trim((string) config('wm-package.analytics_shard_name'));

        if ($shardName === '') {
            $shardName = trim((string) config('wm-package.shard_name'));
        }

        if ($shardName === '') {
            Log::warning('AnalyticsService: wm-package.shard_name non configurato, filtro shard disabilitato');

            return '';
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $shardName);

        return " AND (properties.shard_name._value = '{$escaped}' OR properties.shard_name = '{$escaped}')";
    }
```

- [ ] **Step 4: Esegui tutti i test del file e verifica che passino, inclusi quelli pre-esistenti**

```bash
docker exec laravel-camminiditalia vendor/bin/phpunit wm-package/tests/Unit/AnalyticsServiceTest.php
```

Atteso: tutti i test verdi, incluse le 10 asserzioni pre-esistenti sul filtro `shard_name` (righe 527-682) e le 3 nuove aggiunte in Step 1 — nessuna modifica richiesta ai test pre-esistenti.

- [ ] **Step 5: Commit**

```bash
cd wm-package
git add src/Services/PostHog/AnalyticsService.php tests/Unit/AnalyticsServiceTest.php
git commit -m "fix(oc:8464): decouple PostHog shard filter from storage shard_name"
```

---

## Task 3: Documentazione `.env-example` (repo principale)

**Files:**
- Modify: `.env-example` (repo principale `camminiditalia`, vicino alle righe `APP_NAME`/`APP_ENV`, riga 3/20)

**Interfaces:**
- Nessuna — solo commenti, nessun valore attivo, nessuna riga eseguibile.

- [ ] **Step 1: Aggiungi le righe commentate**

In `.env-example`, dopo la riga `APP_NAME=camminiditalia` (riga 3), aggiungi:

```
APP_NAME=camminiditalia
# Shard usato per storage (bucket AWS) e per i link generati da Nova (Layer, EcTrack) —
# NON per le query PostHog. Se non impostata, ricade su APP_NAME (comportamento attuale,
# invariato da oc:8464 — vedi wm-package/docs/features/8464-shard-name-fallback-app-name/).
#SHARD_NAME=camminiditalia
# Shard usato SOLO per filtrare le query PostHog della dashboard analytics Nova (oc:8464).
# Se non impostata, ricade su SHARD_NAME (o APP_NAME). Uso: interrogare temporaneamente dati
# reali di produzione da un ambiente non-prod (es. ANALYTICS_SHARD_NAME=camminiditalia mentre
# SHARD_NAME resta camminiditaliadev). NON IMPOSTARE MAI IN PRODUZIONE: se puntasse allo shard
# di un altro cliente Webmapp sullo stesso progetto PostHog condiviso, la dashboard mostrerebbe
# dati cross-tenant (vedi wm-package: AnalyticsService::shardNameClause()).
#ANALYTICS_SHARD_NAME=camminiditalia
```

- [ ] **Step 2: Verifica**

```bash
grep -n "SHARD_NAME" .env-example
```

Atteso: entrambe le righe presenti, entrambe commentate (prefisso `#`), nessun valore attivo introdotto in nessun `.env` reale.

- [ ] **Step 3: Commit**

```bash
git add .env-example
git commit -m "fix(oc:8464): document SHARD_NAME and ANALYTICS_SHARD_NAME in .env-example"
```

---

## Task 4: Bump gitlink submodule `wm-package` (da eseguire per ultimo, dopo il merge upstream)

**Files:**
- Modify: `wm-package` (gitlink, repo principale `camminiditalia`)

**Interfaces:**
- Nessuna — aggiornamento del puntatore del submodule al commit che include Task 1 e Task 2, dopo che quel commit è stato mergiato/pushato su `RDO_ass_cammini_italia_2026_2` di `wm-package`.

**⚠️ Precondizione:** questo task richiede che i commit di Task 1 e Task 2 siano già stati pushati su `wm-package` (branch `RDO_ass_cammini_italia_2026_2`) — non eseguirlo se quei commit sono ancora solo locali. Coerente con il vincolo "no commit/push automatici": il push del submodule resta un'azione esplicita dello sviluppatore.

- [ ] **Step 1: Verifica lo stato del submodule**

```bash
cd wm-package && git log --oneline -3 && git status --short
```

Atteso: i commit di Task 1/2 sono in cima al log e il branch è pushato (nessun "ahead of origin" residuo).

- [ ] **Step 2: Aggiorna il gitlink nel repo principale**

```bash
cd /Users/peco/Documents/BackEnd/camminiditalia
git add wm-package
git commit -m "fix(oc:8464): update wm-package gitlink to analytics_shard_name fix"
```

- [ ] **Step 3: Verifica finale**

```bash
git submodule status
```

Atteso: il commit hash di `wm-package` corrisponde a quello con il fix (Task 2, Step 5).

---

## Self-Review

**1. Copertura requisiti overview:**
- Requisito 1 (nuova chiave config) → Task 1 ✅
- Requisito 2 (fallback a runtime in `shardNameClause()`) → Task 2 ✅
- Requisito 3 (zero modifiche a StorageService/Nova\Layer/EcTrackApiLinksCard) → nessun task li tocca, vincolo rispettato per costruzione ✅
- Requisito 4 (nuovi test override + fallback, test esistenti invariati) → Task 2 ✅ (3 test nuovi, 10 pre-esistenti non modificati)
- Requisito 5 (`.env-example` con 2 righe commentate) → Task 3 ✅
- Requisito 6 (docblock con avvisi dei rischi) → Task 2, Step 3 ✅
- Requisito 7 (bump gitlink dopo merge) → Task 4 ✅

**2. Placeholder scan:** nessun "TBD"/"implement later"/generico "handle edge cases" — ogni step ha codice o comando eseguibile completo.

**3. Coerenza tipi/firme:** `shardNameClause(): string` invariata in firma e contratto di ritorno rispetto alla versione pre-fix; nessun altro metodo pubblico del service viene toccato, quindi nessun rischio di firme disallineate tra task.

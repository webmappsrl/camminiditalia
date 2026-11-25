> Ticket: oc:8958

# Nomenclatura regioni su "Filtri" — piano `camminiditalia`

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** gli attributi `taxonomy_where` dei layer prendono il `name` dalle `TaxonomyWhere` del DB,
e una correzione del nome in Nova arriva da sola al `config.json`.

**Architecture:** `LayerAttributesService::wheres()` resta l'unico punto che decide quali regioni
attraversa un cammino (osmfeatures); `normalizeWheres()` calcola `value` come oggi e poi sostituisce
`name` con le traduzioni della `TaxonomyWhere` che ha lo stesso `osmfeatures_id`. Un observer sul
`TaxonomyWhere` del package ricalcola i layer interessati e rigenera una volta il config.

**Tech Stack:** Laravel, PostgreSQL/PostGIS, Horizon, PHPUnit (`php artisan test`).

**Spec:** [overview.md](overview.md); la parte Nova è in [wm-package/docs/features/8958-nomenclatura-regioni-su-filtri/](../../../wm-package/docs/features/8958-nomenclatura-regioni-su-filtri/).

## Global Constraints

- Commit con scope `fix(oc:8958): …`, messaggi in italiano; i commit li esegue il dev, dopo il review-gate.
- Branch `feature/oc-8958-nomenclatura-regioni-su-filtri` da `origin/develop`. Il working tree locale
  ha modifiche non sue (`compose.yml`, `develop.compose.yml`, `local.compose.yml`, `docker/…/Dockerfile`,
  puntatore `wm-package`): non vanno aggiunte ai commit di questo ticket.
- Test: `docker exec laravel-camminiditalia php artisan test --filter=<Nome>`.
- Solo `admin_level` 4. Il `value` non cambia mai rispetto a oggi.
- Sui server: prima il dev, poi la produzione; prima le letture, poi i backup, poi le scritture, ognuna confermata dal dev.

## Review Focus

- Regione restituita da osmfeatures senza `TaxonomyWhere` corrispondente: resta il nome di osmfeatures, nessun elemento con `name` vuoto (Task 1).
- `TaxonomyWhere` con `name` senza `it` o vuoto: si tiene il nome di osmfeatures (Task 1).
- Modifica di una `TaxonomyWhere` che non è una regione (`admin_level` 8) o di un campo diverso da `name`: nessun ricalcolo (Task 2).
- Cache `layer-attributes:wheres:*` ancora valida dopo la correzione: il nome nuovo compare comunque al ricalcolo, perché il lookup sul DB è dopo la cache (Task 1).

---

### Task 1: nomi delle regioni dal DB in `normalizeWheres()`

**Files:**
- Modify: `app/Services/LayerAttributesService.php` (`normalizeWheres()`, nuovo metodo privato)
- Test: `tests/Feature/LayerAttributesWheresTest.php`

**Interfaces:**
- Produces: `private function regionNamesByOsmfeaturesId(array $osmfeaturesIds): array` — mappa
  `osmfeatures_id => array<lang, string>` delle `TaxonomyWhere` con `properties->>'admin_level' = '4'`,
  una sola query `whereIn` su `properties->>'osmfeatures_id'`; scarta i `name` senza nessuna lingua non vuota.

- [ ] **Step 1: Scrivi i test che falliscono**, con `Http::fake` sullo stesso schema di
`test_wheres_posts_the_aggregated_geometry_to_osmfeatures` (feature `R42`, `admin_level` 4,
`osm_tags` con `name:it` = `Lucania`, `name:en` = `Basilicata`):
  - `test_wheres_uses_the_taxonomy_where_name_from_the_db`: con una `TaxonomyWhere`
    `properties = ['osmfeatures_id' => 'R42', 'admin_level' => 4]` e `name = ['it' => 'Basilicata', 'en' => 'Basilicata']`,
    `wheres()` restituisce `[['value' => 'basilicata', 'name' => ['it' => 'Basilicata', 'en' => 'Basilicata']]]`.
  - `test_wheres_keeps_the_osmfeatures_name_without_a_taxonomy_where`: senza `TaxonomyWhere`,
    `name['it'] === 'Lucania'` e `value === 'basilicata'`.
  - `test_wheres_value_does_not_depend_on_the_db_name`: con la `TaxonomyWhere` che ha `en = 'Lucania Region'`,
    `value` resta `'basilicata'`.
  - `test_wheres_ignores_non_region_taxonomy_wheres`: una `TaxonomyWhere` `R42` con `admin_level` 8 non cambia il nome.

- [ ] **Step 2: Lancia i test e verifica che falliscano** — `php artisan test --filter=LayerAttributesWheresTest`. Expected: FAIL sui primi tre nuovi.

- [ ] **Step 3: Implementa.** In `normalizeWheres()` calcola `$value` come oggi, dai nomi di
osmfeatures; dopo il ciclo di filtro, una chiamata a `regionNamesByOsmfeaturesId()` con gli id
rimasti, e per ogni elemento `name` = nomi del DB se presenti, altrimenti quelli di osmfeatures con
`Log::warning('LayerAttributesService::normalizeWheres: regione senza TaxonomyWhere', ['osmfeatures_id' => …])`.
La `Cache::remember` di `wheres()` resta sulla sola risposta di osmfeatures.

- [ ] **Step 4: Lancia i test e verifica che passino**, poi l'intera `LayerAttributesWheresTest` e `LayerAttributesPersistenceTest`. Expected: PASS.

- [ ] **Step 5: Commit (lo esegue il dev)** — `fix(oc:8958): nomi delle regioni dei layer dalle TaxonomyWhere del DB`

### Task 2: ricalcolo dei layer quando cambia il nome di una regione

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-ricalcolo-dei-layer-quando-cambia-il-nome-di-una-regione)

**Files:**
- Create: `app/Observers/TaxonomyWhereNameObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` (accanto a `Layer::observe(LayerObserver::class)`, riga ~95)
- Test: `tests/Feature/TaxonomyWhereNameObserverTest.php`

**Interfaces:**
- Consumes: `RecalculateLayerAttributesJob(int $layerId, bool $regenerateConfig = true)`, `UpdateAppConfigJob(int $appId)`.
- Produces: `TaxonomyWhereNameObserver::updated(TaxonomyWhere $taxonomyWhere): void`.

- [ ] **Step 1: Scrivi i test che falliscono**, con `Bus::fake()`:
  - `test_renaming_a_region_recalculates_the_layers_that_cross_it`: una `TaxonomyWhere` regionale con
    geometria, un layer con una traccia che la interseca e un layer con una traccia fuori; cambiando
    `name` parte una catena con un `RecalculateLayerAttributesJob` (`regenerateConfig === false`) per il
    solo primo layer, seguito da un `UpdateAppConfigJob` della sua App.
  - `test_renaming_a_municipality_does_nothing`: `admin_level` 8 → nessun job.
  - `test_changing_other_fields_does_nothing`: cambia solo `properties` → nessun job.

- [ ] **Step 2: Lancia i test e verifica che falliscano.** Expected: FAIL, nessuna catena.

- [ ] **Step 3: Implementa `updated()`**: esce se `! $taxonomyWhere->wasChanged('name')`, se
`admin_level` ≠ 4 o se `geometry` è null. I layer si trovano con `ST_Intersects` fra la geometria
della `TaxonomyWhere` e le tracce del layer (stesso schema di `LayerService`, righe 408-415), raggruppati
per `app_id`; per ogni App `Bus::chain([...RecalculateLayerAttributesJob per layer con regenerateConfig: false, new UpdateAppConfigJob($appId)])->dispatch()`.
Registra l'observer con `\Wm\WmPackage\Models\TaxonomyWhere::observe(TaxonomyWhereNameObserver::class)`.

- [ ] **Step 4: Lancia i test e verifica che passino**, poi l'intera suite. Expected: PASS.

- [ ] **Step 5: Commit (lo esegue il dev)** — `fix(oc:8958): ricalcolo dei layer quando cambia il nome di una regione`

### Task 3: bump del submodule `wm-package`

- [ ] **Step 1:** dopo il merge del branch di `wm-package` su `develop`, porta il submodule a quel commit.
- [ ] **Step 2:** `php artisan test` completo. Expected: PASS.
- [ ] **Step 3: Commit separato (lo esegue il dev)** — `chore(oc:8958): aggiorna wm-package`, nella stessa PR del ticket.

### Task 4: operazione sui dati, dev poi produzione

Si esegue dopo il deploy del branch sul dev; per la produzione dopo il merge e il deploy.
Alias SSH da chiedere al dev prima di cominciare. Ogni comando di scrittura è confermato dal dev.

- [ ] **Step 1: Letture.** Le `TaxonomyWhere` con `admin_level` 4 il cui `name` contiene «Friuli-Venezia», «Trentino-Alto», «Lucania», «Regione Autonoma»: id, `name`, `properties->>'source'`, `properties->>'osmfeatures_id'`.
- [ ] **Step 2: Backup.** Copia del `config.json` dell'App 1; `pg_dump` di `layers` (o `COPY` di `id, properties->'attributes'`) e delle righe di `taxonomy_wheres` trovate allo step 1.
- [ ] **Step 3: Correzione in Nova** (utente Administrator): «Friuli Venezia Giulia», «Trentino Alto Adige» in `it` e nelle altre lingue che hanno lo stesso trattino; le altre lingue restano.
- [ ] **Step 4:** `php artisan wm:resync-taxonomy-where --app=1 --dry-run`, poi senza `--dry-run`.
- [ ] **Step 5: Ricalcolo.** `RecalculateAppLayerAttributesAction` sull'App 1 (serve per «Lucania» e «Regione Autonoma…», che cambiano per effetto del Task 1 e non dell'observer); attendi in Horizon che tutti i job siano completati senza fallimenti.
- [ ] **Step 6: Verifica.** Scarica il `config.json`: zero occorrenze di «Lucania», «Regione Autonoma», «Friuli-Venezia», «Trentino-Alto»; i layer 3, 4, 7, 12, 23, 39, 45, 60, 65, 67, 74, 75, 101, 115, 125, 135 hanno i nomi nuovi; i `value` sono gli stessi del backup.

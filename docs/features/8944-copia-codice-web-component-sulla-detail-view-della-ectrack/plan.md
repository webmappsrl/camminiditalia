> Ticket: oc:8944

# Copia codice web component sulla detail view della EcTrack — piano camminiditalia

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** per i non-Administrator la select del bottone web component sulla EcTrack propone solo i layer di cui l'utente è owner.

**Architecture:** override del metodo `webComponentLayers()` introdotto in wm-package (piano: `wm-package/docs/features/8944-copia-codice-web-component-sulla-detail-view-della-ectrack/plan.md`, Task 3), filtrando il risultato di `parent::`.

**Tech Stack:** Laravel, Nova 5, PHPUnit (`Tests\TestCase`).

**Spec:** [overview.md](overview.md)

## Global Constraints

- Dipende dai Task 1-3 del piano wm-package: si esegue dopo.
- Regola di visibilità identica a `app/Nova/Layer.php::indexQuery()`: Administrator tutti, ogni altro ruolo `user_id` del layer uguale al proprio.
- Il metodo locale è marcato `#[\Override]`.
- Test: `docker exec laravel-camminiditalia php artisan test --filter=EcTrackWebComponentLayersTest`.
- Rilascio in produzione solo dopo la pubblicazione di oc:8937 su `wm-elements@dist`.

## Review Focus

- Validator su una traccia sua che sta solo in un layer dell'Administrator: nessun layer, Field nascosto (accettato, 40 casi in produzione) — test in Task 1.
- Layer senza owner (`user_id` NULL): mai proposto al Validator — test in Task 1.
- Il filtro sul flag dell'App resta applicato anche per l'Administrator (si parte da `parent::`) — test in Task 1.

---

### Task 1: Override del filtro per owner

**Files:**
- Modify: `app/Nova/EcTrack.php`
- Test: `tests/Feature/EcTrackWebComponentLayersTest.php`

**Interfaces:**
- Consumes: `protected function webComponentLayers(NovaRequest $request): Collection` (wm-package).

- [ ] **Step 1: Scrivi i test che falliscono** (setup come `EcTrackVariantFieldTest`: `RolesAndPermissionsService::seedDatabase()`, App con `properties.layer_web_component_enabled = true`, Administrator, Validator; traccia del Validator in un layer suo, in uno dell'Administrator e in uno con `user_id` NULL; invocazione via `ReflectionMethod` con una `NovaRequest` il cui user resolver restituisce l'utente)

```php
public function test_administrator_gets_all_layers_of_the_track(): void      // 3 layer, ordinati per id
public function test_validator_gets_only_owned_layers(): void                // solo il layer del Validator
public function test_validator_gets_nothing_when_no_layer_is_owned(): void   // traccia solo in layer admin/NULL → vuoto
public function test_flag_off_excludes_layers_also_for_administrator(): void // App con flag spento → vuoto
```

- [ ] **Step 2: Esegui e verifica che fallisca** — Expected: FAIL su `test_validator_gets_only_owned_layers` (riceve 3 layer).
- [ ] **Step 3: Implementa** `#[\Override] protected function webComponentLayers(NovaRequest $request): Collection` in `App\Nova\EcTrack`: `parent::webComponentLayers($request)`, restituito intero se `$request->user()?->hasRole('Administrator')`, altrimenti `->where('user_id', $request->user()?->id)->values()`.
- [ ] **Step 4: Esegui e verifica che passi** — Expected: 4 passed; poi `php artisan test tests/Feature/EcTrackVariantFieldTest.php tests/Feature/EcTrackPolicyTest.php` verdi.
- [ ] **Step 5: Commit** (dopo approvazione) — `feat(oc:8944): bottone web component sulla EcTrack con i soli layer dell'utente`

### Task 2: Integrazione di oc:8910

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-integrazione-di-oc8910)

Il gitlink su `develop` porta oc:8910. Riferimento: `wm-package/docs/knowledge/8910-tassonomie-cosa-controllare-al-bump-dello-shard.md`.

**Files:**
- Create: `database/migrations/` — i 3 stub di oc:8910
- Modify: `app/Nova/Layer.php:61-64` (filtro dei campi)
- Test: `tests/Feature/TaxonomySectionsHiddenTest.php`

- [ ] **Step 1:** `docker exec laravel-camminiditalia php artisan wm-package:publish-missing-migrations --dry-run`, poi senza `--dry-run`. Expected: solo `zz_2026_11_02_000001_create_taxonomy_terms_table`, `zz_2026_11_02_000002_create_taxonomy_termables`, `zz_2026_11_03_000003_create_app_taxonomy_term_table`; se ne compaiono altre, fermarsi e chiedere al dev.
- [ ] **Step 2:** `php artisan migrate` e `php artisan migrate --env=testing`. Expected: 3 migration applicate.
- [ ] **Step 3: Scrivi il test che fallisce** — `test_layer_fields_do_not_expose_taxonomy_filters`: nei campi di `App\Nova\Layer::fields()` (Administrator), appiattendo `Panel` e `TabsGroup`, nessun campo ha `attribute` in `taxonomyActivities`, `taxonomyWheres`, `taxonomyTerms` e nessun `TabsGroup` è la sezione «Filters». Expected: FAIL.
- [ ] **Step 4:** estendi il filtro di `app/Nova/Layer.php:61` per togliere anche il `TabsGroup` della sezione «Filters» (riconosciuto dalla chiave `BuildsTaxonomyFields::taxonomySectionKey()`), aggiornando il commento di oc:8311. Expected: test PASS.
- [ ] **Step 5: Verifica manuale** — detail view e form di un Layer: nessuna sezione «Filters», nessun errore. Detail view di una EcTrack: guarda la sezione «Taxonomies» e riporta al dev cosa mostra (decisione aperta: tenerla o nasconderla).
- [ ] **Step 6: Commit** (dopo approvazione) — `feat(oc:8944): migration di oc:8910 e sezione Filters nascosta sul Layer`

### Task 3: Gitlink e verifica manuale

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-gitlink-e-verifica-manuale)

- [ ] Gitlink `wm-package` sul commit del branch `feature/oc-8944-…` del package (dopo la PR del package).
- [ ] Note di deploy nella PR: `migrate`, `php artisan scout:import "App\Models\EcTrack"`, riavvio di Horizon.
- [ ] In Nova locale, come Validator 1421, apri la EcTrack 522: nessuna select, lo snippet copiato ha `layer-id="47"` e `track-id="522"`.
- [ ] Come Validator, apri una sua EcTrack che sta solo in un layer dell'Administrator: il Field non compare.
- [ ] Suite completa: `docker exec laravel-camminiditalia php artisan test` verde.
- [ ] Commit (dopo approvazione) — `feat(oc:8944): gitlink wm-package al bottone web component sulla EcTrack`

> Ticket: oc:8611

# Fix associazione EcTrack↔EcPoi in Nova per Validator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Far tornare visibili, nei campi Nova di attach `EcTracks` (su EcPoi) ed `EcPois` (su EcTrack), le righe di proprietà di un utente Validator — anche impersonato — che oggi risultano sempre vuote; e nascondere per il Validator il campo `Layers` su EcTrack, che ha lo stesso bug ma va chiuso diversamente (nessun attach/detach layer diretto per un gestore di cammino).

**Architecture:** I due campi `BelongsToMany`/`MorphToMany` ereditati da `wm-package` referenziano, per assenza di un `use` esplicito nel file del package, la classe Nova del package (`Wm\WmPackage\Nova\EcTrack`/`EcPoi`/`Layer`) invece della sottoclasse locale che ha l'`indexQuery()` corretto (scoping per `user_id`, introdotto da oc:8587). Il fix sovrascrive, dentro i metodi `fields()` già esistenti in `App\Nova\EcPoi`/`App\Nova\EcTrack`, i field oggetto in questione: per `EcTracks`/`EcPois` li ricostruisce puntando alla sottoclasse locale corretta (`App\Nova\EcTrack`/`App\Nova\EcPoi`, risolta per namespace essendo nello stesso `App\Nova`); per `Layers` li lascia puntati al package ma li nasconde (`canSee`) per chi non è Administrator.

**Tech Stack:** Laravel 11, Laravel Nova 5, PHPUnit, PostgreSQL/PostGIS (DB di test dedicato).

**Spec:** `docs/features/8611-associazione-tracks-al-poi-in-modalita-impersonate/overview.md`

## Global Constraints

- Nessuna modifica al submodule `wm-package` — il fix è interamente in `app/Nova/EcPoi.php` e `app/Nova/EcTrack.php`
- Commit scope `fix(oc:8611): ...` (è un Bug, non una Feature — mai `feat(...)`)
- Nessun `git commit`/`git add`/`git push`/branch eseguito autonomamente durante l'esecuzione: i passi "Commit" sono istruzioni testuali per l'utente
- Comportamento Administrator invariato in entrambi i campi (vede tutte le righe, nessuna restrizione nuova)
- Comportamento dell'associazione Layer↔Track/Poi via `LayerFeatureController` invariato (non toccato da questo piano)
- La sostituzione di un campo ereditato da `parent::fields($request)` va filtrata per `$field->attribute` (non solo per classe del field), per non lasciare due campi con lo stesso attributo nell'array risultante
- Il nuovo campo `EcPois` su EcTrack deve replicare esattamente i modificatori del campo originale del package: `->searchable()->collapsedByDefault()`

## Review Focus

- Un Administrator deve continuare a vedere **tutte** le EcTrack/EcPoi in entrambi i campi di attach, non solo le proprie — la modifica tocca lo stesso metodo `fields()` usato da tutti i ruoli, rischio di restringere per errore anche l'Administrator
- Un Validator senza alcuna EcTrack/EcPoi propria deve vedere una lista vuota (comportamento già corretto e testato altrove per `indexQuery()`), non un errore/eccezione, quando il campo di attach risolve alla classe corretta
- Il campo "Layers" deve restare visibile per l'Administrator — solo nascosto per il Validator; un `canSee` scritto al contrario nasconderebbe il campo a tutti
- La sostituzione del campo non deve produrre due field con lo stesso `attribute` (`ecTracks`/`ecPois`) nell'array restituito da `fields()` — comportamento Nova indefinito se capita
- Un utente Guest deve continuare a non vedere nulla nei campi di attach (tramite l'`indexQuery()` già esistente, che questo piano non modifica) — verificato per non introdurre una regressione silenziosa su un ramo che il piano non tocca direttamente

---

### Task 1: Fix del campo "EcTracks" su EcPoi

**Files:**
- Modify: `app/Nova/EcPoi.php:83-92` (metodo `fields()`)
- Test: `tests/Feature/EcTrackEcPoiAttachableFieldTest.php` (nuovo file)

**Interfaces:**
- Consumes: `App\Nova\EcTrack::indexQuery()` (già esistente, oc:8587 — scoping per `user_id`), `Tests\Feature\Helpers\LayerTestHelpers::createUserWithRole()`
- Produces: `App\Nova\EcPoi::fields()` restituisce, per l'attributo `ecTracks`, un field con `resourceClass === \App\Nova\EcTrack::class` (non più `Wm\WmPackage\Nova\EcTrack::class`)

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `tests/Feature/EcTrackEcPoiAttachableFieldTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * wm-package/src/Nova/EcPoi.php e wm-package/src/Nova/EcTrack.php referenziano
 * EcTrack::class/EcPoi::class/Layer::class senza `use` esplicito: per
 * risoluzione di namespace risolvono alla classe Nova del package (priva
 * dell'override indexQuery() per user_id di oc:8587), non alla sottoclasse
 * locale. Nova chiama sempre indexQuery() per costruire la lista "attaccabile"
 * di un campo BelongsToMany/MorphToMany, quindi per un Validator il campo
 * risultava sempre vuoto (oc:8611).
 */
class EcTrackEcPoiAttachableFieldTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();

        RolesAndPermissionsService::seedDatabase();

        if (WmApp::count() === 0) {
            WmApp::factory()->create();
        }
    }

    private function novaRequestFor($user): NovaRequest
    {
        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function makeEcPoi(?int $userId = null): \App\Models\EcPoi
    {
        $attrs = ['properties' => []];
        if ($userId) {
            $attrs['user_id'] = $userId;
        }

        return \App\Models\EcPoi::factory()->createQuietly($attrs);
    }

    private function findField(array $fields, string $attribute)
    {
        foreach ($fields as $field) {
            if (property_exists($field, 'attribute') && $field->attribute === $attribute) {
                return $field;
            }
        }

        return null;
    }

    public function test_ecpoi_ectracks_field_points_to_local_ectrack_resource(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $ecPoi = $this->makeEcPoi($admin->id);

        $fields = (new \App\Nova\EcPoi($ecPoi))->fields($this->novaRequestFor($admin));
        $field = $this->findField($fields, 'ecTracks');

        $this->assertInstanceOf(BelongsToMany::class, $field);
        $this->assertSame(\App\Nova\EcTrack::class, $field->resourceClass);
    }

    public function test_ecpoi_ectracks_field_has_exactly_one_occurrence(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $ecPoi = $this->makeEcPoi($admin->id);

        $fields = (new \App\Nova\EcPoi($ecPoi))->fields($this->novaRequestFor($admin));
        $occurrences = array_filter($fields, fn ($field) => property_exists($field, 'attribute') && $field->attribute === 'ecTracks');

        $this->assertCount(1, $occurrences);
    }

    public function test_validator_finds_own_ectrack_via_ecpoi_attach_field(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $otherValidator = $this->createUserWithRole('Validator');

        $ownTrack = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $validator->id]);
        $otherTrack = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $otherValidator->id]);

        $ecPoi = $this->makeEcPoi($validator->id);
        $fields = (new \App\Nova\EcPoi($ecPoi))->fields($this->novaRequestFor($validator));
        $field = $this->findField($fields, 'ecTracks');

        $results = $field->resourceClass::indexQuery(
            $this->novaRequestFor($validator),
            $field->resourceClass::newModel()->newQuery()
        )->get();

        $this->assertTrue($results->contains($ownTrack));
        $this->assertFalse($results->contains($otherTrack));
    }

    public function test_administrator_finds_all_ectracks_via_ecpoi_attach_field(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $validator = $this->createUserWithRole('Validator');

        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $validator->id]);

        $ecPoi = $this->makeEcPoi($admin->id);
        $fields = (new \App\Nova\EcPoi($ecPoi))->fields($this->novaRequestFor($admin));
        $field = $this->findField($fields, 'ecTracks');

        $results = $field->resourceClass::indexQuery(
            $this->novaRequestFor($admin),
            $field->resourceClass::newModel()->newQuery()
        )->get();

        $this->assertTrue($results->contains($track));
    }
}
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `docker exec laravel-camminiditalia php artisan test --filter=EcTrackEcPoiAttachableFieldTest`
Expected: FAIL su `test_ecpoi_ectracks_field_points_to_local_ectrack_resource` (e sugli altri test che dipendono dallo stesso field) — `$field->resourceClass` risulta `Wm\WmPackage\Nova\EcTrack::class`, non `App\Nova\EcTrack::class`.

- [ ] **Step 3: Implementa il fix minimo**

In `app/Nova/EcPoi.php`, aggiungi l'import e modifica `fields()`:

```php
use Laravel\Nova\Fields\BelongsToMany;
```

(da aggiungere accanto agli altri `use` in testa al file, es. dopo `use Laravel\Nova\Fields\Select;`)

Sostituisci il metodo `fields()` (righe 83-92) con:

```php
    public function fields(NovaRequest $request): array
    {
        $fields = array_map(function ($field) {
            // wm-package/src/Nova/EcPoi.php referenzia EcTrack::class senza `use`
            // esplicito: risolve alla classe Nova del package, priva
            // dell'override indexQuery() per user_id (oc:8587) — il campo di
            // attach risultava sempre vuoto per un Validator (oc:8611).
            if ($field instanceof BelongsToMany && $field->attribute === 'ecTracks') {
                return BelongsToMany::make('EcTracks', 'ecTracks', EcTrack::class);
            }

            return $field;
        }, parent::fields($request));

        if ($layerField = $this->layerAssignmentField($request)) {
            $fields[] = $layerField;
        }

        return $fields;
    }
```

Nota: `EcTrack::class` qui risolve a `App\Nova\EcTrack` (stesso namespace `App\Nova` del file, nessun `use` necessario) — è esattamente il fix, non serve importare nulla per `EcTrack`.

- [ ] **Step 4: Esegui il test e verifica che passi**

Run: `docker exec laravel-camminiditalia php artisan test --filter=EcTrackEcPoiAttachableFieldTest`
Expected: PASS su tutti i test di questo file (4 test: field points to local resource, exactly one occurrence, validator finds own track, administrator finds all tracks).

- [ ] **Step 5: Commit**

```bash
git add app/Nova/EcPoi.php tests/Feature/EcTrackEcPoiAttachableFieldTest.php
git commit -m "fix(oc:8611): punta il campo EcTracks su EcPoi alla risorsa Nova locale"
```

---

### Task 2: Fix del campo "EcPois" su EcTrack e occultamento del campo "Layers" per il Validator

**Files:**
- Modify: `app/Nova/EcTrack.php:46-69` (metodo `fields()`)
- Test: `tests/Feature/EcTrackEcPoiAttachableFieldTest.php` (stesso file di Task 1, nuovi metodi di test)

**Interfaces:**
- Consumes: `App\Nova\EcPoi::indexQuery()` (già esistente, oc:8587 — scoping per `user_id`), helper di Task 1 (`novaRequestFor`, `makeEcPoi`, `findField`) nello stesso file di test
- Produces: `App\Nova\EcTrack::fields()` restituisce, per l'attributo `ecPois`, un field con `resourceClass === \App\Nova\EcPoi::class`; per l'attributo `layers`, un field con `authorizedToSee()` che ritorna `false` per un Validator e `true` per un Administrator

- [ ] **Step 1: Scrivi i test che falliscono**

Aggiungi questi metodi alla classe `EcTrackEcPoiAttachableFieldTest` (stesso file creato in Task 1):

```php
    public function test_ectrack_ecpois_field_points_to_local_ecpoi_resource(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $admin->id]);

        $fields = (new \App\Nova\EcTrack($track))->fields($this->novaRequestFor($admin));
        $field = $this->findField($fields, 'ecPois');

        $this->assertInstanceOf(BelongsToMany::class, $field);
        $this->assertSame(\App\Nova\EcPoi::class, $field->resourceClass);
        $this->assertTrue($field->searchable);
    }

    public function test_ectrack_ecpois_field_has_exactly_one_occurrence(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $admin->id]);

        $fields = (new \App\Nova\EcTrack($track))->fields($this->novaRequestFor($admin));
        $occurrences = array_filter($fields, fn ($field) => property_exists($field, 'attribute') && $field->attribute === 'ecPois');

        $this->assertCount(1, $occurrences);
    }

    public function test_validator_finds_own_ecpoi_via_ectrack_attach_field(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $otherValidator = $this->createUserWithRole('Validator');

        $ownPoi = $this->makeEcPoi($validator->id);
        $otherPoi = $this->makeEcPoi($otherValidator->id);

        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $validator->id]);
        $fields = (new \App\Nova\EcTrack($track))->fields($this->novaRequestFor($validator));
        $field = $this->findField($fields, 'ecPois');

        $results = $field->resourceClass::indexQuery(
            $this->novaRequestFor($validator),
            $field->resourceClass::newModel()->newQuery()
        )->get();

        $this->assertTrue($results->contains($ownPoi));
        $this->assertFalse($results->contains($otherPoi));
    }

    public function test_administrator_finds_all_ecpois_via_ectrack_attach_field(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $validator = $this->createUserWithRole('Validator');

        $poi = $this->makeEcPoi($validator->id);

        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $admin->id]);
        $fields = (new \App\Nova\EcTrack($track))->fields($this->novaRequestFor($admin));
        $field = $this->findField($fields, 'ecPois');

        $results = $field->resourceClass::indexQuery(
            $this->novaRequestFor($admin),
            $field->resourceClass::newModel()->newQuery()
        )->get();

        $this->assertTrue($results->contains($poi));
    }

    public function test_layers_field_hidden_for_validator(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $validator->id]);

        $request = $this->novaRequestFor($validator);
        $fields = (new \App\Nova\EcTrack($track))->fields($request);
        $field = $this->findField($fields, 'layers');

        $this->assertInstanceOf(MorphToMany::class, $field);
        $this->assertFalse($field->authorizedToSee($request));
    }

    public function test_layers_field_visible_for_administrator(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $track = \App\Models\EcTrack::factory()->createQuietly(['user_id' => $admin->id]);

        $request = $this->novaRequestFor($admin);
        $fields = (new \App\Nova\EcTrack($track))->fields($request);
        $field = $this->findField($fields, 'layers');

        $this->assertInstanceOf(MorphToMany::class, $field);
        $this->assertTrue($field->authorizedToSee($request));
    }
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=EcTrackEcPoiAttachableFieldTest`
Expected: FAIL sui 6 nuovi test — `ecPois` risolve ancora a `Wm\WmPackage\Nova\EcPoi::class`, e `layers` non ha alcun `canSee` impostato (`authorizedToSee()` ritorna `true` anche per il Validator).

- [ ] **Step 3: Implementa il fix minimo**

In `app/Nova/EcTrack.php`, aggiungi gli import:

```php
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\MorphToMany;
```

(accanto agli altri `use`, es. dopo `use Laravel\Nova\Fields\BelongsTo;`)

Sostituisci il metodo `fields()` (righe 46-69) con:

```php
    /**
     * Get the fields displayed by the resource.
     */
    public function fields(NovaRequest $request): array
    {
        $fields = parent::fields($request);
        $currentUser = $request->user();

        // Remove App field for all users
        $fields = array_filter($fields, function ($field) {
            return ! ($field instanceof BelongsTo && $field->attribute === 'app');
        });

        $fields = array_map(function ($field) use ($currentUser) {
            // Show User field only to admins
            if ($field instanceof BelongsTo && $field->attribute === 'user') {
                $field->canSee(function () use ($currentUser) {
                    return $currentUser && $currentUser->hasRole('Administrator');
                });

                return $field;
            }

            // wm-package/src/Nova/EcTrack.php referenzia EcPoi::class senza `use`
            // esplicito: risolve alla classe Nova del package, priva
            // dell'override indexQuery() per user_id (oc:8587) — il campo di
            // attach risultava sempre vuoto per un Validator (oc:8611).
            if ($field instanceof BelongsToMany && $field->attribute === 'ecPois') {
                return BelongsToMany::make('EcPois', 'ecPois', EcPoi::class)
                    ->searchable()
                    ->collapsedByDefault();
            }

            // Il campo Layers (stesso bug del campo EcPois sopra) va nascosto
            // per il Validator invece che corretto: un gestore di cammino non
            // deve poter fare attach/detach diretto del layer da qui,
            // l'associazione passa sempre dal pannello Layer/LayerFeatureController.
            if ($field instanceof MorphToMany && $field->attribute === 'layers') {
                $field->canSee(function () use ($currentUser) {
                    return $currentUser && $currentUser->hasRole('Administrator');
                });
            }

            return $field;
        }, $fields);

        return array_values($fields);
    }
```

Nota: `EcPoi::class` qui risolve a `App\Nova\EcPoi` (stesso namespace `App\Nova`, nessun `use` necessario).

- [ ] **Step 4: Esegui tutti i test del file e verifica che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter=EcTrackEcPoiAttachableFieldTest`
Expected: PASS su tutti i 10 test del file (4 di Task 1 + 6 di Task 2).

- [ ] **Step 5: Commit**

```bash
git add app/Nova/EcTrack.php tests/Feature/EcTrackEcPoiAttachableFieldTest.php
git commit -m "fix(oc:8611): punta il campo EcPois su EcTrack alla risorsa Nova locale e nasconde Layers per il Validator"
```

---

### Task 3: Verifica di non regressione sulla suite esistente

**Files:**
- Nessuna modifica di codice — solo esecuzione della suite completa

**Interfaces:**
- Consumes: tutti i test esistenti in `tests/Feature/` (in particolare `EcTrackIndexQueryTest`, `EcPoiIndexQueryTest`, `EcPoiPolicyTest`, `EcTrackPolicyTest`, `EcPoiNovaAuthorizationTest`, `EcPoiNovaActionsTest`)
- Produces: conferma che nessuna modifica di Task 1/2 rompe test già verdi

- [ ] **Step 1: Esegui la suite completa**

Run: `docker exec laravel-camminiditalia php artisan test`
Expected: tutti i test passano, incluso il nuovo `EcTrackEcPoiAttachableFieldTest` (10 test) e i test esistenti su `EcTrack`/`EcPoi` (in particolare quelli che chiamano `array_filter`/`array_map` su `fields()`, se ce ne sono, e quelli sull'action `ExecuteEcTrackDataChainAction`/`ExecuteEcPoiDataChainAction` che potrebbero iterare sui field).

- [ ] **Step 2: Se qualcosa fallisce**

Analizza il fallimento con `superpowers:systematic-debugging` prima di modificare qualsiasi codice — non aggiustare a tentativi. Se il fallimento è un falso positivo preesistente (es. Redis/qemu, vedi `CLAUDE.md` → Database PostgreSQL separato per i test PHPUnit), documentalo in `notes.md` invece di correggerlo qui.

- [ ] **Step 3: Nessun commit in questo task**

Questo task è solo verifica — se la suite passa, non c'è nulla da committare. Se hai dovuto correggere qualcosa, torna al task pertinente e committa lì.

> Ticket: oc:8773

# Corrispondenza UgcTrack per cammini e tappe — piano camminiditalia

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** portare in camminiditalia la corrispondenza per segmenti delle UgcTrack fatta in wm-package, togliere dalle UgcTrack il link "Cammino" e la correzione manuale basati su `layer_id`, e tradurre le etichette del package con il lessico di Cammini d'Italia.

**Architecture:** quasi tutto il lavoro è nel package (vedi `wm-package/docs/features/8773-associazione-delle-ugctrack-a-piu-cammini-tappe-invece-che-a-un-solo-layer/plan.md`). Qui: bump del submodule e migration pubblicata, `App\Nova\UgcTrack` che eredita campo e filtro dal package senza più i trait del `layer_id`, `PopulateUgcLayerIdCommand` limitato agli UgcPoi, traduzioni e test di integrazione.

**Tech Stack:** Laravel 12, Nova 5, PHPUnit (`Tests\TestCase`, `DatabaseTransactions`), PostgreSQL + PostGIS.

**Spec:** [overview.md](overview.md) di questo cantiere e l'overview del package.

## Global Constraints

- **Prerequisito:** la parte wm-package di oc:8773 è mergiata. Il bump del submodule si fa solo dopo.
- Comandi `php artisan` sempre con `docker exec laravel-camminiditalia …`; i test girano sul DB `camminiditalia_testing`.
- Gli UgcPoi non cambiano: link, correzione manuale, scoping del Validator e mail restano come sono.
- La visibilità delle UgcTrack non cambia: solo l'Administrator (`indexQuery()`, menu e `UgcTrackPolicy` invariati).
- Lessico di camminiditalia: layer = cammino, EcTrack = tappa. Lingue: it, en, fr, es, de.
- Segmento 400 m e distanza massima 50 m: coincidono con i default del package, nessun valore da impostare sulla App.
- Documentazione e commit in italiano, scope `feat(oc:8773): …`.
- **Nessun commit, push o branch automatico**: i `git commit` qui sotto sono istruzioni per il dev.

## Review Focus

1. UgcPoi "report" creato dopo il bump: riceve ancora `properties.layer_id` e la mail parte → test in Task 3.
2. Validator che chiama l'API Nova delle UgcTrack: lista vuota come prima → test in Task 2.
3. Filtro per cammino passato nell'URL Nova con il nome di classe del filtro del package: restituisce solo le tracce che passano sul cammino → test in Task 2.
4. Locale diverso da `it` in produzione: le etichette compaiono in inglese, comprensibili → controllo manuale in Task 5.
5. Coda `ugc-track-matching` non servita da Horizon dopo il deploy: i calcoli restano in coda → controllo in Task 5.

---

### Task 1: Bump del submodule e migration

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-bump-del-submodule-e-migration)

**Files:**
- Modify: gitlink `wm-package`
- Create: `database/migrations/zz_2026_10_13_000001_create_ugc_track_matching_tables.php` (pubblicata dallo stub)

- [ ] **Step 1: Porta il submodule sul commit mergiato**

```bash
git -C /Users/bongiu/Documents/camminiditalia/wm-package fetch origin
git -C /Users/bongiu/Documents/camminiditalia/wm-package checkout <commit-del-merge-di-oc-8773>
```

- [ ] **Step 2: Procedura migration del package**

Segui `wm-package/docs/howto/migration-wm-package.md`:

```bash
docker exec laravel-camminiditalia php artisan wm-package:publish-missing-migrations --dry-run
docker exec laravel-camminiditalia php artisan wm-package:publish-missing-migrations
docker exec laravel-camminiditalia php artisan migrate
docker exec laravel-camminiditalia php artisan migrate --env=testing
```

Expected: pubblicato solo `zz_2026_10_13_000001_create_ugc_track_matching_tables.php`; tabelle `ugc_track_ec_track_matches` e `ugc_track_matching_runs` presenti nei due DB.

- [ ] **Step 3: Verifica che il package risponda**

```bash
docker exec laravel-camminiditalia php artisan list wm | grep match-ugc-tracks
```

Expected: compare `wm:match-ugc-tracks`.

- [ ] **Step 4: Commit (solo dopo ok del dev)** — insieme al Task 2, perché senza il Task 2 i test esistenti di `UgcTrackLayerFieldTest` falliscono.

---

### Task 2: Nova UgcTrack senza `layer_id`, con campo e filtro del package

**Files:**
- Modify: `app/Nova/UgcTrack.php`
- Delete: `tests/Feature/UgcTrackLayerFieldTest.php` (sostituito)
- Create: `tests/Feature/UgcTrackMatchingNovaTest.php`
- Modify: `tests/Feature/HasLayerOverrideTest.php` (usa UgcPoi invece di UgcTrack)

**Interfaces:**
- Consumes (package): campo `__('Layers crossed by the track')` nel detail di `Wm\WmPackage\Nova\UgcTrack`, filtro `Wm\WmPackage\Nova\Filters\UgcTrackLayerFilter`, `UgcTrackMatchingService::make()->matchUgcTrack(int)`, `ecTrackMorphClass()`.

- [ ] **Step 1: Scrivi i test che falliscono**

`tests/Feature/UgcTrackMatchingNovaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Nova\Filters\UgcTrackLayerFilter;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class UgcTrackMatchingNovaTest extends TestCase
{
    use DatabaseTransactions;

    private App $app_;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
        $this->app_ = App::query()->first() ?? App::factory()->create();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function ecTrack(string $wkt, Layer $layer): int
    {
        $track = EcTrack::withoutEvents(fn () => EcTrack::factory()->create(['app_id' => $this->app_->id]));
        DB::update('UPDATE ec_tracks SET geometry = ST_Force3D(ST_GeomFromText(?, 4326))::geography WHERE id = ?', [$wkt, $track->id]);
        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_id' => $track->id,
            'layerable_type' => UgcTrackMatchingService::make()->ecTrackMorphClass(),
        ]);

        return $track->id;
    }

    private function ugcTrack(string $wkt): UgcTrack
    {
        $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create(['app_id' => $this->app_->id, 'properties' => []]));
        DB::update('UPDATE ugc_tracks SET geometry = ST_Force3D(ST_GeomFromText(?, 4326))::geography WHERE id = ?', [$wkt, $track->id]);
        UgcTrackMatchingService::make()->matchUgcTrack($track->id);

        return $track;
    }

    public function test_administrator_sees_crossed_routes_in_detail(): void
    {
        $layer = Layer::factory()->create(['app_id' => $this->app_->id, 'name' => ['it' => 'Via degli Dei Test']]);
        $this->ecTrack('MULTILINESTRING((11 44, 11 44.018))', $layer);
        $ugc = $this->ugcTrack('MULTILINESTRING((11.00025 44, 11.00025 44.009))');

        $response = $this->actingAs($this->user('Administrator'))->getJson('/nova-api/ugc-tracks/'.$ugc->id);

        $response->assertOk();
        $field = collect($response->json('resource.fields'))->firstWhere('name', __('Layers crossed by the track'));
        $this->assertNotNull($field);
        $this->assertStringContainsString('Via degli Dei Test', $field['value']);
    }

    public function test_layer_link_and_manual_override_are_gone_from_ugc_tracks(): void
    {
        $ugc = $this->ugcTrack('MULTILINESTRING((12 45, 12 45.001))');

        $index = $this->actingAs($this->user('Administrator'))->getJson('/nova-api/ugc-tracks?perPage=25')->assertOk();
        $attributes = collect($index->json('resources'))->flatMap(fn ($r) => collect($r['fields'])->pluck('attribute'))->all();
        $this->assertNotContains('layer_link', $attributes);

        $edit = $this->actingAs($this->user('Administrator'))->getJson('/nova-api/ugc-tracks/'.$ugc->id.'/update-fields')->assertOk();
        $editAttributes = collect($edit->json('fields'))->pluck('attribute')->all();
        $this->assertEmpty(array_filter($editAttributes, fn ($a) => str_contains((string) $a, 'layer_override')));
    }

    public function test_administrator_filters_ugc_tracks_by_route(): void
    {
        $layerA = Layer::factory()->create(['app_id' => $this->app_->id]);
        $layerB = Layer::factory()->create(['app_id' => $this->app_->id]);
        $this->ecTrack('MULTILINESTRING((11 44, 11 44.018))', $layerA);
        $this->ecTrack('MULTILINESTRING((12 45, 12 45.018))', $layerB);
        $onA = $this->ugcTrack('MULTILINESTRING((11.00025 44, 11.00025 44.009))');
        $this->ugcTrack('MULTILINESTRING((12.00025 45, 12.00025 45.009))');

        $filters = base64_encode(json_encode([[UgcTrackLayerFilter::class => $layerA->id]]));
        $response = $this->actingAs($this->user('Administrator'))->getJson('/nova-api/ugc-tracks?perPage=25&filters='.$filters);

        $response->assertOk();
        $this->assertEquals([(string) $onA->id], collect($response->json('resources'))->pluck('id.value')->all());
    }

    public function test_validator_still_gets_an_empty_list(): void
    {
        $this->ugcTrack('MULTILINESTRING((11 44, 11 44.001))');

        $response = $this->actingAs($this->user('Validator'))->getJson('/nova-api/ugc-tracks?perPage=25');

        $response->assertOk();
        $this->assertEmpty($response->json('resources'));
    }
}
```

Prima di eseguire, controlla nel `fields()` di `HasLayerOverride` il nome esatto degli attributi dei due campi di override (es. `layer_override`, `layer_override_original`) e allinea l'asserzione del secondo test.

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/UgcTrackMatchingNovaTest.php`
Expected: FAIL su `test_layer_link_and_manual_override_are_gone_from_ugc_tracks` (il link c'è ancora).

- [ ] **Step 3: Togli i trait del `layer_id` da `App\Nova\UgcTrack`**

```php
<?php

namespace App\Nova;

use App\Nova\Traits\HidesAppFromIndexTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\UgcTrack as WmNovaUgcTrack;

/**
 * Le UgcTrack non hanno un cammino singolo (oc:8773): cammini e tappe attraversati, e il filtro
 * per cammino, arrivano dal package (corrispondenza per segmenti). Link e correzione manuale su
 * layer_id restano solo sugli UgcPoi.
 */
class UgcTrack extends WmNovaUgcTrack
{
    use HidesAppFromIndexTrait;

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
}
```

Il metodo `fields()` locale sparisce: restava solo per aggiungere i campi dei due trait.

- [ ] **Step 4: Sposta `HasLayerOverrideTest` sugli UgcPoi**

In `tests/Feature/HasLayerOverrideTest.php` sostituisci ogni `UgcTrack::factory()` con `UgcPoi::factory()` (righe 71, 86, 151, 173, 195, 228, 262) e togli l'`use Wm\WmPackage\Models\UgcTrack;`. Il trait si usa ancora su `App\Nova\UgcPoi`, quindi il test resta valido.

- [ ] **Step 5: Elimina `UgcTrackLayerFieldTest.php`**

```bash
git rm tests/Feature/UgcTrackLayerFieldTest.php
```

Copriva link e filtro su `layer_id` delle UgcTrack, che non esistono più; i casi utili sono in `UgcTrackMatchingNovaTest`.

- [ ] **Step 6: Esegui i test e verifica che passino**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/UgcTrackMatchingNovaTest.php tests/Feature/HasLayerOverrideTest.php`
Expected: PASS.

- [ ] **Step 7: Commit (solo dopo ok del dev), insieme al Task 1**

```bash
git add wm-package database/migrations/zz_2026_10_13_000001_create_ugc_track_matching_tables.php app/Nova/UgcTrack.php tests/Feature/UgcTrackMatchingNovaTest.php tests/Feature/HasLayerOverrideTest.php
git commit -m "feat(oc:8773): bump wm-package, cammini e tappe attraversati dalle UgcTrack in Nova"
```

---

### Task 3: `PopulateUgcLayerIdCommand` solo sugli UgcPoi, UgcPoi invariati

**Files:**
- Modify: `app/Console/Commands/PopulateUgcLayerIdCommand.php:14,19`
- Test: `tests/Feature/PopulateUgcLayerIdCommandTest.php` (crea o estendi se esiste)

- [ ] **Step 1: Scrivi il test che fallisce**

```php
<?php

namespace Tests\Feature;

use App\Jobs\ResolveUgcLayerJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;

class PopulateUgcLayerIdCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_queues_only_ugc_pois(): void
    {
        $poi = UgcPoi::withoutEvents(fn () => UgcPoi::factory()->create(['properties' => ['form' => ['id' => 'report']]]));
        $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create(['properties' => []]));
        Bus::fake();

        $this->artisan('camminiditalia:populate-ugc-layer-id')->assertSuccessful();

        Bus::assertDispatched(ResolveUgcLayerJob::class, fn ($job) => $job->model->is($poi));
        Bus::assertNotDispatched(ResolveUgcLayerJob::class, fn ($job) => $job->model instanceof UgcTrack);
    }
}
```

Prima di eseguire, apri il command e usa la `$signature` reale (il nome qui sopra è un'ipotesi) e il nome reale della proprietà del modello in `ResolveUgcLayerJob`.

- [ ] **Step 2: Esegui e verifica che fallisca**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/PopulateUgcLayerIdCommandTest.php`
Expected: FAIL (accoda anche la UgcTrack).

- [ ] **Step 3: Limita il command**

```php
    protected $description = 'Accoda ResolveUgcLayerJob per tutti gli UgcPoi privi di layer_id (le UgcTrack non hanno layer_id: oc:8773)';
```

e in `handle()` togli `$this->processModel(UgcTrack::class);` e l'`use` relativo.

- [ ] **Step 4: Esegui i test UGC esistenti**

Run: `docker exec laravel-camminiditalia php artisan test --filter='Ugc|Layer|Report'`
Expected: PASS, compresi i test sugli UgcPoi report (layer_id, mail, scoping Validator).

- [ ] **Step 5: Commit (solo dopo ok del dev)**

```bash
git add app/Console/Commands/PopulateUgcLayerIdCommand.php tests/Feature/PopulateUgcLayerIdCommandTest.php
git commit -m "feat(oc:8773): PopulateUgcLayerIdCommand solo sugli UgcPoi"
```

---

### Task 4: Traduzioni con il lessico di camminiditalia

**Files:**
- Modify: `resources/lang/{it,en,fr,es,de}.json`

- [ ] **Step 1: Aggiungi le voci**

| Chiave | it | en | fr | es | de |
|---|---|---|---|---|---|
| `Layers crossed by the track` | Cammini attraversati dalla traccia | Routes crossed by the track | Chemins traversés par la trace | Caminos atravesados por la traza | Von der Spur durchquerte Wege |
| `Layer crossed by the track` | Cammino attraversato dalla traccia | Route crossed by the track | Chemin traversé par la trace | Camino atravesado por la traza | Von der Spur durchquerter Weg |
| `EcTracks crossed outside any layer` | Tappe attraversate fuori da ogni cammino | Stages crossed outside any route | Étapes traversées hors de tout chemin | Etapas atravesadas fuera de todo camino | Durchquerte Etappen ohne Weg |
| `The track does not cross any EcTrack` | La traccia non attraversa nessuna tappa | The track does not cross any stage | La trace ne traverse aucune étape | La traza no atraviesa ninguna etapa | Die Spur durchquert keine Etappe |

Le altre chiavi del package (stato del calcolo, parametri in Nova App) restano con la traduzione del package.

- [ ] **Step 2: Verifica che i JSON siano validi**

```bash
for f in resources/lang/*.json; do python3 -m json.tool "$f" > /dev/null && echo "ok $f"; done
```

Expected: `ok` per tutti e cinque.

- [ ] **Step 3: Esegui di nuovo i test Nova**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/UgcTrackMatchingNovaTest.php`
Expected: PASS (le asserzioni usano `__()`).

- [ ] **Step 4: Commit (solo dopo ok del dev)**

```bash
git add resources/lang
git commit -m "feat(oc:8773): cammini e tappe nelle etichette della corrispondenza UgcTrack"
```

---

### Task 5: Verifica nel locale, documentazione, procedura di deploy

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-verifica-nel-locale-documentazione-procedura-di-deploy)

**Files:**
- Modify: `docs/knowledge/risoluzione-layer-ugc.md`, `CLAUDE.md` (Fase update-context), `notes.md` di questo cantiere

- [ ] **Step 1: Prova sui dati del DB di sviluppo**

```bash
docker exec laravel-camminiditalia php artisan wm:match-ugc-tracks --dry-run
docker exec laravel-camminiditalia php artisan wm:match-ugc-tracks
docker exec laravel-camminiditalia php artisan horizon:status
```

Expected: `267 tracce da calcolare`, `76 tracce con layer_id da togliere` (valori del DB di sviluppo al 13/10/2026); Horizon attivo con il supervisor `supervisor-ugc-track-matching`. Riavvia Horizon se è stato avviato prima del bump: un worker già partito non vede le classi nuove.

- [ ] **Step 2: Controlli sui dati**

```bash
docker exec laravel-camminiditalia php artisan tinker --execute='echo json_encode(DB::selectOne("select (select count(*) from ugc_track_matching_runs where status = ?) calcolate, (select count(*) from ugc_track_matching_runs where status = ?) fallite, (select count(*) from ugc_tracks where properties ? ?) con_layer_id", ["computed","failed","layer_id"]));'
```

Expected: `calcolate` = 267, `fallite` = 0, `con_layer_id` = 0. Apri in Nova la UgcTrack 321 (caso di oc:8466) e verifica che compaia la Via degli Dei.

- [ ] **Step 3: Controllo del locale**

Apri il dettaglio di una UgcTrack con il locale dell'utente impostato a `en`: le etichette devono essere comprensibili ("Routes crossed by the track").

- [ ] **Step 4: Procedura di deploy in `notes.md`**

Scrivi in `notes.md`, sezione `## Decisioni`:
1. deploy di wm-package e camminiditalia insieme (submodule già bumpato), `php artisan migrate`;
2. riavvio di Horizon (nuovo supervisor `supervisor-ugc-track-matching`);
3. backup del DB;
4. se non è già stato fatto: `wm:fix-duplicated-ugc` (oc:8718), poi `wm:clean-ugc-track-geometry` (oc:8719);
5. `php artisan wm:match-ugc-tracks --dry-run`, poi senza `--dry-run`;
6. controllo: nessuna riga `failed` in `ugc_track_matching_runs`.

- [ ] **Step 5: Commit (solo dopo ok del dev)**

```bash
git add docs CLAUDE.md
git commit -m "docs(oc:8773): corrispondenza delle UgcTrack per cammini e tappe"
```

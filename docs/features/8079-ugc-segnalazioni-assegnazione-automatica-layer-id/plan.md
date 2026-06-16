> Ticket: oc:8079

# UGC Segnalazioni: assegnazione automatica layer_id lato API se assente — Piano implementativo

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Quando una segnalazione UgcPoi (form=report) arriva senza `layer_id`, il sistema la assegna automaticamente al cammino più vicino via PostGIS tramite un job in coda, prima di inviare la notifica email al gestore.

**Architecture:** L'observer `UgcObserver::created()` rileva l'assenza di `layer_id` e dispatcha `ResolveUgcLayerJob`. Il job usa `UgcService::resolveLayer()` per trovare il layer, salva `layer_id` nelle properties con `saveQuietly()`, poi dispatcha `SendUgcReportMailJob`. `PopulateUgcLayerIdCommand` viene refactored per usare lo stesso job invece di duplicare la logica. `UgcService::LAYER_SEARCH_DISTANCE_METERS` diventa configurabile via env.

**Tech Stack:** Laravel 10, PHP 8.2, Horizon/Redis queue, PostGIS, wm-package submodule

---

## File Map

| File | Azione | Responsabilità |
|------|--------|----------------|
| `wm-package/src/Services/UgcService.php` | Modifica | Costante LAYER_SEARCH_DISTANCE_METERS → env() |
| `app/Jobs/ResolveUgcLayerJob.php` | Crea | Risolve layer via PostGIS, salva properties, dispatcha email |
| `app/Observers/UgcObserver.php` | Modifica | Dispatcha ResolveUgcLayerJob quando layer_id assente |
| `app/Console/Commands/PopulateUgcLayerIdCommand.php` | Modifica | Refactor: dispatcha ResolveUgcLayerJob per ogni record |
| `tests/Feature/UgcNotificationTest.php` | Modifica | Aggiorna test esistenti per approccio asincrono |
| `.env.example` | Modifica | Aggiunge UGC_LAYER_SEARCH_DISTANCE_METERS |

---

## Task 1: UgcService — distanza configurabile via env

**Files:**
- Modify: `wm-package/src/Services/UgcService.php`

- [ ] **Step 1: Modifica la costante**

Sostituisci la costante hardcoded con una lettura da env:

```php
// Prima
private const LAYER_SEARCH_DISTANCE_METERS = 500;

// Dopo — rimuovi la const e aggiungi un metodo
private function searchDistanceMeters(): int
{
    return (int) env('UGC_LAYER_SEARCH_DISTANCE_METERS', 500);
}
```

Aggiorna le chiamate interne che usano `self::LAYER_SEARCH_DISTANCE_METERS`:

```php
private function resolveLayerByNearestEcTrack(GeometryModel $ugc): ?Layer
{
    $closestTrack = GeometryComputationService::make()
        ->getClosestWithinDistance($ugc, EcTrack::class, $this->searchDistanceMeters());
    // ... resto invariato
}
```

- [ ] **Step 2: Aggiungi `UGC_LAYER_SEARCH_DISTANCE_METERS=1000` al `.env` di camminiditalia**

```bash
echo "\nUGC_LAYER_SEARCH_DISTANCE_METERS=1000" >> /Users/bongiu/Documents/camminiditalia/.env
```

- [ ] **Step 3: Aggiungi la variabile a `.env.example`**

Apri `.env.example` e aggiungi dopo le variabili MAIL:

```
UGC_LAYER_SEARCH_DISTANCE_METERS=500
```

- [ ] **Step 4: Verifica che i test esistenti di UgcService passino ancora**

```bash
docker exec laravel-camminiditalia php artisan test tests/Unit/Services/UgcServiceTest.php
```

Expected: tutti i test passano.

---

## Task 2: Crea ResolveUgcLayerJob

**Files:**
- Create: `app/Jobs/ResolveUgcLayerJob.php`
- Modify: `tests/Feature/UgcNotificationTest.php`

- [ ] **Step 1: Scrivi i test per il job**

Apri `tests/Feature/UgcNotificationTest.php` e aggiorna i due test esistenti per l'approccio asincrono:

```php
// Aggiorna import in cima al file
use App\Jobs\ResolveUgcLayerJob;
```

Aggiorna `test_creating_ugc_poi_without_layer_id_populates_it_and_dispatches_job`:

```php
public function test_creating_ugc_poi_without_layer_id_dispatches_resolve_job(): void
{
    Queue::fake();

    $ugcPoi = UgcPoi::factory()->create([
        'properties' => ['form' => ['id' => 'report']],
    ]);

    Queue::assertPushed(ResolveUgcLayerJob::class, function ($job) use ($ugcPoi) {
        return $job->ugc->id === $ugcPoi->id;
    });
    Queue::assertNotPushed(SendUgcReportMailJob::class);
}
```

Aggiorna `test_creating_ugc_poi_far_from_any_track_does_not_dispatch_job`:

```php
public function test_creating_ugc_poi_without_layer_id_and_form_not_report_does_not_dispatch_resolve_job(): void
{
    Queue::fake();

    UgcPoi::factory()->create([
        'properties' => ['form' => ['id' => 'poi']],
    ]);

    Queue::assertNotPushed(ResolveUgcLayerJob::class);
}
```

Aggiungi test: layer_id già presente → nessun job di risoluzione:

```php
public function test_creating_ugc_poi_with_layer_id_does_not_dispatch_resolve_job(): void
{
    Queue::fake();

    $layer = Layer::factory()->create();

    UgcPoi::factory()->create([
        'properties' => ['layer_id' => $layer->id, 'form' => ['id' => 'report']],
    ]);

    Queue::assertNotPushed(ResolveUgcLayerJob::class);
    Queue::assertPushed(SendUgcReportMailJob::class);
}
```

Aggiungi test: utente lascia campo vuoto (form.layer_id null) → nessun job di risoluzione:

```php
public function test_creating_ugc_poi_with_null_form_layer_id_does_not_dispatch_resolve_job(): void
{
    Queue::fake();

    UgcPoi::factory()->create([
        'properties' => ['form' => ['id' => 'report', 'layer_id' => null]],
    ]);

    Queue::assertNotPushed(ResolveUgcLayerJob::class);
    Queue::assertNotPushed(SendUgcReportMailJob::class);
}
```

- [ ] **Step 2: Esegui i test — devono fallire**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/UgcNotificationTest.php
```

Expected: FAIL (ResolveUgcLayerJob non esiste ancora, observer non implementato).

- [ ] **Step 3: Crea il job**

Crea `app/Jobs/ResolveUgcLayerJob.php`:

```php
<?php

namespace App\Jobs;

use App\Mail\NewUgcReportMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Services\UgcService;

class ResolveUgcLayerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly GeometryModel $ugc,
    ) {}

    public function handle(UgcService $ugcService): void
    {
        // Idempotenza: se layer_id è già stato salvato da un retry precedente, skip
        $this->ugc->refresh();
        if (! empty($this->ugc->properties['layer_id'])) {
            SendUgcReportMailJob::dispatch($this->ugc, \Wm\WmPackage\Models\Layer::find($this->ugc->properties['layer_id']));
            return;
        }

        // Guard: geometry assente o non valida
        if (! $this->ugc->geometry) {
            Log::warning('ResolveUgcLayerJob: UgcPoi #'.$this->ugc->id.' ha geometry null, invio fallback a info@.');
            Mail::to('info@camminiditalia.org')->send(new NewUgcReportMail($this->ugc, null, noOwner: true));
            return;
        }

        $layer = $ugcService->resolveLayerByProximity($this->ugc);

        if (! $layer) {
            Log::info('ResolveUgcLayerJob: nessun layer trovato per UgcPoi #'.$this->ugc->id.', invio fallback a info@.');
            Mail::to('info@camminiditalia.org')->send(new NewUgcReportMail($this->ugc, null, noOwner: true));
            return;
        }

        // Salva layer_id nelle properties con saveQuietly() per non retriggare l'observer
        $properties = $this->ugc->properties ?? [];
        $properties['layer_id'] = $layer->id;
        $properties['layer_id_auto_resolved'] = true;
        if (isset($properties['form']) && is_array($properties['form'])) {
            $properties['form']['layer_id'] = $layer->id;
        }
        $this->ugc->properties = $properties;
        $this->ugc->saveQuietly();

        SendUgcReportMailJob::dispatch($this->ugc, $layer);
    }
}
```

> **Nota:** il job chiama `$ugcService->resolveLayerByProximity()` — un nuovo metodo pubblico che esponiamo in Task 3 su `UgcService`. `resolveLayer()` esistente controlla prima `properties['layer_id']` (che qui è assente) poi fa la query spaziale — potremmo usarlo direttamente, ma dato che sappiamo già che layer_id è assente, è più chiaro esporre il solo metodo spaziale.

> **Nota:** `NewUgcReportMail` riceve `null` come layer quando non trovato — il costruttore va aggiornato per accettare `?Layer` (Task 4).

---

## Task 3: Esponi resolveLayerByProximity in UgcService

**Files:**
- Modify: `wm-package/src/Services/UgcService.php`

- [ ] **Step 1: Rendi pubblico il metodo spaziale**

Cambia `private` in `public` su `resolveLayerByNearestEcTrack` e rinominalo per chiarezza:

```php
// Prima
private function resolveLayerByNearestEcTrack(GeometryModel $ugc): ?Layer

// Dopo
public function resolveLayerByProximity(GeometryModel $ugc): ?Layer
```

Aggiorna la chiamata interna in `resolveLayer()`:

```php
public function resolveLayer(GeometryModel $ugc): ?Layer
{
    $properties = $ugc->properties ?? [];

    if (! empty($properties['layer_id'])) {
        $layer = Layer::find($properties['layer_id']);
        if ($layer) {
            return $layer;
        }
    }

    return $this->resolveLayerByProximity($ugc);
}
```

- [ ] **Step 2: Verifica test UgcService**

```bash
docker exec laravel-camminiditalia php artisan test tests/Unit/Services/UgcServiceTest.php
```

Expected: tutti passano.

---

## Task 4: Aggiorna NewUgcReportMail per Layer nullable

**Files:**
- Modify: `app/Mail/NewUgcReportMail.php`

- [ ] **Step 1: Rendi Layer nullable nel costruttore**

```php
public function __construct(
    protected GeometryModel $ugc,
    protected ?Layer $layer,
    protected bool $noOwner = false,
) {}
```

- [ ] **Step 2: Aggiorna il template Blade per gestire layer null**

In `resources/views/emails/new-ugc-report.blade.php`, ovunque venga usato `$layer`, aggiungi un guard:

```blade
@if($layer)
    <p>Cammino: {{ $layer->getStringName() }}</p>
@else
    <p>Cammino: non determinato</p>
@endif
```

- [ ] **Step 3: Verifica che i test mail esistenti passino**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/UgcNotificationTest.php --filter=test_job_sends_mail
```

Expected: PASS.

---

## Task 5: Aggiorna UgcObserver

**Files:**
- Modify: `app/Observers/UgcObserver.php`

- [ ] **Step 1: Aggiorna il metodo created()**

```php
<?php

namespace App\Observers;

use App\Jobs\ResolveUgcLayerJob;
use App\Jobs\SendUgcReportMailJob;
use Illuminate\Database\Eloquent\Model;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Observers\UgcObserver as BaseUgcObserver;

class UgcObserver extends BaseUgcObserver
{
    public function created(Model $model): void
    {
        parent::created($model);

        if (! $model instanceof GeometryModel) {
            return;
        }

        $properties = $model->properties ?? [];
        $form = $properties['form'] ?? null;

        // Solo le segnalazioni (form=report) ricevono notifiche
        if (($form['id'] ?? null) !== 'report') {
            return;
        }

        $layerId = $properties['layer_id'] ?? null;

        if ($layerId) {
            // layer_id già presente: comportamento invariato
            $layer = Layer::find($layerId);
            if ($layer) {
                SendUgcReportMailJob::dispatch($model, $layer);
            }
            return;
        }

        // layer_id assente: controlla se l'utente ha lasciato il campo vuoto consapevolmente
        // Se form è presente e contiene la chiave layer_id (anche se null) → scelta consapevole
        if ($form !== null && array_key_exists('layer_id', $form)) {
            return;
        }

        // layer_id non inviato dall'app (vecchia versione) → risoluzione automatica in background
        ResolveUgcLayerJob::dispatch($model);
    }
}
```

- [ ] **Step 2: Esegui i test di notifica**

```bash
docker exec laravel-camminiditalia php artisan test tests/Feature/UgcNotificationTest.php
```

Expected: tutti passano.

---

## Task 6: Refactor PopulateUgcLayerIdCommand

**Files:**
- Modify: `app/Console/Commands/PopulateUgcLayerIdCommand.php`

- [ ] **Step 1: Refactor del command**

Il command ora dispatcha `ResolveUgcLayerJob` per ogni record, senza logica duplicata:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\ResolveUgcLayerJob;
use Illuminate\Console\Command;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;

class PopulateUgcLayerIdCommand extends Command
{
    protected $signature = 'ugc:populate-layer-id';

    protected $description = 'Dispatcha ResolveUgcLayerJob per tutti i UgcPoi e UgcTrack privi di layer_id';

    public function handle(): int
    {
        $this->processModel(UgcPoi::class);
        $this->processModel(UgcTrack::class);

        return self::SUCCESS;
    }

    private function processModel(string $modelClass): void
    {
        $shortName = class_basename($modelClass);
        $query = $modelClass::whereNull('properties->layer_id');
        $total = $query->count();

        if ($total === 0) {
            $this->info("{$shortName}: nessun record senza layer_id.");
            return;
        }

        $this->info("{$shortName}: trovati {$total} record, dispatching jobs...");
        $bar = $this->output->createProgressBar($total);

        $query->chunkById(100, function ($models) use ($bar) {
            foreach ($models as $model) {
                ResolveUgcLayerJob::dispatch($model);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("{$shortName}: {$total} job accodati.");
    }
}
```

> **Nota:** il command ora dispatcha job anche per UgcTrack. Il job `ResolveUgcLayerJob` non invia email per UgcTrack perché non è una segnalazione — ma salva comunque `layer_id` nelle properties, utile per futuri filtri. Se questo è indesiderato, aggiungere un guard nel job: `if (!($this->ugc instanceof \Wm\WmPackage\Models\UgcPoi)) { /* solo salva, no email */ }`.

- [ ] **Step 2: Verifica manuale**

```bash
docker exec laravel-camminiditalia php artisan ugc:populate-layer-id
```

Expected: output con conteggio job accodati.

---

## Task 7: Test suite completa e commit

- [ ] **Step 1: Esegui tutta la suite di test**

```bash
docker exec laravel-camminiditalia php artisan test
```

Expected: tutti i test passano.

- [ ] **Step 2: Formatta il codice**

```bash
docker exec laravel-camminiditalia composer format
```

- [ ] **Step 3: Verifica diff**

```bash
git diff --stat
git -C wm-package diff --stat
```

Attendi approvazione del developer prima di procedere con i commit.

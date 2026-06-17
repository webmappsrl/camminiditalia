# UGC Email Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Assegnare automaticamente il layer a ogni UgcPoi (via PostGIS se non fornito dal frontend) e inviare una email all'owner del layer ad ogni nuova segnalazione.

**Architecture:** `UgcService::resolveLayer()` è il punto centrale: controlla prima `properties->layer_id`, altrimenti esegue una query PostGIS con `ST_Intersects` + `ST_Distance` dal centroide per trovare il layer più vicino. L'observer `created()` usa questo service per popolare `layer_id` se mancante, poi accoda `SendUgcReportNotificationJob`. Un Artisan command backfilla i record esistenti.

**Tech Stack:** Laravel Mail (Mailable), Laravel Queue (Job), Artisan Command, Blade HTML email, PostGIS `ST_Intersects` + `ST_Distance` + `ST_Centroid`, `GeometryComputationService` (esistente).

---

## File Structure

| File | Azione | Responsabilità |
|------|--------|----------------|
| `wm-package/src/Services/UgcService.php` | Crea | `resolveLayer(UgcPoi): ?Layer` — lookup properties→PostGIS con distanza centroide |
| `wm-package/src/Jobs/SendUgcReportNotificationJob.php` | Crea | Job asincrono che invia la mail a un singolo owner |
| `wm-package/src/Mail/NewUgcReportMail.php` | Crea | Mailable con dati UgcPoi + Layer |
| `wm-package/src/resources/views/emails/new-ugc-report.blade.php` | Crea | Template HTML email con CTA "Vai alla segnalazione" |
| `wm-package/src/Observers/UgcObserver.php` | Modifica | Aggiunge hook `created()`: popola `layer_id` + accoda job |
| `wm-package/src/Console/Commands/PopulateUgcLayerIdCommand.php` | Crea | Backfill `layer_id` su tutti i UgcPoi esistenti senza di esso |
| `tests/Unit/Services/UgcServiceTest.php` | Crea | Test unitari per `resolveLayer()` |
| `tests/Unit/Jobs/SendUgcReportNotificationJobTest.php` | Crea | Test unitari per il job |
| `tests/Feature/UgcNotificationTest.php` | Crea | Test integrazione: creazione UgcPoi → layer_id popolato + email accodata |

---

## Task 1: UgcService — resolveLayer

**Contesto:** Il service è il punto centrale di risoluzione layer per un UgcPoi. Strategia:
1. Se `properties['layer_id']` è presente e il layer esiste in DB → ritorna quello
2. Altrimenti → query PostGIS: trova i Layer la cui geometry interseca il punto UGC, ordinati per `ST_Distance(ST_Centroid(layer.geometry), ugc.geometry) ASC`, prende il primo

`Layer` ha geometry di tipo poligono calcolata come `ST_Envelope(ST_ConvexHull(...))` delle feature contenute (vedi `LayerService::updateLayerGeometry()`). `UgcPoi` estende `Point` — la sua geometry è un punto PostGIS.

**Files:**
- Crea: `wm-package/src/Services/UgcService.php`
- Crea: `tests/Unit/Services/UgcServiceTest.php`

- [ ] **Step 1: Scrivi i test che falliscono**

```php
<?php
// tests/Unit/Services/UgcServiceTest.php

namespace Tests\Unit\Services;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\UgcService;

class UgcServiceTest extends TestCase
{
    use RefreshDatabase;

    private UgcService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UgcService();
    }

    /** @test */
    public function it_returns_layer_from_properties_layer_id_when_present(): void
    {
        $layer = Layer::factory()->create();
        $ugcPoi = UgcPoi::factory()->create([
            'properties' => ['layer_id' => $layer->id],
        ]);

        $result = $this->service->resolveLayer($ugcPoi);

        $this->assertInstanceOf(Layer::class, $result);
        $this->assertEquals($layer->id, $result->id);
    }

    /** @test */
    public function it_falls_back_to_spatial_query_when_layer_id_missing(): void
    {
        // Crea un layer con geometry poligono che contiene il punto UGC
        $layer = Layer::factory()->create();
        DB::table('layers')->where('id', $layer->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POLYGON((10 44, 11 44, 11 45, 10 45, 10 44))', 4326)"),
        ]);

        // UGC point dentro il poligono, senza layer_id nelle properties
        $ugcPoi = UgcPoi::factory()->create([
            'properties' => [],
        ]);
        DB::table('ugc_pois')->where('id', $ugcPoi->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POINT(10.5 44.5)', 4326)"),
        ]);
        $ugcPoi->refresh();

        $result = $this->service->resolveLayer($ugcPoi);

        $this->assertInstanceOf(Layer::class, $result);
        $this->assertEquals($layer->id, $result->id);
    }

    /** @test */
    public function it_returns_closest_layer_by_centroid_distance_when_multiple_intersect(): void
    {
        // Layer vicino: centroide a (10.5, 44.5) — stesso punto UGC
        $nearLayer = Layer::factory()->create();
        DB::table('layers')->where('id', $nearLayer->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POLYGON((10 44, 11 44, 11 45, 10 45, 10 44))', 4326)"),
        ]);

        // Layer lontano: centroide a (12.5, 44.5)
        $farLayer = Layer::factory()->create();
        DB::table('layers')->where('id', $farLayer->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POLYGON((10 44, 15 44, 15 45, 10 45, 10 44))', 4326)"),
        ]);

        $ugcPoi = UgcPoi::factory()->create(['properties' => []]);
        DB::table('ugc_pois')->where('id', $ugcPoi->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POINT(10.5 44.5)', 4326)"),
        ]);
        $ugcPoi->refresh();

        $result = $this->service->resolveLayer($ugcPoi);

        $this->assertEquals($nearLayer->id, $result->id);
    }

    /** @test */
    public function it_returns_null_when_no_layer_intersects_and_no_layer_id(): void
    {
        $ugcPoi = UgcPoi::factory()->create(['properties' => []]);
        DB::table('ugc_pois')->where('id', $ugcPoi->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POINT(0 0)', 4326)"),
        ]);
        $ugcPoi->refresh();

        $result = $this->service->resolveLayer($ugcPoi);

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_when_properties_layer_id_does_not_exist_in_db(): void
    {
        $ugcPoi = UgcPoi::factory()->create([
            'properties' => ['layer_id' => 99999],
        ]);

        $result = $this->service->resolveLayer($ugcPoi);

        $this->assertNull($result);
    }
}
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
php artisan test tests/Unit/Services/UgcServiceTest.php
```

Expected: FAIL con "Class not found".

- [ ] **Step 3: Implementa UgcService**

```php
<?php
// wm-package/src/Services/UgcService.php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcPoi;

class UgcService extends BaseService
{
    /**
     * Risolve il Layer di appartenenza di un UgcPoi.
     * Prima controlla properties->layer_id, poi fallback spaziale via PostGIS.
     * In caso di più layer intersecanti, vince quello con centroide più vicino al punto.
     */
    public function resolveLayer(UgcPoi $ugcPoi): ?Layer
    {
        $properties = $ugcPoi->properties ?? [];

        if (! empty($properties['layer_id'])) {
            $layer = Layer::find($properties['layer_id']);
            if ($layer) {
                return $layer;
            }
        }

        return $this->resolveLayerBySpatialQuery($ugcPoi);
    }

    private function resolveLayerBySpatialQuery(UgcPoi $ugcPoi): ?Layer
    {
        $result = DB::selectOne("
            SELECT id
            FROM layers
            WHERE geometry IS NOT NULL
              AND ST_Intersects(geometry::geometry, (
                  SELECT geometry::geometry FROM ugc_pois WHERE id = ?
              ))
            ORDER BY ST_Distance(
                ST_Centroid(geometry::geometry),
                (SELECT geometry::geometry FROM ugc_pois WHERE id = ?)
            ) ASC
            LIMIT 1
        ", [$ugcPoi->id, $ugcPoi->id]);

        return $result ? Layer::find($result->id) : null;
    }
}
```

- [ ] **Step 4: Esegui i test**

```bash
php artisan test tests/Unit/Services/UgcServiceTest.php
```

Expected: 5 test PASS.

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Services/UgcService.php tests/Unit/Services/UgcServiceTest.php
git commit -m "feat(ugc): add UgcService with resolveLayer via properties fallback to PostGIS spatial query"
```

---

## Task 2: Mailable NewUgcReportMail + template Blade

**Contesto:** La `Mailable` riceve `UgcPoi` e `Layer` nel costruttore. Le coordinate vengono estratte dalla geometry via PostGIS (`ST_X`/`ST_Y`) usando il metodo già esistente `GeometryComputationService::getGeometryModelCoordinates()` che ritorna un oggetto con `->x` (lon) e `->y` (lat).

**Files:**
- Crea: `wm-package/src/Mail/NewUgcReportMail.php`
- Crea: `wm-package/src/resources/views/emails/new-ugc-report.blade.php`
- Crea: `tests/Unit/Mail/NewUgcReportMailTest.php`

- [ ] **Step 1: Verifica il namespace delle view del package**

```bash
grep -n "loadViewsFrom" wm-package/src/Providers/WmPackageServiceProvider.php
```

Annota il namespace usato (es. `wm-package`). Se non c'è `loadViewsFrom`, aggiungilo nel metodo `boot()` (vedi Task 3).

- [ ] **Step 2: Scrivi i test che falliscono**

```php
<?php
// tests/Unit/Mail/NewUgcReportMailTest.php

namespace Tests\Unit\Mail;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Mail\NewUgcReportMail;

class NewUgcReportMailTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function mailable_has_correct_subject(): void
    {
        $layer = Layer::factory()->create(['name' => 'Via Francigena']);
        $ugcPoi = UgcPoi::factory()->create([
            'properties' => ['layer_id' => $layer->id],
        ]);

        $mailable = new NewUgcReportMail($ugcPoi, $layer);

        $mailable->assertHasSubject('Nuova segnalazione su Via Francigena');
    }

    /** @test */
    public function mailable_contains_ugc_data_in_body(): void
    {
        $layer = Layer::factory()->create(['name' => 'Via Francigena']);
        $ugcPoi = UgcPoi::factory()->create([
            'name' => 'Sentiero franato',
            'description' => 'Il sentiero è impraticabile',
            'properties' => ['layer_id' => $layer->id],
        ]);

        $mailable = new NewUgcReportMail($ugcPoi, $layer);

        $mailable->assertSeeInHtml('Sentiero franato');
        $mailable->assertSeeInHtml('Via Francigena');
        $mailable->assertSeeInHtml('Il sentiero è impraticabile');
    }

    /** @test */
    public function mailable_contains_nova_link(): void
    {
        $layer = Layer::factory()->create();
        $ugcPoi = UgcPoi::factory()->create([
            'properties' => ['layer_id' => $layer->id],
        ]);

        $mailable = new NewUgcReportMail($ugcPoi, $layer);

        $mailable->assertSeeInHtml('/nova/resources/ugc-pois/' . $ugcPoi->id);
    }
}
```

- [ ] **Step 3: Esegui i test per verificare che falliscano**

```bash
php artisan test tests/Unit/Mail/NewUgcReportMailTest.php
```

Expected: FAIL con "Class not found".

- [ ] **Step 4: Implementa la Mailable**

```php
<?php
// wm-package/src/Mail/NewUgcReportMail.php

namespace Wm\WmPackage\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Services\GeometryComputationService;

class NewUgcReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $novaUrl;
    public ?string $coordinates;

    public function __construct(
        public readonly UgcPoi $ugcPoi,
        public readonly Layer $layer,
    ) {
        $this->novaUrl = rtrim(config('app.url'), '/') . '/nova/resources/ugc-pois/' . $ugcPoi->id;
        $this->coordinates = $this->extractCoordinates($ugcPoi);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nuova segnalazione su ' . $this->layer->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'wm-package::emails.new-ugc-report',
        );
    }

    private function extractCoordinates(UgcPoi $ugcPoi): ?string
    {
        try {
            $coords = GeometryComputationService::make()->getGeometryModelCoordinates($ugcPoi);
            return number_format($coords->y, 6) . ', ' . number_format($coords->x, 6);
        } catch (\Throwable) {
            return null;
        }
    }
}
```

- [ ] **Step 5: Crea il template Blade**

```bash
mkdir -p wm-package/src/resources/views/emails
```

```html
{{-- wm-package/src/resources/views/emails/new-ugc-report.blade.php --}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuova segnalazione</title>
    <style>
        body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 600px; margin: 30px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .header { background: #2d6a4f; color: #fff; padding: 24px 32px; }
        .header h1 { margin: 0; font-size: 20px; }
        .body { padding: 32px; }
        .field { margin-bottom: 16px; }
        .field label { display: block; font-size: 12px; text-transform: uppercase; color: #888; margin-bottom: 4px; }
        .field p { margin: 0; font-size: 15px; }
        .cta { display: inline-block; margin-top: 24px; background: #2d6a4f; color: #fff; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold; }
        .footer { padding: 16px 32px; font-size: 12px; color: #aaa; border-top: 1px solid #eee; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Nuova segnalazione ricevuta</h1>
    </div>
    <div class="body">
        <p>È stata creata una nuova segnalazione sul cammino <strong>{{ $layer->name }}</strong>.</p>

        <div class="field">
            <label>Cammino / Layer</label>
            <p>{{ $layer->name }}</p>
        </div>

        <div class="field">
            <label>Nome segnalazione</label>
            <p>{{ $ugcPoi->name ?? '—' }}</p>
        </div>

        @if($ugcPoi->description)
        <div class="field">
            <label>Descrizione</label>
            <p>{{ $ugcPoi->description }}</p>
        </div>
        @endif

        @if($coordinates)
        <div class="field">
            <label>Posizione geografica</label>
            <p>{{ $coordinates }}</p>
        </div>
        @endif

        <div class="field">
            <label>Data e ora</label>
            <p>{{ $ugcPoi->created_at?->format('d/m/Y H:i') ?? '—' }}</p>
        </div>

        <a href="{{ $novaUrl }}" class="cta">Apri nel pannello admin →</a>
    </div>
    <div class="footer">
        Questa è una notifica automatica del sistema Cammini d'Italia. Non rispondere a questa email.
    </div>
</div>
</body>
</html>
```

- [ ] **Step 6: Esegui i test**

```bash
php artisan test tests/Unit/Mail/NewUgcReportMailTest.php
```

Expected: 3 test PASS.

- [ ] **Step 7: Commit**

```bash
git add wm-package/src/Mail/NewUgcReportMail.php wm-package/src/resources/views/emails/new-ugc-report.blade.php tests/Unit/Mail/NewUgcReportMailTest.php
git commit -m "feat(ugc): add NewUgcReportMail mailable and HTML email template"
```

---

## Task 3: Registrazione view nel ServiceProvider

**Contesto:** Se `loadViewsFrom` non è già presente in `WmPackageServiceProvider`, le view `wm-package::emails.*` non verranno trovate.

**Files:**
- Modifica (condizionale): `wm-package/src/Providers/WmPackageServiceProvider.php`

- [ ] **Step 1: Verifica**

```bash
grep -n "loadViewsFrom" wm-package/src/Providers/WmPackageServiceProvider.php
```

Se presente con namespace `wm-package` → salta questo task.

- [ ] **Step 2: Aggiungi loadViewsFrom nel metodo boot()**

```php
$this->loadViewsFrom(__DIR__ . '/../resources/views', 'wm-package');
```

- [ ] **Step 3: Verifica**

```bash
php artisan view:clear
php artisan tinker --execute="echo view()->exists('wm-package::emails.new-ugc-report') ? 'OK' : 'NOT FOUND';"
```

Expected: `OK`

- [ ] **Step 4: Commit**

```bash
git add wm-package/src/Providers/WmPackageServiceProvider.php
git commit -m "feat(ugc): register package views in service provider"
```

---

## Task 4: SendUgcReportNotificationJob

**Contesto:** Riceve `UgcPoi` e `Layer`, risolve l'owner via `$layer->layerOwner` (relazione `BelongsTo User` via `user_id` già esistente sul modello `Layer`). Se l'owner è null, logga e termina senza errore.

**Files:**
- Crea: `wm-package/src/Jobs/SendUgcReportNotificationJob.php`
- Crea: `tests/Unit/Jobs/SendUgcReportNotificationJobTest.php`

- [ ] **Step 1: Scrivi i test che falliscono**

```php
<?php
// tests/Unit/Jobs/SendUgcReportNotificationJobTest.php

namespace Tests\Unit\Jobs;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Jobs\SendUgcReportNotificationJob;
use Wm\WmPackage\Mail\NewUgcReportMail;

class SendUgcReportNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_sends_mail_to_layer_owner(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['email' => 'gestore@example.com']);
        $layer = Layer::factory()->create(['user_id' => $owner->id]);
        $ugcPoi = UgcPoi::factory()->create([
            'properties' => ['layer_id' => $layer->id],
        ]);

        (new SendUgcReportNotificationJob($ugcPoi, $layer))->handle();

        Mail::assertSent(NewUgcReportMail::class, fn ($mail) => $mail->hasTo('gestore@example.com'));
    }

    /** @test */
    public function it_does_not_send_mail_when_layer_has_no_owner(): void
    {
        Mail::fake();
        Log::spy();

        $layer = Layer::factory()->create(['user_id' => null]);
        $ugcPoi = UgcPoi::factory()->create([
            'properties' => ['layer_id' => $layer->id],
        ]);

        (new SendUgcReportNotificationJob($ugcPoi, $layer))->handle();

        Mail::assertNothingSent();
        Log::shouldHaveReceived('info')->once();
    }
}
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
php artisan test tests/Unit/Jobs/SendUgcReportNotificationJobTest.php
```

Expected: FAIL con "Class not found".

- [ ] **Step 3: Implementa il job**

```php
<?php
// wm-package/src/Jobs/SendUgcReportNotificationJob.php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Wm\WmPackage\Mail\NewUgcReportMail;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcPoi;

class SendUgcReportNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        protected UgcPoi $ugcPoi,
        protected Layer $layer,
    ) {}

    public function handle(): void
    {
        $owner = $this->layer->layerOwner;

        if (! $owner) {
            Log::info('SendUgcReportNotificationJob: layer ' . $this->layer->id . ' has no owner, skipping email.');
            return;
        }

        Mail::to($owner->email)->send(new NewUgcReportMail($this->ugcPoi, $this->layer));
    }
}
```

- [ ] **Step 4: Esegui i test**

```bash
php artisan test tests/Unit/Jobs/SendUgcReportNotificationJobTest.php
```

Expected: 2 test PASS.

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Jobs/SendUgcReportNotificationJob.php tests/Unit/Jobs/SendUgcReportNotificationJobTest.php
git commit -m "feat(ugc): add SendUgcReportNotificationJob"
```

---

## Task 5: Hook created() in UgcObserver

**Contesto:** L'observer esistente è in `wm-package/src/Observers/UgcObserver.php`. Il metodo `created()` fa due cose in sequenza:
1. Se `properties['layer_id']` è assente, chiama `UgcService::resolveLayer()` e salva il risultato in `properties['layer_id']` via `saveQuietly()` (per non retriggare l'observer)
2. Accoda `SendUgcReportNotificationJob` se il layer è stato risolto

**Files:**
- Modifica: `wm-package/src/Observers/UgcObserver.php`
- Crea: `tests/Feature/UgcNotificationTest.php`

- [ ] **Step 1: Scrivi i test di integrazione che falliscono**

```php
<?php
// tests/Feature/UgcNotificationTest.php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Jobs\SendUgcReportNotificationJob;

class UgcNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function creating_ugc_poi_with_layer_id_in_properties_dispatches_notification_job(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $layer = Layer::factory()->create(['user_id' => $owner->id]);

        UgcPoi::factory()->create([
            'properties' => ['layer_id' => $layer->id],
        ]);

        Queue::assertPushed(SendUgcReportNotificationJob::class);
    }

    /** @test */
    public function creating_ugc_poi_without_layer_id_populates_it_via_spatial_query(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $layer = Layer::factory()->create(['user_id' => $owner->id]);
        DB::table('layers')->where('id', $layer->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POLYGON((10 44, 11 44, 11 45, 10 45, 10 44))', 4326)"),
        ]);

        $ugcPoi = UgcPoi::factory()->create(['properties' => []]);
        DB::table('ugc_pois')->where('id', $ugcPoi->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POINT(10.5 44.5)', 4326)"),
        ]);
        // Simula la creazione triggering l'observer manualmente dopo aver settato la geometry
        $ugcPoi->refresh();
        (new \Wm\WmPackage\Observers\UgcObserver())->created($ugcPoi);

        $ugcPoi->refresh();
        $this->assertEquals($layer->id, $ugcPoi->properties['layer_id']);
        Queue::assertPushed(SendUgcReportNotificationJob::class);
    }

    /** @test */
    public function creating_ugc_poi_without_intersecting_layer_does_not_dispatch_job(): void
    {
        Queue::fake();

        UgcPoi::factory()->create(['properties' => []]);

        Queue::assertNotPushed(SendUgcReportNotificationJob::class);
    }
}
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

```bash
php artisan test tests/Feature/UgcNotificationTest.php
```

Expected: FAIL.

- [ ] **Step 3: Aggiungi created() in UgcObserver**

Apri `wm-package/src/Observers/UgcObserver.php` e aggiungi dopo il metodo `creating()`:

```php
public function created(UgcPoi $model): void
{
    $ugcService = new \Wm\WmPackage\Services\UgcService();
    $layer = $ugcService->resolveLayer($model);

    if (! $layer) {
        return;
    }

    // Popola layer_id se non era stato fornito dal frontend
    if (empty($model->properties['layer_id'])) {
        $properties = $model->properties ?? [];
        $properties['layer_id'] = $layer->id;
        $model->properties = $properties;
        $model->saveQuietly();
    }

    SendUgcReportNotificationJob::dispatch($model, $layer);
}
```

Aggiungi anche l'import in cima al file:

```php
use Wm\WmPackage\Jobs\SendUgcReportNotificationJob;
use Wm\WmPackage\Models\UgcPoi;
```

- [ ] **Step 4: Esegui i test**

```bash
php artisan test tests/Feature/UgcNotificationTest.php
```

Expected: 3 test PASS.

- [ ] **Step 5: Esegui la suite completa**

```bash
php artisan test
```

Expected: tutti i test pre-esistenti PASS più i nuovi.

- [ ] **Step 6: Commit**

```bash
git add wm-package/src/Observers/UgcObserver.php tests/Feature/UgcNotificationTest.php
git commit -m "feat(ugc): populate layer_id and dispatch notification on UgcPoi creation"
```

---

## Task 6: Artisan Command — backfill layer_id

**Contesto:** Tutti i `UgcPoi` esistenti senza `properties->layer_id` vengono aggiornati usando la stessa logica spaziale di `UgcService::resolveLayer()`. Il command processa i record in chunk di 100 per evitare problemi di memoria.

**Files:**
- Crea: `wm-package/src/Console/Commands/PopulateUgcLayerIdCommand.php`
- Modifica: `wm-package/src/Providers/WmPackageServiceProvider.php` (registrazione command)

- [ ] **Step 1: Implementa il command**

```php
<?php
// wm-package/src/Console/Commands/PopulateUgcLayerIdCommand.php

namespace Wm\WmPackage\Console\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Services\UgcService;

class PopulateUgcLayerIdCommand extends Command
{
    protected $signature = 'ugc:populate-layer-id';
    protected $description = 'Popola layer_id nelle properties dei UgcPoi che ne sono privi tramite query spaziale PostGIS';

    public function handle(UgcService $ugcService): int
    {
        $query = UgcPoi::whereNull('properties->layer_id');
        $total = $query->count();

        if ($total === 0) {
            $this->info('Nessun UgcPoi senza layer_id trovato.');
            return self::SUCCESS;
        }

        $this->info("Trovati {$total} UgcPoi senza layer_id. Avvio elaborazione...");
        $bar = $this->output->createProgressBar($total);
        $updated = 0;

        $query->chunkById(100, function ($ugcPois) use ($ugcService, $bar, &$updated) {
            foreach ($ugcPois as $ugcPoi) {
                $layer = $ugcService->resolveLayer($ugcPoi);
                if ($layer) {
                    $properties = $ugcPoi->properties ?? [];
                    $properties['layer_id'] = $layer->id;
                    $ugcPoi->properties = $properties;
                    $ugcPoi->saveQuietly();
                    $updated++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Completato: {$updated}/{$total} UgcPoi aggiornati.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Registra il command nel ServiceProvider**

Trova il metodo `boot()` o la lista dei commands in `WmPackageServiceProvider` e aggiungi:

```php
if ($this->app->runningInConsole()) {
    $this->commands([
        \Wm\WmPackage\Console\Commands\PopulateUgcLayerIdCommand::class,
    ]);
}
```

- [ ] **Step 3: Verifica che il command sia disponibile**

```bash
php artisan list | grep ugc
```

Expected: `ugc:populate-layer-id` presente nell'output.

- [ ] **Step 4: Commit**

```bash
git add wm-package/src/Console/Commands/PopulateUgcLayerIdCommand.php wm-package/src/Providers/WmPackageServiceProvider.php
git commit -m "feat(ugc): add ugc:populate-layer-id command for backfilling existing records"
```

---

## Task 7: Verifica end-to-end in locale

- [ ] **Step 1: Verifica .env locale**

```
MAIL_MAILER=log
MAIL_FROM_ADDRESS=noreply@camminiditalia.it
MAIL_FROM_NAME="Cammini d'Italia"
QUEUE_CONNECTION=sync
```

- [ ] **Step 2: Crea un UgcPoi di test tramite tinker**

```bash
php artisan tinker
```

```php
$owner = \Wm\WmPackage\Models\User::factory()->create(['email' => 'test-gestore@example.com']);
$layer = \Wm\WmPackage\Models\Layer::factory()->create(['user_id' => $owner->id, 'name' => 'Via Francigena Test']);
// Setta la geometry del layer manualmente se la factory non la popola
\Illuminate\Support\Facades\DB::table('layers')->where('id', $layer->id)->update([
    'geometry' => \Illuminate\Support\Facades\DB::raw("ST_GeomFromText('POLYGON((10 44, 11 44, 11 45, 10 45, 10 44))', 4326)"),
]);
// Crea UGC senza layer_id — deve essere risolto spazialmente
$ugc = \Wm\WmPackage\Models\UgcPoi::create([
    'name' => 'Sentiero franato test',
    'description' => 'Il sentiero è impraticabile per frana',
    'geometry' => \Illuminate\Support\Facades\DB::raw("ST_GeomFromText('POINT(10.5 44.5)', 4326)"),
    'properties' => [],
]);
```

- [ ] **Step 3: Verifica layer_id popolato e mail nei log**

```bash
php artisan tinker --execute="dump(\Wm\WmPackage\Models\UgcPoi::latest()->first()->properties);"
```

Expected: `layer_id` presente nelle properties.

```bash
tail -100 storage/logs/laravel.log | grep -A 30 "new-ugc-report"
```

Expected: corpo HTML della mail con dati del UGC.

---

## Task 8: Configurazione produzione

- [ ] **Step 1: Variabili .env da configurare in produzione**

```env
# Opzione A: SMTP
MAIL_MAILER=smtp
MAIL_HOST=smtp.esempio.it
MAIL_PORT=587
MAIL_USERNAME=noreply@camminiditalia.it
MAIL_PASSWORD=<password>
MAIL_ENCRYPTION=tls

# Opzione B: Amazon SES
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=<key>
AWS_SECRET_ACCESS_KEY=<secret>
AWS_DEFAULT_REGION=eu-west-1

# Opzione C: Postmark
MAIL_MAILER=postmark
POSTMARK_TOKEN=<token>

# Mittente (comune a tutti)
MAIL_FROM_ADDRESS=noreply@camminiditalia.it
MAIL_FROM_NAME="Cammini d'Italia"
```

- [ ] **Step 2: Esegui il backfill in produzione dopo il deploy**

```bash
php artisan ugc:populate-layer-id
```

---

## Self-Review

### Copertura spec

| Requisito | Task |
|-----------|------|
| Notifica email al gestore alla creazione segnalazione | Task 5 + Task 4 |
| Owner del layer come destinatario | Task 4 (`layer->layerOwner`) |
| Cammino/layer nel contenuto email | Task 2 (template) |
| Descrizione e nome segnalazione | Task 2 (template) |
| Posizione geografica (coordinate) | Task 2 (`extractCoordinates` via `GeometryComputationService`) |
| Link diretto al pannello admin Nova | Task 2 (`novaUrl`) |
| Invio immediato per ogni segnalazione | Task 4 (job senza scheduling) |
| Fallback se no owner | Task 4 (log + skip) |
| Risoluzione layer: properties → PostGIS centroide | Task 1 (`UgcService::resolveLayer`) |
| Paternità unica (layer più vicino) | Task 1 (`ORDER BY ST_Distance ... LIMIT 1`) |
| Backfill record esistenti | Task 6 (command `ugc:populate-layer-id`) |
| Configurazione mail produzione | Task 8 |

### Coerenza tipi
- `UgcService::resolveLayer(UgcPoi): ?Layer` — usato in Task 5 e Task 6 ✓
- `SendUgcReportNotificationJob(UgcPoi, Layer)` — dispatch coerente con costruttore ✓
- `NewUgcReportMail(UgcPoi, Layer)` — costruttore coerente con uso in Task 4 ✓
- `Layer::layerOwner` (BelongsTo User via user_id) — confermato dal modello ✓
- `GeometryComputationService::getGeometryModelCoordinates()` restituisce `stdClass` con `->x` e `->y` ✓

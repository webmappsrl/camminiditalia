<?php

namespace Tests\Feature;

use App\Jobs\ResolveUgcLayerJob;
use App\Jobs\SendUgcReportMailJob;
use App\Mail\NewUgcReportMail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;
use Wm\WmPackage\Services\UgcService;

class UgcNotificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    // -------------------------------------------------------------------------
    // Job dispatch — observer
    // -------------------------------------------------------------------------

    public function test_creating_ugc_poi_with_layer_id_dispatches_notification_job(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $layer = Layer::factory()->create(['user_id' => $owner->id]);

        UgcPoi::factory()->create([
            'properties' => ['layer_id' => $layer->id, 'form' => ['id' => 'report']],
        ]);

        Queue::assertPushed(SendUgcReportMailJob::class);
        Queue::assertNotPushed(ResolveUgcLayerJob::class);
    }

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

    public function test_creating_ugc_poi_without_layer_id_and_form_not_report_does_not_dispatch_resolve_job(): void
    {
        Queue::fake();

        UgcPoi::factory()->create([
            'properties' => ['form' => ['id' => 'poi']],
        ]);

        Queue::assertNotPushed(ResolveUgcLayerJob::class);
        Queue::assertNotPushed(SendUgcReportMailJob::class);
    }

    public function test_creating_ugc_poi_with_null_form_layer_id_does_not_dispatch_resolve_job(): void
    {
        Queue::fake();

        UgcPoi::factory()->create([
            'properties' => ['form' => ['id' => 'report', 'layer_id' => null]],
        ]);

        Queue::assertNotPushed(ResolveUgcLayerJob::class);
        Queue::assertNotPushed(SendUgcReportMailJob::class);
    }

    public function test_resolve_job_skips_non_report_ugc(): void
    {
        Mail::fake();

        $ugcPoi = UgcPoi::factory()->create([
            'properties' => ['form' => ['id' => 'poi']],
        ]);

        (new ResolveUgcLayerJob($ugcPoi))->handle(app(UgcService::class));

        Mail::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Mail sending
    // -------------------------------------------------------------------------

    public function test_job_sends_mail_to_layer_owner(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['email' => 'gestore@test.com']);
        $layer = Layer::factory()->create(['user_id' => $owner->id]);
        $ugcPoi = UgcPoi::factory()->create(['properties' => ['layer_id' => $layer->id]]);

        (new SendUgcReportMailJob($ugcPoi, $layer))->handle();

        Mail::assertSent(NewUgcReportMail::class, fn ($mail) => $mail->hasTo('gestore@test.com'));
    }

    public function test_job_sends_fallback_mail_when_layer_has_no_owner(): void
    {
        Mail::fake();

        $layer = Layer::factory()->create(['user_id' => null]);
        $ugcPoi = UgcPoi::factory()->create(['properties' => ['layer_id' => $layer->id]]);

        (new SendUgcReportMailJob($ugcPoi, $layer))->handle();

        Mail::assertSent(NewUgcReportMail::class, fn ($mail) => $mail->hasTo('info@camminiditalia.org'));
    }

    // -------------------------------------------------------------------------
    // Mail content
    // -------------------------------------------------------------------------

    public function test_mail_contains_ugc_data_and_nova_link(): void
    {
        $layer = Layer::factory()->create(['name' => 'Via Francigena Test']);
        $ugcPoi = UgcPoi::factory()->create([
            'name' => 'Sentiero franato',
            'properties' => [
                'layer_id' => $layer->id,
                'form' => [
                    'title' => 'Sentiero franato',
                    'description' => 'Frana in corso',
                    'waypointtype' => 'danger',
                ],
            ],
        ]);

        $mail = new NewUgcReportMail($ugcPoi, $layer);

        $mail->assertHasSubject('Nuova segnalazione su Via Francigena Test');
        $mail->assertSeeInHtml('Sentiero franato');
        $mail->assertSeeInHtml('Frana in corso');
        $mail->assertSeeInHtml('danger');
        $mail->assertSeeInHtml('Via Francigena Test');
        $mail->assertSeeInHtml('/nova/resources/ugc-pois/'.$ugcPoi->id);
    }
}

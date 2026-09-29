<?php

namespace Tests\Feature;

use App\Jobs\SendCertificationRequestMailJob;
use App\Mail\NewCertificationRequestMail;
use App\Models\CertificationRequest;
use App\Services\CertificationRequestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class CertificationRequestMailTest extends TestCase
{
    use DatabaseTransactions, FakesCertificationDisk, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->fakeCertificationDisk();
    }

    private function makeRequest(array $overrides = []): CertificationRequest
    {
        $user = $overrides['user'] ?? User::factory()->create();
        $layer = $overrides['layer'] ?? $this->createLayer();

        $request = CertificationRequest::create([
            'user_id' => $user->id,
            'layer_id' => $layer->id,
            'app_id' => $layer->app_id,
            'status' => CertificationRequest::STATUS_PENDING,
            'serial_number' => $overrides['serial_number'] ?? null,
            'disclaimer_accepted_at' => now(),
        ]);

        $request->addMedia(UploadedFile::fake()->image('original.jpg'))->toMediaCollection('default');

        return $request->fresh();
    }

    // -------------------------------------------------------------------------
    // Dispatch from the service
    // -------------------------------------------------------------------------

    public function test_submit_dispatches_mail_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $layer = $this->createLayer();

        $service = app(CertificationRequestService::class);
        $request = $service->submit($user, $layer, [UploadedFile::fake()->image('c1.jpg')], null);

        Queue::assertPushed(SendCertificationRequestMailJob::class, function ($job) use ($request) {
            return $job->request->id === $request->id;
        });
    }

    public function test_failed_submit_does_not_dispatch_mail_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $layer = $this->createLayer();

        CertificationRequest::create([
            'user_id' => $user->id,
            'layer_id' => $layer->id,
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
        ]);

        $service = app(CertificationRequestService::class);

        try {
            $service->submit($user, $layer, [UploadedFile::fake()->image('c1.jpg')], null);
        } catch (\Throwable $e) {
            // atteso: richiesta pending già esistente
        }

        Queue::assertNotPushed(SendCertificationRequestMailJob::class);
    }

    // -------------------------------------------------------------------------
    // Job / mail sending
    // -------------------------------------------------------------------------

    public function test_job_sends_to_layer_owner(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['email' => 'gestore@test.com']);
        $layer = $this->createLayer($owner->id);
        $request = $this->makeRequest(['layer' => $layer]);

        (new SendCertificationRequestMailJob($request))->handle();

        Mail::assertSent(NewCertificationRequestMail::class, fn ($mail) => $mail->hasTo('gestore@test.com'));
    }

    public function test_job_falls_back_to_info_address_without_owner(): void
    {
        Mail::fake();

        $layer = $this->createLayer();
        $request = $this->makeRequest(['layer' => $layer]);

        (new SendCertificationRequestMailJob($request))->handle();

        Mail::assertSent(NewCertificationRequestMail::class, fn ($mail) => $mail->hasTo('info@camminiditalia.org'));
    }

    // -------------------------------------------------------------------------
    // Mail content
    // -------------------------------------------------------------------------

    public function test_mail_does_not_change_global_locale(): void
    {
        $before = app()->getLocale();
        $mail = new NewCertificationRequestMail($this->makeRequest());

        $mail->render();

        $this->assertSame($before, app()->getLocale());
        $this->assertSame('it', $mail->locale);
    }

    public function test_mail_contains_nova_link_and_no_image_urls(): void
    {
        $owner = User::factory()->create(['name' => 'Mario Gestore']);
        $layer = $this->createLayer($owner->id);
        $walker = User::factory()->create(['name' => 'Luigi Camminatore', 'email' => 'luigi@test.com']);
        $request = $this->makeRequest(['layer' => $layer, 'user' => $walker, 'serial_number' => 'SN-042']);

        $mail = new NewCertificationRequestMail($request);

        $rendered = $mail->render();

        $this->assertStringContainsString($mail->novaUrl, $rendered);
        $this->assertStringContainsString('/nova/resources/certification-requests/'.$request->id, $rendered);
        $this->assertStringNotContainsString('<img', $rendered);

        $media = $request->getMedia(CertificationRequest::MEDIA_COLLECTION);
        $this->assertCount(1, $media);

        foreach ($media as $item) {
            $this->assertStringNotContainsString($item->getPathRelativeToRoot(), $rendered);
            $this->assertStringNotContainsString($item->getUrl(), $rendered);
            $this->assertStringNotContainsString($item->file_name, $rendered);
        }

        // Il Mailable renderizza in italiano (locale solo del Mailable).
        $previousLocale = app()->getLocale();
        app()->setLocale('it');
        $layerName = $layer->getStringName();
        app()->setLocale($previousLocale);
        $mail->assertSeeInHtml($layerName);
        $mail->assertSeeInHtml('Luigi Camminatore');
        $mail->assertSeeInHtml('luigi@test.com');
        $mail->assertSeeInHtml('SN-042');
    }

    public function test_mail_subject_contains_layer_name(): void
    {
        $layer = $this->createLayer();
        \Wm\WmPackage\Models\Layer::whereKey($layer->id)->update(['name' => 'Via Francigena Test']);
        $layer = $layer->fresh();
        $request = $this->makeRequest(['layer' => $layer]);

        $mail = new NewCertificationRequestMail($request);

        $mail->assertHasSubject('Nuova richiesta di certificazione su '.$layer->getStringName());
    }

    public function test_mail_no_owner_notice_is_shown_when_no_owner(): void
    {
        $layer = $this->createLayer();
        $request = $this->makeRequest(['layer' => $layer]);

        $mail = new NewCertificationRequestMail($request, noOwner: true);

        $mail->assertSeeInHtml('info@camminiditalia.org');
    }

    public function test_mail_shows_walker_email_when_name_missing(): void
    {
        $layer = $this->createLayer();
        $walker = User::factory()->create(['name' => '', 'email' => 'senzanome@test.com']);
        $request = $this->makeRequest(['layer' => $layer, 'user' => $walker]);

        $mail = new NewCertificationRequestMail($request);

        $mail->assertSeeInHtml('senzanome@test.com');
    }
}

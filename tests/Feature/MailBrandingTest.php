<?php

namespace Tests\Feature;

use App\Mail\CertificationDecisionMail;
use App\Mail\NewCertificationRequestMail;
use App\Mail\NewUgcReportMail;
use App\Models\CertificationRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Layout comune delle mail di Cammini d'Italia (oc:8671): icon dell'App in
 * testata, logo del cammino nel corpo, lingua del documento.
 */
class MailBrandingTest extends TestCase
{
    use DatabaseTransactions, FakesCertificationDisk, LayerTestHelpers;

    private App $wmApp;

    private Layer $layer;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();
        $this->fakeCertificationDisk();

        $this->wmApp = App::first() ?? App::factory()->create();
        $this->layer = $this->createLayer($this->createUserWithRole('Validator')->id);
        $this->layer->forceFill(['app_id' => $this->wmApp->id])->saveQuietly();
    }

    private function makeRequest(string $status = CertificationRequest::STATUS_PENDING, string $locale = 'it'): CertificationRequest
    {
        return CertificationRequest::create([
            'user_id' => User::factory()->create(['name' => 'Mario Rossi'])->id,
            'layer_id' => $this->layer->id,
            'app_id' => $this->wmApp->id,
            'status' => $status,
            'disclaimer_accepted_at' => now(),
            'locale' => $locale,
        ]);
    }

    private function withAppIcon(): string
    {
        $this->wmApp->addMedia(UploadedFile::fake()->image('icon.png', 96, 96))->toMediaCollection('icon');

        return $this->wmApp->fresh()->getFirstMediaUrl('icon');
    }

    private function withRouteLogo(): string
    {
        $this->layer->addMedia(UploadedFile::fake()->image('logo.png', 128, 128))->toMediaCollection('logo');

        return $this->layer->fresh()->getFirstMediaUrl('logo');
    }

    private function routeNameInItalian(): string
    {
        $previous = app()->getLocale();
        app()->setLocale('it');

        try {
            return $this->layer->getStringName();
        } finally {
            app()->setLocale($previous);
        }
    }

    public function test_all_mails_show_app_icon_in_header(): void
    {
        $iconUrl = $this->withAppIcon();
        $ugc = UgcPoi::factory()->create(['app_id' => $this->wmApp->id, 'properties' => ['layer_id' => $this->layer->id]]);

        foreach ([
            new NewCertificationRequestMail($this->makeRequest()),
            new CertificationDecisionMail($this->makeRequest(CertificationRequest::STATUS_REJECTED)),
            new NewUgcReportMail($ugc, $this->layer),
        ] as $mail) {
            $html = $mail->render();
            $this->assertStringContainsString($iconUrl, $html, $mail::class);
            $this->assertStringContainsString("Cammini d'Italia", html_entity_decode($html, ENT_QUOTES), $mail::class);
        }
    }

    public function test_mails_show_route_logo_with_descriptive_alt(): void
    {
        $logoUrl = $this->withRouteLogo();
        // Le mail sono in italiano: il nome del cammino (traducibile) va letto in quella lingua.
        $name = $this->routeNameInItalian();

        foreach ([
            new NewCertificationRequestMail($this->makeRequest()),
            new CertificationDecisionMail($this->makeRequest(CertificationRequest::STATUS_REJECTED)),
        ] as $mail) {
            $html = html_entity_decode($mail->render(), ENT_QUOTES);
            $this->assertStringContainsString($logoUrl, $html, $mail::class);
            $this->assertStringContainsString('alt="Logo di '.$name.'"', $html, $mail::class);
        }
    }

    public function test_mails_without_logos_have_no_broken_images(): void
    {
        $html = (new CertificationDecisionMail($this->makeRequest(CertificationRequest::STATUS_REJECTED)))->render();

        $this->assertStringNotContainsString('src=""', $html);
        $this->assertStringContainsString($this->routeNameInItalian(), html_entity_decode($html, ENT_QUOTES));
    }

    public function test_decision_mail_document_language_follows_request_locale(): void
    {
        $html = (new CertificationDecisionMail($this->makeRequest(CertificationRequest::STATUS_REJECTED, 'de')))->render();

        $this->assertStringContainsString('<html lang="de"', $html);
    }

    public function test_rejected_mail_tells_walker_how_to_try_again(): void
    {
        $html = html_entity_decode((new CertificationDecisionMail($this->makeRequest(CertificationRequest::STATUS_REJECTED)))->render(), ENT_QUOTES);

        $this->assertStringContainsString('nuova richiesta', $html);
    }

    public function test_manager_mail_button_and_fallback_link(): void
    {
        $mail = new NewCertificationRequestMail($this->makeRequest());
        $html = $mail->render();

        $this->assertStringContainsString('Esamina la richiesta', $html);
        $this->assertSame(3, substr_count($html, $mail->novaUrl), 'href del pulsante, href e testo del link di riserva.');
    }
}

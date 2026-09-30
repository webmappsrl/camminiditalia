<?php

namespace Tests\Feature;

use App\Jobs\SendCertificationDecisionMailJob;
use App\Mail\CertificationDecisionMail;
use App\Models\CertificationRequest;
use App\Services\CertificationRequestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\CreatesCertificationTracks;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class CertificationDecisionMailTest extends TestCase
{
    use CreatesCertificationTracks, DatabaseTransactions, LayerTestHelpers;

    private User $owner;

    private User $walker;

    private Layer $layer;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->owner = $this->createUserWithRole('Validator');
        $this->layer = $this->createLayer($this->owner->id);
        $this->walker = User::factory()->create(['email' => 'mario@example.org', 'name' => 'Mario']);
    }

    private function decided(string $outcome, array $trackNames = [], ?string $note = null, string $locale = 'it'): CertificationRequest
    {
        $ids = collect($trackNames)
            ->map(fn ($name) => $this->createTrack($this->owner->id, $name, $this->layer)->id)
            ->all();

        $request = CertificationRequest::create([
            'user_id' => $this->walker->id,
            'layer_id' => $this->layer->id,
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
            'locale' => $locale,
        ]);

        return app(CertificationRequestService::class)->decide($request, $this->owner, $outcome, $ids, false, $note);
    }

    public function test_job_sends_mail_to_walker(): void
    {
        Mail::fake();
        $request = $this->decided('reject');

        (new SendCertificationDecisionMailJob($request))->handle();

        Mail::assertSent(CertificationDecisionMail::class, fn (CertificationDecisionMail $mail) => $mail->hasTo('mario@example.org')
            && $mail->request->is($request));
    }

    public function test_job_skips_walker_without_email(): void
    {
        Mail::fake();
        $request = $this->decided('reject');
        $this->walker->forceFill(['email' => ''])->saveQuietly();

        (new SendCertificationDecisionMailJob($request->fresh()))->handle();

        Mail::assertNothingSent();
    }

    public function test_mail_uses_request_locale(): void
    {
        $mail = new CertificationDecisionMail($this->decided('reject', locale: 'de'));

        $this->assertSame('de', $mail->locale);
        $this->assertStringContainsString('abgelehnt', $mail->render());
    }

    public function test_approved_mail_lists_validated_stages(): void
    {
        $html = (new CertificationDecisionMail($this->decided('approve', ['Tappa 1', 'Tappa 2'])))->render();

        $this->assertStringContainsString('Tappa 1', $html);
        $this->assertStringContainsString('Tappa 2', $html);
        $this->assertStringContainsString('approvata', $html);
    }

    public function test_rejected_mail_contains_note_and_no_stages(): void
    {
        $this->createTrack($this->owner->id, 'Tappa non validata', $this->layer);

        $html = (new CertificationDecisionMail($this->decided('reject', note: 'Foto illeggibile')))->render();

        $this->assertStringContainsString('Foto illeggibile', $html);
        $this->assertStringContainsString('rifiutata', $html);
        $this->assertStringNotContainsString('Tappa non validata', $html);
    }

    public function test_note_is_escaped(): void
    {
        $html = (new CertificationDecisionMail($this->decided('reject', note: '<script>alert(1)</script>')))->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}

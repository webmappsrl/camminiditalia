<?php

namespace Tests\Feature;

use App\Exceptions\CertificationDecisionException;
use App\Jobs\SendCertificationDecisionMailJob;
use App\Models\CertificationRequest;
use App\Models\ValidatedEcTrack;
use App\Services\CertificationRequestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\CreatesCertificationTracks;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class CertificationRequestDecisionTest extends TestCase
{
    use CreatesCertificationTracks, DatabaseTransactions, LayerTestHelpers;

    private CertificationRequestService $service;

    private User $owner;

    private User $walker;

    private Layer $layer;

    /** @var array<int, int> */
    private array $trackIds;

    private CertificationRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->service = app(CertificationRequestService::class);
        $this->owner = $this->createUserWithRole('Validator');
        $this->layer = $this->createLayer($this->owner->id);
        $this->walker = User::factory()->create();
        $this->trackIds = collect(['Tappa 1', 'Tappa 2', 'Tappa 3'])
            ->map(fn ($name) => $this->createTrack($this->owner->id, $name, $this->layer)->id)
            ->all();
        $this->request = $this->makeRequest($this->layer);
    }

    private function makeRequest(Layer $layer): CertificationRequest
    {
        return CertificationRequest::create([
            'user_id' => $this->walker->id,
            'layer_id' => $layer->id,
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
        ]);
    }

    public function test_approve_with_selected_tracks(): void
    {
        $this->service->decide($this->request, $this->owner, 'approve', [$this->trackIds[0], $this->trackIds[2]], false, 'Ok');

        $fresh = $this->request->fresh();
        $this->assertSame(CertificationRequest::STATUS_APPROVED, $fresh->status);
        $this->assertSame($this->owner->id, $fresh->decided_by);
        $this->assertNotNull($fresh->decided_at);
        $this->assertSame('Ok', $fresh->decision_note);
        $this->assertEqualsCanonicalizing(
            [$this->trackIds[0], $this->trackIds[2]],
            $fresh->validatedTracks()->pluck('ec_track_id')->all()
        );
        $row = $fresh->validatedTracks()->first();
        $this->assertSame(ValidatedEcTrack::SOURCE_MANUAL, $row->source);
        $this->assertSame($this->layer->id, $row->layer_id);
        $this->assertSame($this->walker->id, $row->user_id);
    }

    public function test_approve_select_all_takes_every_selectable_track(): void
    {
        $this->service->decide($this->request, $this->owner, 'approve', [], true, null);

        $this->assertEqualsCanonicalizing(
            $this->trackIds,
            $this->request->validatedTracks()->pluck('ec_track_id')->all()
        );
    }

    public function test_approve_without_tracks_throws(): void
    {
        $this->expectException(CertificationDecisionException::class);

        try {
            $this->service->decide($this->request, $this->owner, 'approve', [], false, null);
        } finally {
            $this->assertTrue($this->request->fresh()->isPending());
        }
    }

    public function test_reject_ignores_tracks_and_saves_note(): void
    {
        $this->service->decide($this->request, $this->owner, 'reject', $this->trackIds, true, 'Foto illeggibile');

        $fresh = $this->request->fresh();
        $this->assertSame(CertificationRequest::STATUS_REJECTED, $fresh->status);
        $this->assertSame('Foto illeggibile', $fresh->decision_note);
        $this->assertSame(0, ValidatedEcTrack::count());
    }

    public function test_empty_note_is_stored_as_null(): void
    {
        $this->service->decide($this->request, $this->owner, 'reject', [], false, '   ');

        $this->assertNull($this->request->fresh()->decision_note);
    }

    public function test_unknown_outcome_throws(): void
    {
        $this->expectException(CertificationDecisionException::class);

        $this->service->decide($this->request, $this->owner, 'maybe', [], false, null);
    }

    public function test_decide_twice_throws_and_changes_nothing(): void
    {
        $this->service->decide($this->request, $this->owner, 'approve', [$this->trackIds[0]], false, null);
        $decidedAt = $this->request->fresh()->decided_at;

        try {
            $this->service->decide($this->request, $this->owner, 'reject', [], false, 'ripensamento');
            $this->fail('La seconda decisione doveva essere rifiutata.');
        } catch (CertificationDecisionException) {
        }

        $fresh = $this->request->fresh();
        $this->assertSame(CertificationRequest::STATUS_APPROVED, $fresh->status);
        $this->assertNull($fresh->decision_note);
        $this->assertTrue($decidedAt->equalTo($fresh->decided_at));
        $this->assertSame(1, ValidatedEcTrack::count());
    }

    public function test_decide_rejects_track_not_owned_by_layer_owner(): void
    {
        $foreign = $this->createTrack(User::factory()->create()->id, 'Tappa estranea', $this->layer);

        $this->expectException(CertificationDecisionException::class);

        try {
            $this->service->decide($this->request, $this->owner, 'approve', [$this->trackIds[0], $foreign->id], false, null);
        } finally {
            $this->assertSame(0, ValidatedEcTrack::count());
            $this->assertTrue($this->request->fresh()->isPending());
        }
    }

    public function test_decide_rejects_track_of_another_layer(): void
    {
        $other = $this->createTrack($this->owner->id, 'Altro cammino', $this->createLayer($this->owner->id));

        $this->expectException(CertificationDecisionException::class);

        $this->service->decide($this->request, $this->owner, 'approve', [$other->id], false, null);
    }

    public function test_decide_already_validated_track_is_readable_error(): void
    {
        ValidatedEcTrack::create([
            'user_id' => $this->walker->id,
            'ec_track_id' => $this->trackIds[0],
            'layer_id' => $this->layer->id,
            'source' => ValidatedEcTrack::SOURCE_MANUAL,
            'validated_at' => now(),
        ]);

        try {
            $this->service->decide($this->request, $this->owner, 'approve', [$this->trackIds[0]], false, null);
            $this->fail('Una tappa già validata non deve essere accettata.');
        } catch (CertificationDecisionException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertTrue($this->request->fresh()->isPending());
    }

    public function test_decision_mail_job_dispatched(): void
    {
        $this->service->decide($this->request, $this->owner, 'reject', [], false, null);

        Queue::assertPushed(
            SendCertificationDecisionMailJob::class,
            fn (SendCertificationDecisionMailJob $job) => $job->request->is($this->request)
        );
    }

    public function test_no_mail_when_decision_fails(): void
    {
        try {
            $this->service->decide($this->request, $this->owner, 'approve', [], false, null);
        } catch (CertificationDecisionException) {
        }

        Queue::assertNotPushed(SendCertificationDecisionMailJob::class);
    }

    public function test_after_decision_user_can_submit_new_request(): void
    {
        $this->service->decide($this->request, $this->owner, 'reject', [], false, null);

        $new = $this->makeRequest($this->layer);

        $this->assertTrue($new->exists);
    }
}

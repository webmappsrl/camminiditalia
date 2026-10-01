<?php

namespace Tests\Feature;

use App\Models\CertificationRequest;
use App\Models\ValidatedEcTrack;
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

class CertificationDecisionControllerTest extends TestCase
{
    use CreatesCertificationTracks, DatabaseTransactions, LayerTestHelpers;

    private const URL = '/nova-vendor/certification-decision/confirm';

    private User $owner;

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

        $this->owner = $this->createUserWithRole('Validator');
        $this->layer = $this->createLayer($this->owner->id);
        $this->trackIds = collect(['Tappa 1', 'Tappa 2'])
            ->map(fn ($name) => $this->createTrack($this->owner->id, $name, $this->layer)->id)
            ->all();
        $this->request = CertificationRequest::create([
            'user_id' => User::factory()->create()->id,
            'layer_id' => $this->layer->id,
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
        ]);
    }

    private function confirm(User $user, array $overrides = [])
    {
        return $this->actingAs($user)->postJson(self::URL, array_merge([
            'certification_request_id' => $this->request->id,
            'outcome' => 'approve',
            'ec_track_ids' => [$this->trackIds[0]],
            'decision_note' => null,
        ], $overrides));
    }

    public function test_owner_validator_confirms_approval(): void
    {
        $this->confirm($this->owner)->assertOk()->assertJsonStructure(['message']);

        $this->assertSame(CertificationRequest::STATUS_APPROVED, $this->request->fresh()->status);
        $this->assertSame([$this->trackIds[0]], ValidatedEcTrack::pluck('ec_track_id')->all());
    }

    public function test_administrator_confirms_rejection_with_note(): void
    {
        $this->confirm($this->createUserWithRole('Administrator'), [
            'outcome' => 'reject',
            'ec_track_ids' => [],
            'decision_note' => 'Foto sfocata',
        ])->assertOk();

        $fresh = $this->request->fresh();
        $this->assertSame(CertificationRequest::STATUS_REJECTED, $fresh->status);
        $this->assertSame('Foto sfocata', $fresh->decision_note);
    }

    public function test_foreign_validator_gets_403(): void
    {
        $this->confirm($this->createUserWithRole('Validator'))->assertForbidden();
        $this->assertTrue($this->request->fresh()->isPending());
    }

    public function test_guest_gets_403(): void
    {
        $this->confirm($this->createUserWithRole('Guest'))->assertForbidden();
    }

    public function test_confirm_on_decided_request_gets_readable_422(): void
    {
        $this->request->forceFill(['status' => CertificationRequest::STATUS_REJECTED])->save();

        $this->confirm($this->owner)
            ->assertStatus(422)
            ->assertJson(['message' => __('This request has already been decided.')]);
        $this->assertSame(0, ValidatedEcTrack::count());
    }

    public function test_confirm_with_track_not_selectable_gets_422(): void
    {
        $foreign = $this->createTrack(User::factory()->create()->id, 'Tappa estranea', $this->layer);

        $this->confirm($this->owner, ['ec_track_ids' => [$foreign->id]])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->assertTrue($this->request->fresh()->isPending());
    }

    public function test_confirm_on_missing_request_gets_404(): void
    {
        $this->confirm($this->owner, ['certification_request_id' => 999999])->assertNotFound();
    }

    public function test_confirm_requires_nova_auth(): void
    {
        $response = $this->postJson(self::URL, [
            'certification_request_id' => $this->request->id,
            'outcome' => 'reject',
        ]);

        $this->assertContains($response->status(), [401, 403, 302]);
        $this->assertTrue($this->request->fresh()->isPending());
    }
}

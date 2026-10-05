<?php

namespace Tests\Feature;

use App\Models\CertificationRequest;
use App\Models\ValidatedEcTrack;
use App\Nova\Actions\DecideCertificationRequest;
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

class CertificationRequestNovaActionTest extends TestCase
{
    use CreatesCertificationTracks, DatabaseTransactions, LayerTestHelpers;

    private const ACTION_URL = '/nova-api/certification-requests/action?action=decide-certification-request';

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
        $this->trackIds = collect(['Tappa 10', 'Tappa 2', 'Tappa 1'])
            ->map(fn ($name) => $this->createTrack($this->owner->id, $name, $this->layer)->id)
            ->all();
        $this->request = $this->makeRequest();
    }

    private function makeRequest(): CertificationRequest
    {
        return CertificationRequest::create([
            'user_id' => User::factory()->create(['name' => 'Mario Rossi'])->id,
            'layer_id' => $this->layer->id,
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
        ]);
    }

    private function runAction(User $user, array $fields, ?string $resources = null)
    {
        return $this->actingAs($user)->postJson(self::ACTION_URL, array_merge([
            'resources' => $resources ?? (string) $this->request->id,
        ], $fields));
    }

    private function approvePayload(array $trackIds): array
    {
        return [
            'outcome' => 'approve',
            'tracks' => json_encode(collect($trackIds)->mapWithKeys(fn ($id) => [$id => true])->all()),
            'select_all' => false,
            'decision_note' => 'ok',
        ];
    }

    private function actionKeysFor(User $user, CertificationRequest $request): array
    {
        return collect($this->actingAs($user)
            ->getJson('/nova-api/certification-requests/actions?resources[]='.$request->id.'&display=detail')
            ->assertOk()
            ->json('actions'))
            ->pluck('uriKey')
            ->all();
    }

    public function test_action_returns_confirm_modal_without_writing(): void
    {
        $response = $this->runAction($this->owner, $this->approvePayload([$this->trackIds[1]]))->assertOk();

        $this->assertSame(DecideCertificationRequest::CONFIRM_MODAL_COMPONENT, $response->json('modal.component'));
        $payload = $response->json('modal.payload');
        $this->assertSame($this->request->id, $payload['certification_request_id']);
        $this->assertSame('approve', $payload['outcome']);
        $this->assertSame([$this->trackIds[1]], $payload['ec_track_ids']);
        $this->assertSame(['Tappa 2'], $payload['track_labels']);
        $this->assertStringContainsString('Mario Rossi', $payload['user']);
        $this->assertSame('ok', $payload['note']);
        $this->assertArrayHasKey('labels', $payload);
        $this->assertSame(0, ValidatedEcTrack::count());
        $this->assertTrue($this->request->fresh()->isPending());
    }

    public function test_action_select_all_preview_lists_tracks_in_natural_order(): void
    {
        $payload = $this->runAction($this->owner, [
            'outcome' => 'approve',
            'tracks' => json_encode([]),
            'select_all' => true,
        ])->assertOk()->json('modal.payload');

        $this->assertSame(['Tappa 1', 'Tappa 2', 'Tappa 10'], $payload['track_labels']);
    }

    public function test_action_preview_errors_are_danger_messages(): void
    {
        $response = $this->runAction($this->owner, $this->approvePayload([]))->assertOk();

        $this->assertNotEmpty($response->json('danger'));
        $this->assertNull($response->json('modal'));
    }

    /**
     * Nova risponde 200 con `danger` quando canRun blocca, 403/404 quando
     * l'azione non è proprio disponibile: in entrambi i casi niente modale e
     * niente scritture.
     */
    private function assertBlocked($response): void
    {
        if ($response->status() < 400) {
            $this->assertNotEmpty($response->json('danger'), (string) $response->getContent());
            $this->assertNull($response->json('modal'));
        }
        $this->assertSame(0, ValidatedEcTrack::count());
        $this->assertTrue($this->request->fresh()->isPending());
    }

    public function test_foreign_validator_cannot_run(): void
    {
        $this->assertBlocked($this->runAction($this->createUserWithRole('Validator'), $this->approvePayload([$this->trackIds[0]])));
    }

    public function test_guest_cannot_run(): void
    {
        $this->assertBlocked($this->runAction($this->createUserWithRole('Guest'), $this->approvePayload([$this->trackIds[0]])));
    }

    public function test_administrator_sees_action_on_pending_request(): void
    {
        $this->assertContains('decide-certification-request', $this->actionKeysFor($this->createUserWithRole('Administrator'), $this->request));
        $this->assertContains('decide-certification-request', $this->actionKeysFor($this->owner, $this->request));
    }

    public function test_action_not_available_on_decided_request(): void
    {
        $this->request->forceFill(['status' => CertificationRequest::STATUS_REJECTED])->save();

        $this->assertNotContains('decide-certification-request', $this->actionKeysFor($this->owner, $this->request));

        $response = $this->runAction($this->owner, ['outcome' => 'reject']);
        $this->assertContains($response->status(), [403, 404]);
    }

    /**
     * Via API (il pannello non lo permette, l'azione è `sole`) Nova passa a
     * handle() una richiesta alla volta: al massimo esce il riepilogo di una
     * sola richiesta, e nulla viene scritto finché la conferma non arriva al
     * controller, che decide solo l'id esplicito.
     */
    public function test_action_on_multiple_requests_writes_nothing(): void
    {
        $second = $this->makeRequest();

        $response = $this->runAction($this->owner, ['outcome' => 'reject'], $this->request->id.','.$second->id);

        $modalId = $response->json('modal.payload.certification_request_id');
        $this->assertTrue($modalId === null || in_array($modalId, [$this->request->id, $second->id], true));
        $this->assertTrue($this->request->fresh()->isPending());
        $this->assertTrue($second->fresh()->isPending());
    }

    public function test_action_fields_list_selectable_tracks_in_natural_order(): void
    {
        $actions = collect($this->actingAs($this->owner)
            ->getJson('/nova-api/certification-requests/actions?resources[]='.$this->request->id.'&display=detail')
            ->json('actions'));

        $fields = collect($actions->firstWhere('uriKey', 'decide-certification-request')['fields']);
        $options = collect($fields->firstWhere('attribute', 'tracks')['options'])->pluck('label')->all();

        $this->assertSame(['Tappa 1', 'Tappa 2', 'Tappa 10'], $options);
    }

    public function test_detail_shows_validated_stages_and_decision(): void
    {
        app(\App\Services\CertificationRequestService::class)
            ->decide($this->request, $this->owner, 'approve', [$this->trackIds[2]], false, 'Timbri ok');

        $response = $this->actingAs($this->owner)
            ->getJson('/nova-api/certification-requests/'.$this->request->id)
            ->assertOk();

        $fields = collect($response->json('resource.fields'));
        $this->assertSame('Timbri ok', $fields->firstWhere('attribute', 'decision_note')['value']);
        $this->assertNotNull($fields->firstWhere('attribute', 'validatedTracks'));

        $this->actingAs($this->owner)
            ->getJson('/nova-api/validated-ec-tracks?viaResource=certification-requests&viaResourceId='.$this->request->id.'&viaRelationship=validatedTracks&relationshipType=hasMany')
            ->assertOk()
            ->assertJsonCount(1, 'resources');
    }

    public function test_validated_ec_track_resource_is_read_only(): void
    {
        app(\App\Services\CertificationRequestService::class)
            ->decide($this->request, $this->owner, 'approve', [$this->trackIds[2]], false, null);
        $validated = ValidatedEcTrack::first();
        $admin = $this->createUserWithRole('Administrator');

        $this->actingAs($admin)
            ->deleteJson('/nova-api/validated-ec-tracks?resources[]='.$validated->id);
        $this->assertNotNull(ValidatedEcTrack::find($validated->id));

        $this->actingAs($admin)->getJson('/nova-api/validated-ec-tracks/creation-fields')->assertForbidden();
    }

    public function test_foreign_validator_does_not_see_validated_tracks(): void
    {
        app(\App\Services\CertificationRequestService::class)
            ->decide($this->request, $this->owner, 'approve', [$this->trackIds[2]], false, null);

        $this->actingAs($this->createUserWithRole('Validator'))
            ->getJson('/nova-api/validated-ec-tracks')
            ->assertOk()
            ->assertJsonCount(0, 'resources');
    }

    public function test_note_over_5000_chars_is_rejected_in_first_step(): void
    {
        $response = $this->runAction($this->owner, [
            'outcome' => 'reject',
            'decision_note' => str_repeat('a', 5001),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('decision_note');
        $this->assertNull($response->json('modal'));
    }

    public function test_index_does_not_offer_decision_on_rows(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson('/nova-api/certification-requests')
            ->assertOk();

        $row = collect($response->json('resources'))->firstWhere('id.value', $this->request->id);
        $this->assertNotNull($row);
        $this->assertNotContains('decide-certification-request', collect($row['actions'] ?? [])->pluck('uriKey')->all());
    }

    public function test_validated_track_source_labels(): void
    {
        app(\App\Services\CertificationRequestService::class)
            ->decide($this->request, $this->owner, 'approve', [$this->trackIds[2]], false, null);
        $validated = ValidatedEcTrack::first();
        $validated->forceFill(['source' => 'other'])->save();

        $fields = collect($this->actingAs($this->owner)
            ->getJson('/nova-api/validated-ec-tracks/'.$validated->id)
            ->assertOk()
            ->json('resource.fields'));

        $this->assertSame('other', $fields->firstWhere('name', __('Source'))['value']);
    }
}

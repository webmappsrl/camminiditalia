<?php

namespace Tests\Feature;

use App\Models\CertificationRequest;
use App\Models\PassportStage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\CreatesCertificationTracks;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class PassportStageModelTest extends TestCase
{
    use CreatesCertificationTracks, DatabaseTransactions, LayerTestHelpers;

    private User $walker;

    private Layer $layer;

    private WmEcTrack $track;

    private CertificationRequest $certificationRequest;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $owner = $this->createUserWithRole('Validator');
        $this->layer = $this->createLayer($owner->id);
        $this->walker = User::factory()->create();
        $this->track = $this->createTrack($owner->id, 'Tappa 1', $this->layer);

        $this->certificationRequest = CertificationRequest::factory()->create([
            'user_id' => $this->walker->id,
            'layer_id' => $this->layer->id,
            'status' => CertificationRequest::STATUS_APPROVED,
        ]);
    }

    private function makeValidation(array $overrides = []): PassportStage
    {
        return PassportStage::create(array_merge([
            'user_id' => $this->walker->id,
            'ec_track_id' => $this->track->id,
            'layer_id' => $this->layer->id,
            'certification_request_id' => $this->certificationRequest->id,
            'source' => PassportStage::SOURCE_MANUAL,
            'validated_at' => now(),
        ], $overrides));
    }

    public function test_validated_ec_track_relations_resolve(): void
    {
        $validation = $this->makeValidation()->fresh();

        $this->assertSame($this->walker->id, $validation->user->id);
        $this->assertSame($this->track->id, $validation->ecTrack?->getKey());
        $this->assertSame($this->layer->id, $validation->layer->id);
        $this->assertSame($this->certificationRequest->id, $validation->certificationRequest->id);
        $this->assertSame([$validation->id], $this->certificationRequest->validatedTracks()->pluck('id')->all());
    }

    public function test_unique_user_ec_track_is_enforced(): void
    {
        $this->makeValidation();

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeValidation(['certification_request_id' => null]);
    }

    public function test_deleting_certification_request_nulls_validated_track_fk(): void
    {
        $validation = $this->makeValidation();

        $this->certificationRequest->delete();

        $this->assertNull($validation->fresh()->certification_request_id);
    }

    public function test_deleting_user_cascades_validated_tracks(): void
    {
        $validation = $this->makeValidation();

        $this->walker->delete();

        $this->assertNull(PassportStage::find($validation->id));
    }

    public function test_validated_scope_filters_on_validated_at_and_keeps_existing_rows(): void
    {
        $validation = $this->makeValidation();

        $this->assertStringContainsString(
            '"passport_stages"."validated_at" is not null',
            PassportStage::validated()->toSql(),
        );
        $this->assertSame('v.validated_at IS NOT NULL', PassportStage::validatedSql('v'));
        $this->assertSame([$validation->id], PassportStage::validated()->pluck('id')->all());
        $this->assertSame([$validation->id], \App\Models\User::findOrFail($this->walker->id)->passportStages()->pluck('id')->all());
        $this->assertStringContainsString('is not null', $this->certificationRequest->validatedTracks()->toSql());
    }
}

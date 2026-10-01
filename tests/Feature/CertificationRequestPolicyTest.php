<?php

namespace Tests\Feature;

use App\Models\CertificationRequest;
use App\Models\User;
use App\Policies\CertificationRequestPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class CertificationRequestPolicyTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    private User $owner;

    private CertificationRequest $certificationRequest;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($this->owner->id);
        $walker = User::factory()->create();

        $this->certificationRequest = CertificationRequest::create([
            'user_id' => $walker->id,
            'layer_id' => $layer->id,
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
        ]);
    }

    public static function abilityProvider(): array
    {
        $abilities = ['viewAny', 'view', 'create', 'update', 'delete', 'replicate', 'restore', 'forceDelete'];

        $expected = [
            'administrator' => ['viewAny' => true, 'view' => true, 'delete' => true],
            'owner_validator' => ['viewAny' => true, 'view' => true],
            'foreign_validator' => ['viewAny' => true],
            'guest' => [],
            'no_role' => [],
        ];

        $cases = [];
        foreach ($expected as $actor => $allowed) {
            foreach ($abilities as $ability) {
                $cases["{$actor} {$ability}"] = [$actor, $ability, $allowed[$ability] ?? false];
            }
        }

        return $cases;
    }

    #[DataProvider('abilityProvider')]
    public function test_policy_matrix(string $actor, string $ability, bool $expected): void
    {
        $user = match ($actor) {
            'administrator' => $this->createUserWithRole('Administrator'),
            'owner_validator' => $this->owner,
            'foreign_validator' => $this->createUserWithRole('Validator'),
            'guest' => $this->createUserWithRole('Guest'),
            'no_role' => $this->createUserWithoutRole(),
            default => throw new \LogicException("Unhandled actor [{$actor}] in test_policy_matrix."),
        };

        $argument = in_array($ability, ['viewAny', 'create'], true)
            ? CertificationRequest::class
            : $this->certificationRequest;

        $this->assertSame($expected, Gate::forUser($user)->allows($ability, $argument));
    }

    public function test_policy_is_registered(): void
    {
        $this->assertInstanceOf(CertificationRequestPolicy::class, Gate::getPolicyFor(CertificationRequest::class));
    }

    public static function decidedStatusProvider(): array
    {
        return [
            'approved' => [CertificationRequest::STATUS_APPROVED],
            'rejected' => [CertificationRequest::STATUS_REJECTED],
        ];
    }

    #[DataProvider('decidedStatusProvider')]
    public function test_administrator_cannot_delete_decided_request(string $status): void
    {
        $this->certificationRequest->forceFill(['status' => $status])->save();

        $this->assertFalse(Gate::forUser($this->createUserWithRole('Administrator'))->allows('delete', $this->certificationRequest));
        $this->assertFalse(Gate::forUser($this->owner)->allows('delete', $this->certificationRequest));
    }
}

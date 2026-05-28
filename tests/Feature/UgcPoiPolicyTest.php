<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class UgcPoiPolicyTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
        if (WmApp::count() === 0) {
            WmApp::factory()->create();
        }
    }

    public function test_administrator_can_view_any_ugc_poi(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', UgcPoi::class));
    }

    public function test_administrator_can_view_ugc_poi(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $poi = UgcPoi::factory()->create();
        $this->assertTrue(Gate::forUser($admin)->allows('view', $poi));
    }

    public function test_validator_can_view_any_ugc_poi(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $this->assertTrue(Gate::forUser($validator)->allows('viewAny', UgcPoi::class));
    }

    public function test_validator_can_view_ugc_poi(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $poi = UgcPoi::factory()->create();
        $this->assertTrue(Gate::forUser($validator)->allows('view', $poi));
    }

    public function test_guest_cannot_view_any_ugc_poi(): void
    {
        $guest = $this->createUserWithRole('Guest');
        $this->assertFalse(Gate::forUser($guest)->allows('viewAny', UgcPoi::class));
    }

    public function test_guest_cannot_view_ugc_poi(): void
    {
        $guest = $this->createUserWithRole('Guest');
        $poi = UgcPoi::factory()->create();
        $this->assertFalse(Gate::forUser($guest)->allows('view', $poi));
    }

    public function test_user_without_role_cannot_view_any_ugc_poi(): void
    {
        $user = $this->createUserWithoutRole();
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', UgcPoi::class));
    }
}

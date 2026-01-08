<?php

namespace Tests\Feature;

use App\Models\User;
use App\Nova\Layer as NovaLayer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Nova\Actions\ExecuteEcTrackDataChainAction;
use Wm\WmPackage\Nova\Actions\RegenerateLayerPbfAction;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerActionTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles and permissions
        RolesAndPermissionsService::seedDatabase();

        // Create an App for layers (required by LayerFactory)
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_administrator_can_see_regenerate_layer_pbf_action(): void
    {
        $administrator = $this->createUserWithRole('Administrator');
        $layer = $this->createLayer($administrator->id);

        Auth::login($administrator);

        $novaRequest = NovaRequest::create('/nova-api/layers/actions');
        $novaRequest->setUserResolver(fn () => $administrator);

        $novaLayer = new NovaLayer($layer);
        $actions = $novaLayer->actions($novaRequest);

        $regenerateAction = collect($actions)->first(function ($action) {
            return $action instanceof RegenerateLayerPbfAction;
        });

        $this->assertNotNull($regenerateAction, 'RegenerateLayerPbfAction should be present for administrator');

        // Test that the action can be seen by administrator
        $this->assertTrue(
            $this->canActionBeSeen($regenerateAction, $novaRequest),
            'Administrator should be able to see RegenerateLayerPbfAction'
        );
    }

    public function test_administrator_can_see_execute_ec_track_data_chain_action(): void
    {
        $administrator = $this->createUserWithRole('Administrator');
        $layer = $this->createLayer($administrator->id);

        Auth::login($administrator);

        $novaRequest = NovaRequest::create('/nova-api/layers/actions');
        $novaRequest->setUserResolver(fn () => $administrator);

        $novaLayer = new NovaLayer($layer);
        $actions = $novaLayer->actions($novaRequest);

        $executeAction = collect($actions)->first(function ($action) {
            return $action instanceof ExecuteEcTrackDataChainAction;
        });

        $this->assertNotNull($executeAction, 'ExecuteEcTrackDataChainAction should be present for administrator');

        // Test that the action can be seen by administrator
        $this->assertTrue(
            $this->canActionBeSeen($executeAction, $novaRequest),
            'Administrator should be able to see ExecuteEcTrackDataChainAction'
        );
    }

    // =========================================================================
    // Validator Tests - Actions Visibility
    // =========================================================================

    public function test_validator_cannot_see_regenerate_layer_pbf_action(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($validator->id);

        Auth::login($validator);

        $novaRequest = NovaRequest::create('/nova-api/layers/actions');
        $novaRequest->setUserResolver(fn () => $validator);

        $novaLayer = new NovaLayer($layer);
        $actions = $novaLayer->actions($novaRequest);

        $regenerateAction = collect($actions)->first(function ($action) {
            return $action instanceof RegenerateLayerPbfAction;
        });

        if ($regenerateAction) {
            $this->assertFalse(
                $this->canActionBeSeen($regenerateAction, $novaRequest),
                'Validator should not be able to see RegenerateLayerPbfAction'
            );
        } else {
            // Action might be filtered out completely, which is also acceptable
            $this->assertNull($regenerateAction, 'RegenerateLayerPbfAction should not be visible to validator');
        }
    }

    public function test_validator_cannot_see_execute_ec_track_data_chain_action(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($validator->id);

        Auth::login($validator);

        $novaRequest = NovaRequest::create('/nova-api/layers/actions');
        $novaRequest->setUserResolver(fn () => $validator);

        $novaLayer = new NovaLayer($layer);
        $actions = $novaLayer->actions($novaRequest);

        $executeAction = collect($actions)->first(function ($action) {
            return $action instanceof ExecuteEcTrackDataChainAction;
        });

        if ($executeAction) {
            $this->assertFalse(
                $this->canActionBeSeen($executeAction, $novaRequest),
                'Validator should not be able to see ExecuteEcTrackDataChainAction'
            );
        } else {
            // Action might be filtered out completely, which is also acceptable
            $this->assertNull($executeAction, 'ExecuteEcTrackDataChainAction should not be visible to validator');
        }
    }

    public function test_user_without_role_cannot_see_regenerate_layer_pbf_action(): void
    {
        $user = $this->createUserWithoutRole();
        $layer = $this->createLayer($user->id);

        Auth::login($user);

        $novaRequest = NovaRequest::create('/nova-api/layers/actions');
        $novaRequest->setUserResolver(fn () => $user);

        $novaLayer = new NovaLayer($layer);
        $actions = $novaLayer->actions($novaRequest);

        $regenerateAction = collect($actions)->first(function ($action) {
            return $action instanceof RegenerateLayerPbfAction;
        });

        if ($regenerateAction) {
            $this->assertFalse(
                $this->canActionBeSeen($regenerateAction, $novaRequest),
                'User senza ruoli should not be able to see RegenerateLayerPbfAction'
            );
        } else {
            // Action might be filtered out completely, which is also acceptable
            $this->assertNull($regenerateAction, 'RegenerateLayerPbfAction should not be visible to user senza ruoli');
        }
    }

    public function test_user_without_role_cannot_see_execute_ec_track_data_chain_action(): void
    {
        $user = $this->createUserWithoutRole();
        $layer = $this->createLayer($user->id);

        Auth::login($user);

        $novaRequest = NovaRequest::create('/nova-api/layers/actions');
        $novaRequest->setUserResolver(fn () => $user);

        $novaLayer = new NovaLayer($layer);
        $actions = $novaLayer->actions($novaRequest);

        $executeAction = collect($actions)->first(function ($action) {
            return $action instanceof ExecuteEcTrackDataChainAction;
        });

        if ($executeAction) {
            $this->assertFalse(
                $this->canActionBeSeen($executeAction, $novaRequest),
                'User senza ruoli should not be able to see ExecuteEcTrackDataChainAction'
            );
        } else {
            // Action might be filtered out completely, which is also acceptable
            $this->assertNull($executeAction, 'ExecuteEcTrackDataChainAction should not be visible to user senza ruoli');
        }
    }

    public function test_actions_are_filtered_by_role(): void
    {
        $administrator = $this->createUserWithRole('Administrator');
        $validator = $this->createUserWithRole('Validator');
        $userWithoutRole = $this->createUserWithoutRole();

        $layer = $this->createLayer();

        // Test administrator sees both actions
        Auth::login($administrator);
        $adminRequest = NovaRequest::create('/nova-api/layers/actions');
        $adminRequest->setUserResolver(fn () => $administrator);
        $adminNovaLayer = new NovaLayer($layer);
        $adminActions = $adminNovaLayer->actions($adminRequest);

        $adminRegenerateAction = collect($adminActions)->first(fn ($action) => $action instanceof RegenerateLayerPbfAction);
        $adminExecuteAction = collect($adminActions)->first(fn ($action) => $action instanceof ExecuteEcTrackDataChainAction);

        $this->assertNotNull($adminRegenerateAction, 'Administrator should see RegenerateLayerPbfAction');
        $this->assertNotNull($adminExecuteAction, 'Administrator should see ExecuteEcTrackDataChainAction');

        // Test validator doesn't see restricted actions
        Auth::login($validator);
        $validatorRequest = NovaRequest::create('/nova-api/layers/actions');
        $validatorRequest->setUserResolver(fn () => $validator);
        $validatorNovaLayer = new NovaLayer($layer);
        $validatorActions = $validatorNovaLayer->actions($validatorRequest);

        $validatorRegenerateAction = collect($validatorActions)->first(fn ($action) => $action instanceof RegenerateLayerPbfAction);
        $validatorExecuteAction = collect($validatorActions)->first(fn ($action) => $action instanceof ExecuteEcTrackDataChainAction);

        if ($validatorRegenerateAction) {
            $this->assertFalse(
                $this->canActionBeSeen($validatorRegenerateAction, $validatorRequest),
                'Validator should not see RegenerateLayerPbfAction'
            );
        }
        if ($validatorExecuteAction) {
            $this->assertFalse(
                $this->canActionBeSeen($validatorExecuteAction, $validatorRequest),
                'Validator should not see ExecuteEcTrackDataChainAction'
            );
        }

        // Test user senza ruoli doesn't see restricted actions
        Auth::login($userWithoutRole);
        $userRequest = NovaRequest::create('/nova-api/layers/actions');
        $userRequest->setUserResolver(fn () => $userWithoutRole);
        $userNovaLayer = new NovaLayer($layer);
        $userActions = $userNovaLayer->actions($userRequest);

        $userRegenerateAction = collect($userActions)->first(fn ($action) => $action instanceof RegenerateLayerPbfAction);
        $userExecuteAction = collect($userActions)->first(fn ($action) => $action instanceof ExecuteEcTrackDataChainAction);

        if ($userRegenerateAction) {
            $this->assertFalse(
                $this->canActionBeSeen($userRegenerateAction, $userRequest),
                'User senza ruoli should not see RegenerateLayerPbfAction'
            );
        }
        if ($userExecuteAction) {
            $this->assertFalse(
                $this->canActionBeSeen($userExecuteAction, $userRequest),
                'User senza ruoli should not see ExecuteEcTrackDataChainAction'
            );
        }
    }

    /**
     * Helper method to check if an action can be seen for a given request
     */
    protected function canActionBeSeen($action, NovaRequest $request): bool
    {
        // Use the public authorizedToSee method from Laravel Nova's AuthorizedToSee trait
        return $action->authorizedToSee($request);
    }
}

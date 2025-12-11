<?php

use App\Models\User;
use App\Nova\Layer as NovaLayer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Nova\Actions\ExecuteEcTrackDataChainAction;
use Wm\WmPackage\Nova\Actions\RegenerateLayerPbfAction;
use Wm\WmPackage\Services\RolesAndPermissionsService;

require_once __DIR__.'/Helpers/LayerTestHelpers.php';

uses(DatabaseTransactions::class);

beforeEach(function () {
    // Seed roles and permissions
    RolesAndPermissionsService::seedDatabase();

    // Create an App for layers (required by LayerFactory)
    if (App::count() === 0) {
        App::factory()->create();
    }
});

function createNovaRequest(User $user): NovaRequest
{
    Auth::login($user);
    $request = NovaRequest::create('/nova-api/layers');
    $request->setUserResolver(fn () => $user);

    return $request;
}

function isActionVisibleInNovaResource(NovaRequest $request, string $actionClass): bool
{
    $novaLayer = new NovaLayer;
    $actions = $novaLayer->actions($request);

    $action = collect($actions)->first(fn ($action) => $action instanceof $actionClass);

    if ($action === null) {
        return false;
    }

    // Verifica se l'azione è visibile usando il metodo pubblico authorizedToSee
    // Le azioni Nova implementano il trait AuthorizedToSee che fornisce questo metodo
    return $action->authorizedToSee($request);
}

function assertActionVisibility(User $user, string $actionClass, bool $expected): void
{
    $request = createNovaRequest($user);
    $isVisible = isActionVisibleInNovaResource($request, $actionClass);

    expect($isVisible)->toBe($expected);
}

// Nova Actions tests
test('administrators can see RegenerateLayerPbfAction', function () {
    $administrator = createUserWithRole('Administrator');

    assertActionVisibility($administrator, RegenerateLayerPbfAction::class, true);
});

test('administrators can see ExecuteEcTrackDataChainAction', function () {
    $administrator = createUserWithRole('Administrator');

    assertActionVisibility($administrator, ExecuteEcTrackDataChainAction::class, true);
});

test('validators cannot see RegenerateLayerPbfAction', function () {
    $validator = createUserWithRole('Validator');

    assertActionVisibility($validator, RegenerateLayerPbfAction::class, false);
});

test('validators cannot see ExecuteEcTrackDataChainAction', function () {
    $validator = createUserWithRole('Validator');

    assertActionVisibility($validator, ExecuteEcTrackDataChainAction::class, false);
});

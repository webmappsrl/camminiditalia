<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
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

function assertGateAllows(User $user, string $ability, Layer|string $layer, bool $expected): void
{
    expect(Gate::forUser($user)->allows($ability, $layer))->toBe($expected);
}

// Policy tests
test('administrators can update any layer', function () {
    $administrator = createUserWithRole('Administrator');
    $layer = createLayer(User::factory()->create()->id);

    assertGateAllows($administrator, 'update', $layer, true);
});

test('administrators can delete any layer', function () {
    $administrator = createUserWithRole('Administrator');
    $layer = createLayer(User::factory()->create()->id);

    assertGateAllows($administrator, 'delete', $layer, true);
});

test('administrators can create layers', function () {
    $administrator = createUserWithRole('Administrator');

    assertGateAllows($administrator, 'create', Layer::class, true);
});

test('validators cannot update their own layers', function () {
    $validator = createUserWithRole('Validator');
    $layer = createLayer($validator->id);

    assertGateAllows($validator, 'update', $layer, false);
});

test('validators cannot update other users layers', function () {
    $validator = createUserWithRole('Validator');
    $layer = createLayer(User::factory()->create()->id);

    assertGateAllows($validator, 'update', $layer, false);
});

test('validators cannot delete their own layers', function () {
    $validator = createUserWithRole('Validator');
    $layer = createLayer($validator->id);

    assertGateAllows($validator, 'delete', $layer, false);
});

test('validators cannot delete other users layers', function () {
    $validator = createUserWithRole('Validator');
    $layer = createLayer(User::factory()->create()->id);

    assertGateAllows($validator, 'delete', $layer, false);
});

test('validators cannot create layers', function () {
    $validator = createUserWithRole('Validator');

    assertGateAllows($validator, 'create', Layer::class, false);
});

test('validators can view any layer', function () {
    $validator = createUserWithRole('Validator');
    $layer = createLayer();

    assertGateAllows($validator, 'view', $layer, true);
});

test('validators can view any layers list', function () {
    $validator = createUserWithRole('Validator');

    assertGateAllows($validator, 'viewAny', Layer::class, true);
});

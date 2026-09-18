<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class UgcTrackPolicyTest extends TestCase
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

    private function makeUgcTrack(?int $appId = null): UgcTrack
    {
        return UgcTrack::factory()->create($appId ? ['app_id' => $appId] : []);
    }

    private function makeEditorWithUgcEnabled(bool $ownsApp = true): array
    {
        $editor = $this->createUserWithRole('Editor');
        $app = WmApp::factory()->create([
            'user_id' => $ownsApp ? $editor->id : null,
            'auth_show_at_startup' => true,
            'geolocation_record_enable' => true,
        ]);

        return [$editor, $app];
    }

    // --- Administrator: bypassa tutto (prima e dopo questo ticket) ---

    public function test_administrator_can_do_everything_on_ugc_track(): void
    {
        $admin = $this->createUserWithRole('Administrator');
        $track = $this->makeUgcTrack();

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', UgcTrack::class));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $track));
        $this->assertTrue(Gate::forUser($admin)->allows('create', UgcTrack::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $track));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $track));
    }

    // --- Validator: bypassa tutto TRANNE update — è la vera restrizione di
    // questo ticket (oc:8575). Prima del ticket, Wm\WmPackage\Policies\UgcTrackPolicy
    // (auto-risolta da Laravel via Gate::guessPolicyName, verificato leggendo
    // vendor/laravel/framework/.../Gate.php) bypassava anche update per il
    // Validator: qui lo togliamo deliberatamente, lasciando intatto il resto. ---

    public function test_validator_can_do_everything_except_update_on_ugc_track(): void
    {
        $validator = $this->createUserWithRole('Validator');
        $track = $this->makeUgcTrack();

        $this->assertTrue(Gate::forUser($validator)->allows('viewAny', UgcTrack::class));
        $this->assertTrue(Gate::forUser($validator)->allows('view', $track));
        $this->assertTrue(Gate::forUser($validator)->allows('create', UgcTrack::class));
        $this->assertTrue(Gate::forUser($validator)->allows('delete', $track));
        $this->assertFalse(Gate::forUser($validator)->allows('update', $track));
    }

    // --- Editor: stessa logica di Wm\WmPackage\Policies\UgcTrackPolicy
    // (hasUgcEnabled + ownsApp), non toccata da questo ticket ---

    public function test_editor_with_ugc_enabled_can_view_own_app_ugc_track_but_not_manage_it(): void
    {
        [$editor, $app] = $this->makeEditorWithUgcEnabled(ownsApp: true);
        $track = $this->makeUgcTrack($app->id);

        $this->assertTrue(Gate::forUser($editor)->allows('viewAny', UgcTrack::class));
        $this->assertTrue(Gate::forUser($editor)->allows('view', $track));
        $this->assertFalse(Gate::forUser($editor)->allows('create', UgcTrack::class));
        $this->assertFalse(Gate::forUser($editor)->allows('update', $track));
        $this->assertFalse(Gate::forUser($editor)->allows('delete', $track));
    }

    public function test_editor_with_ugc_enabled_cannot_view_ugc_track_of_another_app(): void
    {
        [$editor] = $this->makeEditorWithUgcEnabled(ownsApp: true);
        $otherApp = WmApp::factory()->create();
        $track = $this->makeUgcTrack($otherApp->id);

        $this->assertFalse(Gate::forUser($editor)->allows('view', $track));
    }

    public function test_editor_without_ugc_enabled_cannot_view_any_ugc_track(): void
    {
        $editor = $this->createUserWithRole('Editor');
        $app = WmApp::factory()->create([
            'user_id' => $editor->id,
            'auth_show_at_startup' => false,
            'geolocation_record_enable' => false,
        ]);
        $track = $this->makeUgcTrack($app->id);

        $this->assertFalse(Gate::forUser($editor)->allows('viewAny', UgcTrack::class));
        $this->assertFalse(Gate::forUser($editor)->allows('view', $track));
    }

    // --- Guest / nessun ruolo: nessun accesso (comportamento della policy
    // reale del package, mai stato "permesso a tutti" nonostante nessuna
    // Gate::policy() esplicita fosse registrata prima di questo ticket) ---

    public function test_guest_cannot_access_ugc_track(): void
    {
        $guest = $this->createUserWithRole('Guest');
        $track = $this->makeUgcTrack();

        $this->assertFalse(Gate::forUser($guest)->allows('viewAny', UgcTrack::class));
        $this->assertFalse(Gate::forUser($guest)->allows('view', $track));
        $this->assertFalse(Gate::forUser($guest)->allows('create', UgcTrack::class));
        $this->assertFalse(Gate::forUser($guest)->allows('update', $track));
        $this->assertFalse(Gate::forUser($guest)->allows('delete', $track));
    }

    public function test_user_without_role_cannot_access_ugc_track(): void
    {
        $user = $this->createUserWithoutRole();
        $track = $this->makeUgcTrack();

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', UgcTrack::class));
        $this->assertFalse(Gate::forUser($user)->allows('view', $track));
        $this->assertFalse(Gate::forUser($user)->allows('create', UgcTrack::class));
        $this->assertFalse(Gate::forUser($user)->allows('update', $track));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $track));
    }
}

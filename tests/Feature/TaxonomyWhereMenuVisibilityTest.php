<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Laravel\Nova\Nova;
use Tests\TestCase;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * oc:8588 (correzione post-test manuale): la voce di menu "Taxonomy Where" nella sezione
 * Taxonomies è visibile solo all'Administrator — vedi NovaServiceProvider::boot().
 *
 * Nova::resolveMainMenu() esegue davvero il callback registrato con Nova::mainMenu() (booted
 * dal NovaServiceProvider dell'applicazione durante il bootstrap dei test), quindi verifica la
 * voce di menu nel suo complesso, non solo la closure canSee isolata.
 */
class TaxonomyWhereMenuVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * Cerca la voce di menu "Taxonomy Where" dentro la sezione "Taxonomies" del menu Nova
     * risolto per l'utente autenticato sulla request data.
     */
    private function taxonomyWhereMenuItemVisibleFor(User $user): bool
    {
        $request = Request::create('/nova');
        $request->setUserResolver(fn () => $user);

        $taxonomiesSection = collect(Nova::resolveMainMenu($request))
            ->first(fn ($section) => (string) $section->name === 'Taxonomies');

        $this->assertNotNull($taxonomiesSection, 'Sezione "Taxonomies" non trovata nel menu Nova.');

        $menuItem = collect($taxonomiesSection->items)
            ->first(fn ($item) => str_contains((string) $item->path, 'taxonomy-wheres'));

        $this->assertNotNull($menuItem, 'Voce di menu per la risorsa taxonomy-wheres non trovata.');

        return $menuItem->authorizedToSee($request);
    }

    public function test_administrator_sees_taxonomy_where_menu_item(): void
    {
        $admin = $this->makeUser('Administrator');

        $this->assertTrue($this->taxonomyWhereMenuItemVisibleFor($admin));
    }

    public function test_validator_does_not_see_taxonomy_where_menu_item(): void
    {
        $validator = $this->makeUser('Validator');

        $this->assertFalse($this->taxonomyWhereMenuItemVisibleFor($validator));
    }

    public function test_guest_does_not_see_taxonomy_where_menu_item(): void
    {
        $guest = $this->makeUser('Guest');

        $this->assertFalse($this->taxonomyWhereMenuItemVisibleFor($guest));
    }
}

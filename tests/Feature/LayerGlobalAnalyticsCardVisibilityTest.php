<?php

namespace Tests\Feature;

use App\Models\User;
use App\Nova\Dashboards\Main;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Feature\Concerns\CreatesUsersWithRole;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Dove si vedono le statistiche dei cammini (oc:9040).
 *
 * Le statistiche stanno solo nella dashboard «Statistiche» (`App\Nova\Dashboards\Main`):
 * l'Administrator vede l'aggregato di tutti i cammini, il Validator le statistiche e la mappa
 * del suo primo layer (id più basso). Dalle pagine dei Layer, index e detail view, la card delle
 * statistiche non c'è più.
 *
 * Sempre via HTTP autenticato: `Dashboard::cards()` non riceve la request e legge l'utente da
 * `request()->user()`.
 */
class LayerGlobalAnalyticsCardVisibilityTest extends TestCase
{
    use CreatesUsersWithRole;
    use RefreshDatabase;

    private const ANALYTICS_CARD = 'layer-analytics-card';

    private const MAP_CARD = 'layer-map-card';

    private const GLOBAL_ROUTES_URL = '/nova-vendor/layer-analytics/all-routes';

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
    }

    public function test_administrator_sees_the_global_card_on_the_dashboard(): void
    {
        $admin = $this->userWithRole('Administrator');
        Layer::factory()->create(['user_id' => $admin->id, 'app_id' => $this->appWithAnalytics(true)->id]);

        $cards = $this->dashboardCards($admin);

        $analytics = $cards->where('component', self::ANALYTICS_CARD);
        $this->assertCount(1, $analytics);
        $this->assertSame('global', $analytics->first()['mode']);
        $this->assertFalse($analytics->first()['onlyOnDetail']);
    }

    public function test_administrator_sees_the_global_map_after_the_statistics(): void
    {
        $admin = $this->userWithRole('Administrator');
        Layer::factory()->create(['user_id' => $admin->id, 'app_id' => $this->appWithAnalytics(true)->id]);

        $cards = $this->dashboardCards($admin)->values();

        $this->assertSame([self::ANALYTICS_CARD, self::MAP_CARD], $cards->pluck('component')->all());
        $this->assertSame(self::GLOBAL_ROUTES_URL, $cards[1]['geojson_url']);
        $this->assertSame([false], $cards->pluck('onlyOnDetail')->unique()->values()->all());
    }

    public function test_administrator_sees_the_global_map_with_analytics_disabled(): void
    {
        $admin = $this->userWithRole('Administrator');
        Layer::factory()->create(['user_id' => $admin->id, 'app_id' => $this->appWithAnalytics(false)->id]);

        $cards = $this->dashboardCards($admin);

        $this->assertNotContains(self::ANALYTICS_CARD, $cards->pluck('component'));
        $this->assertSame([self::GLOBAL_ROUTES_URL], $cards->where('component', self::MAP_CARD)->pluck('geojson_url')->values()->all());
    }

    public function test_administrator_without_layers_sees_no_map(): void
    {
        $admin = $this->userWithRole('Administrator');
        $this->appWithAnalytics(true);

        $this->assertNotContains(self::MAP_CARD, $this->dashboardCards($admin)->pluck('component'));
    }

    public function test_administrator_sees_no_card_when_analytics_is_disabled(): void
    {
        $admin = $this->userWithRole('Administrator');
        Layer::factory()->create(['user_id' => $admin->id, 'app_id' => $this->appWithAnalytics(false)->id]);

        $this->assertNotContains(self::ANALYTICS_CARD, $this->dashboardCards($admin)->pluck('component'));
    }

    public function test_administrator_sees_no_card_without_layers(): void
    {
        $admin = $this->userWithRole('Administrator');
        $this->appWithAnalytics(true);

        $this->assertNotContains(self::ANALYTICS_CARD, $this->dashboardCards($admin)->pluck('component'));
    }

    public function test_validator_sees_statistics_and_map_of_the_layer_with_the_lowest_id_only(): void
    {
        $validator = $this->userWithRole('Validator');
        $app = $this->appWithAnalytics(true);
        $first = Layer::factory()->create(['user_id' => $validator->id, 'app_id' => $app->id]);
        Layer::factory()->create(['user_id' => $validator->id, 'app_id' => $app->id]);
        Layer::factory()->create(['user_id' => $validator->id, 'app_id' => $app->id]);

        $cards = $this->dashboardCards($validator);

        $analytics = $cards->where('component', self::ANALYTICS_CARD);
        $this->assertCount(1, $analytics);
        $this->assertSame('layer', $analytics->first()['mode']);
        $this->assertSame($first->id, $analytics->first()['layer_id']);

        $maps = $cards->where('component', self::MAP_CARD);
        $this->assertCount(1, $maps);
        $this->assertSame($first->id, $maps->first()['resource_id']);
        $this->assertNotSame(self::GLOBAL_ROUTES_URL, $maps->first()['geojson_url']);

        // Il frontend di Nova, nelle dashboard, scarta le card con onlyOnDetail true
        // (Cards.vue): l'API le restituisce comunque, quindi va controllato qui.
        $this->assertSame([false], $cards->pluck('onlyOnDetail')->unique()->values()->all());
    }

    public function test_validator_sees_only_the_map_when_analytics_is_disabled(): void
    {
        $validator = $this->userWithRole('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id, 'app_id' => $this->appWithAnalytics(false)->id]);

        $cards = $this->dashboardCards($validator);

        $this->assertNotContains(self::ANALYTICS_CARD, $cards->pluck('component'));
        $this->assertSame([$layer->id], $cards->where('component', self::MAP_CARD)->pluck('resource_id')->values()->all());
    }

    public function test_validator_without_layers_sees_an_empty_dashboard(): void
    {
        $this->appWithAnalytics(true);

        $this->assertCount(0, $this->dashboardCards($this->userWithRole('Validator')));
    }

    public function test_guest_cannot_open_the_dashboard(): void
    {
        $this->appWithAnalytics(true);

        // Il Guest è già bloccato dal gate di Nova: la dashboard non cambia questo.
        $this->actingAs($this->userWithRole('Guest'))
            ->getJson('/nova-api/dashboards/cards/main')
            ->assertForbidden();
    }

    public function test_nobody_sees_the_nova_help_card(): void
    {
        $admin = $this->userWithRole('Administrator');
        Layer::factory()->create(['user_id' => $admin->id, 'app_id' => $this->appWithAnalytics(true)->id]);

        $this->assertNotContains('help-card', $this->dashboardCards($admin)->pluck('component'));
    }

    public function test_layer_index_has_no_statistics_card(): void
    {
        $admin = $this->userWithRole('Administrator');
        Layer::factory()->create(['user_id' => $admin->id, 'app_id' => $this->appWithAnalytics(true)->id]);

        $response = $this->actingAs($admin)->getJson('/nova-api/layers/cards');

        $response->assertOk();
        $this->assertNotContains(self::ANALYTICS_CARD, collect($response->json())->pluck('component'));
    }

    public function test_layer_detail_keeps_the_package_cards_but_not_the_statistics(): void
    {
        $admin = $this->userWithRole('Administrator');
        $layer = Layer::factory()->create(['user_id' => $admin->id, 'app_id' => $this->appWithAnalytics(true)->id]);

        $response = $this->actingAs($admin)->getJson('/nova-api/layers/cards?resourceId='.$layer->id);

        $response->assertOk();
        $components = collect($response->json())->pluck('component');
        $this->assertNotContains(self::ANALYTICS_CARD, $components);
        $this->assertContains('api-links-card', $components);
    }

    public function test_dashboard_is_named_statistics_and_keeps_the_main_uri_key(): void
    {
        $dashboard = new Main;

        $this->assertSame(__('Statistics'), $dashboard->name());
        $this->assertSame('main', $dashboard->uriKey());
    }

    private function appWithAnalytics(bool $enabled): App
    {
        return App::factory()->create([
            'properties' => ['analytics_app_enabled' => $enabled, 'analytics_webapp_enabled' => false],
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function dashboardCards(User $user): Collection
    {
        $response = $this->actingAs($user)->getJson('/nova-api/dashboards/cards/main');

        $response->assertOk();

        return collect($response->json());
    }
}

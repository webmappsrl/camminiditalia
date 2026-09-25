<?php

namespace Tests\Feature;

use App\Enums\RouteShape;
use App\Models\User;
use App\Nova\Layer as NovaLayer;
use App\Services\LayerAttributesService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Resources\MergeValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class LayerShapeOverrideFieldTest extends TestCase
{
    use DatabaseTransactions;

    private const ATTRIBUTE = 'properties->attributes->'.LayerAttributesService::SHAPE_MANUAL_KEY;

    /** @var array<int, int> */
    private array $appIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();

        // Topologia fissata a discontinua per tutte le risoluzioni via container
        // (il campo Nova usa app(LayerAttributesService::class)).
        $this->app->instance(LayerAttributesService::class, new class extends LayerAttributesService
        {
            public function determineType(array $endpoints): RouteShape
            {
                return RouteShape::DISCONTINUOUS;
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->appIds as $appId) {
            $this->releaseUpdateAppConfigLock($appId);
        }

        parent::tearDown();
    }

    private function releaseUpdateAppConfigLock(int $appId): void
    {
        app(UniqueLock::class)->release(new UpdateAppConfigJob($appId));
    }

    private function shapeField(): ?Select
    {
        $found = null;
        $walk = function ($items) use (&$walk, &$found) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $walk($item);
                } elseif ($item instanceof MergeValue) {
                    $walk($item->data);
                } elseif ($item instanceof Select && $item->attribute === self::ATTRIBUTE) {
                    $found = $item;
                }
            }
        };
        $walk((new NovaLayer(new Layer))->fields(NovaRequest::create('/')));

        return $found;
    }

    private function adminAndLayer(): array
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $app = App::factory()->createQuietly();
        $layer = Layer::factory()->create(['user_id' => $admin->id, 'app_id' => $app->id]);

        // Con Queue::fake() il lock di unicità di UpdateAppConfigJob (su Redis,
        // condiviso con lo sviluppo, uniqueFor 600 s) non viene mai rilasciato.
        // Ogni suite rifà migrate:fresh e gli app_id ripartono dagli stessi
        // numeri: un lock rimasto da un'esecuzione precedente farebbe scartare
        // in silenzio il dispatch che questi test verificano. Stesso pattern di
        // RecalculateLayerAttributesJobTest; rilasciato anche in tearDown per
        // non lasciare lock ai test successivi.
        $this->releaseUpdateAppConfigLock($app->id);
        $this->appIds[] = $app->id;

        // Tipologia già calcolata, come per un layer esistente.
        $service = app(LayerAttributesService::class);
        $service->persistCalculatedValues($layer, $service->computeCalculatedValues($layer));

        return [$admin, $layer];
    }

    private function stored(Layer $layer): array
    {
        $row = DB::selectOne(
            "SELECT COALESCE(properties->'attributes', '{}'::jsonb) AS a FROM layers WHERE id = ?",
            [$layer->id]
        );

        return json_decode((string) $row->a, true) ?? [];
    }

    public function test_field_is_only_on_update_form_with_linear_and_roundtrip_options(): void
    {
        $field = $this->shapeField();

        $this->assertNotNull($field, 'Campo Route shape assente dal pannello Manual attributes.');
        $this->assertSame(['linear', 'roundtrip'], array_keys(value($field->optionsCallback)));
        $this->assertFalse($field->showOnIndex);
        $this->assertFalse($field->showOnDetail);
        $this->assertFalse($field->showOnCreation);
        $this->assertTrue($field->showOnUpdate);
    }

    public function test_choosing_roundtrip_updates_shape_immediately_and_regenerates_config(): void
    {
        [$admin, $layer] = $this->adminAndLayer();

        $this->actingAs($admin)
            ->putJson('/nova-api/layers/'.$layer->id, [
                'name' => $layer->name,
                self::ATTRIBUTE => 'roundtrip',
            ])
            ->assertStatus(200);

        $stored = $this->stored($layer);
        $this->assertSame('roundtrip', $stored[LayerAttributesService::SHAPE_MANUAL_KEY]);
        $this->assertSame('roundtrip', $stored['shape']['value'], 'shape deve essere aggiornato senza eseguire il job in coda.');
        Queue::assertPushed(UpdateAppConfigJob::class);
    }

    public function test_back_to_automatic_removes_override_and_restores_calculated_shape(): void
    {
        [$admin, $layer] = $this->adminAndLayer();
        app(LayerAttributesService::class)->applyManualShape($layer, 'roundtrip');

        $this->actingAs($admin)
            ->putJson('/nova-api/layers/'.$layer->id, [
                'name' => $layer->name,
                self::ATTRIBUTE => '',
            ])
            ->assertStatus(200);

        $stored = $this->stored($layer);
        $this->assertArrayNotHasKey(LayerAttributesService::SHAPE_MANUAL_KEY, $stored);
        $this->assertSame('linear', $stored['shape']['value']);
        Queue::assertPushed(UpdateAppConfigJob::class);
    }

    public function test_discontinuous_cannot_be_forced_from_the_form(): void
    {
        [$admin, $layer] = $this->adminAndLayer();

        $this->actingAs($admin)
            ->putJson('/nova-api/layers/'.$layer->id, [
                'name' => $layer->name,
                self::ATTRIBUTE => 'discontinuous',
            ])
            ->assertStatus(200);

        $stored = $this->stored($layer);
        $this->assertArrayNotHasKey(LayerAttributesService::SHAPE_MANUAL_KEY, $stored);
        $this->assertSame('linear', $stored['shape']['value']);
    }

    public function test_translations_exist_in_every_language(): void
    {
        foreach (['it', 'en', 'fr', 'es', 'de'] as $locale) {
            $json = json_decode(file_get_contents(lang_path($locale.'.json')), true);
            $this->assertArrayHasKey('Automatic', $json, "Manca 'Automatic' in {$locale}.json");
            $this->assertArrayHasKey(
                'Leave on Automatic to use the shape computed from the stages; choose a value to correct it manually.',
                $json,
                "Manca l'help del campo Route shape in {$locale}.json"
            );
        }
    }
}

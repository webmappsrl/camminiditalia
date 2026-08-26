<?php

namespace Tests\Feature;

use App\Jobs\RecalculateLayerAttributesJob;
use App\Nova\Actions\RecalculateAppLayerAttributesAction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Fields\ActionFields;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class RecalculateAppLayerAttributesActionTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    public function test_action_queues_one_job_per_layer_of_the_app(): void
    {
        $app = App::first();
        $first = $this->createLayer();
        $second = $this->createLayer();

        Queue::fake();
        (new RecalculateAppLayerAttributesAction)->handle(
            new ActionFields(new Collection, new Collection),
            new Collection([$app])
        );

        Queue::assertPushed(RecalculateLayerAttributesJob::class, fn ($job) => $job->layerId === $first->id);
        Queue::assertPushed(RecalculateLayerAttributesJob::class, fn ($job) => $job->layerId === $second->id);
    }

    public function test_action_returns_a_message_with_the_queued_count(): void
    {
        $app = App::first();
        $this->createLayer();

        $result = (new RecalculateAppLayerAttributesAction)->handle(
            new ActionFields(new Collection, new Collection),
            new Collection([$app])
        );

        $this->assertNotEmpty($result);
    }
}

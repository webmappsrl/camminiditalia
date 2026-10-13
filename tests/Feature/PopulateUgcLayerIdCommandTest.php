<?php

namespace Tests\Feature;

use App\Jobs\ResolveUgcLayerJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

class PopulateUgcLayerIdCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_queues_only_ugc_pois(): void
    {
        $user = User::factory()->createQuietly();
        $app = App::factory()->createQuietly();
        $base = ['user_id' => $user->id, 'app_id' => $app->id];
        $poi = UgcPoi::factory()->createQuietly($base + ['properties' => ['form' => ['id' => 'report']]]);
        UgcTrack::factory()->createQuietly($base + ['properties' => []]);
        Bus::fake();

        $this->artisan('ugc:populate-layer-id')->assertSuccessful();

        Bus::assertDispatched(ResolveUgcLayerJob::class, fn ($job) => $job->ugc->is($poi));
        Bus::assertNotDispatched(ResolveUgcLayerJob::class, fn ($job) => $job->ugc instanceof UgcTrack);
    }
}

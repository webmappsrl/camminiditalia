<?php

namespace Tests\Feature;

use App\Nova\Actions\MarkAsRead;
use App\Nova\Actions\MarkAsUnread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Laravel\Nova\Fields\ActionFields;
use Tests\TestCase;
use Wm\WmPackage\Models\App as WmApp;
use App\Models\UgcPoi;

class MarkAsReadActionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (WmApp::count() === 0) {
            WmApp::factory()->create();
        }
    }

    public function test_mark_as_read_sets_read_at(): void
    {
        $poi = UgcPoi::factory()->create(['read_at' => null]);

        (new MarkAsRead)->handle(new ActionFields(collect(), collect()), Collection::make([$poi]));

        $this->assertNotNull($poi->fresh()->read_at);
    }

    public function test_mark_as_unread_clears_read_at(): void
    {
        $poi = UgcPoi::factory()->create(['read_at' => now()]);

        (new MarkAsUnread)->handle(new ActionFields(collect(), collect()), Collection::make([$poi]));

        $this->assertNull($poi->fresh()->read_at);
    }
}

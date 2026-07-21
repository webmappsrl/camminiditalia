<?php

namespace Tests\Feature;

use App\Nova\Traits\HasLayerFilterAndLink;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class HasLayerFilterAndLinkTest extends TestCase
{
    use DatabaseTransactions;

    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->subject = new class
        {
            use HasLayerFilterAndLink;
        };
    }

    public function test_render_layer_link_returns_placeholder_for_null(): void
    {
        $this->assertSame('Non assegnato', $this->subject::renderLayerLink(null));
    }

    public function test_render_layer_link_returns_placeholder_for_non_numeric(): void
    {
        $this->assertSame('Non assegnato', $this->subject::renderLayerLink('not-a-number'));
    }

    public function test_render_layer_link_returns_deleted_placeholder_for_missing_layer(): void
    {
        $missingId = 999999;

        $this->assertSame(
            "Layer eliminato (ID: {$missingId})",
            $this->subject::renderLayerLink($missingId)
        );
    }

    public function test_render_layer_link_returns_escaped_link_for_existing_layer(): void
    {
        $layer = Layer::factory()->create([
            'name' => ['it' => 'Via <script>alert(1)</script> Francigena', 'en' => 'Via <script>alert(1)</script> Francigena'],
        ]);

        $result = $this->subject::renderLayerLink((string) $layer->id);

        $this->assertStringContainsString('target="_blank"', $result);
        $this->assertStringContainsString('rel="noopener noreferrer"', $result);
        $this->assertStringContainsString('/nova/resources/layers/'.$layer->id, $result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }
}

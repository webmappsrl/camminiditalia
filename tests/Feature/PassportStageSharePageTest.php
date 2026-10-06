<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Models\PassportStageShare;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * GET /share/passport-stage/{uuid} (oc:8702): pagina pubblica con OG tags.
 */
class PassportStageSharePageTest extends TestCase
{
    use DatabaseTransactions, FakesCertificationDisk, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->fakeCertificationDisk();
    }

    private function share(?string $from = 'Pacentro'): PassportStageShare
    {
        $user = User::factory()->create(['name' => 'Mario Segretissimo']);
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create();

        $share = PassportStageShare::forUserAndTrack($user, $layer, $track);
        $share->addMediaFromString('png-bytes')->usingFileName('share.png')->toMediaCollection('share_image');
        $share->forceFill([
            'snapshot' => [
                'layer_name' => 'Cammino del Gran Sasso',
                'stage_label' => 'Tappa 01',
                'from' => $from,
                'to' => 'Caramanico',
                'distance_km' => 20.4,
                'ascent_m' => 358,
                'shared_at' => '2026-09-30T10:00:00+00:00',
            ],
        ])->save();

        return $share->fresh();
    }

    public function test_page_has_og_tags_with_image_url(): void
    {
        $share = $this->share();
        $imageUrl = $share->getFirstMedia('share_image')->getUrl();

        $response = $this->get(route('share.passport-stage', ['uuid' => $share->uuid]));

        $response->assertOk();
        $response->assertSee('<meta property="og:image" content="'.$imageUrl.'"', false);
        $response->assertSee('property="og:title"', false);
        $response->assertSee('property="og:description"', false);
        $response->assertSee('<meta property="og:url" content="'.route('share.passport-stage', ['uuid' => $share->uuid]).'"', false);
        $response->assertSee('Cammino del Gran Sasso');
        $response->assertSee('Pacentro');
        // Stesso formato dell'immagine (StageShareLocale): dislivello con il segno.
        $response->assertSee('20,4 km');
        $response->assertSee('+358 m');
    }

    public function test_page_does_not_leak_user_data(): void
    {
        $share = $this->share();

        $html = $this->get(route('share.passport-stage', ['uuid' => $share->uuid]))->getContent();

        $this->assertStringNotContainsString('Mario Segretissimo', $html);
        $this->assertStringNotContainsString('user_id', $html);
        $this->assertStringNotContainsString('ec_track_id', $html);
    }

    public function test_from_entry_is_omitted_when_null(): void
    {
        $share = $this->share(null);

        $this->get(route('share.passport-stage', ['uuid' => $share->uuid]))
            ->assertOk()
            ->assertDontSee('Partenza')
            ->assertSee('Arrivo');
    }

    public function test_page_has_no_app_link_and_is_not_indexed(): void
    {
        $share = $this->share();

        $html = $this->get(route('share.passport-stage', ['uuid' => $share->uuid]))->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('href="'.config('app.url').'"', $html);
    }

    public function test_unknown_uuid_returns_404(): void
    {
        $this->get(route('share.passport-stage', ['uuid' => '00000000-0000-0000-0000-000000000000']))->assertNotFound();
    }

    public function test_non_uuid_string_returns_404(): void
    {
        $this->get('/share/passport-stage/not-a-uuid')->assertNotFound();
    }
}

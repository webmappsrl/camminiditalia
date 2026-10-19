<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Models\PassportShare;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * GET /share/passport/{uuid} (oc:8702, oc:8703): pagina pubblica di una tappa o
 * di un cammino condivisi, con OG tags.
 */
class PassportSharePageTest extends TestCase
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

    private function share(?string $from = 'Pacentro'): PassportShare
    {
        $user = User::factory()->create(['name' => 'Mario Segretissimo']);
        $layer = $this->createLayer();
        $track = EcTrack::factory()->create();

        $share = PassportShare::forUser($user, $layer, $track);
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

        $response = $this->get(route('share.passport', ['uuid' => $share->uuid]));

        $response->assertOk();
        $response->assertSee('<meta property="og:image" content="'.$imageUrl.'"', false);
        $response->assertSee('property="og:title"', false);
        $response->assertSee('property="og:description"', false);
        $response->assertSee('<meta property="og:url" content="'.route('share.passport', ['uuid' => $share->uuid]).'"', false);
        $response->assertSee('Cammino del Gran Sasso');
        $response->assertSee('Pacentro');
        // Stesso formato dell'immagine (StageShareLocale): dislivello con il segno.
        $response->assertSee('20,4 km');
        $response->assertSee('+358 m');
    }

    public function test_page_does_not_leak_user_data(): void
    {
        $share = $this->share();

        $html = $this->get(route('share.passport', ['uuid' => $share->uuid]))->getContent();

        $this->assertStringNotContainsString('Mario Segretissimo', $html);
        $this->assertStringNotContainsString('user_id', $html);
        $this->assertStringNotContainsString('ec_track_id', $html);
    }

    public function test_from_entry_is_omitted_when_null(): void
    {
        $share = $this->share(null);

        $this->get(route('share.passport', ['uuid' => $share->uuid]))
            ->assertOk()
            ->assertDontSee('Partenza')
            ->assertSee('Arrivo');
    }

    public function test_page_has_no_app_link_and_is_not_indexed(): void
    {
        $share = $this->share();

        $html = $this->get(route('share.passport', ['uuid' => $share->uuid]))->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('href="'.config('app.url').'"', $html);
    }

    public function test_unknown_uuid_returns_404(): void
    {
        $this->get(route('share.passport', ['uuid' => '00000000-0000-0000-0000-000000000000']))->assertNotFound();
    }

    public function test_non_uuid_string_returns_404(): void
    {
        $this->get('/share/passport/not-a-uuid')->assertNotFound();
    }

    private function routeShare(?float $distance = 35.5, ?int $outings = null): PassportShare
    {
        $user = User::factory()->create(['name' => 'Mario Segretissimo']);
        $layer = $this->createLayer();

        $share = PassportShare::forUser($user, $layer, $layer);
        $share->addMediaFromString('png-bytes')->usingFileName('share.png')->toMediaCollection('share_image');
        $share->forceFill([
            'snapshot' => [
                'layer_name' => 'Cammino del Gran Sasso',
                'completed_at' => '2026-09-30T10:00:00+00:00',
                'stages_validated' => 6,
                'stages_total' => 6,
                'distance_km' => $distance,
                'outings' => $outings,
                'lang' => 'it',
                'shared_at' => '2026-10-01T10:00:00+00:00',
            ],
        ])->save();

        return $share->fresh();
    }

    public function test_route_page_shows_snapshot(): void
    {
        $share = $this->routeShare();
        $imageUrl = $share->getFirstMedia('share_image')->getUrl();

        $response = $this->get(route('share.passport', ['uuid' => $share->uuid]));

        $response->assertOk();
        $response->assertSee('<meta property="og:image" content="'.$imageUrl.'"', false);
        $response->assertSee('Cammino del Gran Sasso · Cammino completato');
        $response->assertSee('30 settembre 2026');
        $response->assertSee('6/6');
        $response->assertSee('35,5 km');
        $response->assertDontSee('Mario Segretissimo');
    }

    public function test_route_page_omits_distance_when_null(): void
    {
        $share = $this->routeShare(null);

        $this->get(route('share.passport', ['uuid' => $share->uuid]))
            ->assertOk()
            ->assertDontSee('Lunghezza totale');
    }

    public function test_route_page_shows_outings_only_when_known(): void
    {
        $this->get(route('share.passport', ['uuid' => $this->routeShare()->uuid]))->assertOk()->assertDontSee('Uscite');
        $this->get(route('share.passport', ['uuid' => $this->routeShare(35.5, 4)->uuid]))->assertOk()->assertSee('Uscite');
    }
}

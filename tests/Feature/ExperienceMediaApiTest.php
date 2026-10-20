<?php

namespace Tests\Feature;

use App\Models\Experience;
use App\Services\ExperienceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Foto delle esperienze (oc:8169) sulle API dei media di wm-package:
 * lettura all'autore o a tutti se pubblica, cancellazione solo all'autore.
 */
class ExperienceMediaApiTest extends TestCase
{
    use DatabaseTransactions, FakesCertificationDisk, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->fakeCertificationDisk();
        Queue::fake();
    }

    /**
     * Foto di un'esperienza dell'autore indicato.
     */
    private function experienceMedia(User $author, bool $public = false): Media
    {
        $layer = $this->createLayer();
        $experience = Experience::factory()->create(['user_id' => $author->id, 'layer_id' => $layer->id, 'is_public' => $public]);
        app(ExperienceService::class)->storeImages($experience, [UploadedFile::fake()->image('esperienza.jpg', 30, 30)]);

        return Media::query()->where('model_type', Experience::class)->where('model_id', $experience->id)->firstOrFail();
    }

    /**
     * Il middleware lascia passare la richiesta: le route di lettura di
     * wm-package oggi rispondono 500 per qualsiasi media (`MediaResource`
     * e `_getEcModelFromType`), quindi si verifica solo che non sia il 404
     * del middleware.
     */
    private function assertPassesThrough(int $status): void
    {
        $this->assertNotSame(404, $status);
    }

    public function test_senza_login_la_foto_privata_non_si_legge(): void
    {
        $media = $this->experienceMedia($this->createUserWithoutRole());

        $this->getJson("/api/media/{$media->id}")->assertNotFound();
        $this->getJson("/api/v2/media/{$media->id}")->assertNotFound();
        $this->getJson("/api/ec/media/{$media->id}")->assertNotFound();
        $this->getJson("/api/ec/media/image/{$media->id}")->assertNotFound();
    }

    public function test_un_altro_utente_non_legge_la_foto_privata(): void
    {
        $media = $this->experienceMedia($this->createUserWithoutRole());

        $this->actingAs($this->createUserWithoutRole(), 'api')
            ->getJson("/api/media/{$media->id}")
            ->assertNotFound();
    }

    public function test_l_autore_legge_la_propria_foto(): void
    {
        $author = $this->createUserWithoutRole();
        $media = $this->experienceMedia($author);

        $response = $this->actingAs($author, 'api')->getJson("/api/ec/media/{$media->id}");

        $this->assertPassesThrough($response->status());
    }

    public function test_la_foto_di_un_esperienza_pubblica_si_legge_senza_login(): void
    {
        $media = $this->experienceMedia($this->createUserWithoutRole(), public: true);

        $this->assertPassesThrough($this->getJson("/api/ec/media/{$media->id}")->status());
    }

    public function test_un_altro_utente_non_cancella_la_foto_neanche_se_pubblica(): void
    {
        $media = $this->experienceMedia($this->createUserWithoutRole(), public: true);

        $this->actingAs($this->createUserWithoutRole(), 'api')
            ->getJson("/api/ugc/media/delete/{$media->id}")
            ->assertNotFound();
        $this->assertModelExists($media);
    }

    public function test_l_autore_cancella_la_propria_foto(): void
    {
        $author = $this->createUserWithoutRole();
        $media = $this->experienceMedia($author);

        $this->actingAs($author, 'api')->getJson("/api/ugc/media/delete/{$media->id}")->assertOk();
        $this->assertModelMissing($media);
    }
}

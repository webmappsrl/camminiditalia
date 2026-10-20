<?php

namespace Tests\Feature;

use App\Models\Experience;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Jobs\UpdateAppConfigJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\PBFGeneratorService;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class ExperienceCleanupTest extends TestCase
{
    use DatabaseTransactions, FakesCertificationDisk, LayerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }

        // Experience non ha app_id: MediaObserver ripiega su app_id = 1 per i suoi media,
        // e la FK media_app_id_foreign richiede che quell'App esista.
        if (App::find(1) === null) {
            App::factory()->create(['id' => 1]);
        }

        $this->fakeCertificationDisk();
        Queue::fake();
        Http::fake();
    }

    /**
     * Crea un'esperienza con una foto e verifica che il file esista sul disco.
     *
     * @return array{0: Experience, 1: string}
     */
    private function createExperienceWithPhoto(int $userId, Layer $layer): array
    {
        $experience = Experience::factory()->create(['user_id' => $userId, 'layer_id' => $layer->id]);
        $experience->addMedia(UploadedFile::fake()->image('foto.jpg'))->toMediaCollection(Experience::MEDIA_COLLECTION);

        $path = $experience->getFirstMedia(Experience::MEDIA_COLLECTION)->getPathRelativeToRoot();
        Storage::disk('wmfe')->assertExists($path);

        return [$experience, $path];
    }

    private function assertExperienceRemoved(Experience $experience, string $path): void
    {
        $this->assertDatabaseMissing('experiences', ['id' => $experience->id]);
        $this->assertSame(0, Media::where('model_type', Experience::class)->count());
        Storage::disk('wmfe')->assertMissing($path);
    }

    public function test_cancellare_utente_rimuove_esperienze_e_file(): void
    {
        $user = User::factory()->create();
        [$experience, $path] = $this->createExperienceWithPhoto($user->id, $this->createLayer());

        User::find($user->id)->delete();

        $this->assertExperienceRemoved($experience, $path);
    }

    public function test_cancellare_utente_locale_rimuove_esperienze_e_file(): void
    {
        $user = User::factory()->create();
        [$experience, $path] = $this->createExperienceWithPhoto($user->id, $this->createLayer());

        \App\Models\User::find($user->id)->delete();

        $this->assertExperienceRemoved($experience, $path);
    }

    public function test_cancellare_layer_rimuove_esperienze_e_file(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();
        [$experience, $path] = $this->createExperienceWithPhoto($user->id, $layer);

        // LayerObserver::deleted() rigenera i PBF in modo sincrono: lo si isola con un mock.
        $pbfMock = Mockery::mock(PBFGeneratorService::class);
        $pbfMock->shouldReceive('regeneratePbfsForLayer');
        $this->app->instance(PBFGeneratorService::class, $pbfMock);

        $appId = (int) $layer->app_id;
        $layer->delete();

        // Rilascia il lock unico di UpdateAppConfigJob, che sotto Queue::fake() resterebbe preso.
        app(UniqueLock::class)->release(new UpdateAppConfigJob($appId));

        $this->assertExperienceRemoved($experience, $path);
    }
}

<?php

namespace Tests\Feature;

use App\Models\CertificationRequest;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

class CertificationRequestCleanupTest extends TestCase
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

        // Job asincroni (RecalculateLayerAttributesJob su ogni save() di Layer,
        // GenerateFeatureCollectionJob su Layer::deleting) altrimenti girano in sync sotto
        // phpunit.xml (QUEUE_CONNECTION=sync) e falliscono su dati di test incompleti (es.
        // bbox App mancante) — stesso fake già usato in CertificationRequestApiTest.
        Queue::fake();

        // `.env.testing` ha un JWT_SECRET volutamente corto/non realistico
        // ("testing-only-not-a-real-secret", 240 bit) sufficiente per gli altri test, ma
        // l'algoritmo HS256 richiede almeno 256 bit per firmare un token con
        // `auth('api')->login()`. `php artisan jwt:secret --always-no` non aiuta: la
        // console command scrive su `.env`/`.env.testing` su disco, ma qui il processo di
        // test ha già caricato `config('jwt.secret')` in memoria e la flag "always-no"
        // salta comunque la generazione se una chiave esiste già. Sovrascrivere il config
        // in memoria è locale a questo test (nessuna scrittura su file, nessun impatto su
        // altri test).
        config(['jwt.secret' => Str::random(64)]);
    }

    private function createRequestWithMedia(int $userId, Layer $layer): CertificationRequest
    {
        $request = CertificationRequest::create([
            'user_id' => $userId,
            'layer_id' => $layer->id,
            'app_id' => $layer->app_id,
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
        ]);

        $request->addMedia(UploadedFile::fake()->image('one.jpg'))->toMediaCollection('default');
        $request->addMedia(UploadedFile::fake()->image('two.jpg'))->toMediaCollection('default');

        return $request;
    }

    /**
     * @return array<int, string>
     */
    private function mediaPaths(CertificationRequest $request): array
    {
        $paths = $request->getMedia('default')->map->getPathRelativeToRoot()->all();

        $this->assertCount(2, $paths);

        foreach ($paths as $path) {
            Storage::disk('wmfe')->assertExists($path);
        }

        return $paths;
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function assertRequestAndMediaRemoved(array $paths): void
    {
        $this->assertDatabaseCount('certification_requests', 0);
        $this->assertSame(0, Media::where('model_type', CertificationRequest::class)->count());

        foreach ($paths as $path) {
            Storage::disk('wmfe')->assertMissing($path);
        }
    }

    public function test_deleting_user_removes_request_media(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        $paths = $this->mediaPaths($this->createRequestWithMedia($user->id, $layer));

        // Il provider del guard `api` è configurato su App\Models\User (config/auth.php):
        // il token va generato a partire da quella classe, altrimenti il claim `prv`
        // (lock_subject) non combacia e la richiesta successiva risulta 401.
        $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser(\App\Models\User::find($user->id));

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/auth/delete');

        $response->assertOk();

        $this->assertRequestAndMediaRemoved($paths);
    }

    public function test_deleting_user_via_local_model_removes_request_media(): void
    {
        // Copre la registrazione dell'observer su App\Models\User (in aggiunta a
        // Wm\WmPackage\Models\User) in AppServiceProvider::boot().
        $user = User::factory()->create();
        $layer = $this->createLayer();

        $paths = $this->mediaPaths($this->createRequestWithMedia($user->id, $layer));

        \App\Models\User::find($user->id)->delete();

        $this->assertRequestAndMediaRemoved($paths);
    }

    public function test_deleting_layer_removes_request_media(): void
    {
        $user = User::factory()->create();
        $layer = $this->createLayer();

        $paths = $this->mediaPaths($this->createRequestWithMedia($user->id, $layer));

        // Wm\WmPackage\Observers\LayerObserver::deleted() chiama, in modo sincrono (non
        // accodato), PBFGeneratorService::regeneratePbfsForLayer(), che fallisce senza una
        // bbox App valida — pattern di mock già in uso in LayerFeatureControllerTest per
        // isolare i test dagli effetti collaterali reali di questo servizio.
        $pbfMock = Mockery::mock(PBFGeneratorService::class);
        $pbfMock->shouldReceive('regeneratePbfsForLayer');
        $this->app->instance(PBFGeneratorService::class, $pbfMock);

        $appId = (int) $layer->app_id;
        $layer->delete();

        // Layer::deleted dispatcha UpdateAppConfigJob (ShouldBeUnique, lock Redis 600s per
        // app): sotto Queue::fake() il lock non verrebbe mai rilasciato e scarterebbe i
        // dispatch di altri test (es. RecalculateLayerAttributesJobTest) sulla stessa app.
        app(UniqueLock::class)->release(new UpdateAppConfigJob($appId));

        $this->assertRequestAndMediaRemoved($paths);
    }
}

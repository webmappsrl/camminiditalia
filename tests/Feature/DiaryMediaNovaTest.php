<?php

namespace Tests\Feature;

use App\Models\CertificationRequest;
use App\Models\Diary;
use App\Models\User;
use App\Nova\Media as NovaMedia;
use App\Services\DiaryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Foto delle esperienze (oc:8169) nella risorsa Media di Nova: le vede solo
 * l'Administrator; i media delle richieste di certificazione restano visibili
 * al Validator.
 */
class DiaryMediaNovaTest extends TestCase
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
     * Un media di un'esperienza e, creato dopo, uno di una richiesta di certificazione.
     *
     * @return array{0: Media, 1: Media}
     */
    private function seedMedia(): array
    {
        $layer = $this->createLayer($this->createUserWithRole('Validator')->id);
        $walker = User::factory()->create();

        $diary = Diary::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id]);
        app(DiaryService::class)->storeImages($diary, [UploadedFile::fake()->image('esperienza.jpg', 30, 30)]);
        $diaryMedia = Media::query()->where('model_type', Diary::class)->where('model_id', $diary->id)->firstOrFail();

        $request = CertificationRequest::factory()->create([
            'user_id' => $walker->id,
            'layer_id' => $layer->id,
            'app_id' => $layer->app_id,
            'serial_number' => 'SN-'.$walker->id,
        ]);
        $request->addMedia(UploadedFile::fake()->image('credenziale.jpg'))->toMediaCollection('default');
        $certificationMedia = Media::query()->where('model_type', CertificationRequest::class)->where('model_id', $request->id)->firstOrFail();

        return [$diaryMedia, $certificationMedia];
    }

    private function novaRequestFor(User $user): NovaRequest
    {
        $request = NovaRequest::create('/');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_query_di_nova_escludono_i_media_delle_esperienze_per_il_validator(): void
    {
        [$diaryMedia, $certificationMedia] = $this->seedMedia();
        $validator = $this->createUserWithRole('Validator');

        foreach (['indexQuery', 'detailQuery', 'editQuery', 'replicateQuery', 'relatableQuery'] as $method) {
            $ids = NovaMedia::$method($this->novaRequestFor($validator), Media::query())
                ->whereIn('id', [$diaryMedia->id, $certificationMedia->id])
                ->pluck('id')->all();

            $this->assertSame([$certificationMedia->id], $ids, $method);
        }
    }

    public function test_query_di_nova_includono_i_media_delle_esperienze_per_l_administrator(): void
    {
        [$diaryMedia, $certificationMedia] = $this->seedMedia();
        $admin = $this->createUserWithRole('Administrator');

        foreach (['indexQuery', 'detailQuery', 'editQuery', 'replicateQuery', 'relatableQuery'] as $method) {
            $ids = NovaMedia::$method($this->novaRequestFor($admin), Media::query())
                ->whereIn('id', [$diaryMedia->id, $certificationMedia->id])
                ->pluck('id')->all();

            $this->assertEqualsCanonicalizing([$diaryMedia->id, $certificationMedia->id], $ids, $method);
        }
    }

    public function test_validator_non_vede_il_media_di_un_esperienza_via_nova_api(): void
    {
        [$diaryMedia, $certificationMedia] = $this->seedMedia();
        $validator = $this->createUserWithRole('Validator');

        $this->assertContains(
            $this->actingAs($validator)->getJson('/nova-api/media/'.$diaryMedia->id)->status(),
            [403, 404],
        );
        $this->actingAs($validator)->getJson('/nova-api/media/'.$certificationMedia->id)->assertOk();

        // L'indice è ordinato per id decrescente: i due media appena creati stanno nella prima pagina.
        $ids = collect($this->actingAs($validator)->getJson('/nova-api/media')->assertOk()->json('resources'))->pluck('id.value');
        $this->assertFalse($ids->contains($diaryMedia->id));
        $this->assertTrue($ids->contains($certificationMedia->id));
    }

    public function test_administrator_vede_il_media_di_un_esperienza_via_nova_api(): void
    {
        [$diaryMedia] = $this->seedMedia();
        $admin = $this->createUserWithRole('Administrator');

        $this->actingAs($admin)->getJson('/nova-api/media/'.$diaryMedia->id)->assertOk();

        $ids = collect($this->actingAs($admin)->getJson('/nova-api/media')->assertOk()->json('resources'))->pluck('id.value');
        $this->assertTrue($ids->contains($diaryMedia->id));
    }
}

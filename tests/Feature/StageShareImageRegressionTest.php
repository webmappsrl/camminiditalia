<?php

namespace Tests\Feature;

use App\Models\EcTrack;
use App\Services\PassportShare\StageShareImageService;
use App\Services\PassportShare\StageShareLayout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Intervention\Image\Facades\Image;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack as WmEcTrack;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Regressione sull'immagine di condivisione della tappa (oc:8703): il codice
 * comune con l'immagine del cammino è stato estratto da
 * StageShareImageService, e l'immagine della tappa deve restare identica
 * byte per byte, così come la firma del layout (che invaliderebbe le
 * immagini già in cache). I valori attesi sono stati registrati sul codice
 * di oc:8702, prima dell'estrazione.
 */
class StageShareImageRegressionTest extends TestCase
{
    use DatabaseTransactions, FakesCertificationDisk, LayerTestHelpers;

    /** md5 del PNG della tappa di riferimento, registrato prima dell'estrazione. */
    private const EXPECTED_IMAGE_MD5 = '1f1e7949025c448163383b183e85f598';

    /** Firma del layout della tappa, registrata prima dell'estrazione. */
    private const EXPECTED_LAYOUT_SIGNATURE = 'd759644633fd8dfe56cf0fadeba8051f2f57b3a48a24b108b78ada6fb1b52b5b';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $tile = Image::canvas(256, 256, '#cfe3c4')->encode('png')->getEncoded();
        Http::fake(fn () => Http::response($tile, 200, ['Content-Type' => 'image/png']));

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->fakeCertificationDisk();
    }

    /**
     * Tappa con geometria fissa, associata al layer con insert diretto su
     * `layerables`, come in StageShareImageServiceTest.
     *
     * @param  array<string, mixed>  $properties
     * @param  list<array{0: float, 1: float}>  $points
     */
    private function stage(int $layerId, int $ownerId, array $properties, array $points, string $name): WmEcTrack
    {
        $track = EcTrack::factory()->create([
            'user_id' => $ownerId,
            'osmid' => null,
            'name' => ['it' => $name],
            'properties' => $properties,
        ]);

        $wkt = 'MULTILINESTRING Z(('.implode(', ', array_map(fn ($p) => "{$p[0]} {$p[1]} 0", $points)).'))';
        DB::table('ec_tracks')->where('id', $track->id)->update(['geometry' => DB::raw("ST_GeomFromText('{$wkt}', 4326)")]);

        DB::table('layerables')->insert([
            'layer_id' => $layerId,
            'layerable_type' => (new (config('wm-package.ec_track_model')))->getMorphClass(),
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return WmEcTrack::find($track->id);
    }

    /**
     * Immagine della tappa con tutti i blocchi: logo del cammino, nome,
     * mappa con il resto del cammino, etichetta, griglia completa e logo di
     * Cammini d'Italia.
     */
    public function test_stage_image_is_byte_identical_after_refactor(): void
    {
        $owner = $this->createUserWithRole('Validator');
        $layer = $this->createLayer($owner->id);
        $layer->forceFill(['name' => ['it' => 'Cammino del Gran Sasso']])->saveQuietly();
        $layer->addMediaFromString(Image::canvas(400, 200, '#c0392b')->encode('png')->getEncoded())
            ->usingFileName('logo.png')
            ->toMediaCollection('logo');

        $app = App::findOrFail($layer->app_id);
        DB::table('media')->where('model_type', $app->getMorphClass())->where('model_id', $app->id)->delete();
        $app->fresh()->addMediaFromString(Image::canvas(512, 512, '#1f6fb2')->encode('png')->getEncoded())
            ->usingFileName('icon.png')
            ->toMediaCollection('icon');

        $stage = $this->stage($layer->id, $owner->id, ['ref' => '01', 'from' => 'Pacentro', 'to' => 'Caramanico', 'manual_data' => ['distance' => 20.4, 'ascent' => 358]], [[13.90, 42.10], [13.95, 42.12], [14.00, 42.15]], 'Da Pacentro a Caramanico');
        $this->stage($layer->id, $owner->id, ['ref' => '02'], [[14.00, 42.15], [14.08, 42.20], [14.15, 42.22]], 'Seconda tappa');

        $service = app(StageShareImageService::class);
        $layer = $layer->fresh();
        $image = $service->compose($layer, $stage, 'it', $service->snapshot($layer, $stage, 'it'));

        $this->assertSame(self::EXPECTED_IMAGE_MD5, md5($image->getEncoded()));
    }

    /**
     * La firma del layout della tappa non cambia: cambiandola, ogni immagine
     * già salvata verrebbe ricomposta alla prossima condivisione.
     */
    public function test_layout_signature_is_unchanged(): void
    {
        $this->assertSame(self::EXPECTED_LAYOUT_SIGNATURE, StageShareLayout::signature());
    }
}

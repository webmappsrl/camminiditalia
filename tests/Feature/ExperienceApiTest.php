<?php

namespace Tests\Feature;

use App\Jobs\LinkExperienceUgcTracksJob;
use App\Models\EcTrack;
use App\Models\Experience;
use App\Services\ExperienceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Feature\Helpers\CreatesCertificationTracks;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class ExperienceApiTest extends TestCase
{
    use CreatesCertificationTracks, DatabaseTransactions, FakesCertificationDisk, LayerTestHelpers;

    private const UUID_A = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }

        $this->fakeCertificationDisk();
        Queue::fake();
        Http::fake();
    }

    private function postExperience(User $user, array $payload): TestResponse
    {
        return $this->actingAs($user, 'api')->postJson('/api/experiences', $payload);
    }

    /**
     * JPEG reale con un segmento EXIF (orientamento 6 e un tag Make) inserito a mano.
     */
    private function jpegWithExif(string $name = 'exif.jpg'): UploadedFile
    {
        $im = imagecreatetruecolor(40, 20);
        ob_start();
        imagejpeg($im);
        $jpeg = ob_get_clean();

        // TIFF little endian, IFD0 con Make (ASCII, inline <=4 byte) e Orientation = 6.
        $tiff = "II\x2A\x00\x08\x00\x00\x00"
            ."\x02\x00"
            ."\x0F\x01\x02\x00\x04\x00\x00\x00ABC\x00"
            ."\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00"
            ."\x00\x00\x00\x00";
        $payload = "Exif\x00\x00".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
        $jpeg = substr($jpeg, 0, 2).$segment.substr($jpeg, 2);

        $path = tempnam(sys_get_temp_dir(), 'exif_').'.jpg';
        file_put_contents($path, $jpeg);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    /**
     * JPEG con intestazione completa fino all'inizio della scansione (SOS) e nessun dato
     * d'immagine: passa tipo e `dimensions` (getimagesize legge il SOF), GD non lo decodifica.
     */
    private function corruptJpeg(string $name = 'rotta.jpg'): UploadedFile
    {
        $im = imagecreatetruecolor(40, 40);
        ob_start();
        imagejpeg($im);
        $jpeg = ob_get_clean();

        return UploadedFile::fake()->createWithContent($name, substr($jpeg, 0, strpos($jpeg, "\xFF\xDA")));
    }

    public function test_crea_da_cammino(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();

        $this->postExperience($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'Bel giro'])
            ->assertStatus(201)
            ->assertJsonPath('layer.id', $layer->id)
            ->assertJsonPath('ec_track', null)
            ->assertJsonPath('description', 'Bel giro');
    }

    public function test_crea_da_tappa_senza_layer_ricava_il_cammino(): void
    {
        $owner = $this->createUserWithoutRole();
        $high = $this->createLayer();
        $low = $this->createLayer();
        $track = $this->createTrack($owner->id, 'Tappa 1', $high);
        $this->createTrack($owner->id, 'Altra', $low);
        // La stessa tappa anche nel layer con id più basso.
        \DB::table('layerables')->insert([
            'layer_id' => min($low->id, $high->id),
            'layerable_type' => EcTrack::class,
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lowest = min($low->id, $high->id);

        $this->postExperience($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'ec_track_id' => $track->id, 'description' => 'x'])
            ->assertStatus(201)
            ->assertJsonPath('layer.id', $lowest)
            ->assertJsonPath('ec_track.id', $track->id);
    }

    public function test_rifiuta_tappa_fuori_dal_cammino(): void
    {
        $owner = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $other = $this->createLayer();
        $track = $this->createTrack($owner->id, 'Tappa', $other);

        $this->postExperience($this->createUserWithoutRole(), [
            'uuid' => self::UUID_A, 'layer_id' => $layer->id, 'ec_track_id' => $track->id, 'description' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['ec_track_id']);
    }

    public function test_rifiuta_testo_vuoto_senza_foto(): void
    {
        $layer = $this->createLayer();

        $this->postExperience($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    }

    public function test_rifiuta_sette_foto(): void
    {
        $layer = $this->createLayer();
        $images = array_map(fn ($i) => UploadedFile::fake()->image("f{$i}.jpg"), range(1, 7));

        $this->postExperience($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => $images])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images']);
    }

    public function test_stesso_uuid_non_duplica_e_aggiunge_foto_mancanti(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $a = UploadedFile::fake()->image('a.jpg', 30, 30);
        $b = UploadedFile::fake()->image('b.jpg', 50, 40);

        $this->postExperience($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$a]])->assertStatus(201);

        $a2 = new UploadedFile($a->getRealPath(), 'image_0.jpg', 'image/jpeg', null, true);
        $this->postExperience($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$a2, $b]])
            ->assertStatus(200)
            ->assertJsonCount(2, 'photos');

        $this->assertSame(1, Experience::count());
        $this->assertSame(2, Experience::first()->getMedia(Experience::MEDIA_COLLECTION)->count());
    }

    public function test_stesso_uuid_aggiorna_il_testo_corretto(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();

        $this->postExperience($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'primo testo'])->assertStatus(201);
        $this->postExperience($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'testo corretto'])
            ->assertStatus(200)
            ->assertJsonPath('description', 'testo corretto');

        $this->assertSame(1, Experience::count());
    }

    public function test_errore_di_storage_non_lascia_un_esperienza_vuota(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $this->mock(ExperienceService::class, function ($mock) {
            $mock->makePartial();
            $mock->shouldReceive('storeImages')->andThrow(new \RuntimeException('S3 non raggiungibile'));
        });

        $this->postExperience($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [UploadedFile::fake()->image('a.jpg', 30, 30)]])
            ->assertStatus(500);

        $this->assertSame(0, Experience::count());
    }

    public function test_il_limite_delle_esperienze_non_consuma_quello_del_passaporto(): void
    {
        $route = app('router')->getRoutes()->getByName('camminiditalia.api.experiences.store');

        $this->assertContains('throttle:experiences', $route->gatherMiddleware());
        $this->assertNotContains('throttle:10,1', $route->gatherMiddleware());
    }

    public function test_uuid_di_altro_utente_crea_nuova_esperienza(): void
    {
        $layer = $this->createLayer();

        $this->postExperience($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'x'])->assertStatus(201);
        $this->postExperience($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'y'])->assertStatus(201);

        $this->assertSame(2, Experience::count());
    }

    public function test_index_del_cammino_include_le_tappe(): void
    {
        $walker = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $other = $this->createLayer();
        $track = $this->createTrack($walker->id, 'Tappa', $layer);

        $onRoute = Experience::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id]);
        $onStage = Experience::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id, 'ec_track_id' => $track->id]);
        Experience::factory()->create(['user_id' => $walker->id, 'layer_id' => $other->id]);

        $ids = fn (TestResponse $r) => collect($r->json('data'))->pluck('id')->sort()->values()->all();

        $r = $this->actingAs($walker, 'api')->getJson("/api/experiences?layer_id={$layer->id}")->assertOk();
        $this->assertSame([$onRoute->id, $onStage->id], $ids($r));

        $r = $this->actingAs($walker, 'api')->getJson("/api/experiences?ec_track_id={$track->id}")->assertOk();
        $this->assertSame([$onStage->id], $ids($r));
    }

    public function test_index_non_mostra_esperienze_altrui(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        Experience::factory()->create(['user_id' => $this->createUserWithoutRole()->id, 'layer_id' => $layer->id]);

        $this->actingAs($walker, 'api')->getJson('/api/experiences')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_non_autenticato_riceve_401(): void
    {
        $this->getJson('/api/experiences')->assertStatus(401);
        $this->postJson('/api/experiences', [])->assertStatus(401);
    }

    public function test_i_media_portano_l_app_id_del_cammino(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();

        $this->postExperience($walker, [
            'uuid' => self::UUID_A, 'layer_id' => $layer->id,
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertStatus(201);

        $media = Experience::first()->getFirstMedia(Experience::MEDIA_COLLECTION);
        $this->assertSame((int) Layer::find($layer->id)->app_id, (int) $media->app_id);
    }

    public function test_la_foto_viene_salvata_senza_exif(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $upload = $this->jpegWithExif();

        // Verifica del presupposto: il file di partenza ha davvero l'EXIF.
        $this->assertSame(6, exif_read_data($upload->getRealPath())['Orientation'] ?? null);

        $this->postExperience($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$upload]])
            ->assertStatus(201);

        $media = Experience::first()->getFirstMedia(Experience::MEDIA_COLLECTION);
        $stored = \Storage::disk('wmfe')->get($media->getPathRelativeToRoot());
        $tmp = tempnam(sys_get_temp_dir(), 'chk_');
        file_put_contents($tmp, $stored);

        $exif = @exif_read_data($tmp);
        $this->assertTrue($exif === false || ! isset($exif['Orientation']) && ! isset($exif['Make']));
        // Orientamento 6 = ruotata di 90 gradi: la 40x20 diventa 20x40.
        [$w, $h] = getimagesize($tmp);
        $this->assertSame([20, 40], [$w, $h]);
    }

    public function test_rifiuta_svg(): void
    {
        $layer = $this->createLayer();
        $svg = UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->postExperience($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$svg]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0']);
        $this->assertSame(0, Experience::count());
    }

    public function test_jpg_corrotto_da_422_e_non_crea_righe(): void
    {
        $layer = $this->createLayer();
        $broken = $this->corruptJpeg();

        $this->postExperience($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$broken]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images']);
        $this->assertSame(0, Experience::count());
    }

    public function test_tappa_senza_cammino_rifiutata(): void
    {
        $track = $this->createTrack($this->createUserWithoutRole()->id, 'Orfana');

        $this->postExperience($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'ec_track_id' => $track->id, 'description' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ec_track_id']);
        $this->assertSame(0, Experience::count());
    }

    public function test_collisione_sull_uuid_rilegge_la_riga_esistente(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $existing = Experience::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id, 'uuid' => self::UUID_A]);

        // Simula la corsa: la riga compare fra il controllo e l'insert, createOrFirst deve rileggerla.
        $found = Experience::createOrFirst(['user_id' => $walker->id, 'uuid' => self::UUID_A], ['layer_id' => $layer->id]);

        $this->assertTrue($found->is($existing));
        $this->assertFalse($found->wasRecentlyCreated);
        $this->assertSame(1, Experience::count());
    }

    public function test_due_foto_identiche_nella_stessa_richiesta_diventano_un_media(): void
    {
        $layer = $this->createLayer();
        $a = UploadedFile::fake()->image('a.jpg', 30, 30);
        $b = new UploadedFile($a->getRealPath(), 'b.jpg', 'image/jpeg', null, true);

        $this->postExperience($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$a, $b]])
            ->assertStatus(201)
            ->assertJsonCount(1, 'photos');
    }

    public function test_rollback_con_buona_e_corrotta_non_lascia_righe_ne_file(): void
    {
        $layer = $this->createLayer();
        $good = UploadedFile::fake()->image('ok.jpg', 30, 30);
        $broken = $this->corruptJpeg();

        $this->postExperience($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$good, $broken]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images']);

        $this->assertSame(0, Experience::count());
        $this->assertSame([], \Storage::disk('wmfe')->allFiles());
    }

    /**
     * Esperienza dell'utente con `$n` foto distinte già salvate.
     */
    private function experienceWithPhotos(User $user, int $n, ?string $description = 'testo'): Experience
    {
        $layer = $this->createLayer();
        $experience = Experience::factory()->create(['user_id' => $user->id, 'layer_id' => $layer->id, 'description' => $description]);
        $images = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 20 + $i * 7, 20 + $i * 3), $n > 0 ? range(1, $n) : []);
        app(ExperienceService::class)->storeImages($experience, $images);

        return $experience->fresh();
    }

    private function updateExperience(User $user, Experience $experience, array $payload): TestResponse
    {
        return $this->actingAs($user, 'api')->post("/api/experiences/{$experience->id}", $payload, ['Accept' => 'application/json']);
    }

    public function test_modifica_testo_e_toglie_foto(): void
    {
        $walker = $this->createUserWithoutRole();
        $experience = $this->experienceWithPhotos($walker, 2);
        [$first, $second] = $experience->getMedia(Experience::MEDIA_COLLECTION)->all();
        $removedPath = $second->getPathRelativeToRoot();

        $this->updateExperience($walker, $experience, ['description' => 'nuovo', 'keep_media_ids' => json_encode([$first->id])])
            ->assertOk()
            ->assertJsonPath('description', 'nuovo')
            ->assertJsonCount(1, 'photos')
            ->assertJsonPath('photos.0.id', $first->id);

        $this->assertFalse(\Storage::disk('wmfe')->exists($removedPath));
    }

    public function test_modifica_aggiunge_foto_nuove(): void
    {
        $walker = $this->createUserWithoutRole();
        $experience = $this->experienceWithPhotos($walker, 1);
        $keep = $experience->getFirstMedia(Experience::MEDIA_COLLECTION)->id;

        $this->updateExperience($walker, $experience, [
            'keep_media_ids' => json_encode([$keep]),
            'images' => [UploadedFile::fake()->image('nuova.jpg', 90, 50)],
        ])->assertOk()->assertJsonCount(2, 'photos');
    }

    public function test_keep_media_ids_obbligatorio(): void
    {
        $walker = $this->createUserWithoutRole();
        $experience = $this->experienceWithPhotos($walker, 1);

        $this->updateExperience($walker, $experience, ['description' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['keep_media_ids']);
    }

    public function test_keep_vuoto_toglie_tutte_se_resta_testo(): void
    {
        $walker = $this->createUserWithoutRole();
        $experience = $this->experienceWithPhotos($walker, 2);

        $this->updateExperience($walker, $experience, ['description' => 'solo testo', 'keep_media_ids' => '[]'])
            ->assertOk()
            ->assertJsonCount(0, 'photos');
        $this->assertSame([], \Storage::disk('wmfe')->allFiles());
    }

    public function test_modifica_che_svuota_tutto_rifiutata(): void
    {
        $walker = $this->createUserWithoutRole();
        $experience = $this->experienceWithPhotos($walker, 1);

        $this->updateExperience($walker, $experience, ['description' => '  ', 'keep_media_ids' => '[]'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
        $this->assertSame(1, $experience->fresh()->getMedia(Experience::MEDIA_COLLECTION)->count());
    }

    public function test_modifica_oltre_sei_foto_rifiutata(): void
    {
        $walker = $this->createUserWithoutRole();
        $experience = $this->experienceWithPhotos($walker, 5);
        $keep = $experience->getMedia(Experience::MEDIA_COLLECTION)->pluck('id')->all();

        $this->updateExperience($walker, $experience, [
            'keep_media_ids' => json_encode($keep),
            'images' => [UploadedFile::fake()->image('a.jpg', 61, 62), UploadedFile::fake()->image('b.jpg', 71, 72)],
        ])->assertStatus(422)->assertJsonValidationErrors(['images']);
    }

    public function test_keep_media_ids_estranei_ignorati(): void
    {
        $walker = $this->createUserWithoutRole();
        $experience = $this->experienceWithPhotos($walker, 1);
        $foreign = $this->experienceWithPhotos($walker, 1)->getFirstMedia(Experience::MEDIA_COLLECTION);

        $this->updateExperience($walker, $experience, ['description' => 'x', 'keep_media_ids' => json_encode([$foreign->id])])
            ->assertOk()
            ->assertJsonCount(0, 'photos');
        $this->assertNotNull(Media::find($foreign->id));
    }

    public function test_modifica_esperienza_altrui_404(): void
    {
        $experience = $this->experienceWithPhotos($this->createUserWithoutRole(), 1);

        $this->updateExperience($this->createUserWithoutRole(), $experience, ['description' => 'x', 'keep_media_ids' => '[]'])
            ->assertStatus(404);
        $this->assertSame('testo', $experience->fresh()->description);
    }

    public function test_elimina_rimuove_file(): void
    {
        $walker = $this->createUserWithoutRole();
        $experience = $this->experienceWithPhotos($walker, 2);
        $this->assertNotSame([], \Storage::disk('wmfe')->allFiles());

        $this->actingAs($walker, 'api')->deleteJson("/api/experiences/{$experience->id}")->assertNoContent();

        $this->assertSame(0, Experience::count());
        $this->assertSame([], \Storage::disk('wmfe')->allFiles());
    }

    public function test_elimina_esperienza_altrui_404(): void
    {
        $experience = $this->experienceWithPhotos($this->createUserWithoutRole(), 1);

        $this->actingAs($this->createUserWithoutRole(), 'api')->deleteJson("/api/experiences/{$experience->id}")->assertStatus(404);
        $this->assertSame(1, Experience::count());
    }

    public function test_modifica_toglie_e_ricarica_la_stessa_foto(): void
    {
        $walker = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $experience = Experience::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id, 'description' => 'testo']);
        $photo = UploadedFile::fake()->image('a.jpg', 33, 21);
        app(ExperienceService::class)->storeImages($experience, [$photo]);
        $oldId = $experience->fresh()->getFirstMedia(Experience::MEDIA_COLLECTION)->id;

        // Stesso contenuto (stesso hash) ricaricato mentre la vecchia copia esce.
        $again = new UploadedFile($photo->getRealPath(), 'a-bis.jpg', 'image/jpeg', null, true);
        $this->updateExperience($walker, $experience->fresh(), ['keep_media_ids' => '[]', 'images' => [$again]])
            ->assertOk()
            ->assertJsonCount(1, 'photos');

        $this->assertNotSame($oldId, $experience->fresh()->getFirstMedia(Experience::MEDIA_COLLECTION)->id);
    }

    public function test_modifica_con_seconda_foto_corrotta_non_cambia_nulla(): void
    {
        $walker = $this->createUserWithoutRole();
        $experience = $this->experienceWithPhotos($walker, 1);
        $keep = $experience->getFirstMedia(Experience::MEDIA_COLLECTION)->id;
        $broken = $this->corruptJpeg();

        $this->updateExperience($walker, $experience, [
            'description' => 'cambiato',
            'keep_media_ids' => json_encode([$keep]),
            'images' => [UploadedFile::fake()->image('ok.jpg', 81, 41), $broken],
        ])->assertStatus(422)->assertJsonValidationErrors(['images']);

        $fresh = $experience->fresh();
        $this->assertSame([$keep], $fresh->getMedia(Experience::MEDIA_COLLECTION)->pluck('id')->all());
        $this->assertSame('testo', $fresh->description);
        $this->assertCount(1, \Storage::disk('wmfe')->allFiles());
    }

    public function test_id_non_numerico_da_404(): void
    {
        $walker = $this->createUserWithoutRole();

        $this->actingAs($walker, 'api')->post('/api/experiences/abc', ['keep_media_ids' => '[]'], ['Accept' => 'application/json'])
            ->assertStatus(404);
        $this->actingAs($walker, 'api')->deleteJson('/api/experiences/abc')->assertStatus(404);
    }

    public function test_rifiuta_immagine_oltre_8000_pixel(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $big = UploadedFile::fake()->image('lunga.png', 9000, 10);

        $this->postExperience($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$big]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0']);
        $this->assertSame(0, Experience::count());

        $experience = $this->experienceWithPhotos($walker, 0);
        $this->updateExperience($walker, $experience, ['keep_media_ids' => '[]', 'images' => [UploadedFile::fake()->image('lunga.png', 9000, 10)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0']);
    }

    public function test_interruttore_spento_da_404_anche_senza_autenticazione(): void
    {
        config(['camminiditalia.experiences.enabled' => false]);
        $walker = $this->createUserWithoutRole();

        $this->getJson('/api/experiences')->assertStatus(404);
        $this->postJson('/api/experiences', [])->assertStatus(404);
        $this->actingAs($walker, 'api')->getJson('/api/experiences')->assertStatus(404);
    }

    public function test_interruttore_acceso_senza_autenticazione_da_401(): void
    {
        config(['camminiditalia.experiences.enabled' => true]);

        $this->getJson('/api/experiences')->assertStatus(401);
        $this->deleteJson('/api/experiences/1')->assertStatus(401);
    }

    public function test_retry_con_uuid_esistente_rispetta_il_limite_di_sei_foto(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $first = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 20 + $i * 7, 20 + $i * 3), range(1, 5));

        $this->postExperience($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => $first])->assertStatus(201);

        // Retry: una delle 5 già salvate (doppione, non conta) più due nuove = 7 > 6.
        $again = new UploadedFile($first[0]->getRealPath(), 'p1.jpg', 'image/jpeg', null, true);
        $this->postExperience($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [
            $again, UploadedFile::fake()->image('n1.jpg', 91, 13), UploadedFile::fake()->image('n2.jpg', 92, 14),
        ]])->assertStatus(422)->assertJsonValidationErrors(['images']);

        $this->assertSame(1, Experience::count());
        $this->assertSame(5, Experience::first()->getMedia(Experience::MEDIA_COLLECTION)->count());

        // Doppione più una nuova = 6: accettato.
        $this->postExperience($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [
            $again, UploadedFile::fake()->image('n1.jpg', 91, 13),
        ]])->assertStatus(200)->assertJsonCount(6, 'photos');
    }

    public function test_index_filtra_per_registrazione(): void
    {
        $walker = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $linked = Experience::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id]);
        Experience::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id]);
        $ugc = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create(['app_id' => App::first()->id, 'user_id' => $walker->id]));
        $linked->ugcTracks()->attach($ugc->id);

        $this->actingAs($walker, 'api')->getJson("/api/experiences?ugc_track_id={$ugc->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $linked->id);
    }

    public function test_creazione_accoda_il_collegamento_alle_registrazioni(): void
    {
        $layer = $this->createLayer();

        $id = $this->postExperience($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'x'])
            ->assertStatus(201)
            ->json('id');

        Queue::assertPushed(LinkExperienceUgcTracksJob::class, fn (LinkExperienceUgcTracksJob $job) => $job->experienceId === $id);
    }

    public function test_nome_del_file_casuale_con_estensione_del_formato(): void
    {
        $layer = $this->createLayer();
        // PNG con nome ed estensione del cliente fuorvianti.
        $png = UploadedFile::fake()->image('mario-rossi.png', 30, 30);
        $upload = new UploadedFile($png->getRealPath(), 'Mario Rossi.jpeg', 'image/jpeg', null, true);

        $this->postExperience($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$upload]])
            ->assertStatus(201);

        $media = Experience::first()->getFirstMedia(Experience::MEDIA_COLLECTION);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $media->file_name);
        $this->assertStringNotContainsString('Mario', $media->getPathRelativeToRoot());
    }

    public function test_formato_non_ricodificabile_rifiutato_senza_salvare_l_originale(): void
    {
        $walker = $this->createUserWithoutRole();
        $experience = $this->experienceWithPhotos($walker, 0);
        $gif = UploadedFile::fake()->image('a.gif', 20, 20);

        try {
            app(ExperienceService::class)->storeImages($experience, [$gif]);
            $this->fail('Attesa ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('images', $e->errors());
        }

        $this->assertSame(0, $experience->fresh()->getMedia(Experience::MEDIA_COLLECTION)->count());
        $this->assertSame([], \Storage::disk('wmfe')->allFiles());
    }
}

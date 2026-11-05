<?php

namespace Tests\Feature;

use App\Jobs\LinkDiaryUgcTracksJob;
use App\Models\Diary;
use App\Models\EcTrack;
use App\Services\DiaryService;
use App\Services\DiaryTextService;
use Carbon\Carbon;
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

class DiaryApiTest extends TestCase
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

    private function postDiary(User $user, array $payload): TestResponse
    {
        return $this->actingAs($user, 'api')->postJson('/api/diaries', $payload);
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

    public function test_crea_salva_il_consenso_alla_pubblicazione(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();

        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'Bel giro', 'publication_consent' => '1'])
            ->assertStatus(201)
            ->assertJsonPath('publication_consent', true);

        $this->assertTrue(Diary::where('uuid', self::UUID_A)->first()->publication_consent);
    }

    public function test_crea_senza_consenso_vale_false(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();

        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'Bel giro'])
            ->assertStatus(201)
            ->assertJsonPath('publication_consent', false);
    }

    public function test_crea_con_valutazioni(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();

        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'Bel giro', 'rating_route' => '5', 'rating_hospitality' => '1'])
            ->assertStatus(201)
            ->assertJsonPath('rating_route', 5)
            ->assertJsonPath('rating_hospitality', 1)
            ->assertJsonPath('rating_difficulty', null);

        $diary = Diary::where('uuid', self::UUID_A)->first();
        $this->assertSame(5, $diary->rating_route);
        $this->assertSame(1, $diary->rating_hospitality);
        $this->assertNull($diary->rating_difficulty);
    }

    public function test_valutazioni_fuori_scala_rifiutate(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();

        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'Bel giro', 'rating_route' => '0', 'rating_difficulty' => '6'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rating_route', 'rating_difficulty']);
    }

    public function test_modifica_cambia_e_azzera_le_valutazioni(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $diary = Diary::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id, 'description' => 'Bel giro', 'rating_route' => 4, 'rating_hospitality' => 3]);

        $this->actingAs($walker, 'api')
            ->postJson("/api/diaries/{$diary->id}", ['description' => 'Bel giro', 'keep_media_ids' => '[]', 'rating_route' => '', 'rating_difficulty' => '2'])
            ->assertOk()
            ->assertJsonPath('rating_route', null)
            ->assertJsonPath('rating_hospitality', 3)
            ->assertJsonPath('rating_difficulty', 2);

        $fresh = $diary->fresh();
        $this->assertNull($fresh->rating_route);
        $this->assertSame(3, $fresh->rating_hospitality);
        $this->assertSame(2, $fresh->rating_difficulty);
    }

    public function test_modifica_cambia_il_consenso(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $diary = Diary::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id, 'description' => 'Bel giro']);

        $this->actingAs($walker, 'api')
            ->postJson("/api/diaries/{$diary->id}", ['description' => 'Bel giro', 'keep_media_ids' => '[]', 'publication_consent' => '1'])
            ->assertOk()
            ->assertJsonPath('publication_consent', true);

        $this->assertTrue($diary->fresh()->publication_consent);
    }

    public function test_crea_da_cammino(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();

        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'Bel giro'])
            ->assertStatus(201)
            ->assertJsonPath('layer.id', $layer->id)
            ->assertJsonPath('ec_track', null)
            ->assertJsonPath('description', '<p>Bel giro</p>');
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

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'ec_track_id' => $track->id, 'description' => 'x'])
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

        $this->postDiary($this->createUserWithoutRole(), [
            'uuid' => self::UUID_A, 'layer_id' => $layer->id, 'ec_track_id' => $track->id, 'description' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['ec_track_id']);
    }

    public function test_rifiuta_testo_vuoto_senza_foto(): void
    {
        $layer = $this->createLayer();

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    }

    public function test_crea_ripulisce_il_racconto_in_html(): void
    {
        $layer = $this->createLayer();

        $this->postDiary($this->createUserWithoutRole(), [
            'uuid' => self::UUID_A, 'layer_id' => $layer->id,
            'description' => '<h2>Giorno 1</h2><p onclick="x()">Ciao <strong>tu</strong></p><script>alert(1)</script>',
        ])->assertStatus(201)
            ->assertJsonPath('description', '<h2>Giorno 1</h2><p>Ciao <strong>tu</strong></p>');
    }

    public function test_crea_converte_il_testo_semplice_in_paragrafi(): void
    {
        $layer = $this->createLayer();

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => "Uno\n\nDue & tre"])
            ->assertStatus(201)
            ->assertJsonPath('description', '<p>Uno</p><p>Due &amp; tre</p>');
    }

    public function test_accetta_ventimila_caratteri_visibili(): void
    {
        $layer = $this->createLayer();

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => '<p>'.str_repeat('a', 20000).'</p>'])
            ->assertStatus(201);
    }

    public function test_rifiuta_oltre_ventimila_caratteri_visibili(): void
    {
        $layer = $this->createLayer();

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => '<p>'.str_repeat('a', 20001).'</p>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description' => 'The story can be at most 20,000 characters long.']);
    }

    public function test_rifiuta_html_troppo_lungo_anche_con_poco_testo(): void
    {
        $layer = $this->createLayer();

        $this->postDiary($this->createUserWithoutRole(), [
            'uuid' => self::UUID_A, 'layer_id' => $layer->id,
            'description' => str_repeat('<p><strong><em>a</em></strong></p>', 4000),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['description' => 'The story is too long.']);
    }

    public function test_rifiuta_solo_paragrafi_vuoti_senza_foto(): void
    {
        $layer = $this->createLayer();

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => '<p></p><p> </p><p><br></p>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description' => 'Write a text or add at least one photo.']);
    }

    public function test_rifiuta_racconto_non_testuale_con_422(): void
    {
        $layer = $this->createLayer();

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => ['x']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    }

    public function test_html_enorme_si_rifiuta_senza_ripulirlo(): void
    {
        $textService = $this->spy(DiaryTextService::class);
        $layer = $this->createLayer();

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => '<p>'.str_repeat('a', 300000).'</p>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description' => 'The story is too long.']);

        $textService->shouldNotHaveReceived('clean');
    }

    public function test_modifica_ripulisce_e_controlla_i_limiti(): void
    {
        $walker = $this->createUserWithoutRole();
        $diary = $this->diaryWithPhotos($walker, 0);

        $this->updateDiary($walker, $diary, ['description' => '<p>Ok <img src=x onerror=y></p>', 'keep_media_ids' => '[]'])
            ->assertOk()
            ->assertJsonPath('description', '<p>Ok </p>');

        $this->updateDiary($walker, $diary, ['description' => '<p>'.str_repeat('a', 20001).'</p>', 'keep_media_ids' => '[]'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description' => 'The story can be at most 20,000 characters long.']);

        $this->updateDiary($walker, $diary, ['description' => '<p></p>', 'keep_media_ids' => '[]'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description' => 'Write a text or add at least one photo.']);
    }

    public function test_crea_con_le_date_di_percorrenza(): void
    {
        $layer = $this->createLayer();

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'x', 'walk_start_date' => '2026-05-10', 'walk_end_date' => '2026-05-12'])
            ->assertStatus(201)
            ->assertJsonPath('walk_start_date', '2026-05-10')
            ->assertJsonPath('walk_end_date', '2026-05-12');
    }

    public function test_solo_la_data_di_inizio_lascia_la_fine_vuota(): void
    {
        $layer = $this->createLayer();

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'x', 'walk_start_date' => '2026-05-10'])
            ->assertStatus(201)
            ->assertJsonPath('walk_start_date', '2026-05-10')
            ->assertJsonPath('walk_end_date', null);
    }

    public function test_rifiuta_fine_prima_dell_inizio_e_date_future(): void
    {
        $layer = $this->createLayer();
        $user = $this->createUserWithoutRole();

        $this->postDiary($user, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'x', 'walk_start_date' => '2026-05-12', 'walk_end_date' => '2026-05-10'])
            ->assertStatus(422)->assertJsonValidationErrors(['walk_end_date']);

        $tomorrow = now('Europe/Rome')->addDay()->format('Y-m-d');
        $this->postDiary($user, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'x', 'walk_start_date' => $tomorrow])
            ->assertStatus(422)->assertJsonValidationErrors(['walk_start_date']);
    }

    public function test_oggi_si_calcola_nel_fuso_di_roma(): void
    {
        // 23:30 UTC del 9 maggio: a Roma è già il 10
        Carbon::setTestNow('2026-05-09 23:30:00 UTC');
        try {
            $layer = $this->createLayer();
            $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'x', 'walk_start_date' => '2026-05-10'])
                ->assertStatus(201);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_modifica_le_date_solo_se_inviate(): void
    {
        $walker = $this->createUserWithoutRole();
        $diary = $this->diaryWithPhotos($walker, 0);
        $diary->update(['walk_start_date' => '2026-05-10', 'walk_end_date' => '2026-05-12']);

        $this->updateDiary($walker, $diary, ['description' => 'nuovo', 'keep_media_ids' => '[]'])
            ->assertOk()->assertJsonPath('walk_start_date', '2026-05-10')->assertJsonPath('walk_end_date', '2026-05-12');

        $this->updateDiary($walker, $diary, ['keep_media_ids' => '[]', 'walk_start_date' => '', 'walk_end_date' => ''])
            ->assertOk()->assertJsonPath('walk_start_date', null)->assertJsonPath('walk_end_date', null);
    }

    public function test_rifiuta_sette_foto(): void
    {
        $layer = $this->createLayer();
        $images = array_map(fn ($i) => UploadedFile::fake()->image("f{$i}.jpg"), range(1, 7));

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => $images])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images']);
    }

    public function test_stesso_uuid_non_duplica_e_aggiunge_foto_mancanti(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $a = UploadedFile::fake()->image('a.jpg', 30, 30);
        $b = UploadedFile::fake()->image('b.jpg', 50, 40);

        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$a]])->assertStatus(201);

        $a2 = new UploadedFile($a->getRealPath(), 'image_0.jpg', 'image/jpeg', null, true);
        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$a2, $b]])
            ->assertStatus(200)
            ->assertJsonCount(2, 'photos');

        $this->assertSame(1, Diary::count());
        $this->assertCount(2, Diary::first()->getMedia(Diary::MEDIA_COLLECTION));
    }

    public function test_stesso_uuid_aggiorna_il_testo_corretto(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();

        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'primo testo'])->assertStatus(201);
        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'testo corretto'])
            ->assertStatus(200)
            ->assertJsonPath('description', '<p>testo corretto</p>');

        $this->assertSame(1, Diary::count());
    }

    public function test_errore_di_storage_non_lascia_un_esperienza_vuota(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $this->mock(DiaryService::class, function ($mock) {
            $mock->makePartial();
            $mock->shouldReceive('storeImages')->andThrow(new \RuntimeException('S3 non raggiungibile'));
        });

        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [UploadedFile::fake()->image('a.jpg', 30, 30)]])
            ->assertStatus(500);

        $this->assertSame(0, Diary::count());
    }

    public function test_il_limite_delle_esperienze_non_consuma_quello_del_passaporto(): void
    {
        $route = app('router')->getRoutes()->getByName('camminiditalia.api.diaries.store');

        $this->assertContains('throttle:diaries', $route->gatherMiddleware());
        $this->assertNotContains('throttle:10,1', $route->gatherMiddleware());
    }

    public function test_uuid_di_altro_utente_crea_nuova_esperienza(): void
    {
        $layer = $this->createLayer();

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'x'])->assertStatus(201);
        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'y'])->assertStatus(201);

        $this->assertSame(2, Diary::count());
    }

    public function test_index_del_cammino_include_le_tappe(): void
    {
        $walker = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $other = $this->createLayer();
        $track = $this->createTrack($walker->id, 'Tappa', $layer);

        $onRoute = Diary::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id]);
        $onStage = Diary::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id, 'ec_track_id' => $track->id]);
        Diary::factory()->create(['user_id' => $walker->id, 'layer_id' => $other->id]);

        $ids = fn (TestResponse $r) => collect($r->json('data'))->pluck('id')->sort()->values()->all();

        $r = $this->actingAs($walker, 'api')->getJson("/api/diaries?layer_id={$layer->id}")->assertOk();
        $this->assertSame([$onRoute->id, $onStage->id], $ids($r));

        $r = $this->actingAs($walker, 'api')->getJson("/api/diaries?ec_track_id={$track->id}")->assertOk();
        $this->assertSame([$onStage->id], $ids($r));
    }

    public function test_index_non_mostra_esperienze_altrui(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        Diary::factory()->create(['user_id' => $this->createUserWithoutRole()->id, 'layer_id' => $layer->id]);

        $this->actingAs($walker, 'api')->getJson('/api/diaries')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_non_autenticato_riceve_401(): void
    {
        $this->getJson('/api/diaries')->assertStatus(401);
        $this->postJson('/api/diaries', [])->assertStatus(401);
    }

    public function test_i_media_portano_l_app_id_del_cammino(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();

        $this->postDiary($walker, [
            'uuid' => self::UUID_A, 'layer_id' => $layer->id,
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertStatus(201);

        $media = Diary::first()->getFirstMedia(Diary::MEDIA_COLLECTION);
        $this->assertSame((int) Layer::find($layer->id)->app_id, (int) $media->app_id);
    }

    public function test_la_foto_viene_salvata_senza_exif(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $upload = $this->jpegWithExif();

        // Verifica del presupposto: il file di partenza ha davvero l'EXIF.
        $this->assertSame(6, exif_read_data($upload->getRealPath())['Orientation'] ?? null);

        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$upload]])
            ->assertStatus(201);

        $media = Diary::first()->getFirstMedia(Diary::MEDIA_COLLECTION);
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

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$svg]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0']);
        $this->assertSame(0, Diary::count());
    }

    public function test_jpg_corrotto_da_422_e_non_crea_righe(): void
    {
        $layer = $this->createLayer();
        $broken = $this->corruptJpeg();

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$broken]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images']);
        $this->assertSame(0, Diary::count());
    }

    public function test_tappa_senza_cammino_rifiutata(): void
    {
        $track = $this->createTrack($this->createUserWithoutRole()->id, 'Orfana');

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'ec_track_id' => $track->id, 'description' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ec_track_id']);
        $this->assertSame(0, Diary::count());
    }

    public function test_collisione_sull_uuid_rilegge_la_riga_esistente(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $existing = Diary::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id, 'uuid' => self::UUID_A]);

        // Simula la corsa: la riga compare fra il controllo e l'insert, createOrFirst deve rileggerla.
        $found = Diary::createOrFirst(['user_id' => $walker->id, 'uuid' => self::UUID_A], ['layer_id' => $layer->id]);

        $this->assertTrue($found->is($existing));
        $this->assertFalse($found->wasRecentlyCreated);
        $this->assertSame(1, Diary::count());
    }

    public function test_due_foto_identiche_nella_stessa_richiesta_diventano_un_media(): void
    {
        $layer = $this->createLayer();
        $a = UploadedFile::fake()->image('a.jpg', 30, 30);
        $b = new UploadedFile($a->getRealPath(), 'b.jpg', 'image/jpeg', null, true);

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$a, $b]])
            ->assertStatus(201)
            ->assertJsonCount(1, 'photos');
    }

    public function test_rollback_con_buona_e_corrotta_non_lascia_righe_ne_file(): void
    {
        $layer = $this->createLayer();
        $good = UploadedFile::fake()->image('ok.jpg', 30, 30);
        $broken = $this->corruptJpeg();

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$good, $broken]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images']);

        $this->assertSame(0, Diary::count());
        $this->assertSame([], \Storage::disk('wmfe')->allFiles());
    }

    /**
     * Esperienza dell'utente con `$n` foto distinte già salvate.
     */
    private function diaryWithPhotos(User $user, int $n, ?string $description = 'testo'): Diary
    {
        $layer = $this->createLayer();
        $diary = Diary::factory()->create(['user_id' => $user->id, 'layer_id' => $layer->id, 'description' => $description]);
        $images = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 20 + $i * 7, 20 + $i * 3), $n > 0 ? range(1, $n) : []);
        app(DiaryService::class)->storeImages($diary, $images);

        return $diary->fresh();
    }

    private function updateDiary(User $user, Diary $diary, array $payload): TestResponse
    {
        return $this->actingAs($user, 'api')->post("/api/diaries/{$diary->id}", $payload, ['Accept' => 'application/json']);
    }

    public function test_modifica_testo_e_toglie_foto(): void
    {
        $walker = $this->createUserWithoutRole();
        $diary = $this->diaryWithPhotos($walker, 2);
        [$first, $second] = $diary->getMedia(Diary::MEDIA_COLLECTION)->all();
        $removedPath = $second->getPathRelativeToRoot();

        $this->updateDiary($walker, $diary, ['description' => 'nuovo', 'keep_media_ids' => json_encode([$first->id])])
            ->assertOk()
            ->assertJsonPath('description', '<p>nuovo</p>')
            ->assertJsonCount(1, 'photos')
            ->assertJsonPath('photos.0.id', $first->id);

        $this->assertFalse(\Storage::disk('wmfe')->exists($removedPath));
    }

    public function test_modifica_aggiunge_foto_nuove(): void
    {
        $walker = $this->createUserWithoutRole();
        $diary = $this->diaryWithPhotos($walker, 1);
        $keep = $diary->getFirstMedia(Diary::MEDIA_COLLECTION)->id;

        $this->updateDiary($walker, $diary, [
            'keep_media_ids' => json_encode([$keep]),
            'images' => [UploadedFile::fake()->image('nuova.jpg', 90, 50)],
        ])->assertOk()->assertJsonCount(2, 'photos');
    }

    public function test_keep_media_ids_obbligatorio(): void
    {
        $walker = $this->createUserWithoutRole();
        $diary = $this->diaryWithPhotos($walker, 1);

        $this->updateDiary($walker, $diary, ['description' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['keep_media_ids']);
    }

    public function test_keep_vuoto_toglie_tutte_se_resta_testo(): void
    {
        $walker = $this->createUserWithoutRole();
        $diary = $this->diaryWithPhotos($walker, 2);

        $this->updateDiary($walker, $diary, ['description' => 'solo testo', 'keep_media_ids' => '[]'])
            ->assertOk()
            ->assertJsonCount(0, 'photos');
        $this->assertSame([], \Storage::disk('wmfe')->allFiles());
    }

    public function test_modifica_che_svuota_tutto_rifiutata(): void
    {
        $walker = $this->createUserWithoutRole();
        $diary = $this->diaryWithPhotos($walker, 1);

        $this->updateDiary($walker, $diary, ['description' => '  ', 'keep_media_ids' => '[]'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
        $this->assertCount(1, $diary->fresh()->getMedia(Diary::MEDIA_COLLECTION));
    }

    public function test_modifica_oltre_sei_foto_rifiutata(): void
    {
        $walker = $this->createUserWithoutRole();
        $diary = $this->diaryWithPhotos($walker, 5);
        $keep = $diary->getMedia(Diary::MEDIA_COLLECTION)->pluck('id')->all();

        $this->updateDiary($walker, $diary, [
            'keep_media_ids' => json_encode($keep),
            'images' => [UploadedFile::fake()->image('a.jpg', 61, 62), UploadedFile::fake()->image('b.jpg', 71, 72)],
        ])->assertStatus(422)->assertJsonValidationErrors(['images']);
    }

    public function test_keep_media_ids_estranei_ignorati(): void
    {
        $walker = $this->createUserWithoutRole();
        $diary = $this->diaryWithPhotos($walker, 1);
        $foreign = $this->diaryWithPhotos($walker, 1)->getFirstMedia(Diary::MEDIA_COLLECTION);

        $this->updateDiary($walker, $diary, ['description' => 'x', 'keep_media_ids' => json_encode([$foreign->id])])
            ->assertOk()
            ->assertJsonCount(0, 'photos');
        $this->assertNotNull(Media::find($foreign->id));
    }

    public function test_modifica_esperienza_altrui_404(): void
    {
        $diary = $this->diaryWithPhotos($this->createUserWithoutRole(), 1);

        $this->updateDiary($this->createUserWithoutRole(), $diary, ['description' => 'x', 'keep_media_ids' => '[]'])
            ->assertStatus(404);
        $this->assertSame('testo', $diary->fresh()->description);
    }

    public function test_elimina_rimuove_file(): void
    {
        $walker = $this->createUserWithoutRole();
        $diary = $this->diaryWithPhotos($walker, 2);
        $this->assertNotSame([], \Storage::disk('wmfe')->allFiles());

        $this->actingAs($walker, 'api')->deleteJson("/api/diaries/{$diary->id}")->assertNoContent();

        $this->assertSame(0, Diary::count());
        $this->assertSame([], \Storage::disk('wmfe')->allFiles());
    }

    public function test_elimina_esperienza_altrui_404(): void
    {
        $diary = $this->diaryWithPhotos($this->createUserWithoutRole(), 1);

        $this->actingAs($this->createUserWithoutRole(), 'api')->deleteJson("/api/diaries/{$diary->id}")->assertStatus(404);
        $this->assertSame(1, Diary::count());
    }

    public function test_modifica_toglie_e_ricarica_la_stessa_foto(): void
    {
        $walker = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $diary = Diary::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id, 'description' => 'testo']);
        $photo = UploadedFile::fake()->image('a.jpg', 33, 21);
        app(DiaryService::class)->storeImages($diary, [$photo]);
        $oldId = $diary->fresh()->getFirstMedia(Diary::MEDIA_COLLECTION)->id;

        // Stesso contenuto (stesso hash) ricaricato mentre la vecchia copia esce.
        $again = new UploadedFile($photo->getRealPath(), 'a-bis.jpg', 'image/jpeg', null, true);
        $this->updateDiary($walker, $diary->fresh(), ['keep_media_ids' => '[]', 'images' => [$again]])
            ->assertOk()
            ->assertJsonCount(1, 'photos');

        $this->assertNotSame($oldId, $diary->fresh()->getFirstMedia(Diary::MEDIA_COLLECTION)->id);
    }

    public function test_modifica_con_seconda_foto_corrotta_non_cambia_nulla(): void
    {
        $walker = $this->createUserWithoutRole();
        $diary = $this->diaryWithPhotos($walker, 1);
        $keep = $diary->getFirstMedia(Diary::MEDIA_COLLECTION)->id;
        $broken = $this->corruptJpeg();

        $this->updateDiary($walker, $diary, [
            'description' => 'cambiato',
            'keep_media_ids' => json_encode([$keep]),
            'images' => [UploadedFile::fake()->image('ok.jpg', 81, 41), $broken],
        ])->assertStatus(422)->assertJsonValidationErrors(['images']);

        $fresh = $diary->fresh();
        $this->assertSame([$keep], $fresh->getMedia(Diary::MEDIA_COLLECTION)->pluck('id')->all());
        $this->assertSame('testo', $fresh->description);
        $this->assertCount(1, \Storage::disk('wmfe')->allFiles());
    }

    public function test_id_non_numerico_da_404(): void
    {
        $walker = $this->createUserWithoutRole();

        $this->actingAs($walker, 'api')->post('/api/diaries/abc', ['keep_media_ids' => '[]'], ['Accept' => 'application/json'])
            ->assertStatus(404);
        $this->actingAs($walker, 'api')->deleteJson('/api/diaries/abc')->assertStatus(404);
    }

    public function test_rifiuta_immagine_oltre_8000_pixel(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $big = UploadedFile::fake()->image('lunga.png', 9000, 10);

        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$big]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0']);
        $this->assertSame(0, Diary::count());

        $diary = $this->diaryWithPhotos($walker, 0);
        $this->updateDiary($walker, $diary, ['keep_media_ids' => '[]', 'images' => [UploadedFile::fake()->image('lunga.png', 9000, 10)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0']);
    }

    public function test_interruttore_spento_da_404_anche_senza_autenticazione(): void
    {
        config(['camminiditalia.diaries.enabled' => false]);
        $walker = $this->createUserWithoutRole();

        $this->getJson('/api/diaries')->assertStatus(404);
        $this->postJson('/api/diaries', [])->assertStatus(404);
        $this->actingAs($walker, 'api')->getJson('/api/diaries')->assertStatus(404);
    }

    public function test_interruttore_acceso_senza_autenticazione_da_401(): void
    {
        config(['camminiditalia.diaries.enabled' => true]);

        $this->getJson('/api/diaries')->assertStatus(401);
        $this->deleteJson('/api/diaries/1')->assertStatus(401);
    }

    public function test_retry_con_uuid_esistente_rispetta_il_limite_di_sei_foto(): void
    {
        $layer = $this->createLayer();
        $walker = $this->createUserWithoutRole();
        $first = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 20 + $i * 7, 20 + $i * 3), range(1, 5));

        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => $first])->assertStatus(201);

        // Retry: una delle 5 già salvate (doppione, non conta) più due nuove = 7 > 6.
        $again = new UploadedFile($first[0]->getRealPath(), 'p1.jpg', 'image/jpeg', null, true);
        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [
            $again, UploadedFile::fake()->image('n1.jpg', 91, 13), UploadedFile::fake()->image('n2.jpg', 92, 14),
        ]])->assertStatus(422)->assertJsonValidationErrors(['images']);

        $this->assertSame(1, Diary::count());
        $this->assertCount(5, Diary::first()->getMedia(Diary::MEDIA_COLLECTION));

        // Doppione più una nuova = 6: accettato.
        $this->postDiary($walker, ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [
            $again, UploadedFile::fake()->image('n1.jpg', 91, 13),
        ]])->assertStatus(200)->assertJsonCount(6, 'photos');
    }

    public function test_index_filtra_per_registrazione(): void
    {
        $walker = $this->createUserWithoutRole();
        $layer = $this->createLayer();
        $linked = Diary::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id]);
        Diary::factory()->create(['user_id' => $walker->id, 'layer_id' => $layer->id]);
        $ugc = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create(['app_id' => App::first()->id, 'user_id' => $walker->id]));
        $linked->ugcTracks()->attach($ugc->id);

        $this->actingAs($walker, 'api')->getJson("/api/diaries?ugc_track_id={$ugc->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $linked->id);
    }

    public function test_creazione_accoda_il_collegamento_alle_registrazioni(): void
    {
        $layer = $this->createLayer();

        $id = $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'description' => 'x'])
            ->assertStatus(201)
            ->json('id');

        Queue::assertPushed(LinkDiaryUgcTracksJob::class, fn (LinkDiaryUgcTracksJob $job) => $job->diaryId === $id);
    }

    public function test_nome_del_file_casuale_con_estensione_del_formato(): void
    {
        $layer = $this->createLayer();
        // PNG con nome ed estensione del cliente fuorvianti.
        $png = UploadedFile::fake()->image('mario-rossi.png', 30, 30);
        $upload = new UploadedFile($png->getRealPath(), 'Mario Rossi.jpeg', 'image/jpeg', null, true);

        $this->postDiary($this->createUserWithoutRole(), ['uuid' => self::UUID_A, 'layer_id' => $layer->id, 'images' => [$upload]])
            ->assertStatus(201);

        $media = Diary::first()->getFirstMedia(Diary::MEDIA_COLLECTION);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $media->file_name);
        $this->assertStringNotContainsString('Mario', $media->getPathRelativeToRoot());
    }

    public function test_formato_non_ricodificabile_rifiutato_senza_salvare_l_originale(): void
    {
        $walker = $this->createUserWithoutRole();
        $diary = $this->diaryWithPhotos($walker, 0);
        $gif = UploadedFile::fake()->image('a.gif', 20, 20);

        try {
            app(DiaryService::class)->storeImages($diary, [$gif]);
            $this->fail('Attesa ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('images', $e->errors());
        }

        $this->assertCount(0, $diary->fresh()->getMedia(Diary::MEDIA_COLLECTION));
        $this->assertSame([], \Storage::disk('wmfe')->allFiles());
    }
}

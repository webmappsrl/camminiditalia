<?php

namespace Tests\Feature;

use App\Models\PassportStage;
use App\Models\User;
use App\Services\StageProgressService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Le tappe in corso non sono validazioni (oc:8165).
 *
 * Dal momento in cui `passport_stages` ospita anche l'avanzamento parziale, tutto ciò che
 * in Nova elenca «tappe validate» deve lasciarle fuori: il gestore le leggerebbe come percorse,
 * anche nel pannello dove decide se rilasciare una certificazione.
 */
class PassportStageNovaScopeTest extends TestCase
{
    use DatabaseTransactions;

    private App $app_;

    private User $admin;

    private User $walker;

    private Layer $layer;

    private EcTrack $walked;

    private EcTrack $inProgress;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();

        $this->app_ = App::factory()->createQuietly();
        $this->admin = User::factory()->create(['app_id' => $this->app_->id]);
        $this->admin->assignRole('Administrator');
        $this->walker = User::factory()->create(['app_id' => $this->app_->id]);
        $this->layer = Layer::factory()->create(['app_id' => $this->app_->id, 'user_id' => $this->admin->id]);

        $this->walked = $this->stage();
        $this->inProgress = $this->stage();

        PassportStage::create([
            'user_id' => $this->walker->id,
            'ec_track_id' => $this->walked->id,
            'layer_id' => $this->layer->id,
            'source' => PassportStage::SOURCE_GPS,
            'validated_at' => now(),
            'progress' => 100,
        ]);

        PassportStage::create([
            'user_id' => $this->walker->id,
            'ec_track_id' => $this->inProgress->id,
            'layer_id' => $this->layer->id,
            'source' => PassportStage::SOURCE_GPS,
            'validated_at' => null,
            'progress' => 74,
        ]);
    }

    private function stage(): EcTrack
    {
        $track = EcTrack::withoutEvents(fn () => EcTrack::factory()->create([
            'app_id' => $this->app_->id,
            'user_id' => $this->admin->id,
            'properties' => [],
            'osmid' => null,
        ]));

        DB::table('layerables')->insert([
            'layer_id' => $this->layer->id,
            'layerable_id' => $track->id,
            'layerable_type' => (new (config('wm-package.ec_track_model')))->getMorphClass(),
        ]);

        return $track;
    }

    public function test_the_list_hides_stages_in_progress()
    {
        $service = app(StageProgressService::class);
        $query = $service->scopeVisibleTo(PassportStage::query(), $this->admin);

        $ids = $query->pluck('ec_track_id')->all();

        $this->assertContains($this->walked->id, $ids);
        $this->assertNotContains($this->inProgress->id, $ids, 'una tappa al 74% non è una validazione');
    }

    public function test_the_summary_does_not_count_stages_in_progress()
    {
        $riga = app(StageProgressService::class)
            ->summaryQuery($this->admin)
            ->where('user_id', $this->walker->id)
            ->first();

        $this->assertSame(1, (int) $riga->getAttribute('validated'), 'solo la tappa davvero percorsa');
        $this->assertSame(2, (int) $riga->getAttribute('total'));
    }

    public function test_the_nova_list_hides_stages_in_progress()
    {
        $this->actingAs($this->admin);

        $response = $this->getJson('/nova-api/validated-ec-tracks');

        $response->assertOk();
        $ids = collect($response->json('resources'))
            ->map(fn ($r) => collect($r['fields'])->firstWhere('attribute', 'ec_track_id')['value'] ?? null)
            ->filter()
            ->all();

        $this->assertNotContains($this->inProgress->id, $ids);
    }
}

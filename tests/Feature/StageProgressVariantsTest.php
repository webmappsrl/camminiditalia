<?php

namespace Tests\Feature;

use App\Models\EcTrackVariant;
use App\Models\PassportStage;
use App\Services\StageProgressService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

/**
 * Il cammino si conta sui gruppi, non sulle tappe (oc:8165).
 *
 * Scenario reale: Via Vandelli ha nove tappe, fra cui due coppie di rami di partenza
 * (`Tappa 01M`/`01S` e `Tappa 02M`/`02S`) che convergono a Pavullo. Chi parte da Modena non
 * percorrerà mai il ramo da Sassuolo: contando le tappe si fermerebbe a sette su nove per
 * sempre, contando i gruppi arriva al 100%.
 */
class StageProgressVariantsTest extends TestCase
{
    use DatabaseTransactions;

    private App $app_;

    private User $user;

    private Layer $layer;

    private StageProgressService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app_ = App::factory()->createQuietly();
        $this->user = User::factory()->create(['app_id' => $this->app_->id]);
        $this->layer = Layer::factory()->create(['app_id' => $this->app_->id, 'user_id' => $this->user->id]);
        $this->service = app(StageProgressService::class);
    }

    private function stage(?Layer $layer = null): EcTrack
    {
        $track = EcTrack::withoutEvents(fn () => EcTrack::factory()->create([
            'app_id' => $this->app_->id,
            'user_id' => $this->user->id,
            'properties' => [],
            'osmid' => null,
        ]));

        DB::table('layerables')->insert([
            'layer_id' => ($layer ?? $this->layer)->id,
            'layerable_id' => $track->id,
            'layerable_type' => (new (config('wm-package.ec_track_model')))->getMorphClass(),
        ]);

        return $track;
    }

    /** Tappa con una distanza nota, come la scrive il gestore a mano. */
    private function stageWithDistance(float $km): EcTrack
    {
        $track = $this->stage();
        $track->forceFill(['properties' => ['manual_data' => ['distance' => (string) $km]]])->saveQuietly();

        return $track;
    }

    private function validate(EcTrack $stage, ?Layer $layer = null): void
    {
        PassportStage::create([
            'user_id' => $this->user->id,
            'ec_track_id' => $stage->id,
            'layer_id' => ($layer ?? $this->layer)->id,
            'source' => PassportStage::SOURCE_GPS,
            'validated_at' => now(),
            'progress' => 100,
        ]);
    }

    private function partial(EcTrack $stage, int $progress): void
    {
        PassportStage::create([
            'user_id' => $this->user->id,
            'ec_track_id' => $stage->id,
            'layer_id' => $this->layer->id,
            'source' => PassportStage::SOURCE_GPS,
            'validated_at' => null,
            'progress' => $progress,
        ]);
    }

    /** Le nove tappe di Via Vandelli, con i due gruppi di rami di partenza. */
    private function viaVandelli(): array
    {
        $t = [];
        foreach (['01M', '01S', '02M', '02S', '03', '04', '05', '06', '07'] as $nome) {
            $t[$nome] = $this->stage();
        }

        EcTrackVariant::create(['ec_track_id' => $t['01S']->id, 'main_ec_track_id' => $t['01M']->id]);
        EcTrackVariant::create(['ec_track_id' => $t['02S']->id, 'main_ec_track_id' => $t['02M']->id]);

        return $t;
    }

    public function test_counts_seven_groups_and_not_nine_stages()
    {
        $this->viaVandelli();

        $progresso = $this->service->progressFor($this->user, $this->layer);

        $this->assertSame(7, $progresso['total']);
    }

    public function test_a_single_branch_reaches_one_hundred_percent()
    {
        $t = $this->viaVandelli();

        foreach (['01M', '02M', '03', '04', '05', '06', '07'] as $nome) {
            $this->validate($t[$nome]);
        }

        $progresso = $this->service->progressFor($this->user, $this->layer);

        $this->assertSame(7, $progresso['validated']);
        $this->assertSame(100, $progresso['percentage']);
        $this->assertTrue($progresso['completed']);
    }

    public function test_any_stage_of_the_group_satisfies_it()
    {
        $t = $this->viaVandelli();

        // L'altro ramo: chi parte da Sassuolo percorre 01S e 02S.
        foreach (['01S', '02S', '03', '04', '05', '06', '07'] as $nome) {
            $this->validate($t[$nome]);
        }

        $progresso = $this->service->progressFor($this->user, $this->layer);

        $this->assertSame(7, $progresso['validated']);
        $this->assertTrue($progresso['completed']);
    }

    public function test_a_variant_counts_alone_in_a_route_without_its_main_stage()
    {
        $main = $this->stage();
        $otherRoute = Layer::factory()->create(['app_id' => $this->app_->id, 'user_id' => $this->user->id]);

        // La variante appartiene a entrambi i cammini, la principale solo al primo.
        $variant = $this->stage();
        DB::table('layerables')->insert([
            'layer_id' => $otherRoute->id,
            'layerable_id' => $variant->id,
            'layerable_type' => (new (config('wm-package.ec_track_model')))->getMorphClass(),
        ]);
        EcTrackVariant::create(['ec_track_id' => $variant->id, 'main_ec_track_id' => $main->id]);

        // Nel cammino che non contiene la principale, la variante è una tappa come le altre.
        $this->assertSame(1, $this->service->progressFor($this->user, $otherRoute)['total']);

        // Nel cammino che le contiene entrambe, valgono per una sola.
        $this->assertSame(1, $this->service->progressFor($this->user, $this->layer)['total']);
    }

    public function test_group_kilometres_are_those_of_the_main_stage()
    {
        // I due rami di partenza di Via Vandelli differiscono di 19 km: il totale del cammino
        // deve essere quello del percorso ufficiale, non quello del ramo più lungo.
        $main = $this->stageWithDistance(27.3);
        $variant = $this->stageWithDistance(20.1);
        EcTrackVariant::create(['ec_track_id' => $variant->id, 'main_ec_track_id' => $main->id]);

        $progress = $this->service->progressFor($this->user, $this->layer);

        $this->assertSame(1, $progress['total'], 'un gruppo solo');
        $this->assertSame(27.3, $progress['km_total'], 'i km sono quelli della principale');
    }

    public function test_returns_the_real_percentage_of_a_stage_in_progress()
    {
        $stage = $this->stage();
        $this->partial($stage, 74);

        $progresso = $this->service->progressFor($this->user, $this->layer);
        $stage = collect($progresso['tracks'])->firstWhere('id', $stage->id);

        $this->assertSame(74, $stage['progress']);
        $this->assertSame(StageProgressService::STATUS_NOT_VALIDATED, $stage['status']);
    }

    public function test_a_validated_stage_stays_at_one_hundred()
    {
        $stage = $this->stage();
        $this->validate($stage);

        $progresso = $this->service->progressFor($this->user, $this->layer);
        $stage = collect($progresso['tracks'])->firstWhere('id', $stage->id);

        $this->assertSame(100, $stage['progress']);
        $this->assertSame(StageProgressService::STATUS_VALIDATED, $stage['status']);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\SendUgcReportMailJob;
use App\Mail\NewUgcReportMail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class UgcNotificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();

        if (App::count() === 0) {
            App::factory()->create();
        }
    }

    // -------------------------------------------------------------------------
    // Job dispatch
    // -------------------------------------------------------------------------

    public function test_creating_ugc_poi_with_layer_id_dispatches_notification_job(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $layer = Layer::factory()->create(['user_id' => $owner->id]);

        UgcPoi::factory()->create([
            'properties' => ['layer_id' => $layer->id],
        ]);

        Queue::assertPushed(SendUgcReportMailJob::class);
    }

    public function test_creating_ugc_poi_without_layer_id_populates_it_and_dispatches_job(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $layer = Layer::factory()->create(['user_id' => $owner->id]);
        $track = EcTrack::factory()->create();

        // Traccia vicina al punto UGC (Roma) — 3D obbligatorio
        DB::table('ec_tracks')->where('id', $track->id)->update([
            'geometry' => DB::raw("ST_Force3D(ST_GeomFromText('MULTILINESTRING((12.49 41.89, 12.50 41.90, 12.51 41.91))', 4326))"),
        ]);

        // Associa la traccia al layer tramite layerables
        DB::table('layerables')->insert([
            'layer_id' => $layer->id,
            'layerable_type' => 'App\Models\EcTrack',
            'layerable_id' => $track->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Aggiorna geometry del layer (bbox delle tracce)
        DB::table('layers')->where('id', $layer->id)->update([
            'geometry' => DB::raw("ST_GeomFromText('POLYGON((12.48 41.88, 12.52 41.88, 12.52 41.92, 12.48 41.92, 12.48 41.88))', 4326)"),
        ]);

        // UgcPoi senza layer_id, geometria vicina alla traccia — 3D obbligatorio
        $ugcPoi = UgcPoi::factory()->create(['properties' => []]);
        DB::table('ugc_pois')->where('id', $ugcPoi->id)->update([
            'geometry' => DB::raw("ST_Force3D(ST_GeomFromText('POINT(12.50 41.90)', 4326))"),
        ]);
        $ugcPoi->refresh();

        // Simula il created() dell'observer
        (new \App\Observers\UgcObserver)->created($ugcPoi);
        $ugcPoi->refresh();

        $this->assertEquals($layer->id, $ugcPoi->properties['layer_id']);
        Queue::assertPushed(SendUgcReportMailJob::class);
    }

    public function test_creating_ugc_poi_far_from_any_track_does_not_dispatch_job(): void
    {
        Queue::fake();

        // UgcPoi nel mezzo del Pacifico, nessuna traccia vicina — 3D obbligatorio
        $ugcPoi = UgcPoi::factory()->create(['properties' => []]);
        DB::table('ugc_pois')->where('id', $ugcPoi->id)->update([
            'geometry' => DB::raw("ST_Force3D(ST_GeomFromText('POINT(-150.0 0.0)', 4326))"),
        ]);
        $ugcPoi->refresh();

        (new \App\Observers\UgcObserver)->created($ugcPoi);

        Queue::assertNotPushed(SendUgcReportMailJob::class);
    }

    // -------------------------------------------------------------------------
    // Mail sending
    // -------------------------------------------------------------------------

    public function test_job_sends_mail_to_layer_owner(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['email' => 'gestore@test.com']);
        $layer = Layer::factory()->create(['user_id' => $owner->id]);
        $ugcPoi = UgcPoi::factory()->create(['properties' => ['layer_id' => $layer->id]]);

        (new SendUgcReportMailJob($ugcPoi, $layer))->handle();

        Mail::assertSent(NewUgcReportMail::class, fn ($mail) => $mail->hasTo('gestore@test.com'));
    }

    public function test_job_does_not_send_mail_when_layer_has_no_owner(): void
    {
        Mail::fake();

        $layer = Layer::factory()->create(['user_id' => null]);
        $ugcPoi = UgcPoi::factory()->create(['properties' => ['layer_id' => $layer->id]]);

        (new SendUgcReportMailJob($ugcPoi, $layer))->handle();

        Mail::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Mail content
    // -------------------------------------------------------------------------

    public function test_mail_contains_ugc_data_and_nova_link(): void
    {
        $layer = Layer::factory()->create(['name' => 'Via Francigena Test']);
        $ugcPoi = UgcPoi::factory()->create([
            'name' => 'Sentiero franato',
            'properties' => [
                'layer_id' => $layer->id,
                'form' => [
                    'title' => 'Sentiero franato',
                    'description' => 'Frana in corso',
                    'waypointtype' => 'danger',
                ],
            ],
        ]);

        $mail = new NewUgcReportMail($ugcPoi, $layer);

        $mail->assertHasSubject('Nuova segnalazione su Via Francigena Test');
        $mail->assertSeeInHtml('Sentiero franato');
        $mail->assertSeeInHtml('Frana in corso');
        $mail->assertSeeInHtml('danger');
        $mail->assertSeeInHtml('Via Francigena Test');
        $mail->assertSeeInHtml('/nova/resources/ugc-pois/'.$ugcPoi->id);
    }
}

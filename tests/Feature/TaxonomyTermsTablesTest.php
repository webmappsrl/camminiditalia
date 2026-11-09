<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Wm\WmPackage\Models\EcTrack;

/**
 * Le tabelle del modello unico delle classificazioni (oc:8910, arrivate col package 1.6.1)
 * non sono opzionali per questo progetto, anche se Cammini d'Italia non adotta quel modello:
 * LayerService, gli Observer di EcTrack/EcPoi/Layer, EcTrack e App le interrogano nei percorsi
 * normali, quindi senza di esse salvare un contenuto fallisce con un errore SQL (oc:8951).
 */
class TaxonomyTermsTablesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Salvare una traccia innesca i job di ricalcolo del package, che interrogano
        // osmfeatures: senza questi fake la suite dipenderebbe dalla rete.
        Queue::fake();
        Http::fake();
    }

    public function test_taxonomy_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('taxonomy_terms'));
        $this->assertTrue(Schema::hasTable('taxonomy_termables'));
        $this->assertTrue(Schema::hasTable('app_taxonomy_term'));
    }

    public function test_saving_an_ec_track_does_not_fail_with_the_new_tables(): void
    {
        $track = EcTrack::factory()->create(['properties' => []]);

        $track->name = 'oc:8951 smoke';
        $track->save();

        $this->assertDatabaseHas(
            config('wm-package.ec_track_table', 'ec_tracks'),
            ['id' => $track->id]
        );
    }
}

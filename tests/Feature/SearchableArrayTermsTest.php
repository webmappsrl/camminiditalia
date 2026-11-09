<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Models\EcTrack;

/**
 * Il package 1.6.1 aggiunge `terms` a quello che finisce nell'indice di ricerca (oc:8910).
 * Per Cammini d'Italia, che non adotta il modello unico delle classificazioni, quella chiave
 * deve restare vuota e soprattutto non deve portarsi via `taxonomyWheres`, che è la fonte da
 * cui il frontend legge le zone di una tappa (oc:8951).
 */
class SearchableArrayTermsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
    }

    public function test_searchable_array_keeps_wheres_and_adds_empty_terms(): void
    {
        $track = EcTrack::factory()->create([
            'properties' => ['taxonomy_where' => []],
        ]);

        $searchable = $track->toSearchableArray();

        $this->assertArrayHasKey('taxonomyWheres', $searchable);
        $this->assertArrayHasKey('terms', $searchable);
        $this->assertSame([], $searchable['terms']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Diary;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Nova;
use Tests\Feature\Helpers\LayerTestHelpers;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Racconto del diario in Nova (oc:8169, ciclo 3): formattato nel dettaglio, estratto nell'elenco,
 * ricerca che ignora i tag.
 */
class DiaryNovaTextTest extends TestCase
{
    use DatabaseTransactions, LayerTestHelpers;

    private Diary $diary;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Administrator');
        $this->diary = Diary::factory()->create(['user_id' => $this->createUserWithoutRole()->id, 'layer_id' => $this->createLayer()->id]);
        // scritto direttamente nel database, come un dato non ripulito
        DB::table('diaries')->where('id', $this->diary->id)->update([
            'description' => '<h2>Giorno uno</h2><p>Partiti <strong>presto</strong><img src=x onerror=alert(1)></p>',
        ]);
    }

    /** @return array<string, mixed> */
    private function field(array $fields): array
    {
        return collect($fields)->firstWhere('name', __('Text'));
    }

    public function test_dettaglio_mostra_il_racconto_formattato_e_ripulito(): void
    {
        $field = $this->field($this->actingAs($this->admin)->getJson("/nova-api/diaries/{$this->diary->id}")->assertOk()->json('resource.fields'));

        $this->assertTrue($field['asHtml']);
        $this->assertStringContainsString('<div class="diary-rich-text"><h2>Giorno uno</h2><p>Partiti <strong>presto</strong></p></div>', $field['value']);
        $this->assertStringNotContainsString('onerror', $field['value']);
    }

    public function test_dettaglio_ha_i_bottoni_per_copiare_testo_e_html(): void
    {
        $field = $this->field($this->actingAs($this->admin)->getJson("/nova-api/diaries/{$this->diary->id}")->assertOk()->json('resource.fields'));

        $this->assertStringContainsString('data-diary-copy="text"', $field['value']);
        $this->assertStringContainsString('data-diary-copy="html"', $field['value']);
        $this->assertStringContainsString('>'.__('Copy text').'<', $field['value']);
        $this->assertStringContainsString('>'.__('Copy HTML').'<', $field['value']);
    }

    public function test_dettaglio_senza_racconto_non_mostra_i_bottoni(): void
    {
        DB::table('diaries')->where('id', $this->diary->id)->update(['description' => null]);

        $field = $this->field($this->actingAs($this->admin)->getJson("/nova-api/diaries/{$this->diary->id}")->assertOk()->json('resource.fields'));

        $this->assertNull($field['value']);
    }

    public function test_nova_carica_stile_e_script_del_racconto(): void
    {
        $this->actingAs($this->admin)->get('/nova/resources/diaries/'.$this->diary->id)->assertOk();

        $this->assertContains('diary-rich-text', collect(Nova::allStyles())->map(fn ($asset) => $asset->name())->all());
        $this->assertContains('diary-copy', collect(Nova::allScripts())->map(fn ($asset) => $asset->name())->all());
    }

    public function test_elenco_mostra_l_estratto_senza_tag(): void
    {
        $resource = collect($this->actingAs($this->admin)->getJson('/nova-api/diaries')->assertOk()->json('resources'))
            ->firstWhere('id.value', $this->diary->id);

        $this->assertSame('Giorno uno Partiti presto', $this->field($resource['fields'])['value']);
    }

    public function test_la_ricerca_trova_il_testo_e_non_i_tag(): void
    {
        $ids = fn (string $q) => collect($this->actingAs($this->admin)->getJson('/nova-api/diaries?search='.$q)->assertOk()->json('resources'))
            ->pluck('id.value')->all();

        $this->assertContains($this->diary->id, $ids('presto'));
        $this->assertNotContains($this->diary->id, $ids('strong'));
    }
}

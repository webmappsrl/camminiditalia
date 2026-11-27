<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\NovaBrandLogo;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\CreatesUsersWithRole;
use Tests\Feature\Helpers\FakesCertificationDisk;
use Tests\TestCase;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Logo del cammino accanto a quello di Cammini d'Italia, nell'header di Nova (oc:9040, quarta
 * parte). Per un Validator il cui primo layer ha il media `logo` l'SVG contiene i due loghi;
 * l'animazione (Cammini d'Italia al 60%, poi il logo del cammino) sta in brand.css, sulle classi.
 * Per tutti gli altri il logo resta il file di config('nova.brand.logo').
 */
class NovaRouteLogoTest extends TestCase
{
    use CreatesUsersWithRole;
    use FakesCertificationDisk;
    use RefreshDatabase;

    /** Larghezza utile del link del logo nell'header (vedi NovaBrandLogoTest) */
    private const MAX_HEADER_WIDTH_PX = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->fakeCertificationDisk();
        RolesAndPermissionsService::seedDatabase();
    }

    public function test_validator_with_route_logo_gets_combined_logo(): void
    {
        [$validator, $logoUrl] = $this->validatorWithRouteLogo();

        $xpath = $this->xpath(NovaBrandLogo::forUser($validator));

        $cdi = $xpath->query('//*[contains(concat(" ", @class, " "), " cammini-brand-cdi ")]//*[local-name()="image"]');
        $this->assertSame(1, $cdi->length);
        $this->assertStringStartsWith('data:image/png;base64,', $this->attribute($cdi, 'href'));

        $route = $xpath->query('//*[local-name()="image"][contains(concat(" ", @class, " "), " cammini-brand-route ")]');
        $this->assertSame(1, $route->length);
        $this->assertSame($logoUrl, $this->attribute($route, 'href'));
        $this->assertSame('xMinYMid meet', $this->attribute($route, 'preserveAspectRatio'));
    }

    public function test_route_logo_of_the_first_layer_is_used(): void
    {
        [$validator, $firstLogoUrl] = $this->validatorWithRouteLogo();
        $second = Layer::factory()->create(['user_id' => $validator->id]);
        $second->addMedia(UploadedFile::fake()->image('altro.png', 64, 64))->toMediaCollection('logo');

        $route = $this->xpath(NovaBrandLogo::forUser($validator))
            ->query('//*[local-name()="image"][contains(concat(" ", @class, " "), " cammini-brand-route ")]');

        $this->assertSame($firstLogoUrl, $this->attribute($route, 'href'));
    }

    public function test_administrator_and_validator_without_logo_get_the_default_logo(): void
    {
        $default = file_get_contents(config('nova.brand.logo'));

        $administrator = $this->userWithRole('Administrator');
        Layer::factory()->create(['user_id' => $administrator->id])
            ->addMedia(UploadedFile::fake()->image('logo.png', 64, 64))->toMediaCollection('logo');

        $validatorWithoutLogo = $this->userWithRole('Validator');
        Layer::factory()->create(['user_id' => $validatorWithoutLogo->id]);

        $this->assertSame($default, NovaBrandLogo::forUser($administrator));
        $this->assertSame($default, NovaBrandLogo::forUser($validatorWithoutLogo));
        $this->assertSame($default, NovaBrandLogo::forUser(null));
    }

    public function test_combined_logo_fits_the_header_without_ids_styles_or_root_class(): void
    {
        [$validator] = $this->validatorWithRouteLogo();
        $svg = NovaBrandLogo::forUser($validator);
        $xpath = $this->xpath($svg);
        $root = $xpath->document->documentElement;

        $this->assertSame(0, $xpath->query('//@id')->length);
        $this->assertSame(0, $xpath->query('//*[local-name()="style"]')->length);
        $this->assertFalse($root->hasAttribute('class'));
        $this->assertFalse($root->hasAttribute('width'));
        $this->assertFalse($root->hasAttribute('height'));

        [, , $viewBoxWidth, $viewBoxHeight] = array_map('floatval', explode(' ', $root->getAttribute('viewBox')));
        $this->assertMatchesRegularExpression('/height\s*:\s*36px/', $root->getAttribute('style'));
        $this->assertLessThanOrEqual(self::MAX_HEADER_WIDTH_PX, $viewBoxWidth * 36 / $viewBoxHeight);
    }

    public function test_nova_page_of_the_validator_carries_the_combined_logo(): void
    {
        [$validator, $logoUrl] = $this->validatorWithRouteLogo();

        $this->actingAs($validator)
            ->get('/nova/dashboards/main')
            ->assertOk()
            ->assertSee('cammini-brand-route', false)
            ->assertSee(str_replace('/', '\/', $logoUrl), false);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function validatorWithRouteLogo(): array
    {
        $validator = $this->userWithRole('Validator');
        $layer = Layer::factory()->create(['user_id' => $validator->id]);
        $layer->addMedia(UploadedFile::fake()->image('logo.png', 300, 100))->toMediaCollection('logo');

        return [$validator, $layer->fresh()->getFirstMediaUrl('logo')];
    }

    /**
     * @param  DOMNodeList<\DOMNode>  $nodes
     */
    private function attribute(DOMNodeList $nodes, string $name): string
    {
        $element = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $element);

        return $element->getAttribute($name);
    }

    private function xpath(string $svg): DOMXPath
    {
        $document = new DOMDocument;
        $this->assertTrue(@$document->loadXML($svg), 'Il logo non è XML ben formato');

        return new DOMXPath($document);
    }
}

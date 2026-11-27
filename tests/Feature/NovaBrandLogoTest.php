<?php

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Laravel\Nova\Nova;
use Tests\TestCase;

/**
 * Il logo del pannello Nova (oc:9031, oc:9040).
 *
 * Nova inserisce l'SVG nella pagina con innerHTML, due volte (header e menu mobile),
 * e ci aggiunge la classe h-6 (h-8 nella pagina di login): l'SVG non deve quindi
 * portare id né stili. Se il file manca, Nova::logo() restituisce il percorso come testo
 * e il frontend non disegna nemmeno la pagina di login.
 *
 * Dal oc:9040 l'SVG incorpora il PNG del cliente
 * (docs/features/9040-personalizzazione-laravel/logo-cliente.png) come data URI: un'immagine
 * troncata o mal codificata non rompe l'XML, quindi va controllata a parte.
 */
class NovaBrandLogoTest extends TestCase
{
    /** Dimensioni del PNG originale del cliente */
    private const CUSTOMER_PNG_WIDTH = 1080;

    private const CUSTOMER_PNG_HEIGHT = 328;

    private const PNG_DATA_URI_PREFIX = 'data:image/png;base64,';

    /** Larghezza utile del link del logo nell'header: lg:w-60 (240 px) meno ml-2 e px-4 */
    private const MAX_HEADER_WIDTH_PX = 200;

    public function test_brand_logo_points_to_an_existing_file(): void
    {
        $path = config('nova.brand.logo');

        $this->assertIsString($path);
        $this->assertStringStartsWith(resource_path('img'), $path);
        $this->assertFileExists($path);
    }

    public function test_logo_fits_the_header(): void
    {
        $svg = $this->svgRoot();

        $this->assertSame('svg', $svg->localName);
        $this->assertMatchesRegularExpression('/^0 0 \d+(\.\d+)? \d+(\.\d+)?$/', $svg->getAttribute('viewBox'));
        $this->assertMatchesRegularExpression('/height\s*:\s*(\d+(?:\.\d+)?)px/', $svg->getAttribute('style'));

        [, , $viewBoxWidth, $viewBoxHeight] = array_map('floatval', explode(' ', $svg->getAttribute('viewBox')));
        preg_match('/height\s*:\s*(\d+(?:\.\d+)?)px/', $svg->getAttribute('style'), $height);

        $this->assertLessThanOrEqual(self::MAX_HEADER_WIDTH_PX, $viewBoxWidth * (float) $height[1] / $viewBoxHeight);
    }

    public function test_logo_has_no_id_attributes(): void
    {
        $xpath = $this->xpath();

        $this->assertSame(0, $xpath->query('//@id')->length);
    }

    public function test_logo_has_no_style_element(): void
    {
        $styles = $this->xpath()->query('//*[local-name()="style"]');

        $this->assertSame(0, $styles->length);
    }

    public function test_logo_embeds_the_customer_png(): void
    {
        $images = $this->xpath()->query('//*[local-name()="image"]');

        $this->assertSame(1, $images->length);

        /** @var DOMElement $image */
        $image = $images->item(0);
        $href = $image->getAttribute('href');

        $this->assertStringStartsWith(self::PNG_DATA_URI_PREFIX, $href);

        $png = base64_decode(substr($href, strlen(self::PNG_DATA_URI_PREFIX)), true);
        $this->assertNotFalse($png, 'Il PNG incorporato non è base64 valido');

        $size = getimagesizefromstring($png);
        $this->assertNotFalse($size, 'Il contenuto incorporato non è un\'immagine leggibile');
        $this->assertSame(IMAGETYPE_PNG, $size[2]);
        $this->assertSame(self::CUSTOMER_PNG_WIDTH, $size[0]);
        $this->assertSame(self::CUSTOMER_PNG_HEIGHT, $size[1]);
    }

    public function test_logo_height_is_set_inline_without_fixed_size_or_classes(): void
    {
        $svg = $this->svgRoot();

        $this->assertMatchesRegularExpression('/(^|;)\s*height\s*:\s*\d+(\.\d+)?px/', $svg->getAttribute('style'));
        $this->assertFalse($svg->hasAttribute('width'));
        $this->assertFalse($svg->hasAttribute('height'));
        $this->assertFalse($svg->hasAttribute('class'));
    }

    private function document(): DOMDocument
    {
        $logo = Nova::logo();

        $this->assertIsString($logo);
        $this->assertStringStartsWith('<svg', ltrim($logo));

        $document = new DOMDocument;
        $this->assertTrue(@$document->loadXML($logo), 'Il logo non è XML ben formato');

        return $document;
    }

    private function svgRoot(): DOMElement
    {
        return $this->document()->documentElement;
    }

    private function xpath(): DOMXPath
    {
        return new DOMXPath($this->document());
    }
}

<?php

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Laravel\Nova\Nova;
use Tests\TestCase;

/**
 * Il logo del pannello Nova (oc:9031).
 *
 * Nova inserisce l'SVG nella pagina con innerHTML, due volte (header e menu mobile),
 * e ci aggiunge la classe h-6 (h-8 nella pagina di login): l'SVG non deve quindi
 * portare id, né regole di stile che tocchino il resto della pagina. Se il file manca,
 * Nova::logo() restituisce il percorso come testo e il frontend non disegna nemmeno
 * la pagina di login.
 */
class NovaBrandLogoTest extends TestCase
{
    /** La stessa classe di NAME_CLASS in docs/features/9031-cammini-logo-sul-backend/build-logo.py */
    private const NAME_CLASS = 'cdi-logo-name';

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

    public function test_logo_style_only_targets_the_name(): void
    {
        $styles = $this->xpath()->query('//*[local-name()="style"]');

        $this->assertLessThanOrEqual(1, $styles->length);

        foreach ($styles as $style) {
            preg_match_all('/([^{}]+)\{[^}]*\}/', $style->textContent, $rules);

            $this->assertNotEmpty($rules[1]);

            foreach ($rules[1] as $selectorList) {
                foreach (explode(',', $selectorList) as $selector) {
                    $this->assertMatchesRegularExpression(
                        '/^(\.dark\s+)?\.'.self::NAME_CLASS.'$/',
                        trim($selector),
                    );
                }
            }
        }
    }

    public function test_logo_contains_the_name(): void
    {
        $names = $this->xpath()->query('//*[contains(concat(" ", normalize-space(@class), " "), " '.self::NAME_CLASS.' ")]');

        $this->assertGreaterThan(0, $names->length);
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

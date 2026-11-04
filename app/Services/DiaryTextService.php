<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use HTMLPurifier;
use Wm\WmPackage\Nova\Fields\Concerns\HasEmbeddableRichText;

/**
 * Racconto del diario (oc:8169, ciclo 3): `description` è sempre HTML, scritto dall'editor dell'app.
 * Qui si ripulisce con un elenco chiuso di tag, si converte il testo semplice in paragrafi e si
 * misura il testo visibile per i limiti.
 */
class DiaryTextService
{
    /** Caratteri di testo visibile ammessi. */
    public const MAX_VISIBLE = 20000;

    /** Caratteri di HTML ammessi, tag compresi. */
    public const MAX_HTML = 100000;

    /** Tag e attributi ammessi, nel formato `HTML.Allowed` di HTMLPurifier. */
    private const ALLOWED_HTML = 'p,br,strong,em,s,h2,h3,ul,ol,li,blockquote,hr,a[href]';

    /** Schemi ammessi per i collegamenti: un `href` senza uno di questi perde il tag. */
    private const LINK_SCHEMES = '#^(https?:|mailto:)#i';

    private static ?HTMLPurifier $purifier = null;

    /**
     * Ripulisce il racconto. Un testo che non comincia con un tag è testo semplice: diventa
     * paragrafi sulle righe vuote, con gli a capo.
     *
     * @return string|null L'HTML ripulito, `null` se non resta testo visibile.
     */
    public function clean(?string $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        $html = str_starts_with($text, '<') ? $this->unwrapRelativeLinks($text) : $this->paragraphs($text);
        $clean = trim(self::purifier()->purify($html));

        return $this->visibleLength($clean) > 0 ? $clean : null;
    }

    /**
     * Caratteri di testo visibile, senza tag e con le entità decodificate.
     */
    public function visibleLength(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // `trim` non toglie gli spazi non separabili, che Word usa per le righe vuote
        return mb_strlen(preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $text) ?? $text);
    }

    /**
     * Testo semplice su una riga, con i blocchi separati da uno spazio.
     */
    public function plainText(string $html): string
    {
        $spaced = preg_replace('#<(/(p|h2|h3|li|blockquote)|br\s*/?|hr\s*/?)>#i', '$0 ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function purifier(): HTMLPurifier
    {
        // nessun iframe ammesso: l'espressione non corrisponde a nessun indirizzo
        return self::$purifier ??= HasEmbeddableRichText::buildRichTextPurifier(self::ALLOWED_HTML, '%^$%');
    }

    /**
     * @param  string  $text  Testo semplice.
     * @return string Paragrafi con i caratteri speciali protetti.
     */
    private function paragraphs(string $text): string
    {
        $blocks = preg_split('/\R\s*\R/u', $text) ?: [$text];

        return implode('', array_map(
            fn (string $block) => '<p>'.preg_replace('/\R/u', '<br>', htmlspecialchars(trim($block), ENT_QUOTES, 'UTF-8')).'</p>',
            $blocks
        ));
    }

    /**
     * Toglie il tag ai collegamenti con un indirizzo relativo o con uno schema non ammesso, e ne tiene
     * il testo. HTMLPurifier filtra solo gli schemi, i relativi li lascerebbe passare.
     */
    private function unwrapRelativeLinks(string $html): string
    {
        if (stripos($html, '<a') === false) {
            return $html;
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="wm-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $links = iterator_to_array($doc->getElementsByTagName('a'));
        foreach ($links as $link) {
            /** @var DOMElement $link */
            if (preg_match(self::LINK_SCHEMES, trim($link->getAttribute('href')))) {
                continue;
            }
            while ($link->firstChild) {
                $link->parentNode?->insertBefore($link->firstChild, $link);
            }
            $link->parentNode?->removeChild($link);
        }

        $root = $doc->getElementById('wm-root');
        if ($root === null) {
            return $html;
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }
}

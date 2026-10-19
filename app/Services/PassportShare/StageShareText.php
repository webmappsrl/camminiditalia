<?php

namespace App\Services\PassportShare;

use Intervention\Image\Image as InterventionImage;

/**
 * Misura, adattamento e disegno dei testi dell'immagine di condivisione della
 * tappa (oc:8702): a capo, riduzione del corpo, troncamento con «…».
 * Estratto da {@see StageShareImageService}; nessuno stato.
 */
class StageShareText
{
    /**
     * Rapporto fra corpo in pixel e punti di GD, la stessa conversione di
     * Intervention.
     */
    private const PIXELS_TO_POINTS = 0.75;

    /** Altezza delle maiuscole, in frazione del corpo: serve a centrare la riga. */
    private const CAP_HEIGHT_RATIO = 0.72;

    /**
     * Adatta un testo al suo riquadro: a capo fino a `$maxLines` righe; se
     * non basta, corpo ridotto a passi di FONT_STEP fino a `$minFontSize`;
     * oltre, l'ultima riga si tronca con «…».
     *
     * @return array{lines: list<string>, fontSize: int}
     */
    public function fit(string $text, int $boxWidth, int $maxLines, int $fontSize, int $minFontSize, string $font = StageShareLayout::FONT_BOLD): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        for ($size = $fontSize; $size >= $minFontSize; $size -= StageShareLayout::FONT_STEP) {
            $lines = $this->wrap($text, $boxWidth, $size, $font);
            if (count($lines) <= $maxLines) {
                return ['lines' => $lines, 'fontSize' => $size];
            }
        }

        $lines = $this->wrap($text, $boxWidth, $minFontSize, $font);
        $kept = array_slice($lines, 0, $maxLines - 1);
        $rest = implode(' ', array_slice($lines, $maxLines - 1));
        $kept[] = $this->ellipsize($rest, $boxWidth, $minFontSize, $font);

        return ['lines' => $kept, 'fontSize' => $minFontSize];
    }

    /**
     * Larghezza in pixel del testo, con la stessa conversione in punti di
     * Intervention (corpo × 0,75).
     */
    public function width(string $text, int $size, string $font): int
    {
        $box = imagettfbbox($this->pointSize($size), 0, $font, $text);

        return (int) (max($box[2], $box[4]) - min($box[0], $box[6]));
    }

    /**
     * Altezza occupata dalle righe di fit().
     *
     * @param  array{lines: list<string>, fontSize: int}  $fitted
     */
    public function linesHeight(array $fitted): int
    {
        return count($fitted['lines']) * (int) round($fitted['fontSize'] * StageShareLayout::LINE_HEIGHT);
    }

    /**
     * Disegna le righe di fit() a partire da `$top` (allineamento
     * `center` su `$x`, oppure `left` da `$x`). Restituisce la y sotto
     * l'ultima riga.
     *
     * @param  array{lines: list<string>, fontSize: int}  $fitted
     */
    public function draw(InterventionImage $canvas, array $fitted, string $font, string $color, float $x, int $top, string $align): int
    {
        $size = $fitted['fontSize'];
        $lineHeight = (int) round($size * StageShareLayout::LINE_HEIGHT);
        [$r, $g, $b] = self::hexToRgb($color);
        $core = $canvas->getCore();
        imagealphablending($core, true);
        $gdColor = imagecolorallocate($core, $r, $g, $b);

        foreach ($fitted['lines'] as $index => $line) {
            $width = $this->width($line, $size, $font);
            $left = $align === 'center' ? (int) round($x - $width / 2) : (int) round($x);
            // Linea di base: l'altezza delle maiuscole più metà
            // dell'interlinea, così la riga è centrata nel suo spazio.
            $baseline = $top + $index * $lineHeight + (int) round(($lineHeight + $size * self::CAP_HEIGHT_RATIO) / 2);
            imagettftext($core, $this->pointSize($size), 0, $left, $baseline, $gdColor, $font, $line);
        }

        return $top + count($fitted['lines']) * $lineHeight;
    }

    /**
     * Componenti RGB di un colore `#rrggbb`.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /**
     * A capo per parole; una parola più larga del riquadro si spezza per caratteri.
     *
     * @return list<string>
     */
    private function wrap(string $text, int $boxWidth, int $size, string $font): array
    {
        $lines = [];
        $current = '';

        foreach (explode(' ', $text) as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($this->width($candidate, $size, $font) <= $boxWidth) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            // Parola da sola troppo larga: spezzata per caratteri.
            $current = '';
            foreach (mb_str_split($word) as $char) {
                if ($current !== '' && $this->width($current.$char, $size, $font) > $boxWidth) {
                    $lines[] = $current;
                    $current = '';
                }
                $current .= $char;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Tronca il testo finché, con «…» in coda, sta nel riquadro.
     */
    private function ellipsize(string $text, int $boxWidth, int $size, string $font): string
    {
        $chars = mb_str_split($text);

        while ($chars !== [] && $this->width(rtrim(implode('', $chars)).'…', $size, $font) > $boxWidth) {
            array_pop($chars);
        }

        return rtrim(implode('', $chars)).'…';
    }

    /**
     * Corpo in punti di GD a partire dal corpo in pixel.
     */
    private function pointSize(int $size): float
    {
        return $size * self::PIXELS_TO_POINTS;
    }
}

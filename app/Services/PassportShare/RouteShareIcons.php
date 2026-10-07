<?php

namespace App\Services\PassportShare;

use GdImage;

/**
 * Icone della griglia dell'immagine del cammino che quella della tappa non ha
 * (oc:8703): oggi il calendario delle uscite. Stanno qui e non in
 * StageShareIcons perché le costanti di quella classe entrano nella firma del
 * layout della tappa: aggiungerle lì rifarebbe tutte le immagini delle tappe.
 * Stesso disegno: supercampionato e poi ridotto, colore GRID_ICON_COLOR.
 */
class RouteShareIcons
{
    /** Fattore di supercampionamento, come StageShareIcons. */
    private const SUPERSAMPLE = 4;

    /** Corpo del calendario: [x1, y1, x2, y2] in frazioni del lato. */
    private const CALENDAR_BODY = [0.12, 0.2, 0.88, 0.92];

    /** Spessore del bordo del calendario, in frazioni del lato. */
    private const CALENDAR_BORDER = 0.08;

    /** Fascia superiore piena, fino a questa y. */
    private const CALENDAR_HEADER_BOTTOM = 0.38;

    /** Anelli in alto: x dei due anelli e loro estensione verticale. */
    private const CALENDAR_RINGS_X = [0.32, 0.68];

    private const CALENDAR_RINGS_Y = [0.08, 0.28];

    /** Griglia dei giorni: 3 × 2 quadratini sotto la fascia. */
    private const CALENDAR_DAY_SIZE = 0.12;

    private const CALENDAR_DAYS_X = [0.24, 0.44, 0.64];

    private const CALENDAR_DAYS_Y = [0.48, 0.68];

    /**
     * Calendario delle uscite, lato GRID_ICON_SIZE, sfondo trasparente.
     */
    public function calendar(): GdImage
    {
        $s = StageShareLayout::GRID_ICON_SIZE * self::SUPERSAMPLE;
        $big = imagecreatetruecolor($s, $s);
        imagealphablending($big, false);
        imagesavealpha($big, true);
        imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));
        imagealphablending($big, true);

        [$r, $g, $b] = StageShareText::hexToRgb(StageShareLayout::GRID_ICON_COLOR);
        $ink = imagecolorallocate($big, $r, $g, $b);
        $p = fn (float $f): int => (int) round($f * $s);
        [$x1, $y1, $x2, $y2] = self::CALENDAR_BODY;

        imagesetthickness($big, $p(self::CALENDAR_BORDER));
        imagerectangle($big, $p($x1), $p($y1), $p($x2), $p($y2), $ink);
        imagefilledrectangle($big, $p($x1), $p($y1), $p($x2), $p(self::CALENDAR_HEADER_BOTTOM), $ink);
        foreach (self::CALENDAR_RINGS_X as $x) {
            imageline($big, $p($x), $p(self::CALENDAR_RINGS_Y[0]), $p($x), $p(self::CALENDAR_RINGS_Y[1]), $ink);
        }
        foreach (self::CALENDAR_DAYS_Y as $y) {
            foreach (self::CALENDAR_DAYS_X as $x) {
                imagefilledrectangle($big, $p($x), $p($y), $p($x + self::CALENDAR_DAY_SIZE), $p($y + self::CALENDAR_DAY_SIZE), $ink);
            }
        }

        $size = StageShareLayout::GRID_ICON_SIZE;
        $small = imagecreatetruecolor($size, $size);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagecopyresampled($small, $big, 0, 0, 0, 0, $size, $size, $s, $s);
        imagedestroy($big);

        return $small;
    }
}

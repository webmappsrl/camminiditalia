<?php

namespace App\Services\PassportShare;

use GdImage;
use InvalidArgumentException;

/**
 * Icone della griglia dei dati dell'immagine di condivisione della tappa
 * (oc:8702), disegnate con GD a ICON_SUPERSAMPLE volte la misura e ridotte,
 * per l'antialias. Estratto da {@see StageShareImageService}; nessuno stato.
 *
 * Tutte le misure delle forme sono frazioni del lato dell'icona (0 = bordo
 * sinistro o superiore, 1 = bordo destro o inferiore).
 */
class StageShareIcons
{
    /** Fattore di sovracampionamento (antialias). */
    private const ICON_SUPERSAMPLE = 4;

    // --- `from`: segnaposto (cerchio con foro e punta in basso) ---
    private const PIN_CENTER_X = 0.5;

    private const PIN_CENTER_Y = 0.36;

    private const PIN_DIAMETER = 0.62;

    private const PIN_HOLE_DIAMETER = 0.24;

    /** Vertici della punta: [x sinistra, y, x destra, y, x punta, y punta]. */
    private const PIN_TIP = [0.21, 0.48, 0.79, 0.48, 0.5, 0.98];

    // --- `to`: bandiera a scacchi su un'asta ---
    /** Asta: [x1, y1, x2, y2]. */
    private const FLAG_POLE = [0.14, 0.04, 0.22, 0.98];

    private const FLAG_COLUMNS = 4;

    private const FLAG_ROWS = 3;

    private const FLAG_LEFT = 0.22;

    private const FLAG_TOP = 0.06;

    private const FLAG_RIGHT = 0.9;

    private const FLAG_HEIGHT = 0.48;

    private const FLAG_BORDER = 0.04;

    // --- `distance`: anello aperto in alto con punta di freccia ---
    private const RING_CENTER_X = 0.5;

    private const RING_CENTER_Y = 0.52;

    private const RING_OUTER_DIAMETER = 0.84;

    private const RING_INNER_DIAMETER = 0.62;

    /** Apertura dell'anello, in gradi GD (0 = destra, senso orario). */
    private const RING_GAP_START_DEGREES = 240;

    private const RING_GAP_END_DEGREES = 300;

    /** Raggio del triangolo che svuota l'apertura (oltre l'anello esterno). */
    private const RING_GAP_RADIUS = 0.6;

    /** Punta di freccia all'estremità sinistra dell'arco: [x1, y1, x2, y2, x3, y3]. */
    private const RING_ARROW = [0.17, 0.02, 0.43, 0.2, 0.15, 0.36];

    // --- `ascent`: freccia in salita ---
    private const ASCENT_THICKNESS = 0.09;

    /** Asta: [x1, y1, x2, y2]. */
    private const ASCENT_SHAFT = [0.1, 0.9, 0.72, 0.28];

    /** Punta: [x1, y1, x2, y2, x3, y3]. */
    private const ASCENT_HEAD = [0.95, 0.05, 0.88, 0.5, 0.5, 0.12];

    /**
     * Icona della griglia (`from` segnaposto, `to` bandiera a scacchi,
     * `distance` anello con freccia, `ascent` freccia in salita), lato
     * GRID_ICON_SIZE, sfondo trasparente.
     *
     * @throws InvalidArgumentException se `$type` non è un'icona nota.
     */
    public function icon(string $type): GdImage
    {
        $s = StageShareLayout::GRID_ICON_SIZE * self::ICON_SUPERSAMPLE;
        $big = imagecreatetruecolor($s, $s);
        imagealphablending($big, false);
        imagesavealpha($big, true);
        imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));
        imagealphablending($big, true);

        [$r, $g, $b] = StageShareText::hexToRgb(StageShareLayout::GRID_ICON_COLOR);
        $ink = imagecolorallocate($big, $r, $g, $b);
        $hole = imagecolorallocatealpha($big, 0, 0, 0, 127);
        $p = fn (float $f): int => (int) round($f * $s);
        $points = fn (array $fractions): array => array_map($p, $fractions);

        switch ($type) {
            case 'from':
                imagefilledellipse($big, $p(self::PIN_CENTER_X), $p(self::PIN_CENTER_Y), $p(self::PIN_DIAMETER), $p(self::PIN_DIAMETER), $ink);
                imagefilledpolygon($big, $points(self::PIN_TIP), $ink);
                imagealphablending($big, false);
                imagefilledellipse($big, $p(self::PIN_CENTER_X), $p(self::PIN_CENTER_Y), $p(self::PIN_HOLE_DIAMETER), $p(self::PIN_HOLE_DIAMETER), $hole);
                break;
            case 'to':
                imagefilledrectangle($big, ...[...$points(self::FLAG_POLE), $ink]);
                $cw = (self::FLAG_RIGHT - self::FLAG_LEFT) / self::FLAG_COLUMNS;
                $ch = self::FLAG_HEIGHT / self::FLAG_ROWS;
                imagesetthickness($big, $p(self::FLAG_BORDER));
                imagerectangle($big, $p(self::FLAG_LEFT), $p(self::FLAG_TOP), $p(self::FLAG_LEFT + $cw * self::FLAG_COLUMNS), $p(self::FLAG_TOP + $ch * self::FLAG_ROWS), $ink);
                for ($i = 0; $i < self::FLAG_COLUMNS; $i++) {
                    for ($j = 0; $j < self::FLAG_ROWS; $j++) {
                        if (($i + $j) % 2 === 0) {
                            imagefilledrectangle($big, $p(self::FLAG_LEFT + $i * $cw), $p(self::FLAG_TOP + $j * $ch), $p(self::FLAG_LEFT + ($i + 1) * $cw), $p(self::FLAG_TOP + ($j + 1) * $ch), $ink);
                        }
                    }
                }
                break;
            case 'distance':
                $cx = self::RING_CENTER_X;
                $cy = self::RING_CENTER_Y;
                imagefilledarc($big, $p($cx), $p($cy), $p(self::RING_OUTER_DIAMETER), $p(self::RING_OUTER_DIAMETER), self::RING_GAP_END_DEGREES, self::RING_GAP_START_DEGREES, $ink, IMG_ARC_PIE);
                imagealphablending($big, false);
                imagefilledellipse($big, $p($cx), $p($cy), $p(self::RING_INNER_DIAMETER), $p(self::RING_INNER_DIAMETER), $hole);
                imagefilledpolygon($big, [
                    $p($cx), $p($cy),
                    $p($cx + self::RING_GAP_RADIUS * cos(deg2rad(self::RING_GAP_START_DEGREES))), $p($cy + self::RING_GAP_RADIUS * sin(deg2rad(self::RING_GAP_START_DEGREES))),
                    $p($cx + self::RING_GAP_RADIUS * cos(deg2rad(self::RING_GAP_END_DEGREES))), $p($cy + self::RING_GAP_RADIUS * sin(deg2rad(self::RING_GAP_END_DEGREES))),
                ], $hole);
                imagealphablending($big, true);
                // Punta di freccia in senso orario all'estremità sinistra dell'arco.
                imagefilledpolygon($big, $points(self::RING_ARROW), $ink);
                break;
            case 'ascent':
                imagesetthickness($big, $p(self::ASCENT_THICKNESS));
                imageline($big, ...[...$points(self::ASCENT_SHAFT), $ink]);
                imagefilledpolygon($big, $points(self::ASCENT_HEAD), $ink);
                break;
            default:
                imagedestroy($big);

                throw new InvalidArgumentException("Icona della griglia sconosciuta: {$type}");
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

<?php

namespace App\Services\PassportShare;

use ReflectionClass;
use Wm\WmPackage\Services\Models\StoryShare\StoryImageLayout;

/**
 * Costanti di layout dell'immagine di condivisione della tappa (oc:8702),
 * sul mockup del cliente. Unica fonte di coordinate, misure, colori e font
 * usati da {@see StageShareImageService}.
 *
 * Il layout scorre dall'alto in basso: ogni blocco parte dopo il precedente
 * (un blocco assente, come il logo del cammino, fa salire il resto) e
 * l'insieme si centra in verticale nello spazio fra TOP_MARGIN e il logo di
 * Cammini d'Italia, ancorato in fondo. Con tutti i blocchi al massimo il
 * contenuto è alto 1674 px:
 * - logo del cammino: LOGO_BOX_SIZE + LOGO_TITLE_GAP = 240 + 30 = 270;
 * - nome del cammino su due righe a 88 px: 2 × round(88 × 1,15) + TITLE_MAP_GAP = 2 × 101 + 30 = 232;
 * - mappa con cornice: MAP_HEIGHT + 2 × MAP_BORDER + MAP_STAGE_LABEL_GAP = 720 + 24 + 40 = 784;
 * - «Tappa {ref}» su una riga a 64 px: round(64 × 1,15) = 74;
 * - griglia su due righe: STAGE_LABEL_GRID_GAP + 2 × GRID_ROW_HEIGHT + GRID_ROW_GAP = 44 + 260 + 10 = 314.
 * Lo spazio disponibile è CANVAS_HEIGHT − TOP_MARGIN − CDI_LOGO_GAP −
 * CDI_LOGO_SIZE − BOTTOM_MARGIN = 1920 − 60 − 20 − 110 − 40 = 1690 px; senza
 * il logo di Cammini d'Italia diventa 1920 − 60 − 40 = 1820 px.
 * L'etichetta della tappa può andare su STAGE_LABEL_MAX_LINES righe (+74 px):
 * se con tutti gli altri blocchi al massimo non entra, torna su una riga.
 * Coordinate in pixel sulla tela CANVAS_WIDTH × CANVAS_HEIGHT.
 */
final class StageShareLayout
{
    /**
     * Versione del codice di disegno, parte dell'impronta che decide se
     * un'immagine già salvata è ancora valida (vedi signature()). Le costanti
     * di questa classe, di StageShareIcons e di StageShareText e il file di
     * sfondo, i font e le traduzioni entrano già nell'impronta da soli.
     * VERSION va incrementata quando cambia il codice che disegna
     * (StageShareImageService, StageShareText, StageShareIcons) senza che
     * cambi una costante, e SEMPRE quando cambia MapRenderService di
     * wm-package: sia il suo codice di disegno sia le sue costanti
     * (margini, zoom, marker, tile), che non sono nell'impronta.
     */
    public const VERSION = 3;

    // --- Tela (formato storia) ---
    public const CANVAS_WIDTH = 1080;

    public const CANVAS_HEIGHT = 1920;

    public const BACKGROUND_PATH = 'images/passport-share/background.png';

    public const TOP_MARGIN = 60;

    // --- Logo del cammino, così com'è (trasparenza compresa) ---
    /** Lato del riquadro in cui il logo si adatta, mantenendo le proporzioni. */
    public const LOGO_BOX_SIZE = 240;

    public const LOGO_TITLE_GAP = 30;

    // --- Nome del cammino ---
    public const TITLE_FONT_SIZE = 88;

    public const TITLE_MIN_FONT_SIZE = 56;

    public const TITLE_MAX_LINES = 2;

    public const TITLE_BOX_WIDTH = 960;

    public const TITLE_MAP_GAP = 30;

    // --- Riquadro mappa ---
    public const MAP_WIDTH = 960;

    public const MAP_HEIGHT = 720;

    public const MAP_BORDER = 12;

    public const MAP_BORDER_COLOR = '#E8822E';

    public const MAP_CORNER_RADIUS = 24;

    public const MAP_STAGE_LABEL_GAP = 40;

    /**
     * Margine per lato intorno alla tappa, in frazione del suo span, passato
     * a MapRenderService::renderLayers() (`$marginRatio`). Lo zoom delle
     * tile è a scatti: il margine effettivo può risultare più largo.
     */
    public const STAGE_FOCUS_MARGIN_RATIO = 0.30;

    // Layer 1: tutte le tappe del cammino.
    public const ROUTE_COLOR = '#D93025';

    public const ROUTE_THICKNESS = 2;

    public const ROUTE_OPACITY = 0.6;

    // Layer 2: la tappa condivisa.
    public const STAGE_COLOR = '#F2C200';

    public const STAGE_THICKNESS = 6;

    public const STAGE_OUTLINE_COLOR = '#FFFFFF';

    public const STAGE_OUTLINE_THICKNESS = 2;

    // Marker di partenza e di arrivo: disco con anello bianco.
    public const MARKER_SIZE = 13;

    public const MARKER_RING_WIDTH = 2;

    public const MARKER_START_COLOR = '#2E7D32';

    public const MARKER_END_COLOR = '#C62828';

    /**
     * Tolleranza di ST_Simplify (gradi, ~20 m) per le tappe del cammino:
     * linee sottili su un'inquadratura di decine di km, il dettaglio non si vede.
     */
    public const ROUTE_SIMPLIFY_DEGREES = 0.0002;

    /** Tolleranza di ST_Simplify (gradi, ~5 m) per la tappa condivisa. */
    public const STAGE_SIMPLIFY_DEGREES = 0.00005;

    // --- «Tappa {ref}» ---
    public const STAGE_LABEL_FONT_SIZE = 64;

    public const STAGE_LABEL_MIN_FONT_SIZE = 40;

    /** Righe dell'etichetta della tappa: un nome lungo senza `ref` va a capo. */
    public const STAGE_LABEL_MAX_LINES = 2;

    public const STAGE_LABEL_BOX_WIDTH = 960;

    public const STAGE_LABEL_GRID_GAP = 44;

    // --- Griglia dei dati: 2 colonne, righe a altezza fissa, blocco centrato sulla tela ---
    public const GRID_X = 60;

    public const GRID_WIDTH = 960;

    public const GRID_COLUMNS = 2;

    public const GRID_ROW_HEIGHT = 130;

    public const GRID_ROW_GAP = 10;

    /** Distanza fissa fra le due colonne, misurata fra la cella più larga di ciascuna. */
    public const GRID_COLUMN_GAP = 60;

    public const GRID_ICON_SIZE = 52;

    public const GRID_ICON_TEXT_GAP = 18;

    /** Scostamento verticale dell'icona dal bordo superiore della cella. */
    public const GRID_ICON_OFFSET_Y = 6;

    public const GRID_ICON_COLOR = '#1d282b';

    public const GRID_LABEL_FONT_SIZE = 28;

    public const GRID_LABEL_COLOR = '#5b6466';

    public const GRID_LABEL_VALUE_GAP = 8;

    public const GRID_VALUE_FONT_SIZE = 38;

    public const GRID_VALUE_MAX_LINES = 2;

    /**
     * Larghezza massima del testo di una cella: ogni colonna può occupare al
     * più (GRID_WIDTH - GRID_COLUMN_GAP) / GRID_COLUMNS = 450 px, così il
     * blocco resta dentro i margini della tela.
     * = 450 - GRID_ICON_SIZE - GRID_ICON_TEXT_GAP
     */
    public const GRID_VALUE_BOX_WIDTH = 380;

    // --- Testi ---
    /** Corpo minimo dei valori della griglia: sotto si tronca con «…». */
    public const MIN_FONT_SIZE = 26;

    /** Passo di riduzione del corpo quando il testo non entra. */
    public const FONT_STEP = 2;

    /** Interlinea, in multipli del corpo. */
    public const LINE_HEIGHT = 1.15;

    public const TEXT_COLOR = '#1d282b';

    public const FONT_BLACK = StoryImageLayout::FONT_BLACK;

    public const FONT_BOLD = StoryImageLayout::FONT_BOLD;

    public const FONT_REGULAR = StoryImageLayout::FONT_REGULAR;

    // --- Logo di Cammini d'Italia, ancorato in fondo ---
    /**
     * Media collection dell'App del cammino da cui si prende il logo, in
     * ordine di preferenza; se nessuna è leggibile il blocco si omette.
     */
    public const CDI_LOGO_COLLECTIONS = ['icon', 'icon_small'];

    public const CDI_LOGO_SIZE = 110;

    public const BOTTOM_MARGIN = 40;

    /** Spazio minimo fra il contenuto e il logo di Cammini d'Italia. */
    public const CDI_LOGO_GAP = 20;

    /**
     * Firma del layout per l'impronta della cache delle immagini: hash di
     * - VERSION e di tutte le costanti di StageShareLayout, StageShareIcons e
     *   StageShareText (lette con la reflection, private comprese); una
     *   costante che è il percorso di un file esistente (i font) entra con
     *   l'hash del contenuto, non con il percorso assoluto, che cambia fra
     *   ambienti;
     * - il contenuto dei file di signatureFiles(): sfondo e file di lingua
     *   `passport_share.php` di tutte le lingue supportate (etichette e
     *   «Tappa {ref}» sono disegnati nell'immagine).
     * Cambiare una misura, un colore, un font, lo sfondo o una traduzione
     * invalida da sé le immagini salvate, senza alzare VERSION.
     *
     * @param  array<string, mixed>|null  $constants  Costanti al posto di quelle lette con la reflection (per i test).
     * @param  array<string, string>|null  $files  File al posto di signatureFiles() (per i test).
     */
    public static function signature(?array $constants = null, ?array $files = null): string
    {
        $constants ??= array_map(
            fn (string $class) => (new ReflectionClass($class))->getConstants(),
            [self::class => self::class, StageShareIcons::class => StageShareIcons::class, StageShareText::class => StageShareText::class],
        );

        $fileHash = fn (string $path): ?string => is_file($path) ? hash_file('sha256', $path) : null;

        array_walk_recursive($constants, function (mixed &$value) use ($fileHash) {
            if (is_string($value) && str_starts_with($value, '/') && is_file($value)) {
                $value = 'file:'.$fileHash($value);
            }
        });

        return hash('sha256', json_encode([
            'constants' => $constants,
            'files' => array_map($fileHash, $files ?? self::signatureFiles()),
        ]));
    }

    /**
     * File il cui contenuto entra in signature(): sfondo e traduzioni
     * dell'immagine per ogni lingua supportata.
     *
     * @return array<string, string>
     */
    public static function signatureFiles(): array
    {
        $files = ['background' => resource_path(self::BACKGROUND_PATH)];
        foreach (StageShareLocale::SUPPORTED_LANGUAGES as $lang) {
            $files["lang.{$lang}"] = lang_path("{$lang}/passport_share.php");
        }

        return $files;
    }
}

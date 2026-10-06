<?php

namespace App\Services\PassportShare;

/**
 * Lingua e formato dei numeri della condivisione della tappa (oc:8702), in un
 * posto solo: li usano l'endpoint che genera l'immagine, l'immagine stessa e
 * la pagina pubblica, che devono mostrare gli stessi valori nello stesso
 * formato.
 */
final class StageShareLocale
{
    /** Lingue con etichette tradotte; ogni altra ripiega sull'italiano. */
    public const SUPPORTED_LANGUAGES = ['it', 'en', 'de', 'es', 'fr', 'pt'];

    public const FALLBACK_LANGUAGE = 'it';

    /** Codici che l'app invia ma che non sono ISO 639-1. */
    private const LANGUAGE_ALIASES = ['pr' => 'pt'];

    /**
     * Lingua supportata a partire da un codice qualsiasi (`de`, `de-DE`,
     * `pr`), altrimenti il ripiego italiano.
     */
    public static function normalize(?string $lang): string
    {
        $code = strtolower(preg_split('/[-_;]/', trim((string) $lang))[0]);
        $code = self::LANGUAGE_ALIASES[$code] ?? $code;

        return in_array($code, self::SUPPORTED_LANGUAGES, true) ? $code : self::FALLBACK_LANGUAGE;
    }

    /**
     * Prima lingua dell'header Accept-Language, solo codice principale
     * (`de-DE,de;q=0.9` → `de`, `pr` → `pt`); ripiego `it`.
     */
    public static function fromAcceptLanguage(?string $header): string
    {
        return self::normalize(explode(',', (string) $header)[0]);
    }

    /**
     * Lunghezza della tappa: una cifra decimale con la virgola («20,4 km»).
     */
    public static function formatDistance(?float $km): ?string
    {
        return $km !== null ? number_format($km, 1, ',', '').' km' : null;
    }

    /**
     * Dislivello positivo con il segno e il punto delle migliaia («+1.258 m»).
     */
    public static function formatAscent(?int $meters): ?string
    {
        return $meters !== null ? '+'.number_format($meters, 0, ',', '.').' m' : null;
    }
}

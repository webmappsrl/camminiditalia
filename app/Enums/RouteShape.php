<?php

namespace App\Enums;

/**
 * Forma del percorso di un cammino (Layer), dedotta dalla topologia delle
 * tappe (EcTrack associate), non da un ordine esplicito (che nel DB non
 * esiste).
 *
 * Specifico di camminiditalia: "discontinuo" descrive l'aggregazione di più
 * tappe in un cammino, concetto non presente in wm-package (che ha solo
 * OsmWalkingNetwork e Season). I valori sono identificatori stabili in
 * inglese minuscolo: la resa all'utente passa sempre da label().
 */
enum RouteShape: string
{
    case ROUNDTRIP = 'roundtrip';
    case LINEAR = 'linear';
    case DISCONTINUOUS = 'discontinuous';

    /**
     * Etichetta tradotta in una lingua specifica, senza dipendere dalla
     * locale attiva: serve a comporre la mappa delle traduzioni persistita
     * in properties->attributes, che deve contenere tutte le lingue del
     * progetto e non solo quella della richiesta corrente.
     */
    public function labelIn(string $locale): string
    {
        return match ($this) {
            self::ROUNDTRIP => __('Roundtrip', [], $locale),
            self::LINEAR => __('Linear', [], $locale),
            self::DISCONTINUOUS => __('Discontinuous', [], $locale),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ROUNDTRIP => __('Roundtrip'),
            self::LINEAR => __('Linear'),
            self::DISCONTINUOUS => __('Discontinuous'),
        };
    }

    /**
     * @return array<string, string> value => label
     */
    public static function toArray(): array
    {
        return array_reduce(self::cases(), function (array $carry, RouteShape $shape) {
            $carry[$shape->value] = $shape->label();

            return $carry;
        }, []);
    }
}

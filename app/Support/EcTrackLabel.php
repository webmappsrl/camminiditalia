<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Wm\WmPackage\Models\EcTrack;

/**
 * Nome leggibile di una tappa (EcTrack) e ordinamento naturale per nome
 * ("Tappa 2" prima di "Tappa 10"), usati da Nova, mail e service del passaporto.
 */
class EcTrackLabel
{
    public static function for(EcTrack $track): string
    {
        $label = trim((string) $track->getTranslation('name', app()->getLocale(), true));

        if ($label === '') {
            $label = trim((string) collect($track->getTranslations('name'))->first(fn ($value) => trim((string) $value) !== ''));
        }

        return $label !== '' ? $label : '#'.$track->id;
    }

    /**
     * @template TKey of array-key
     *
     * @param  Collection<TKey, EcTrack>  $tracks
     * @return Collection<int, EcTrack>
     */
    public static function sortNatural(Collection $tracks): Collection
    {
        return $tracks
            ->sort(fn (EcTrack $a, EcTrack $b) => strnatcasecmp(self::for($a), self::for($b)))
            ->values();
    }
}

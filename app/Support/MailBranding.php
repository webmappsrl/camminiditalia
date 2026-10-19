<?php

namespace App\Support;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

/**
 * Immagini del brand nelle mail (oc:8671): icon dell'App in testata, logo del
 * cammino (media `logo` del Layer) nel corpo. URL assoluti dallo storage, null
 * se l'immagine manca: la mail mostra allora solo il testo.
 */
class MailBranding
{
    public static function appIconUrl(?int $appId): ?string
    {
        $app = $appId !== null ? App::find($appId) : App::query()->orderBy('id')->first();

        return $app?->getFirstMediaUrl('icon') ?: null;
    }

    public static function routeLogoUrl(?Layer $layer): ?string
    {
        return $layer?->getFirstMediaUrl('logo') ?: null;
    }
}

<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

/**
 * Il logo nell'header di Nova (oc:9040, quarta parte).
 *
 * Per un Validator il cui primo layer (`user_id` suo, `id` più basso, come la dashboard
 * «Statistiche») ha il media `logo`: un SVG con il logo di Cammini d'Italia e, accanto, il logo del
 * cammino. All'apertura della pagina Cammini d'Italia c'è intero, poi si rimpicciolisce al 60% e
 * compare il logo del cammino: l'animazione è in resources/css/nova/brand.css, sulle classi
 * `cammini-brand-*`, perché l'SVG non può avere `<style>` né `id` (Nova lo inserisce due volte con
 * innerHTML). Le proporzioni dei due loghi non cambiano: solo una scala uniforme e
 * `preserveAspectRatio`.
 *
 * Per tutti gli altri, e se qualcosa manca, il file di config('nova.brand.logo'), come prima.
 */
class NovaBrandLogo
{
    /** Altezza del logo nell'header di Nova, in px */
    private const HEIGHT = 36;

    /** Logo di Cammini d'Italia: il PNG del cliente è 1080 × 328 */
    private const CDI_WIDTH = 1080 / 328 * self::HEIGHT;

    /** Scala finale del logo di Cammini d'Italia */
    private const CDI_FINAL_SCALE = 0.6;

    private const GAP = 8;

    /** Box del logo del cammino */
    private const ROUTE_BOX_WIDTH = 100;

    public static function forUser(?Authenticatable $user): string
    {
        $default = self::defaultLogo();
        $routeLogoUrl = self::routeLogoUrl($user);

        if ($routeLogoUrl === null) {
            return $default;
        }

        $cdiImage = self::cdiImageHref($default);

        if ($cdiImage === null) {
            return $default;
        }

        return self::combined($cdiImage, $routeLogoUrl);
    }

    private static function defaultLogo(): string
    {
        return (string) file_get_contents(config('nova.brand.logo'));
    }

    private static function routeLogoUrl(?Authenticatable $user): ?string
    {
        if (! $user instanceof User || ! $user->hasRole('Validator') || $user->hasRole('Administrator')) {
            return null;
        }

        $firstLayer = Layer::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->orderBy('id')
            ->first();

        return MailBranding::routeLogoUrl($firstLayer);
    }

    /**
     * L'immagine incorporata nel logo di oggi: così il logo di Cammini d'Italia resta uno solo, il
     * file di config('nova.brand.logo').
     */
    private static function cdiImageHref(string $svg): ?string
    {
        return preg_match('/<image\s[^>]*href="(data:image\/png;base64,[^"]+)"/', $svg, $match) ? $match[1] : null;
    }

    private static function combined(string $cdiImage, string $routeLogoUrl): string
    {
        $height = self::HEIGHT;
        $cdiWidth = round(self::CDI_WIDTH, 2);
        $separatorX = round(self::CDI_WIDTH * self::CDI_FINAL_SCALE + self::GAP, 2);
        $routeX = $separatorX + 1 + self::GAP;
        $width = $routeX + self::ROUTE_BOX_WIDTH;
        $routeBox = self::ROUTE_BOX_WIDTH;
        $routeHref = htmlspecialchars($routeLogoUrl, ENT_QUOTES | ENT_XML1);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$width} {$height}" role="img" aria-label="Cammini d’Italia" style="height:{$height}px;width:auto"><g class="cammini-brand-cdi"><image href="{$cdiImage}" x="0" y="0" width="{$cdiWidth}" height="{$height}"/></g><g class="cammini-brand-after"><rect x="{$separatorX}" y="7" width="1" height="22" fill="#c7cccd"/><image class="cammini-brand-route" href="{$routeHref}" x="{$routeX}" y="0" width="{$routeBox}" height="{$height}" preserveAspectRatio="xMinYMid meet"/></g></svg>
SVG;
    }
}

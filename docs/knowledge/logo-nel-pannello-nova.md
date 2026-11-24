# Logo nel pannello Nova

## Come funziona oggi

`config/nova.php` → `brand.logo` punta a `resources/img/camminiditalia-logo.svg`: il disco ufficiale
di Cammini d'Italia e, accanto, il nome «Cammini d'Italia» in Instrument Sans SemiBold, convertito
in tracciati. Il file si genera con `docs/features/9031-cammini-logo-sul-backend/build-logo.py`
(istruzioni nel docstring), non si modifica a mano.

Vincoli che vengono da Nova 5 (`vendor/laravel/nova/resources/js`):

- `PassthroughLogo.vue` inserisce l'SVG nella pagina con `innerHTML`, due volte (header e menu
  mobile, `layouts/MainHeader.vue`): niente attributi `id` e niente `<style>` che tocchi altro
  che la classe `cdi-logo-name`. `tests/Feature/NovaBrandLogoTest.php` lo verifica.
- Nova aggiunge all'`<svg>` la classe `h-6` (header) o `h-8` (login): l'altezza è fissata dallo
  `style` inline dell'`<svg>`, 36 px. Nell'header il link del logo ha 200 px utili di larghezza
  (`lg:w-60` meno `ml-2` e `px-4`); il logo ne occupa 172.
- Il tema scuro è la classe `dark` su `<html>` (`Dropdowns/ThemeDropdown.vue`): il colore del
  nome cambia con `.dark .cdi-logo-name`.
- Se il file manca, `Nova::logo()` restituisce il percorso come testo e il frontend non disegna
  nemmeno la pagina di login.

La scritta «Unregistered» accanto al logo non riguarda il logo: è la licenza, vedi
[docs/howto/licenza-nova.md](../howto/licenza-nova.md).

## Perché così

- **Il nome accanto al disco è un'eccezione voluta al design system** (oc:9031). Il design system
  di Cammini d'Italia vieta di «mettere accanto al disco un nome riscritto con un altro carattere»
  (`project/03-logo.md`, `components/LogoUsi`). L'hanno deciso Carla Cupani e Rubens Garofalo per
  allineare l'header a quello di osm2cai: all'altezza dell'header la scritta dentro il disco non
  si legge. Chi ridisegna l'header non tolga il nome credendolo un errore.
- **Colore del nome per tema** (oc:9031): `primario-700` `#BB4613` sul chiaro (5,25:1 sul bianco),
  `primario` `#F07821` sullo scuro (5,16:1). È la regola del design system: `primario` solo per i
  titoli grandi, `primario-700` per il testo piccolo. `primario` sul bianco sarebbe 2,83:1.
- **Tutto nell'SVG, niente CSS sull'header** (oc:9031): osm2cai aggiunge il nome con `::after` e
  selettori sull'HTML dell'header di Nova, che un aggiornamento può rompere senza errori.

## Come ci siamo arrivati

- **Solo il disco** (oc:9031, scartato): conforme al design system, ma a 24–36 px la scritta dentro
  il disco non si legge.
- **Nome in Instrument Sans Bold, maiuscole al 40% del disco** (oc:9031, scartato): troppo pesante a
  schermo; scelto SemiBold al 34% fra nove varianti.

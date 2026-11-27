# Logo nel pannello Nova

## Come funziona oggi

`config/nova.php` → `brand.logo` punta a `resources/img/camminiditalia-logo.svg`: un SVG che
incorpora come data URI (`<image href="data:image/png;base64,…">`) il **logo ufficiale mandato dal
cliente**, disco e scritta «CAMMINI D'ITALIA» in grigio scuro (PNG 1080×328). L'originale è in
`docs/features/9040-personalizzazione-laravel/logo-cliente.png`: per cambiare logo si rigenera
l'SVG da lì. **Non usare `docs/features/9031-cammini-logo-sul-backend/build-logo.py`**: genera il
logo di prova di oc:9031 e lo rimetterebbe senza errori.

Vincoli che vengono da Nova 5 (`vendor/laravel/nova/resources/js`):

- `Nova::logo()` (`vendor/laravel/nova/src/Nova.php:898-906`) legge il file con
  `file_get_contents()` e `PassthroughLogo.vue` lo inserisce nella pagina con `innerHTML`, due
  volte (header e menu mobile, `layouts/MainHeader.vue`): deve essere un SVG, un PNG grezzo non
  funziona; niente attributi `id` e niente `<style>`. `tests/Feature/NovaBrandLogoTest.php` lo
  verifica, e verifica anche che il PNG incorporato sia leggibile e 1080×328: un base64 troncato
  non rompe l'XML, ma il logo sparisce.
- Nova aggiunge all'`<svg>` la classe `h-6` (header) o `h-8` (login): l'altezza è fissata dallo
  `style` inline dell'`<svg>`, 36 px. Nell'header il link del logo ha 200 px utili di larghezza
  (`lg:w-60` meno `ml-2` e `px-4`); il logo ne occupa circa 119.
- Il data URI pesa circa 48 KB e arriva in ogni pagina HTML di Nova, login compresa (non è un
  asset separato con la sua cache).
- **Dark mode:** il tema scuro è la classe `dark` su `<html>`. Il PNG ha una sola versione, con la
  scritta grigio scuro: sulla barra scura si legge male.
- Se il file manca, `Nova::logo()` restituisce il percorso come testo e il frontend non disegna
  nemmeno la pagina di login.

La scritta «Unregistered» accanto al logo non riguarda il logo: è la licenza, vedi
[docs/howto/licenza-nova.md](../howto/licenza-nova.md).

Colori e caratteri dell'interfaccia di Nova: [colori-e-caratteri-di-nova.md](colori-e-caratteri-di-nova.md).

### Logo del cammino accanto a Cammini d'Italia (oc:9040)

- Per un Validator senza ruolo Administrator il cui primo layer (`user_id` suo, `id` più basso, come la dashboard) ha il
  media `logo`, `App\Support\NovaBrandLogo::forUser()` costruisce un SVG con il logo di Cammini
  d'Italia (la stessa immagine incorporata nel file di `brand.logo`) e il logo del cammino in un
  `<image>` con l'URL del media, in un box di 100 × 36 px con `preserveAspectRatio="xMinYMid meet"`.
  Per tutti gli altri, e se manca qualcosa, il file di `brand.logo` com'è.
- Arriva a Nova con `Nova::provideToScript(['logo' => fn ($request) => …])` in `NovaServiceProvider`:
  sostituisce la variabile che Nova calcola da `brand.logo` (`vendor/laravel/nova/src/Nova.php`,
  `provideToScript()`), e Nova la risolve a ogni richiesta. **A un aggiornamento di Nova va
  ricontrollato che la chiave si chiami ancora `logo`.**
- L'animazione sta in `resources/css/nova/brand.css`, sulle classi `cammini-brand-cdi` e
  `cammini-brand-after`, perché l'SVG non può avere `<style>`: dopo 900 ms Cammini d'Italia si
  rimpicciolisce al 60% con una scala uniforme e compare il logo del cammino. Nova cambia pagina
  senza ricaricare l'header, quindi parte solo all'apertura o alla ricarica; con «riduci movimento»
  si vede subito lo stato finale.
- I file dei loghi stanno sul disco `wmfe`: in locale non ci sono (dump senza media), quindi il box
  del cammino resta vuoto.
- Test: `tests/Feature/NovaRouteLogoTest.php`.

### Favicon (oc:9040)

Il disco di Cammini d'Italia, ritagliato con GD dal PNG del cliente, in
`public/favicon-camminiditalia-32.png` e `-180.png` (apple-touch-icon), dichiarato in
`resources/views/vendor/nova/partials/meta.blade.php`, il partial che Nova include nell'`<head>` di
ogni pagina, login compresa. `public/favicon.ico` resta vuoto, com'era.

## Perché così

- **Logo del cammino accanto, non al posto di Cammini d'Italia** (call interna del 26/11, Giuseppe:
  «No, no, accanto, accanto»). Animazione scelta da Alessandro il 27/11 fra le prove dell'artifact
  «Logo del cammino in Nova» (https://claude.ai/artifact/AFRQkabudmt9t6kcgAMFQF): nei 200 px
  dell'header un logo orizzontale affiancato a quello intero diventava illeggibile.

- **Il PNG del cliente dentro un SVG, con lo stesso nome di file** (oc:9040): Nova vuole un SVG,
  e il cliente ha mandato un PNG. Tenere `camminiditalia-logo.svg` lascia invariato
  `config/nova.php`, come chiesto allo scrum del 25/11 («lo chiami esattamente come si chiamava il
  logo prima»).
- **Niente nome accanto al disco** (oc:9040): la scritta «CAMMINI D'ITALIA» è già nel logo del
  cliente, ed è grande abbastanza da leggersi a 36 px di altezza (verificato riducendo il PNG).
- **Tutto nell'SVG, niente CSS sull'header** (oc:9031): osm2cai aggiunge il nome con `::after` e
  selettori sull'HTML dell'header di Nova, che un aggiornamento può rompere senza errori. Dal oc:9040 l'animazione del logo del cammino è in `brand.css`, ma su classi scritte dall'SVG stesso, non su selettori dell'header di Nova: quel rischio non c'è.

## Domande aperte

- **Logo per il dark mode** (oc:9040): chiedere al cliente il logo in SVG, o una versione con la
  scritta chiara.

## Come ci siamo arrivati

- **Disco + nome «Cammini d'Italia» disegnato accanto, in SVG** (oc:9031, superato da oc:9040):
  era un'eccezione voluta al design system di Cammini d'Italia, che vieta di «mettere accanto al
  disco un nome riscritto con un altro carattere» (`project/03-logo.md`, `components/LogoUsi`);
  l'avevano decisa Carla Cupani e Rubens Garofalo per allineare l'header a quello di osm2cai,
  perché a quell'altezza la scritta dentro il disco non si legge. Il nome era in Instrument Sans
  SemiBold convertito in tracciati, con colore per tema: `primario-700` `#BB4613` sul chiaro
  (5,25:1 sul bianco), `primario` `#F07821` sullo scuro (5,16:1). Superato quando il cliente ha
  mandato il logo definitivo con la scritta, e Peppe allo scrum del 25/11 ha detto di togliere il
  nome perché già contenuto nel logo.
- **Solo il disco** (oc:9031, scartato): conforme al design system, ma a 24–36 px la scritta dentro
  il disco non si legge.
- **Nome in Instrument Sans Bold, maiuscole al 40% del disco** (oc:9031, scartato): troppo pesante a
  schermo; scelto SemiBold al 34% fra nove varianti.

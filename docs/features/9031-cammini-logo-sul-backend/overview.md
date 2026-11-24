> Ticket: oc:9031

# [cammini] logo sul backend

## Cosa cambia

Il pannello Nova di Cammini d'Italia (`camminiditalia.maphub.it/nova`) mostra, al posto del logo
di Laravel Nova, il logo ufficiale di Cammini d'Italia con accanto il nome «Cammini d'Italia» in
arancio, nell'header e nella pagina di login, come fa osm2cai con «OSM2CAI». E non mostra più la
scritta rossa «Unregistered» accanto al logo.

Le due richieste si risolvono in due modi diversi:

- **«Unregistered»: risolto senza codice, già in produzione.** La scritta viene da
  `LicenseWarning.vue` di Nova e compare quando `Nova::checkLicenseValidity()` è falso
  (`vendor/laravel/nova/src/Nova.php:875-893`). La causa era doppia: il dominio non era fra le
  «Production URLs» della licenza Unlimited, e il server non mandava nessuna chiave
  (`NOVA_LICENSE_KEY` non esiste in nessun `.env` del repo). Il 24/11 il dominio è stato
  registrato, è stata creata la chiave dedicata `camminiditalia` (la verifica verso
  `nova.laravel.com/api/license-check` risponde 204) e Rubens l'ha messa nel `.env` di produzione;
  da loggati la scritta non c'è più. Nel repo resta da scrivere la procedura, presa dalla
  documentazione ufficiale di Nova, perché il prossimo shard non ripeta il trucco del CSS.
- **Logo: un file SVG e una modifica alla config.** L'SVG contiene il disco ufficiale e, accanto,
  il nome «Cammini d'Italia» già convertito in tracciati; `brand.logo` lo punta. Nessun CSS
  sull'header di Nova.

## Perché

Richiesta del cliente nel ticket, ripresa da Giuseppe Bonfanti allo scrum del 24/11 (08:29), che
ha chiesto di risolvere «Unregistered» alla radice, registrando il sito sulla licenza, e non
nascondendolo con il CSS come è stato fatto su Forestas
(`resources/css/nova.css` con `a[href='https://nova.laravel.com/licenses'] { display: none }`).

Il nome accanto al disco l'hanno deciso Carla Cupani e Rubens Garofalo, per far assomigliare
l'header a quello di osm2cai (`osm2cai2/public/css/custom-nova.css`, oc:5449). È un'**eccezione
voluta al design system di Cammini d'Italia**, che vieta di «mettere accanto al disco un nome
riscritto con un altro carattere» (`project/03-logo.md`, `components/LogoUsi`): all'altezza
dell'header la scritta dentro il disco non si legge, e il nome accanto la rende leggibile.

Il risultato è lo stesso di osm2cai, ma lo si ottiene dentro l'SVG invece che con il CSS:
osm2cai aggiunge il testo con `::after` e ingrandisce il logo con selettori sull'HTML dell'header
di Nova (`header a[href*="dashboards"] span svg`), che un aggiornamento di Nova può rompere senza
errori, e il testo esce nel font di Nova.

Per il colore del nome si applica la regola del design system sul testo: `primario` solo per i
titoli grandi, `primario-700` per il testo piccolo (`components/Palette`, `components/Titoli`).

## Requisiti

- [x] `resources/img/camminiditalia-logo.svg` contiene, in un unico `<svg>`:
  - il disco ufficiale, con forme e colori invariati: lo stesso SVG che camminiditalia.org mostra
    nell'header (verificato identico, `path` per `path`, al `logo.svg` del design system);
  - alla sua destra il nome «Cammini d'Italia» in Instrument Sans (il carattere dei titoli
    nel design system; peso e dimensione scelti a schermo: SemiBold, vedi `notes.md`), convertito in tracciati così che non dipenda dai font caricati da Nova;
  - il colore del nome è `primario-700` `#BB4613` sul tema chiaro (contrasto 5,25:1 sul bianco)
    e `primario` `#F07821` sul tema scuro (5,16:1 su `#1E293B`). Lo decide un solo `<style>`
    nell'SVG, limitato alla classe univoca `cdi-logo-name` e alla classe `dark` che Nova mette su
    `<html>` (`vendor/laravel/nova/resources/js/components/Dropdowns/ThemeDropdown.vue:86-107`),
    così segue anche la scelta manuale del tema
  - nessun attributo `id`: Nova inserisce l'SVG due volte nella stessa pagina, nell'header e nel
    menu mobile (`MainHeader.vue:21,101`)
- [x] Lo script che genera l'SVG (disco + testo convertito con `fonttools`, kerning applicato con
      `uharfbuzz`) è salvato in
      `docs/features/9031-cammini-logo-sul-backend/`, così il logo si può rigenerare
- [x] L'altezza del logo è fissata con uno `style` sull'`<svg>`, che vince sulla classe `h-6`
      che Nova aggiunge (`MainHeader.vue:21`, `Auth.vue:4` con `h-8`;
      `components/PassthroughLogo.vue` copia l'SVG così com'è). Largo al massimo 200 px: il link
      del logo è alto `h-12` e sta in un contenitore `lg:w-60` (240 px) meno `ml-2` e `px-4`
      (`MainHeader.vue:15,18`)
- [x] `config/nova.php`: `brand.logo` punta a `resource_path('img/camminiditalia-logo.svg')`;
      `brand.colors` resta com'è
- [x] Nessun CSS sull'header di Nova, né per il logo né per «Unregistered»
- [x] Il logo si vede proporzionato e leggibile nell'header e nella pagina di login, in tema
      chiaro e scuro, a larghezza desktop, tablet (768–1023 px, dove il contenitore non ha
      larghezza fissa) e nel menu mobile
- [x] `.env-example` documenta `NOVA_LICENSE_KEY=` vuota, come promemoria per chi installa
- [x] Un test verifica che `Nova::logo()` restituisca un SVG valido con radice `<svg>`, senza
      attributi `id`, con l'unico `<style>` limitato a `cdi-logo-name`, e che il file esista
      (se manca, `Nova::logo()` restituisce il percorso come testo e il frontend di Nova non
      disegna nemmeno la pagina di login)
- [x] `docs/howto/licenza-nova.md` descrive la procedura con le fonti ufficiali
      (https://nova.laravel.com/docs/v5/installation): production URL nella pagina della licenza
      («Add site»), chiave dedicata per shard («Create License Key»), `NOVA_LICENSE_KEY` nel `.env`
      del server, ricostruire la cache della config (il deploy esegue `php artisan optimize`,
      `scripts/deploy_prod.sh:11`), verificare con `php artisan nova:check-license` e non con il
      `validLicense` della pagina di login, che legge solo la cache; e cosa non vale (CSS che
      nasconde la scritta, esclusione `dev.` solo come primo sottodominio)

## Rischi

- **Server dev.** `camminiditalia.dev.maphub.it` non è registrato e la verifica risponde 403:
  l'esclusione dei sottodomini `dev.` della documentazione non vale quando `dev.` sta in mezzo al
  dominio. Sul dev «Unregistered» resta, nello stesso contenitore del logo più largo: l'header
  del dev apparirà diverso da quello di produzione finché non si registra anche quel dominio.
- **Contrasto del disco.** Il design system chiede almeno 3:1 fra l'anello esterno (`#F05724`) e
  il fondo; sul bianco vale 3,45:1 e sui fondi scuri 4,4–6,1:1.

## Out of scope

- La rimozione del CSS che nasconde «Unregistered» su Forestas e su osm2cai, e la registrazione
  dei loro domini
- La registrazione di `camminiditalia.dev.maphub.it` sulla licenza
- Favicon e colori del pannello (`brand.colors`)
- La modifica del `.env` dei server: la fa chi ha accesso, non passa dal deploy

## Moduli toccati

Tutto nel repo principale `camminiditalia`; nessuna modifica a `wm-package`.

- `resources/img/camminiditalia-logo.svg` (nuovo)
- `config/nova.php`
- `.env-example`
- `tests/Feature/NovaBrandLogoTest.php` (nuovo)
- `docs/howto/licenza-nova.md` (nuovo)
- `docs/knowledge/logo-nel-pannello-nova.md` (nuovo)
- `docs/features/9031-cammini-logo-sul-backend/build-logo.py` (nuovo, script di generazione)

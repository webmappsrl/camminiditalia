> Ticket: oc:9031

# Notes — [cammini] logo sul backend

## Divergenze dal piano, task per task

### Task 3 — Script di generazione dell'SVG

- **Carattere SemiBold, maiuscole al 34% del disco** (il piano diceva Bold e circa il 40%). La
  prima versione, Bold al 40% e larga 198 px, a schermo era troppo pesante. Fra nove varianti
  (Bold/SemiBold/Medium × 0.40/0.34/0.30) la dev ha scelto **SemiBold · 0.34**: logo largo 172 px
  a 36 px di altezza. Il rapporto si cambia con la variabile d'ambiente `CAP_HEIGHT_RATIO`.
- **Comando**: serve anche `uharfbuzz`, che applica il kerning della tabella `GPOS` come un
  browser: `uv run --with fonttools --with uharfbuzz python -I build-logo.py …` (docstring dello
  script).
- **Sorgenti fuori dal repo** (il piano prevedeva di salvarli nella cartella della feature).
  - Il font è sotto SIL OFL 1.1 (metadati `name` 13 e 14), che chiede di distribuirlo con copyright
    e licenza: per non portarsi dietro anche quelli, lo script lo riceve come percorso. File usato:
    `InstrumentSans-SemiBold.ttf` del design system di Cammini d'Italia, sha256
    `cb2231708f82dfef7c92c9749cd76e456033b710c677841b01e3e200348394f2`
    (anche su https://github.com/Instrument/instrument-sans).
  - Il disco è l'SVG dell'header di camminiditalia.org, uguale `path` per `path` al `logo.svg` del
    design system; sha256 del file usato
    `c6aec2ed7c0ad884e7fa5b17c5eb8e01e34d13ee8588163fe1f9f82fbebec5e8`. I suoi `path` sono anche
    dentro `resources/img/camminiditalia-logo.svg`, quindi si possono estrarre da lì.

### Task 5 — Altezza e prova visiva

- Prova a schermo a 1512 px invece di 1280 (la finestra del browser), oltre a 900 px e al menu
  mobile a 400 px: le misure che contano sono quelle del contenitore, uguali da 1024 px in su.

### Task 9 — Notes e CLAUDE.md

- Nessuna riga nel `CLAUDE.md`: la dev non lo può modificare. Il rimando a
  `docs/knowledge/logo-nel-pannello-nova.md` e a `docs/howto/licenza-nova.md` va nella
  descrizione del ticket oc:9031 su Orchestrator.

## Bug trovati

- Nessuno nel codice. In locale (`localhost:8000`) Nova 5.7.6 mostra «Unregistered» perché il
  `.env` locale non ha `NOVA_LICENSE_KEY`, nonostante la documentazione escluda `localhost` dal
  controllo. Accanto al logo nuovo la scritta esce dai 240 px del contenitore e tocca la ricerca
  dell'header: succede solo dove la licenza non è valida (locale e server dev), non in produzione.

## Decisioni

- **Nome accanto al disco, eccezione al design system**, decisa da Carla Cupani e Rubens Garofalo
  per allineare l'header a osm2cai. Il design system lo vieta (`project/03-logo.md`,
  `components/LogoUsi`). Il perché è in `docs/knowledge/logo-nel-pannello-nova.md`.
- **Tutto dentro l'SVG, niente CSS sull'header di Nova** (scelta B, contro il CSS `::after` di
  osm2cai): regge agli aggiornamenti di Nova e usa il carattere del design system.
- **Colore del nome per tema**: `primario-700` `#BB4613` sul tema chiaro (5,25:1 sul bianco),
  `primario` `#F07821` sul tema scuro (5,16:1). Regola del design system: `primario` solo per i
  titoli grandi, `primario-700` per il testo piccolo. Il selettore è `.dark .cdi-logo-name`
  perché Nova cambia tema con la classe `dark` su `<html>`, anche quando il tema è scelto a mano.
- **«Unregistered» risolto senza codice**: dominio `camminiditalia.maphub.it` registrato fra le
  Production URLs della licenza Unlimited, chiave dedicata `camminiditalia`, messa da Rubens nel
  `.env` di produzione il 24/11/2026. Procedura in `docs/howto/licenza-nova.md`.
- Nessun tag di contenuto associato al ticket: la dev ha preferito non creare `licenza-nova`.
- Nessun ticket per i 13 errori PHPStan preesistenti: scelta della dev.
- Review formale (`wm-review-ticket`): approvato senza bloccanti; applicati i cleanup (rimandi nel
  piano, pulizia di `build-logo.py`, test sulla larghezza massima di 200 px, hash dei sorgenti).

## Verifiche

- `NovaBrandLogoTest`: 6 test verdi, fra cui la larghezza massima nell'header.
- Pint su `tests/Feature/NovaBrandLogoTest.php` e `config/nova.php`: nessuna correzione.
- A schermo, in locale: header in tema chiaro e scuro a 1512 px, a 900 px e nel menu mobile;
  pagina di login nei due temi. Il logo è 172 × 36 px, il disco resta un cerchio, il colore del
  nome cambia con il tema.
- PHPStan: 13 errori, tutti in file fuori da questo diff (`tests/Feature/LayerFeatureControllerTest.php`,
  `PassportRouteShareApiTest.php`, `PassportStageShareApiTest.php`, `RouteShareImageServiceTest.php`,
  `StageShareImageServiceTest.php`).

## Follow-up

- Registrare `camminiditalia.dev.maphub.it` fra le Production URLs e mettere la chiave nel `.env`
  del dev, se si vuole il dev senza «Unregistered».
- Forestas e osm2cai nascondono «Unregistered» con il CSS: vanno sistemati con la stessa procedura.
- Una versione orizzontale ufficiale del logo, disegnata da chi ha creato il marchio, renderebbe
  inutile l'eccezione al design system.
- `brand.colors` del pannello è ancora verde acqua, accanto al nome arancio: allinearlo è un
  lavoro a parte.

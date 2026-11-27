# Colori e caratteri di Nova

## Come funziona oggi

L'interfaccia di Nova usa i colori e i caratteri del design system di Cammini d'Italia (artifact
«Design System», `project/README.md` e `project/tokens.json`), dal oc:9040. Il logo è a parte:
[logo-nel-pannello-nova.md](logo-nel-pannello-nova.md).

| Cosa | Dove | Valore |
|---|---|---|
| Colore principale (pulsanti, link, `MenuItem` attivo, focus) | `config/nova.php` → `brand.colors` | scala del `primario`, 500 = #F07821 |
| Grigi di Nova (fondi, bordi, testo minore) | `resources/css/nova/brand.css`, `--colors-gray-*` | scala del `secondario` |
| Caratteri | `brand.css`, `@font-face` + `public/fonts/camminiditalia/` | DM Sans (testo), Instrument Sans (`h1`–`h3`) |
| Card delle statistiche | `brand.css`, `--wm-analytics-*` | scala dell'arancio |
| Tracce sulle mappe senza colore del layer | `config/wm-package.php` → `map_default_track_color` | #F07821 |

- **`brand.colors`**: Nova trasforma ogni chiave in `--colors-primary-<chiave>`
  (`vendor/laravel/nova/src/Nova.php:1217`), quindi si può dare tutta la scala 50…950, in formato
  `r, g, b`. 100, 300, 400, 500, 600 e 700 sono del design system; 50, 200, 800, 900 e 950 li
  abbiamo ricavati noi (i commenti nel file dicono quali).
- **Grigi**: Nova li definisce in `:root` nel suo `app.css`, e i fogli di `Nova::style()` arrivano
  dopo (`vendor/laravel/nova/resources/views/layout.blade.php`), quindi `brand.css` li sovrascrive.
  La scala del `secondario` è spostata di un gradino rispetto ai nomi del design system
  (`gray-500` = `secondario-400`), perché la luminosità segua quella dei grigi di Nova: nel tema
  scuro Nova scrive in `gray-400` su `gray-800`/`gray-900`.
- **Caratteri**: il CSS di Nova scrive «Nunito Sans» in circa 90 regole, non in una variabile:
  `brand.css` lo sovrascrive con `body *` e `!important`, e rimette il monospace su `code`, `pre`,
  `kbd`, `samp` e `.font-mono`. I file sono i TTF del design system (licenza OFL), serviti dal
  nostro server: nessuna richiesta a Google Fonts.
- **Tema scuro**: tenuto. Il design system non ne ha uno; i valori scuri di `brand.css` (blocco
  `.dark`) li abbiamo scelti noi.
- **Card delle statistiche**: wm-package dà a ogni colore della card una variabile
  `--wm-analytics-*` con il colore di sempre come default; `brand.css` ne imposta 16 su 20 in
  `:root` e in `.dark` (`divider`, `divider-soft`, `tooltip-bg` e `tooltip-text` restano al
  default, che va bene nei due temi). Il grafico giornaliero le legge quando viene disegnato: dopo un cambio di tema serve una
  ricarica o un cambio di periodo. L'elenco delle variabili è nella pagina del package,
  [analytics-posthog.md](../../wm-package/docs/knowledge/analytics-posthog.md).

Test: `tests/Feature/NovaBrandTest.php` (colore principale, tracce, foglio di stile registrato).
Grigi e caratteri non hanno test: si verificano nel browser, nei due temi.

**A ogni aggiornamento di Nova** va ricontrollato che `--colors-gray-*` e `--colors-primary-*`
esistano ancora e che nessun componente sia tornato a Nunito Sans.

## Perché così

- **#F07821 anche se il bianco sui pulsanti sta a 2,6:1** (Alessandro, 26/11): è l'arancio del
  sito, e il design system lo lascia così segnalando la correzione come decisione aperta.
- **Tema scuro tenuto** (Alessandro, 26/11), con l'arancio e la scala del `secondario`.
- **Card in «scala dell'arancio»** (Alessandro, 26/11), scelta fra cinque prove nell'artifact
  «Prove colori Statistiche» (https://claude.ai/artifact/7PDenvjwbAiAW4NHdWBwTU):
  - Android `primario-700`, iOS `primario`, Webapp `primario-300`: tre serie distinte per
    luminosità, come chiede il design system per i grafici;
  - numeri in `primario`, perché sono testo grande, come i titoli del sito;
  - la prima prova, con iOS in `secondario`, faceva dominare l'ardesia.
- **Colori del package configurabili, non cambiati**: wm-package è usato da altri progetti; i
  colori di Cammini d'Italia stanno solo in questo repo.
- **Tracce in arancio**: il design system lo indica per la mappa dell'app; per Nova è una scelta di
  Alessandro (26/11). Un layer con `properties.color` resta del suo colore.

## Come ci siamo arrivati

- **Verde acqua di Nova** (`24, 182, 155`, tre tonalità) fino al oc:9040.

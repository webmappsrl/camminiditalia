> Ticket: oc:9040

# Personalizzazione Laravel: logo definitivo e dashboard «Statistiche»

## Cosa cambia

1. **Logo del pannello Nova.** `resources/img/camminiditalia-logo.svg` (stesso nome, stesso
   `brand.logo` in `config/nova.php`) contiene il logo ufficiale inviato dal cliente
   (`logo___scritta_grigio.png`, 1080×328, disco + scritta «CAMMINI D'ITALIA» grigio scuro) al
   posto del logo di prova di oc:9031. Il nome «Cammini d'Italia» disegnato accanto al disco
   sparisce, perché è già nell'immagine.
2. **Dashboard `Main` → «Statistiche».** La dashboard `App\Nova\Dashboards\Main` si chiama
   «Statistiche», perde la card `Help` di Nova e mostra all'Administrator la card aggregata di
   tutti i cammini, `LayerAnalyticsCard::global()`, che oggi sta sopra l'index della Resource
   Layer.
3. **Index della Resource Layer senza card.** La card aggregata non compare più sopra l'index
   dei Layer: `App\Nova\Layer::canSeeGlobalAnalyticsCard()` restituisce `false` per tutti.
   `wm-package` non si tocca.

## Perché

Il cliente (Davide, oc:9040) ha mandato il logo definitivo e ha chiesto di rinominare «Main» in
«Statistiche» mettendoci le statistiche. Allo scrum del 25/11 Peppe ha confermato entrambe le
parti nello stesso ticket, come lavoro di spostamento: il logo si sostituisce tenendo lo stesso
nome di file, le card si spostano senza riscriverle («devi solo spostare»). Scadenza: oggi.

## Requisiti

- [ ] `resources/img/camminiditalia-logo.svg` è un SVG che incorpora il PNG del cliente
      (`<image>` con data URI base64): Nova inserisce il file nella pagina con
      `file_get_contents()` (`vendor/laravel/nova/src/Nova.php:898-906`), quindi un PNG grezzo
      non funziona.
- [ ] Il nuovo SVG rispetta i vincoli di Nova già documentati in
      `docs/knowledge/logo-nel-pannello-nova.md`: niente `id`, niente `<style>`, altezza 36 px
      nello `style` inline, larghezza nell'header ≤ 200 px (1080×328 a 36 px ≈ 119 px).
- [ ] Il nome «Cammini d'Italia» disegnato accanto al disco (classe `cdi-logo-name`) non c'è più.
- [ ] Il PNG originale del cliente è salvato in
      `docs/features/9040-personalizzazione-laravel/logo-cliente.png`; il commento in
      `config/nova.php:169-170` punta a quel file e non più a `build-logo.py`, e la pagina di
      conoscenza dice che `build-logo.py` non va più usato (rilanciarlo rimetterebbe il logo di
      prova di oc:9031).
- [ ] Dark mode del logo lasciato com'è: la scritta grigio scuro su sfondo scuro si legge male,
      accettato per ora (vedi Rischi).
- [ ] La dashboard si chiama «Statistiche» (`name()` tradotto); `uriKey()` resta `main`, così
      l'URL `/nova/dashboards/main` e la voce di menu `MenuSection::dashboard(Main::class)` non
      cambiano.
- [ ] La card `Help` di Nova non c'è più.
- [ ] L'Administrator vede nella dashboard `LayerAnalyticsCard::global()` alle **stesse
      condizioni** che oggi valgono sull'index dei Layer (`wm-package/src/Nova/Layer.php:288-307`):
      esiste almeno un Layer e la sua App ha `analytics_app_enabled` o `analytics_webapp_enabled`.
- [ ] Il Validator (e il Guest) apre «Statistiche» e la trova vuota: nessuna card, finché non
      decide Alessandro (vedi Domande aperte).
- [ ] Sopra l'index dei Layer non compare più la card aggregata, per nessun ruolo.
- [ ] La card del singolo layer nella detail view del Layer (`detailCards()`) e la mappa con i
      marker live restano come sono.
- [ ] Prima del merge il logo è controllato nel browser, sulla pagina di login e nell'header.
- [ ] Traduzioni: chiave nuova per «Statistics» in tutti i file `resources/lang/{it,en,fr,es,de}.json`
      (default effettivo `APP_LOCALE=en`).
- [ ] Test: `NovaBrandLogoTest` aggiornato al nuovo logo: tolti `test_logo_contains_the_name` e
      `test_logo_style_only_targets_the_name`, aggiunto un test che trova nell'SVG un `<image>`
      con `href` `data:image/png;base64,…`, lo decodifica e verifica con
      `getimagesizefromstring()` che sia un PNG 1080×328 (un logo malformato blocca anche la
      pagina di login di Nova);
      `LayerGlobalAnalyticsCardVisibilityTest` riscritto per verificare la card nella dashboard
      (Administrator sì, Validator no, analytics spento no) e la sua assenza sull'index dei Layer.

## Domande aperte (per Alessandro, reviewer dell'overview)

Rischi che allo scrum non erano stati considerati: questo ticket non li risolve.

1. **Il Validator nella dashboard.** Peppe ha detto di spostare le statistiche «anche quando sono
   validatore», ma il Validator sull'index dei Layer non ha nessuna card: la sua card esiste solo
   nella detail view di un layer, che sa quale layer mostrare dalla pagina aperta
   (`$request->findModelOrFail()`, `wm-package/src/Nova/Layer.php:273`). In una dashboard non c'è
   un layer aperto. **Proposta:** una `LayerAnalyticsCard($layer)` per ogni layer di cui il
   Validator è owner. Sul DB locale 19 Validator hanno almeno un layer: 18 ne hanno uno, il
   massimo è 3. L'endpoint dà già i dati solo all'owner o all'Administrator
   (`AnalyticsController.php:19-22`).
2. **La mappa con le persone in tempo reale.** Il cliente la chiede nella dashboard, ma non è una
   card: è il marker live del Field `FeatureCollectionMap` nella detail view del Layer
   (`wm-package/src/Nova/Layer.php:203-208`, `->onlyOnDetail()`), che disegna il percorso di quel
   cammino. In una dashboard andrebbe scritta da zero (per esempio una mappa di tutti i cammini).
3. **«Statistiche» è la pagina di arrivo dopo il login.** Nova manda ogni utente su
   `/dashboards/main` (`vendor/laravel/nova/src/Concerns/HandlesRoutes.php:18`, usato da
   `LoginResponse.php:19` e `HomeController.php:17`); camminiditalia non cambia `initialPath`.
   Dopo questo ticket il Validator e il Guest, a ogni login, arrivano su una dashboard vuota
   intitolata «Statistiche». Va deciso se, finché la loro dashboard è vuota, debbano arrivare su
   un'altra pagina (per esempio la Resource UgcPoi). Per l'Administrator il caricamento della card
   a ogni login (query PostHog, in cache con `rememberWithLock`) è voluto: è la pagina principale
   chiesta dal cliente.

## Rischi

- **Dark mode del logo:** la scritta del PNG è grigio scuro e sulla barra scura si legge male.
  Accettato per ora; va chiesto al cliente il logo in SVG o una versione con la scritta chiara.
- **Condizione analytics duplicata:** la dashboard deve ripetere il controllo «analytics acceso»
  che nel package sta in un metodo `protected` della Resource Layer. Se un giorno il package lo
  cambia, la dashboard non segue da sola.

## Out of scope

- Card per il Validator e mappa live nella dashboard (Domande aperte).
- Logo con colori diversi per il dark mode.
- Spostare la dashboard «Statistiche» in `wm-package` per gli altri progetti: ha senso solo
  quando lo chiede più di un progetto.

## Moduli toccati

Tutto nel repo principale (camminiditalia), nessuna modifica a `wm-package`.

- `resources/img/camminiditalia-logo.svg` — sostituito
- `app/Nova/Dashboards/Main.php` — nome, card
- `app/Nova/Layer.php` — `canSeeGlobalAnalyticsCard()` restituisce `false`
- `resources/lang/{it,en,fr,es,de}.json` — chiave «Statistics»
- `config/nova.php` — solo il commento sopra `brand.logo`
- `docs/features/9040-personalizzazione-laravel/logo-cliente.png` — PNG originale del cliente
- `tests/Feature/NovaBrandLogoTest.php`, `tests/Feature/LayerGlobalAnalyticsCardVisibilityTest.php`
- `docs/knowledge/logo-nel-pannello-nova.md` — riscritta a fine lavoro: la regola «non togliere il
  nome accanto al disco» (oc:9031) passa in «Come ci siamo arrivati», superata dal logo del cliente
  e dalla decisione di Peppe allo scrum del 25/11
- `docs/knowledge/` — pagina nuova sulla dashboard «Statistiche»: dove sta la card globale e perché
  `canSeeGlobalAnalyticsCard()` restituisce `false`
- `CLAUDE.md` — **non si tocca** (la dev non può modificarlo): la riga di oc:8182, che indica
  `canSeeGlobalAnalyticsCard()` come il punto in cui decidere chi vede la card, diventa falsa; va
  segnalato nelle note dev del ticket

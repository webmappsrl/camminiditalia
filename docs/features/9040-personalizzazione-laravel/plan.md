> Ticket: oc:9040

# Piano camminiditalia — logo definitivo e dashboard «Statistiche»

Branch: `feature/oc-9040-personalizzazione-laravel` (esiste già, PR #110). Parte `wm-package`:
`wm-package/docs/features/9040-personalizzazione-laravel/plan.md`, da fare **prima** dei task
che usano `LayerMapCard` (Task 8 e i casi relativi del Task 7). Comandi `php artisan` dentro il
container (`docker exec laravel-camminiditalia …`).

**Nessun commit durante l'esecuzione**: i commit sotto sono istruzioni per la dev.

---

## Parte 1 — Logo

### Task 1 — PNG originale del cliente nel repo
- Copiare il PNG del cliente (1080×328 RGBA, 35 KB) in
  `docs/features/9040-personalizzazione-laravel/logo-cliente.png`.

### Task 2 — Test del logo (prima del logo)
File: `tests/Feature/NovaBrandLogoTest.php`
- Togliere `test_logo_contains_the_name`, `test_logo_style_only_targets_the_name` e la costante
  `NAME_CLASS`.
- Aggiungere `test_logo_has_no_style_element`: nessun `<style>`.
- Aggiungere `test_logo_embeds_the_customer_png`: un solo elemento `image` con `href` che inizia
  con `data:image/png;base64,`; `base64_decode(..., true)` non `false`;
  `getimagesizefromstring()` = 1080×328, `IMAGETYPE_PNG`.
- Docblock della classe: oc:9040 accanto a oc:9031.
- Lanciare: deve fallire.

### Task 3 — Nuovo `camminiditalia-logo.svg`
- Generato da `logo-cliente.png` con uno script una tantum (non versionato):
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1080 328" role="img"
  aria-label="Cammini d’Italia" style="height:36px;width:auto"><image href="data:image/png;base64,…"
  width="1080" height="328"/></svg>`. Niente `id`, `<style>`, `width`/`height`/`class`
  sull'`<svg>`; `href` SVG 2.
- Lanciare `NovaBrandLogoTest`: verde.

### Task 4 — Commento in `config/nova.php`
- Righe 169-170: il logo è il PNG del cliente
  (`docs/features/9040-personalizzazione-laravel/logo-cliente.png`) incorporato nell'SVG
  (oc:9040), non più generato da `build-logo.py`.

## Parte 2 — Statistiche

### Task 5 — Test della dashboard e delle pagine dei Layer (prima del codice)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-test-della-dashboard-e-delle-pagine-dei-layer)
File: `tests/Feature/LayerGlobalAnalyticsCardVisibilityTest.php` (riscritto, stesso nome di classe)
- Sempre via HTTP autenticato (`actingAs` + `getJson`): `Dashboard::cards()` non riceve la
  request e usa `request()->user()`.
- Endpoint della dashboard: `GET /nova-api/dashboards/main` (verificare la forma della risposta di
  Nova 5 al primo run).
- Casi:
  1. Administrator, analytics acceso, un Layer → `layer-analytics-card` con `mode = global`;
     nessuna `layer-map-card`.
  2. Administrator, analytics spento → nessuna card delle statistiche.
  3. Administrator, nessun Layer → nessuna card delle statistiche.
  4. Validator con tre layer (id crescenti) e analytics acceso → `layer-analytics-card` con
     `mode = layer` e `layer_id` = id più basso; `layer-map-card` con `resource_id` = id più
     basso; nessuna card degli altri due layer.
  5. Validator con analytics spento → solo `layer-map-card`.
  6. Validator senza layer e Guest → dashboard vuota.
  7. Nessun ruolo vede la card `help`.
  8. `GET /nova-api/layers/cards` (Administrator, analytics acceso) → nessuna `layer-analytics-card`.
  9. Cards della detail view del Layer (`GET /nova-api/layers/cards?resourceId={id}`, verificare
     l'endpoint) → nessuna `layer-analytics-card`, `LayerApiLinksCard` presente.
  10. Nome della dashboard = traduzione di «Statistics», `uriKey` = `main`.
- Lanciare: deve fallire.

### Task 6 — Pagine dei Layer
File: `app/Nova/Layer.php`
- `canSeeGlobalAnalyticsCard()` → `false`, con commento: le statistiche stanno nella dashboard
  «Statistiche» (oc:9040). Togliere `isAdministrator()` se non ha più chiamanti.
- Override di `detailCards(NovaRequest $request): array`: `parent::detailCards($request)` filtrato
  togliendo le istanze di `LayerAnalyticsCard` (`array_values(array_filter(...))`).

### Task 7 — Dashboard `Main` (senza mappa)
File: `app/Nova/Dashboards/Main.php`
- `name()`: `__('Statistics')`; `uriKey()`: `'main'` esplicito.
- `cards()`: utente da `request()->user()`.
  - Administrator: `LayerAnalyticsCard::global()` se il primo Layer (`Layer::query()->first()`)
    ha un'App con `analytics_app_enabled` o `analytics_webapp_enabled`.
  - Validator: primo layer = `Layer::where('user_id', $user->id)->orderBy('id')->first()`; se
    esiste e la sua App ha l'analytics acceso → `new LayerAnalyticsCard($layer)`.
  - Altrimenti `[]`. Tolta la card `Help`.
- La condizione «analytics acceso» in un metodo privato, con un commento che indica la fonte
  (`wm-package/src/Nova/Layer.php:271-307`), da riallineare a mano se il package la cambia.

### Task 8 — Mappa del Validator (dopo la parte `wm-package`)
- Aggiornare il puntatore del submodule al branch del package con `LayerMapCard`.
- In `Main::cards()`, per il Validator con un primo layer: aggiungere `new LayerMapCard($layer)`
  (sempre, anche con analytics spento: la mappa mostra comunque il percorso).
- Lanciare i casi 4-5 del Task 5: verdi.

### Task 9 — Traduzioni
- `resources/lang/{it,en,fr,es,de}.json`: `"Statistics"` → it «Statistiche», en «Statistics»,
  fr «Statistiques», es «Estadísticas», de «Statistiken». Rispettare l'ordine dei file.

## Parte 3 — Verifiche e documentazione

### Task 10 — Verifiche
- `docker exec laravel-camminiditalia php artisan test --filter='NovaBrandLogoTest|LayerGlobalAnalyticsCardVisibilityTest'`
- Suite completa: `docker exec laravel-camminiditalia php artisan test`
- `docker exec laravel-camminiditalia composer format`
- PHPStan (nel review-gate).
- Browser: login e header (logo chiaro e scuro); «Statistiche» come Administrator (card globale)
  e come Validator 1378 (card e mappa di Via Ellenica); index e detail view dei Layer senza card
  delle statistiche, mappa della detail view invariata.

### Task 11 — Documentazione
- `docs/knowledge/logo-nel-pannello-nova.md`: riscrivere «Come funziona oggi»; la regola sul nome
  accanto al disco e i suoi colori (oc:9031) in «Come ci siamo arrivati», superati dal logo del
  cliente e dalla decisione di Peppe allo scrum del 25/11 (oc:9040); `build-logo.py` da non usare.
- `docs/knowledge/dashboard-statistiche.md` (nuova): cosa vede ogni ruolo, «primo layer» = `id`
  più basso e perché, statistiche tolte dalle pagine dei Layer, override di `detailCards()` e
  `canSeeGlobalAnalyticsCard()`, condizione analytics duplicata, mappa senza aggiornamento
  automatico.
- `notes.md`: aggiornare con eventuali deviazioni.
- `CLAUDE.md` non si tocca: la riga di oc:8182 da aggiornare va nelle note dev del ticket.

## Commit (dopo il review-gate, nella PR #110)

1. `feat(oc:9040): logo del cliente nel pannello Nova` — Task 1-4
2. `feat(oc:9040): dashboard Statistiche, statistiche tolte dalle pagine dei Layer` — Task 5-7, 9
3. `chore(oc:9040): aggiorna wm-package con LayerMapCard` — solo il puntatore del submodule
   (commit a sé, nella stessa PR), dopo il merge della PR del package
4. `feat(oc:9040): mappa del layer nella dashboard del Validator` — Task 8
5. `docs(oc:9040): documentazione logo e dashboard Statistiche` — Task 11 + `docs/features/9040-*`

---

# Seconda parte — mappa globale dell'Administrator e posizioni aggiornate (Alessandro, 25/11)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** mappa di tutti i cammini nella dashboard dell'Administrator e mappe della dashboard che
si aggiornano ogni 90 s.

**Architecture:** codice quasi tutto in `wm-package` (vedi il suo `plan.md`, Seconda parte, task
P1-P7); qui la card nella dashboard e i test di tutta la seconda parte, perché i test del package
non si caricano dal consumer.

**Spec:** `docs/features/9040-personalizzazione-laravel/overview.md` (Seconda parte).

## Global Constraints

- Stesso branch e stessa PR #110; **nessun commit durante l'esecuzione**; test con
  `docker exec laravel-camminiditalia php artisan test --filter=…`.
- PostHog nei test: `config(['services.posthog.host' => 'https://posthog.test', 'services.posthog.project_id' => '1', 'services.posthog.personal_api_key' => 'k'])`
  e `Http::fake(['posthog.test/*' => Http::response(['results' => [[person_id, lat, lng, user_id], …]])])`.
- Ordine: task del package prima dei test che li usano (TDD: il test C si scrive prima del task P
  corrispondente).

## Review Focus

Vedi il piano del package: le stesse cinque condizioni, coperte dai test sotto e dalla verifica nel
browser (Task C6).

---

### Task C1: accesso agli endpoint nuovi (prima di P4)

**Files:** Create `tests/Feature/LiveMapEndpointsTest.php`

- [ ] **Step 1:** test, sempre via HTTP:
  - `test_global_live_positions_route_is_not_captured_by_layer_binding`: Administrator,
    `GET /nova-vendor/layer-analytics/live-positions` → 200, `type` = `FeatureCollection`.
  - `test_guest_without_login_gets_403` sui tre endpoint (`live-positions`, `all-routes`,
    `{id}/live-positions`).
  - `test_validator_cannot_read_global_endpoints` → 403 su `live-positions` e `all-routes`.
  - `test_validator_reads_only_own_layer_positions` → 200 sul proprio layer, 403 su quello di un altro.
  - `test_administrator_reads_any_layer_positions` → 200.
- [ ] **Step 2:** falliscono (404); dopo P4 passano.

### Task C2: posizioni e percorsi di tutti i cammini (prima di P1 e P3)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-c2-posizioni-e-percorsi-di-tutti-i-cammini)

**Files:** Create `tests/Feature/AllLayersLiveMapDataTest.php`

- [ ] **Step 1:** test:
  - `test_positions_near_any_layer_track_are_kept`: due layer con una EC track ciascuno, PostHog
    fake con tre persone (vicina alla traccia del layer A, vicina a quella del layer B, a 5 km da
    tutto) → `getRecentUserPositionsOnAllLayers()` restituisce le prime due.
  - `test_positions_near_a_track_without_layer_are_dropped`.
  - `test_positions_are_empty_when_posthog_is_not_configured` → `[]`, nessuna richiesta HTTP.
  - `test_positions_are_empty_without_layers`.
  - `test_all_routes_contains_only_layer_tracks_in_2d`: traccia non associata assente; coordinate
    con due valori e al massimo 5 decimali; `tooltip` = nome italiano.
- [ ] **Step 2:** falliscono; dopo P1 e P3 passano.

### Task C3: GeoJSON del layer con e senza posizioni (prima di P2)

**Files:** Modify `tests/Feature/LayerMapCardTest.php` (o file nuovo `LayerLivePositionsGeojsonTest.php`)

- [ ] `test_layer_geojson_keeps_live_positions_by_default`: PostHog fake con una persona vicina →
  il GeoJSON della route `feature-collection-map/layers/{id}` contiene un Point con
  `tooltip` = `Posizione utente (ultimi 30 minuti)`.
- [ ] `test_layer_geojson_without_live_positions`: con `?without_live_positions=1` nessun Point live,
  tracce presenti.

### Task C4: `LayerMapCard` (prima di P6)

**Files:** Modify `tests/Feature/LayerMapCardTest.php`

- [ ] `test_card_points_to_the_layer_geojson` aggiornato: `geojson_url` con
  `?without_live_positions=1`, `live_positions_url` = `/nova-vendor/layer-analytics/{id}/live-positions`.
- [ ] `test_global_card_points_to_all_routes_and_global_positions`: URL globali, `title` tradotto
  (`it` → «Tutti i cammini»), `resource_id` null.

### Task C5: mappa globale nella dashboard

**Files:** Modify `app/Nova/Dashboards/Main.php` (`administratorCards()`),
`tests/Feature/LayerGlobalAnalyticsCardVisibilityTest.php`

- [ ] **Step 1:** test:
  - `test_administrator_sees_the_global_map`: con un Layer → `layer-map-card` con `geojson_url`
    `/nova-vendor/layer-analytics/all-routes`, dopo la card globale, `onlyOnDetail` false.
  - `test_administrator_sees_the_global_map_with_analytics_disabled`: mappa presente, card delle
    statistiche assente.
  - `test_administrator_without_layers_sees_no_map`.
  - casi del Validator: la sua `layer-map-card` non è quella globale.
- [ ] **Step 2:** `administratorCards()`: card globale come oggi, poi `LayerMapCard::global()` se
  esiste almeno un Layer. Docblock della classe aggiornato.
- [ ] **Step 3:** suite del ticket verde
  (`--filter='LayerGlobalAnalyticsCardVisibility|LayerMapCard|LiveMapEndpoints|AllLayersLiveMapData|NovaBrandLogo'`).

### Task C6: gitlink, verifica nel browser, documentazione

- [ ] Gitlink `wm-package` sul commit finale del branch del package.
- [ ] Nel browser, come Administrator e come Validator: mappa con percorsi, zoom e centro che non
  cambiano dopo 90 s (Network: una richiesta `live-positions` ogni 90 s, nessuna `all-routes`
  ripetuta); detail view del Layer invariata; navigando via dalla dashboard le richieste si fermano.
- [ ] `docs/knowledge/dashboard-statistiche.md`: mappa dell'Administrator e aggiornamento ogni 90 s.
- [ ] `notes.md`: decisioni della seconda parte (mappa per l'Administrator, B a 50 m, filtro a 50 m,
  aggiornamento per entrambe, endpoint protetti) e misure.

## Commit della seconda parte (dopo il review-gate)

6. `chore(oc:9040): aggiorna wm-package con la mappa globale` — solo il puntatore
7. `feat(oc:9040): mappa di tutti i cammini per l'Administrator nella dashboard Statistiche`
8. `docs(oc:9040): documentazione della mappa globale`

---

# Terza parte — interfaccia di Nova con i colori e i caratteri del design system (Alessandro, 26/11)

**Goal:** Nova con l'arancio, i grigi e i caratteri del design system nei due temi, card delle
statistiche e tracce con i colori del brand.

**Spec:** `overview.md`, sezione «Terza parte»; package: `wm-package/.../plan.md`, terza parte
(Task P8 e P9, da fare prima di C8).

## Global Constraints

- Valori del design system (`tokens.json`):
  - `primario`: 100 #FDEDD7, 300 #F7BB7A, 400 #F39544, 500 #F07821, 600 #E15D15, 700 #BB4613;
  - `secondario`: 100 #E6E9EA, 200 #C7CCCD, 400 #6E7B7E, 500 #4C585B, 600 #354144, 700 #283335,
    base #1D282B;
  - `fondo` #F4F6F8, `chiaro-su-scuro` #F3F4F6.
- Tonalità ricavate da noi:
  - primario 50 #FEF6EC, 200 #FAD4A9, 800 #94380F, 900 #78300F, 950 #411606;
  - grigi 50 #F9FAFB, 900 #141C1E, 950 #0B1011.
- Grigi di Nova: 50 ricavato, 100 = `fondo`, 200 = `secondario-100`, 300 = `secondario-200`,
  400 = `secondario-400`, 500 = `secondario-500`, 600 = `secondario-600`, 700 = `secondario-700`,
  800 = `secondario`, 900 e 950 ricavati.
- Nova vuole i colori come `r, g, b` (`brand.colors`).

## Review Focus

- Tema scuro: testo leggibile sui fondi `secondario`, numeri della card in `primario`.
- Caratteri: Nunito Sans non deve restare in nessun componente (il CSS di Nova lo scrive in circa
  90 regole, non in una variabile).
- Font serviti dal nostro server: nessuna richiesta a Google Fonts.

### Task C7: config del brand e test

**Files:** `config/nova.php`, `config/wm-package.php`, `tests/Feature/NovaBrandTest.php` (nuovo).

- [ ] Test, prima che falliscano:
  - `test_primary_color_is_the_brand_orange`: `config('nova.brand.colors.500') === '240, 120, 33'`
    e `700 === '187, 70, 19'`;
  - `test_tracks_without_layer_color_use_the_configured_default`: layer senza `properties.color`,
    tracce `strokeColor === '#F07821'`;
  - `test_layer_color_wins_over_the_default`: con `properties.color` resta il suo;
  - `test_all_routes_use_the_configured_default_and_follow_a_change`: `all-routes` in #F07821,
    poi un'altra config dà subito l'altro colore.
- [ ] `config/nova.php` → `brand.colors` 50…950 (Global Constraints).
- [ ] `config/wm-package.php` (di camminiditalia) → `'map_default_track_color' => '#F07821'`.

### Task C8: caratteri, grigi e variabili della card

**Files:** `public/fonts/camminiditalia/` (nuova), `resources/css/nova/brand.css` (nuovo),
`app/Providers/NovaServiceProvider.php`, `tests/Feature/NovaBrandTest.php`.

- [ ] Test: `test_brand_stylesheet_is_registered` (`Nova::allStyles()` contiene
      `camminiditalia-brand`).
- [ ] Font del design system: DM Sans Regular, Italic, Medium, SemiBold e Bold; Instrument Sans
      Medium, SemiBold e Bold, in `public/fonts/camminiditalia/`.
- [ ] `brand.css`:
  - `@font-face`;
  - `--colors-gray-50…950` su `:root`;
  - DM Sans su `body` e sugli elementi con Nunito Sans, Instrument Sans su `h1`, `h2`, `h3`;
  - `--wm-analytics-*` su `:root` (accent `primario-700`, serie `primario`, `secondario`,
    `primario-300`);
  - su `.dark`: accent `primario`, serie iOS `chiaro-su-scuro`, fondi `secondario` e
    `secondario-700`.
- [ ] `Nova::style('camminiditalia-brand', resource_path('css/nova/brand.css'))`.

### Task C9: gitlink, verifica e documentazione

- [ ] Gitlink `wm-package` sul commit del package.
- [ ] Suite completa, PHPStan e Pint sui file toccati.
- [ ] Verifica nel browser (Alessandro): dashboard, una index e una detail view, nei due temi.
- [ ] `docs/knowledge/logo-nel-pannello-nova.md` (diventa «brand nell'interfaccia di Nova»),
      `wm-package/docs/knowledge/analytics-posthog.md` (variabili della card e colore delle tracce),
      `notes.md`.

## Commit della terza parte (dopo il review-gate)

- wm-package: `feat(oc:9040): colori della card delle statistiche e delle tracce configurabili`.
- camminiditalia, in ordine:
  - `chore(oc:9040): aggiorna wm-package con i colori configurabili`;
  - `feat(oc:9040): interfaccia di Nova con i colori e i caratteri del design system`;
  - `docs(oc:9040): …`.

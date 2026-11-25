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

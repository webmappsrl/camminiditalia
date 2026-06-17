> Ticket: oc:8079

# UGC segnalazioni: assegnazione automatica layer_id lato API se assente

## Cosa cambia
Quando arriva una segnalazione (UgcPoi form=report) senza `layer_id` nelle properties,
il sistema avvia automaticamente in background una risoluzione spaziale via PostGIS per
trovare il cammino più vicino entro 1000m e assegnarlo alla segnalazione prima di inviare
la notifica email al gestore.

## Perché
Durante il periodo di transizione tra la versione precedente dell'app (che non invia
layer_id) e la nuova (oc:7639), le segnalazioni arrivano senza associazione al cammino.
Senza questo fix, queste segnalazioni non sarebbero visibili nel pannello del gestore
(oc:7640) e non trigghererebbero la notifica email (oc:7641).

## Requisiti
- [ ] Se `properties['layer_id']` è presente → comportamento invariato (email diretta)
- [ ] Se `properties['layer_id']` è assente E `array_key_exists('layer_id', properties['form'])` è **false** E il form è `report` → dispatch `ResolveUgcLayerJob`
- [ ] Se `properties['form']['layer_id']` è `null` (utente ha lasciato il campo vuoto consapevolmente) → nessuna auto-risoluzione, nessuna email
- [ ] `ResolveUgcLayerJob` usa `UgcService::resolveLayer()` per trovare il layer più vicino
- [ ] Se il layer viene trovato → salva `properties['layer_id']`, `properties['form']['layer_id']` e `properties['layer_id_auto_resolved'] = true` con `saveQuietly()`, poi dispatcha `SendUgcReportMailJob`
- [ ] Se il layer non viene trovato → dispatcha `SendUgcReportMailJob` senza layer (fallback a `info@camminiditalia.org`, comportamento già gestito da oc:7641)
- [ ] Se geometry è null o non valida → skip risoluzione spaziale, dispatch diretto `SendUgcReportMailJob`
- [ ] `LAYER_SEARCH_DISTANCE_METERS` in `UgcService` rimane 500 come default ma legge `env('UGC_LAYER_SEARCH_DISTANCE_METERS', 500)` — camminiditalia imposta `UGC_LAYER_SEARCH_DISTANCE_METERS=1000` nel proprio `.env`
- [ ] Test: verifica che `ResolveUgcLayerJob` venga accodato quando layer_id è assente e form=report
- [ ] Test: verifica che il job NON venga accodato se layer_id è già presente
- [ ] Test: verifica che il job NON venga accodato per UgcPoi con form diverso da report

## Rischi
- **Doppia email:** se il job viene ritentato (fallimento Horizon), `SendUgcReportMailJob` potrebbe essere dispatched due volte. Mitigazione: rendere il job idempotente controllando se `layer_id` è già stato salvato prima di procedere.
- **Vecchia app senza campo form:** `properties['form']` potrebbe essere assente del tutto (app molto vecchie). Mitigazione: trattare `properties['form']` assente come "chiave layer_id assente" → auto-risoluzione.
- **`saveQuietly()` obbligatorio:** salvare le properties senza `saveQuietly()` retriggera l'observer e crea un loop. Mitigazione: usare sempre `saveQuietly()` come fa il command di backfill.
- **Distanza 1000m:** aumentare la soglia può associare segnalazioni a cammini "sbagliati" in zone dense. Accettato consapevolmente — meglio un'associazione approssimativa che nessuna.

## Out of scope
- Auto-risoluzione per UgcTrack
- Auto-risoluzione per UgcPoi con form diverso da report
- Modifica alla logica di ricerca spaziale in UgcService

## Moduli toccati
**Repo principale `camminiditalia`:**
- `app/Observers/UgcObserver.php` — aggiunta logica dispatch `ResolveUgcLayerJob`
- `app/Jobs/ResolveUgcLayerJob.php` — nuovo job
- `app/Console/Commands/PopulateUgcLayerIdCommand.php` — refactor: itera sugli UgcPoi senza `layer_id` e dispatcha `ResolveUgcLayerJob` per ognuno invece di gestire la logica internamente
- `tests/Feature/ResolveUgcLayerJobTest.php` — nuovi test

**Submodule `wm-package`:**
- `src/Services/UgcService.php` — costante `LAYER_SEARCH_DISTANCE_METERS` resa configurabile via env

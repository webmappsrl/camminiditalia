> Ticket: oc:8182

# Dashboard statistiche aggregate per Cammini d'Italia

## Cosa cambia
`App\Nova\Layer::cards()` viene esteso per registrare `LayerAnalyticsCard::global()` (nuova modalità aggregata, vedi overview in `wm-package/`) nella vista index dell'elenco Layer, visibile solo agli Administrator.

## Perché
Coerenza con i permessi già esistenti nel progetto: solo l'Administrator vede dati aggregati di tutti i cammini (i Validator vedono solo le proprie risorse, pattern consolidato in tutto il progetto — UgcPoi, EcPoi, azioni Layer).

## Requisiti
- [ ] `App\Nova\Layer::cards()` (oggi non esiste, eredita da `Wm\WmPackage\Nova\Layer` che ritorna `[]` se `!$request->resourceId`) viene overridden per restituire `[LayerAnalyticsCard::global()]` quando: vista index (`!$request->resourceId`) E utente ha ruolo Administrator E l'App ha `analytics_app_enabled` o `analytics_webapp_enabled` attivo (stesso gate della card per-layer)
- [ ] Nessuna modifica al comportamento della card per-layer esistente (detail view invariata)
- [ ] Feature test che verifica: card visibile solo su index, solo per Administrator, solo se i flag analytics dell'App sono attivi (mirror di `tests/Feature/LayerActionsVisibilityTest.php`, oc:8089)

## Rischi
- `canSee()` sulla Nova Card è protezione solo cosmetica lato UI (stesso principio già documentato in CLAUDE.md per le Action, oc:8089) — il controllo di autorizzazione reale deve stare lato `AnalyticsController::global()` in wm-package (vedi requisito corrispondente in quell'overview), non solo qui
- [UX] Card globale e card per-layer sulla stessa risorsa Layer potrebbero generare confusione se il titolo non distingue chiaramente le due modalità — mitigato dal requisito "titolo esplicito diverso" nell'overview wm-package

## Out of scope
- Modifiche a `fields()`/`actions()` esistenti di `App\Nova\Layer`
- Gestione multi-app

## Moduli toccati
- `app/Nova/Layer.php`
- nuovo test Feature (es. `tests/Feature/LayerGlobalAnalyticsCardVisibilityTest.php`)

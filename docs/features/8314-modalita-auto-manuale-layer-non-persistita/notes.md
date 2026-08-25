> Ticket: oc:8314

# Notes — Modalità auto/manuale del layer non persistita lato backend

## Deviazioni dal piano

Durante la verifica end-to-end in ambiente Nova reale (dopo l'esecuzione del piano) sono emersi due requisiti aggiuntivi, non presenti nell'overview/piano originali, richiesti esplicitamente dal developer e implementati nello stesso ciclo:

1. **Rifiuto esplicito di `auto: true` per layer con owner Administrator.** Scoperto testando il layer 129 (owner `user_id=2`, Administrator, 550 tracce totali nel sistema): attivare "auto" tramite il ramo esistente del controller locale (`sync($ownedIds)` con `$ownedIds` = tutte le tracce del proprietario, logica di oc:8311) assegnava indiscriminatamente tutte e 550 le tracce dell'Administrator al layer. Il controllo (`hasRole('Administrator')` sull'owner risolto, `user_id ?? default_owner_id`) è stato aggiunto **solo nel controller locale** (`app/Http/Controllers/LayerFeatureController.php::sync()`), non nel package — deliberatamente: `Wm\WmPackage\Models\Layer` non ha un override locale utilizzabile (22 punti nel package lo referenziano direttamente, nessun binding via config come per `EcTrack`/`EcPoi`), quindi la copertura è limitata al salvataggio via questo endpoint. La UI può ancora mostrare "auto" al primo caricamento per un layer con owner Administrator finché non si tenta di attivarlo esplicitamente (limite noto, accettato).
   - Risposta 422 con messaggio esplicito ("Il proprietario di questo layer è un Amministratore: non è possibile attivare la modalità automatica...").
   - Frontend (`LayerFeature.vue::handleModeChange`) aggiornato per mostrare il messaggio specifico ricevuto dal backend invece del generico "Errore durante il cambio di modalità".
   - Test: `test_sync_rejects_auto_true_when_layer_owner_is_administrator`, `test_sync_rejects_auto_true_when_resolved_owner_is_administrator_via_default_owner_id`, `test_sync_allows_manual_true_even_when_layer_owner_is_administrator`.

2. **Default del flag di modalità cambiato da `'auto'` a `'manual'` per camminiditalia.** Argomentazione del developer, verificata nel codice/dati: il default `'auto'` ha senso solo nel modello originale del package (auto = ricalcolo da tassonomia); su camminiditalia non si usano tassonomie sui layer (0 su 118 verificato) — le tappe sono sempre selezionate manualmente (pivot diretto). Implementato con un pattern di configurabilità già in uso nel progetto (stesso schema di `ec_track_model`): nuova chiave `wm-package.default_layer_mode` (default `'auto'`, invariato per gli altri progetti Webmapp), sovrascritta da camminiditalia con `DEFAULT_LAYER_MODE=manual` in `.env`/`.env.testing`. `isAutoTrackMode()`/`isAutoPoiMode()` nel modello `Layer` (package) usano questo config come fallback invece del literal `'auto'`.
   - Nessuna scrittura sui dati esistenti: cambia solo come viene **letto** lo stato assente di `configuration`, reversibile rimuovendo la env var.
   - Test: `test_layer_without_configuration_defaults_to_manual_mode_on_camminiditalia`.
   - Verificato nessun test esistente (repo principale) dipendeva dal default implicito `'auto'` prima del cambiamento.

## Bug trovati (fuori scope, non corretti in questo ciclo)

**Disallineamento ownership layer/tracce — 35 layer su 118 (verificato sia sul dump del 28/07 sia su quello del 23/08, stesso conteggio in entrambi).**

Scoperto testando il layer 124: owner del layer `user_id=2` (Administrator), ma le 23 tracce nel pivot `layerables` appartengono tutte a `user_id=3816` ("Vie Francigene di Sicilia", ruolo Validator, 58 tracce totali di sua proprietà, **0 layer posseduti**). Il controller locale filtra sempre `getAssociatedFeatures()`/`getFeatures()` con `where('user_id', $layerOwnerId)` (oltre al filtro di associazione) — quindi queste tracce, pur essendo nel pivot, non vengono mai mostrate nella UI Nova (edit e detail): il layer appare vuoto ("Nessun dato disponibile") pur avendo tracce realmente associate.

Verifica estesa a tutti i 118 layer: **35 hanno il 100% delle proprie tracce con `user_id` diverso dal proprietario del layer** — quasi tutti con owner `user_id=2` (Administrator) o `user_id=9` (altro Administrator).

Causa più probabile: il meccanismo di trasferimento ownership esistente (`LayerObserver`, oc:8080) scatta solo quando **cambia l'owner del layer** (`wasChanged('user_id')`), non quando vengono aggiunte tracce con owner diverso al pivot in un secondo momento — quindi non corregge mai questo tipo di disallineamento, generato probabilmente da import/sync storici o da gestori (come 3816) le cui tracce sono state associate a un layer rimasto sotto l'Administrator di default, senza che il layer venisse mai riassegnato a loro.

**Non è causato da oc:8314**: `getFeatures()` (locale) non è mai stato toccato in questo ciclo (dichiarato fuori scope negli overview fin dall'inizio). Il problema esiste indipendentemente dal lavoro di questo ticket, ed è stabile nel tempo (nessun peggioramento/miglioramento automatico tra i due dump verificati).

**Decisione del developer**: nessuna correzione bulk sui dati in questo ciclo. Se il cliente segnala tracce mancanti in un layer, va spiegato il problema (disallineamento ownership layer/tracce) e lasciato che lo corregga lui stesso dal pannello Nova (riassegnando owner del layer o delle tracce, secondo la sua conoscenza di chi sia il vero gestore).

## Decisioni

- Il gitlink di `wm-package` verrà aggiornato al momento del commit reale (non durante l'esecuzione, per l'override no-commit imposto da `wm-plan`) — vedi Task 6 del piano, marcato "non applicabile in questa fase" nel ledger SDD.
- Ambiente di sviluppo ripristinato due volte durante la verifica: prima con un dump datato 28/07 (per riportare il layer 129 allo stato pre-esperimento dopo un test del ramo `auto`), poi con un dump più recente (23/08, scaricato da `wm:download-db-backup`) per verificare che il disallineamento ownership non fosse un artefatto di un dump vecchio — confermato presente in entrambi.

## Follow-up

- Ticket separato da aprire (fuori da oc:8314): disallineamento ownership su 35 layer, decisione rimandata al cliente/dev in un ciclo successivo.
- Refactor eventuale: introdurre un `layer_model` configurabile nel package (analogo a `ec_track_model`/`ec_poi_model`) per permettere override locali del modello `Layer` — servirebbe a coprire in modo completo (anche in UI al caricamento) regole di business specifiche come "owner Administrator → mai auto", oggi limitate al solo controller locale.

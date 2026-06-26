> Ticket: oc:8139

# Associazione automatica EcPoi al layer della traccia

## Cosa cambia

Ogni `EcPoi` collegato come related poi a una `EcTrack` viene automaticamente associato al/ai layer a cui appartiene quella traccia. L'associazione si mantiene sincronizzata: aggiungere o rimuovere un POI da una traccia aggiorna in tempo reale la relazione con il layer. Un command artisan migra i dati storici esistenti.

## Perché

Oggi i layer gestiscono già le `EcTrack` associate (con trasferimento ownership), ma i POI collegati a quelle tracce non vengono portati nel layer. Questo crea un'asimmetria: il gestore del layer non vede i POI delle proprie tracce nel pannello Nova, e i POI non ricevono l'ownership corretto al cambio gestore del layer.

## Requisiti

- [ ] Un `EcPoi` aggiunto come related poi a una `EcTrack` viene associato a tutti i layer di quella traccia
- [ ] Un `EcPoi` rimosso da una `EcTrack` viene dissociato dal layer **solo se** nessun'altra traccia di quel layer ha ancora quel POI come related poi
- [ ] Se un POI è related poi di tracce in layer diversi, viene associato a tutti quei layer
- [ ] Una `EcTrack` rimossa da un layer → i suoi POI vengono rimossi dal layer **solo se** nessun'altra traccia di quel layer li ha ancora come related poi
- [ ] Al cambio owner di un layer, anche gli `EcPoi` associati ricevono il nuovo `user_id` (già implementato in `LayerObserver` — verificare copertura post-rinomina)
- [ ] Nuova associazione layer-EcPoi via layerable → `LayerableObserver::created` già trasferisce l'ownership automaticamente
- [ ] Il command disabilita `LayerableObserver` durante l'esecuzione e fa bulk UPDATE di `user_id` alla fine per ogni layer (evita centinaia di UPDATE ridondanti e ordine di esecuzione non deterministico)
- [ ] Command artisan idempotente `camminiditalia:sync-layer-ec-pois` che associa tutti i related poi delle tracce ai rispettivi layer e trasferisce l'ownership (usa `sync()` per far scattare `LayerableObserver`)
- [ ] Rinomina `manualEcPois` → `ecPois` in wm-package con alias `manualEcPois()` per backwards compatibility
- [ ] Panel Nova "Ec Pois" nel Layer già presente nel package — verificare che sia visibile e funzionante con la nuova relazione

## Rischi

- **Breaking change rinomina**: altri progetti che usano `manualEcPois()` direttamente (senza `getLayerRelationName()`) si rompono senza l'alias — l'alias mitiga il rischio
- **Catena observer cross-layer implicita**: `EcPoiEcTrackObserver` (package) produce effetti che dipendono da `LayerableObserver` (repo principale) — accoppiamento invisibile leggendo un solo file, difficile da debuggare se uno dei due viene disabilitato. Rischio accettato per coerenza con il pattern già usato in oc:8080
- **Doppia associazione**: se il command viene eseguito più volte, usare `syncWithoutDetaching()` per garantire idempotenza senza rimuovere associazioni manuali preesistenti
- **Performance command**: scala contenuta (118 layer, 519 pivot EcPoi-EcTrack) — command senza chunking è sufficiente; aggiungere chunking se il dataset cresce

## Out of scope

- Auto-mode per EcPoi (simile all'auto-mode taxonomy delle EcTrack) — non richiesto
- UI Nova per gestire l'associazione layer-EcPoi da parte dell'utente (il sync è automatico da traccia)
- Gestione del caso "traccia spostata tra layer" (rimozione traccia da layer A e aggiunta a layer B) — il sync avviene tramite gli observer su EcPoiEcTrack, non su Layerable

## Moduli toccati

### wm-package
- `src/Observers/EcPoiEcTrackObserver.php` — aggiunta logica sync layer su `created` e `deleted`
- `src/Models/Layer.php` — rinomina `manualEcPois()` → `ecPois()`, aggiunta alias
- `src/Models/EcPoi.php` — `getLayerRelationName()` restituisce `'ecPois'`
- `src/Nova/Layer.php` — aggiornamento `$with` array (`manualEcPois` → `ecPois`)

### repo principale
- `app/Console/Commands/SyncLayerEcPois.php` — nuovo command artisan
- `app/Observers/LayerObserver.php` — aggiornamento riferimento `manualEcPois` → `ecPois` (o alias, da valutare)
- `app/Observers/LayerableObserver.php` — aggiunta metodo `deleted`: EcTrack rimossa da layer → rimozione POI orfani dal layer

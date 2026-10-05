# Passaporto camminatore: come si conta il progresso sulle tappe

## Come funziona oggi

Le validazioni stanno in `validated_ec_tracks` (una riga per coppia utente-tappa, scritta dalla decisione del gestore: vedi [passaporto-validazione-credenziale-cartacea.md](passaporto-validazione-credenziale-cartacea.md)). Il progresso si legge con `App\Services\StageProgressService`, che espone all'app `GET /api/layer/{layer}/progress` e `GET /api/passport` (formato in [docs/features/8676-…/api.md](../features/8676-passaporto-camminatore-tappe-percorse-e-validate-dellutente/api.md)) e alimenta la sezione Nova «Tappe validate» con la Lens «Per utente e cammino».

Vincoli che il codice da solo non spiega:
- **due regole delle tappe, con ambiti distinti**, ognuna implementata una sola volta come query SQL nel servizio:
  - `routeTracksQuery()` — **tappe del cammino**: tutte le EcTrack di `layer->ecTracks()`, a prescindere dal proprietario. La usano l'API e i numeri della Lens. Un cammino di 13 tappe risulta di 13 anche se 2 appartengono a un altro utente per un errore di assegnazione (oc:8314);
  - `managedTracksQuery()` — **tappe del gestore**: tappa del layer di proprietà del proprietario effettivo (`COALESCE(layers.user_id, camminiditalia.default_owner_id)`, come `App\Support\LayerOwner`). La usano l'elenco «Tappe validate», i filtri, la colonna «Routes» e lo scoping del Validator; è la stessa regola delle tappe validabili nella decisione (vedi [passaporto-validazione-credenziale-cartacea.md](passaporto-validazione-credenziale-cartacea.md)), che `CertificationRequestService::ownedLayerTracks()` ora ricava da questa query;
- conseguenza voluta delle due regole: nella Lens un Validator può leggere «7 / 13» e trovare 6 righe nell'elenco; e finché l'assegnazione non è corretta, un cammino con tappe di un altro proprietario **non si completa con la credenziale cartacea**;
- una validazione vale per la tappa, non per il cammino: una tappa validata, in qualunque cammino e in qualunque modo (manuale o GPS), risulta validata in tutti i cammini che la contengono. `validated_ec_tracks.layer_id` è solo il cammino della prima validazione e non decide l'appartenenza: si parte sempre da `layer->ecTracks()`;
- **calcolo sempre sulle tappe di oggi**, nessun completamento salvato: se il gestore aggiunge una tappa, un cammino completato torna «in corso». `completed` = `total > 0` e tutte validate (`StageProgressService::isCompleted()`, usato anche dalla Lens);
- **km**: distanza «corrente» del package (manuale, poi OSM se la tappa ha `osmid`, poi DEM, altrimenti 0), la stessa di `HasDemClassification::classifyField()`, riscritta in SQL con cast protetto (valori non numerici valgono 0). Un test confronta le due versioni. I valori anomali (es. tappa 56 con `1500`, probabilmente metri) non vengono corretti;
- **filtro «solo validate» in un punto solo**: `ValidatedEcTrack::scopeValidated()` (e `ValidatedEcTrack::validatedSql()` nelle query raw), applicato anche alle relazioni `CertificationRequest::validatedTracks()` e `User::validatedEcTracks()`. Oggi è sempre vero; serve a oc:8165, quando la stessa tabella conterrà righe di avanzamento parziale GPS;
- `tracks[]` porta `name` (traduzioni della tappa) e `distance` perché l'app non ha l'elenco completo delle tappe del layer (le tappe in memoria sono i risultati dell'ultima ricerca Elastic); `progress` (0-100) è già presente e oggi vale 100 o 0;
- l'`id` delle righe della Lens è sintetico (`user_id << 32 | layer_id`): le righe non aprono il dettaglio di una validazione.

## Perché così

- **Due regole invece di una** (oc:8676): l'errore di assegnazione delle tappe (oc:8314) è un problema di dati del gestore e non deve pesare sull'utente finale, che deve vedere il cammino intero; il gestore invece resta limitato alle proprie tappe, come in oc:8671.
- **Lens con i numeri dell'app** (oc:8676): se un camminatore chiama il gestore, devono guardare lo stesso numero; il Validator vede solo un conteggio, non le tappe non sue, e la differenza con l'elenco gli segnala l'errore da correggere.
- **Endpoint con i numeri già calcolati** (oc:8676): il conteggio «N di M» è una regola del backend; un elenco grezzo avrebbe costretto ogni client a rifarlo.
- **Tabella unica per validate e parziali** (oc:8676, decisione per oc:8165): un solo posto per lo stato utente-tappa; le prestazioni non cambiano in modo apprezzabile. Lo scope unico è la condizione perché le righe parziali non vengano contate come validate.
- **Cascade sulle cancellazioni lasciata com'è** (oc:8676, confermata dopo oc:8671): con il calcolo sulle tappe di oggi numeratore e totale scendono insieme; bloccare la cancellazione sarebbe un cambio di comportamento per i gestori, da decidere a parte.

## Come ci siamo arrivati

- **Una sola regola, solo le tappe del proprietario effettivo, con lo stato `not_validatable`** (oc:8676, superata nello stesso ticket): escludeva dal totale dell'app le tappe di un altro proprietario, così un cammino non diventava impossibile da completare per un errore di dati. Abbandonata su richiesta del dev, dopo la verifica della sessione frontend: l'utente finale vedeva un cammino più corto di quello mostrato dall'app.
- **Nessun nome né distanza per tappa nell'API** (oc:8676, superata nello stesso ticket): li si riteneva ridondanti, finché la sessione frontend ha verificato che l'app non ha l'elenco delle tappe del layer.
- **Varianti di tappa contate come tappe normali** (oc:8676, ancora valido ma provvisorio): 37 tappe «Variante» in 13 layer, senza un campo che le distingua; un cammino percorso sul tracciato principale non risulta completato. Gestione e completamento rimandati a oc:8165.

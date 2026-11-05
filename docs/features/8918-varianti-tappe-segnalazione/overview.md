> Ticket: oc:8918

# VARIANTI / TAPPE (segnalazione) — parte backend

> Overview principale nel repo `webmapp-app`, stesso slug.

## Cosa cambia

Gli attributi calcolati del layer `stage_count` e `distance` non contano più le varianti: una tappa
principale e le sue varianti nello stesso cammino valgono una tappa, con i km della principale. È la
regola che il passaporto applica già da oc:8165 (`StageProgressService::rootSql`). Segnare, cambiare
o togliere una variante in Nova ricalcola gli attributi dei cammini coinvolti.

## Perché

In produzione 15 cammini hanno varianti e per tutti `stage_count` coincide con il numero di tracce
(es. Via Valeriana 12 invece di 10, Cammino Grande di Celestino 13 invece di 12). Il valore
alimenta la card della home dell'app e i filtri per numero di tappe e per km.

## Requisiti

- [ ] `LayerAttributesService::stageCount` conta i gruppi distinti per radice (principale se nello
  stesso cammino, altrimenti la traccia stessa)
- [ ] `LayerAttributesService::totalDistance` somma per gruppo i km della principale; resta `null`
  se nessuna tappa ha un valore
- [ ] Una variante la cui principale non è nello stesso cammino conta come tappa normale
- [ ] La regola della radice è una sola, condivisa con `StageProgressService`, non duplicata
- [ ] Observer su `EcTrackVariant` (created, updated, deleted) che mette in coda
  `RecalculateLayerAttributesJob` per ogni cammino che contiene la traccia (con `updated` anche
  per quelli della principale precedente)
- [ ] Test: cammino senza varianti invariato, con variante, con variante la cui principale è fuori
  dal cammino, km della principale
- [ ] Test che confronta `stage_count` del layer e `total` del passaporto sullo stesso cammino con
  varianti
- [ ] Prima del deploy: valori attesi di `stage_count` e km dei 15 cammini con varianti, salvati
  con una query in sola lettura in produzione
- [ ] Dopo il deploy: `RecalculateAppLayerAttributesAction`, poi confronto con i valori attesi,
  controllo che i cammini senza varianti non siano cambiati e che il `config.json` servito abbia i
  valori nuovi (altrimenti nuova rigenerazione del config dell'app)

## Rischi

- **Ricalcolo in blocco con numeri plausibili ma sbagliati**, e config che può essere rigenerato
  prima degli ultimi ricalcoli (lo dice il commento in `RecalculateAppLayerAttributesAction`):
  mitigato dal confronto prima/dopo.
- **Regola della radice in due posti**: mitigato condividendo quella di `StageProgressService`.

## Out of scope

Somma dei km nell'immagine di condivisione (`RouteShareImageService`); flag variante nei payload.

## Moduli toccati

- `app/Services/LayerAttributesService.php`
- `app/Services/StageProgressService.php` (solo se serve esporre la regola della radice)
- `app/Observers/EcTrackVariantObserver.php` (nuovo) e registrazione in `AppServiceProvider`
- `tests/Feature/LayerAttributesServiceTest.php`, test dell'observer

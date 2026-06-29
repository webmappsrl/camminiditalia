> Ticket: oc:8140

# Fix: properties.layers su EcPoi valorizzato erroneamente per layer senza taxonomy_where

## Cosa cambia

Un command Artisan idempotente riallinea `properties['layers']` su tutti gli EcPoi, rimuovendo gli ID di layer errati accumulati dal bug e aggiungendo quelli mancanti. Il fix al codice risiede in `wm-package` (vedi `wm-package/docs/features/8140-.../overview.md`).

## Perché

Il dump di produzione mostra 435 EcPoi con `properties['layers']` corrotto: 403 hanno almeno un layer errato, media 6,02 layer/POI. Solo 11 layer hanno POI reali in `layerables`. Il dato storico deve essere riallineato dopo il deploy del fix in wm-package.

## Requisiti

- [ ] Command Artisan `camminiditalia:fix-ec-poi-layers-property` che chiama `LayerService::updateLayersPropertyOnLayeredFeature()` per tutti i layer e tutti i model class (EcPoi + EcTrack) — dopo il deploy del fix in wm-package
- [ ] Il command è idempotente: rieseguirlo produce lo stesso risultato
- [ ] Nessuna rigenerazione PBF nel command
- [ ] Il command logga il risultato per layer (aggiunti / rimossi) usando `$layer->getStringName()` per audit post-esecuzione
- [ ] Il command rifiuta l'esecuzione con messaggio esplicito se rilevato codice non-patchato (layer senza filtri e senza manuali viene processato = guard non in place); flag `--force` per bypassare il check
- [ ] Registrazione del command in `app/Console/Kernel.php`

## Rischi

- **Esecuzione pre-deploy**: se il command gira prima del fix in wm-package, amplifica la corruzione. Il command blocca l'esecuzione se rileva il codice non patchato (flag `--force` per override consapevole).
- **Nessun rollback automatico**: `properties` è JSONB, nessuna migration inversa. Eseguire un dump DB prima del command. In caso di corruzione, ripristinare dal dump.
- **Durata**: 118 layer × logica add/remove — monitorare tempi in produzione (possibile esecuzione in coda).

## Out of scope

- Rigenerazione PBF dopo il riallineamento
- Fix del codice (wm-package)

## Moduli toccati

- `app/Console/Commands/FixEcPoiLayersProperty.php` — command di riallineamento
- `app/Console/Kernel.php` — registrazione command

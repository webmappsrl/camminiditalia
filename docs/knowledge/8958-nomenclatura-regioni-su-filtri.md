# Nomi delle regioni nel filtro dei cammini

## Come funziona oggi

- Il filtro «Regione» dell'app legge `properties->attributes->taxonomy_where` dei layer, scritto da
  `LayerAttributesService::wheres()` e pubblicato nel `config.json`.
- **Quali regioni** attraversa un cammino lo dice osmfeatures (intersezione con la geometria
  aggregata delle tappe, solo `admin_level` 4). **Il nome** di ogni regione viene invece dalla
  `TaxonomyWhere` del DB con lo stesso `osmfeatures_id`, la stessa fonte del dettaglio della tappa.
- Se la `TaxonomyWhere` di una regione non c'è nel DB, resta il nome di osmfeatures e si scrive un
  `Log::warning` con l'`osmfeatures_id`. Succede sempre per le regioni estere dei cammini
  transfrontalieri.
- Il `value` della regione è lo slug del nome inglese **di osmfeatures**, non del DB: la stessa
  regione ha la stessa chiave su tutti i layer, anche su quelli non ancora ricalcolati.
- Una correzione del nome della `TaxonomyWhere` arriva ai layer da sola: `TaxonomyWhereNameObserver`
  ricalcola i layer che attraversano la regione e il config si rigenera. Tracce e POI si riallineano
  a mano con `wm:resync-taxonomy-where`. Un reimport delle where riscrive la correzione: vedi
  [wm-package/docs/knowledge/localita-mostrate-taxonomy-where.md](../../wm-package/docs/knowledge/localita-mostrate-taxonomy-where.md).
- L'observer reagisce solo a una modifica del nome, non alla creazione: una where importata dopo
  arriva ai layer al loro ricalcolo successivo (save del layer o `RecalculateAppLayerAttributesAction`).

## Perché così

- **Nomi dal DB, regioni da osmfeatures** (oc:8958): osmfeatures restituisce «Lucania», «Regione
  Autonoma Valle d'Aosta», «Regione Autonoma Trentino-Alto Adige/Südtirol», mentre le
  `TaxonomyWhere` hanno i nomi che il cliente usa.
- **Job indipendenti e non `Bus::chain` nell'observer** (oc:8958): `RecalculateLayerAttributesJob` è
  `ShouldBeUniqueUntilProcessing`, e in una chain un job scartato dal lock si porta dietro il resto
  della catena. Come in `RecalculateAppLayerAttributesAction`.
- **Layer scelti per intersezione** (oc:8958): negli attributi non c'è l'`osmfeatures_id`, quindi non
  si può cercare la regione per id.

## Come ci siamo arrivati

- **Rigenerare solo il `config.json`** (ipotesi iniziale di oc:8958, scartata): i nomi sbagliati non
  erano un dato vecchio ma il nome che osmfeatures restituisce, e il ricalcolo li riscriveva uguali.
- **Correggere a mano `properties->attributes`** (scartato in oc:8958): il ricalcolo automatico di
  `LayerObserver` a ogni save l'avrebbe sovrascritto.

> Ticket: oc:8958

# Notes — Nomenclatura regioni su "Filtri" (`camminiditalia`)

## Divergenze dal piano, task per task

### Task 2: ricalcolo dei layer quando cambia il nome di una regione

Il piano prevedeva una `Bus::chain` per App: i `RecalculateLayerAttributesJob` con
`regenerateConfig: false` e un solo `UpdateAppConfigJob` in fondo. Nell'implementazione finale
l'observer accoda invece un `RecalculateLayerAttributesJob` indipendente per layer, con
`regenerateConfig: true`.

Motivo, emerso dalla review: `RecalculateLayerAttributesJob` è `ShouldBeUniqueUntilProcessing`, e in
una chain il job successivo passa da `PendingDispatch` (`Bus/Queueable.php:326`), che lo scarta se il
lock è già preso (`PendingDispatch.php:245-248`) portandosi dietro tutto il resto della catena,
`UpdateAppConfigJob` compreso. Anche un solo job fallito fermerebbe la catena prima del config.
`RecalculateAppLayerAttributesAction` evita le chain per lo stesso motivo. Con job indipendenti
ognuno rigenera il config solo se gli attributi cambiano, e `UpdateAppConfigJob`, essendo unico,
deduplica le rigenerazioni.

Anche il criterio di selezione dei layer diverge dall'overview, che diceva «i layer i cui
`properties->attributes->taxonomy_where` contengono quella regione»: negli attributi ci sono solo
`value` e `name`, non l'`osmfeatures_id`, quindi i layer si trovano con `ST_Intersects` fra la
geometria della `TaxonomyWhere` e le tracce (deciso col dev all'approvazione del piano).

## Bug trovati

- Nel test dell'observer, `Bus::assertNothingChained()` in questa versione di Laravel è un alias di
  `assertNothingDispatched()`: si verifica invece che il job non abbia `chained`.
- Nel test, l'attach della tappa accoda un ricalcolo (`LayerAttributesObserver`) che sotto
  `Queue::fake()` tiene il lock di unicità: va rilasciato con `releaseRecalculateLayerAttributesLock()`
  prima di verificare il dispatch dell'observer.

## Decisioni

- Il `value` della regione resta lo slug del nome inglese di osmfeatures; solo `name` viene dal DB.
- Se la `TaxonomyWhere` manca, resta il nome di osmfeatures con un `Log::warning`.
- Nel DB locale (25/11/2026) le 20 `TaxonomyWhere` regionali hanno geometria, `osmfeatures_id`,
  `source` osmfeatures e i nomi in tutte le lingue; «Basilicata», «Valle d'Aosta» e
  «Trentino-Alto Adige» sono già corretti, resta da togliere il trattino a mano.
- Rischio accettato dal dev: un reimport delle regioni (`ImportTaxonomyWhere`,
  `RetryTaxonomyWhereGeometryFetch` → `syncNameFromDetail()`) riscrive i nomi corretti a mano, e
  l'observer propaga anche quella riscrittura a layer e `config.json`.

## Follow-up

- Il `Log::warning` «regione senza TaxonomyWhere» parte a ogni ricalcolo anche per le regioni estere
  dei cammini transfrontalieri, che non sono nel DB.
- Valutare di salvare l'`osmfeatures_id` nell'attributo: una rinomina diventerebbe una riscrittura del
  `name` senza ricalcolo completo, e l'observer potrebbe selezionare i layer per id invece che per
  intersezione.
- La propagazione della rinomina a tracce e POI resta manuale (`wm:resync-taxonomy-where`).
- `layerWithATrack()` è duplicato in `LayerAttributesWheresTest` e `TaxonomyWhereNameObserverTest`.
- Da fare dopo il merge: Task 3 (bump di `wm-package`) e Task 4 (operazione sui dati, dev poi
  produzione).

# Bottone web component sulla tappa e tassonomie di oc:8910 su camminiditalia

## Come funziona oggi

Il bottone «Copia codice web component» su cammini e tappe è del package: come funziona è in
[wm-package/docs/knowledge/8944-copia-codice-web-component-sulla-detail-view-della-ectrack.md](../../wm-package/docs/knowledge/8944-copia-codice-web-component-sulla-detail-view-della-ectrack.md).
Qui ci sono solo le scelte di camminiditalia.

- **Layer proposti sulla tappa**: `App\Nova\EcTrack::webComponentLayers()` (`#[\Override]`, parte da
  `parent::`, che filtra per il flag dell'App). L'Administrator vede tutti i layer della traccia; ogni
  altro ruolo solo quelli con il proprio `user_id`, come nella index dei Layer
  (`App\Nova\Layer::indexQuery()`). Con un solo layer la select non compare; senza layer il Field
  non compare.
- **Sezioni delle tassonomie di oc:8910 nascoste**: oc:8910 (wm-package) aggiunge la sezione
  «Filters» sul Layer e la sezione «Taxonomies» su EcTrack ed EcPoi. Su camminiditalia non compaiono:
  - Layer: filtro in `App\Nova\Layer::fields()` sul `TabsGroup` con chiave `taxonomySectionKey()`,
    perché il package chiama `makeTaxonomySection()` direttamente;
  - EcTrack ed EcPoi: override `taxonomySectionFields()` che restituisce `[]`, così la sezione non
    viene nemmeno costruita.

  Test: `tests/Feature/TaxonomySectionsHiddenTest.php`. Restano visibili i MorphToMany già presenti
  («Activities» sulla EcTrack, «Taxonomy Poi Types» sulla EcPoi).
- **Al deploy del bump che porta oc:8910**: `migrate` (tre migration `zz_2026_11_0*`), poi
  `php artisan scout:import "App\Models\EcTrack"` (il mapping Elasticsearch del campo `terms` è
  cambiato, un reindex normale non basta) e il riavvio di Horizon. Controlli già fatti, senza lavoro:
  nessun modello `App` locale (niente collisione su `taxonomies()`), `App\Models\EcTrack` eredita
  `TaxonomyAbleModel`, `REDIS_CLIENT=predis` in locale e in produzione. Elenco completo dei punti in
  `wm-package/docs/knowledge/8910-tassonomie-cosa-controllare-al-bump-dello-shard.md`.

## Perché così

- **Filtro per owner del layer anche se lascia tappe senza bottone** (oc:8944): la select non deve
  proporre al Validator un cammino che non può aprire né controllare. Prezzo accettato: in produzione
  (06/11/2026) 40 EcTrack di Validator su 520 stanno solo in layer non loro (35 dell'Administrator,
  5 senza owner), e lì il Validator non vede il bottone. L'Administrator lo vede su tutte.
- **Sezione «Filters» nascosta sul Layer** (oc:8944): continua la scelta di oc:8311 (attività e zone
  già nascoste). Su camminiditalia la modalità auto assegna gli EC per owner, mentre un filtro salvato
  lancerebbe `SyncAutoLayerAfterTrackTaxonomyChangeJob` → `assignTracksByTaxonomy()`.
- **Sezione «Taxonomies» nascosta su EcTrack ed EcPoi** (oc:8944): in produzione (06/11/2026) 5
  tappe su 1317 hanno un'attività, e la regione è già mostrata da oc:8588; sulla EcPoi la tassonomia
  usata sono i tipi di POI (1163 associazioni), che hanno il loro MorphToMany. Il job che
  riassegnerebbe i POI per tassonomia non era un rischio concreto: 0 layer in modalità auto.

## Come ci siamo arrivati

- **Proporre al Validator tutti i layer della traccia** (oc:8944, scartata): avrebbe dato il bottone
  anche alle 40 tappe, ma con snippet di cammini non suoi.

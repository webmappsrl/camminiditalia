> Ticket: oc:8958

# Nomenclatura regioni su "Filtri"

Il ticket tocca tre repo con lo stesso slug: questo (`camminiditalia`), il submodule `wm-package`
(Resource Nova `TaxonomyWhere`) e `wm-core` nell'app (lingua del filtro). Qui la parte di
`camminiditalia`: da dove vengono i nomi delle regioni negli attributi dei layer.

## Cosa cambia

`LayerAttributesService::wheres()` continua a chiedere a osmfeatures quali regioni attraversa il
cammino, ma il `name` di ogni regione negli attributi del layer (`properties->attributes->taxonomy_where`)
viene preso dalla `TaxonomyWhere` del DB con lo stesso `osmfeatures_id`, non più dalla risposta di
osmfeatures. Il filtro regioni dell'app e il dettaglio della tappa leggono così la stessa fonte.

Dopo il deploy, sui dati: si correggono a mano (Nova, utente Administrator) i nomi delle due
`TaxonomyWhere` con il trattino, si riallineano le tracce, si ricalcolano gli attributi dei layer e
si rigenera il `config.json`, prima sul dev e poi in produzione.

## Perché

Il cliente segnala nel filtro «Lucania», «Regione Autonoma Valle d'Aosta», «Regione Autonoma
Trentino-Alto Adige/Südtirol» e «Friuli-Venezia Giulia», mentre nella tappa vede i nomi corretti.

Verificato il 25/11/2026 sul `config.json` di produzione e su Elastic: per gli stessi layer le tracce
hanno «Basilicata», «Valle d'Aosta», «Trentino-Alto Adige» (dalla `taxonomy_wheres` del DB), gli
attributi del layer i nomi di osmfeatures. 16 layer coinvolti: 3, 4, 7, 12, 23, 39, 45, 60, 65, 67,
74, 75, 101, 115, 125, 135. Anche il `config.json` del dev ha i nomi sbagliati.

Rigenerare il `config.json` da solo non basta: il ricalcolo richiama osmfeatures e riscrive gli
stessi nomi. E correggere a mano `properties->attributes` verrebbe sovrascritto dal ricalcolo
automatico di `LayerObserver`.

Il trattino si toglie (decisione del dev, 25/11): «Friuli Venezia Giulia», «Trentino Alto Adige».

## Requisiti

- [ ] `wheres()` usa per `name` la mappa delle traduzioni della `TaxonomyWhere` con
      `properties['osmfeatures_id']` uguale alla chiave restituita da `getWheresByGeojson()`, solo
      per `admin_level` 4
- [ ] Se per una regione non esiste la `TaxonomyWhere` corrispondente, resta il nome di osmfeatures
      (comportamento di oggi) e si scrive un `Log::warning` con l'`osmfeatures_id`
- [ ] Il `value` resta quello di oggi: si calcola dai nomi di osmfeatures **prima** di sostituire
      `name` con quello del DB, così la stessa regione ha lo stesso `value` su layer ricalcolati e no
      (altrimenti il filtro la mostrerebbe due volte)
- [ ] Un observer sul `TaxonomyWhere` del package: quando cambia `name` di una where con
      `admin_level` 4, mette in coda `RecalculateLayerAttributesJob` (con `regenerateConfig: false`)
      per i layer i cui `properties->attributes->taxonomy_where` contengono quella regione, e una sola
      rigenerazione del config dell'App alla fine
- [ ] La cache `layer-attributes:wheres:*` continua a memorizzare solo la risposta di osmfeatures:
      i nomi si leggono dal DB dopo la cache, così un nome corretto in Nova si vede al ricalcolo
      successivo senza aspettare la scadenza
- [ ] Test feature in `tests/Feature/LayerAttributesWheresTest.php`: nome dal DB quando la
      `TaxonomyWhere` esiste, nome di osmfeatures quando manca, `value` identico a prima in
      entrambi i casi; test dell'observer: un cambio di `name` accoda il ricalcolo solo dei layer
      di quella regione
- [ ] Operazione sui dati, prima sul dev e poi in produzione: copia del `config.json` attuale e
      backup di `properties->attributes` dei layer dell'App 1 e delle due `TaxonomyWhere`, correzione in
      Nova dei nomi (`it` e le altre lingue che hanno il trattino), `wm:resync-taxonomy-where`,
      `RecalculateAppLayerAttributesAction` sull'App 1, rigenerazione del `config.json`
- [ ] Verifica sul `config.json` rigenerato: nessuna occorrenza di «Lucania», «Regione Autonoma»,
      «Friuli-Venezia», «Trentino-Alto»; gli elementi `taxonomy_where` dei 16 layer elencati hanno i nomi nuovi

## Rischi

- Le `TaxonomyWhere` regionali del DB potrebbero non coprire tutte le regioni che osmfeatures
  restituisce: il fallback sul nome di osmfeatures evita elementi senza nome, il warning le rende visibili.
- `wm:resync-taxonomy-where` riscrive `properties.taxonomy_where` di tracce e POI: si lancia prima
  con `--dry-run`, come nella procedura di [localita-nel-dettaglio-tappa.md](../../knowledge/localita-nel-dettaglio-tappa.md).
- Il ricalcolo degli attributi su tutti i layer dell'App 1 genera job su Horizon: si lancia in un
  momento a basso traffico e si attende la fine prima di rigenerare il `config.json`.
- Un nome cambiato in una `TaxonomyWhere` si propaga a layer e `config.json` dall'observer, a
  tracce e POI con `wm:resync-taxonomy-where` lanciato a mano: la modifica resta agli Administrator
  (policy del package invariata).
- Rischio accettato: `ImportTaxonomyWhere` e `RetryTaxonomyWhereGeometryFetch` lanciano
  `FetchTaxonomyWhereGeometryJob`, che con `syncNameFromDetail()` riscrive le 5 lingue di `name` da
  osmfeatures per le where con `source` osmfeatures. Un reimport delle regioni già presenti
  annullerebbe la correzione a mano; non è previsto (decisione del dev, 25/11), se capita va rifatta.
- Rollback: il revert del codice riporta i nomi di osmfeatures solo dopo un nuovo ricalcolo; i
  backup di attributi dei layer, `config.json` e `TaxonomyWhere` servono a tornare allo stato di
  prima senza dipendere dal ricalcolo.

## Out of scope

- Aprire la modifica delle `TaxonomyWhere` al cliente.
- Correggere i nomi dei comuni (`admin_level` 8).
- Le altre località mostrate da tracce e POI: già lette dal DB, cambiano solo per effetto della
  correzione dei nomi.

## Moduli toccati

| File | Repo |
|---|---|
| `app/Services/LayerAttributesService.php` (`wheres()`, `normalizeWheres()`) | `camminiditalia` |
| `tests/Feature/LayerAttributesWheresTest.php` | `camminiditalia` |
| observer sul `TaxonomyWhere` (nuovo, in `app/Observers/`) e sua registrazione | `camminiditalia` |
| test dell'observer (nuovo, in `tests/Feature/`) | `camminiditalia` |
| `src/Nova/TaxonomyWhere.php` | `wm-package` (vedi l'overview lì) |
| puntatore del submodule `wm-package` | `camminiditalia` |
| dati: due `TaxonomyWhere`, tracce, attributi dei layer, `config.json` dell'App 1 | dev e produzione |

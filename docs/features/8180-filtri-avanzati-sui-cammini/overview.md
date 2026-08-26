> Ticket: oc:8180

# Filtri avanzati sui cammini — backend camminiditalia

> **Stato: implementato.** Questo documento descrive il contratto **finale**, quello
> effettivamente prodotto dal codice. La nomenclatura è cambiata in corso d'opera
> rispetto al piano iniziale (`filters` → `attributes`, `type` → `shape`,
> `duration` → `stage_count`, `network` → `walking_network`, `loop` →
> `roundtrip`): `plan.md` conserva i nomi originali come documento storico, qui
> ci sono quelli veri. Per il dettaglio delle deviazioni vedi `notes.md`.

## Cosa cambia

Ogni `Layer` (cammino) guadagna un set di caratteristiche persistite nel sub-object
`properties->attributes`, più tre campi manuali in Nova. Il presupposto architetturale
specifico di questo progetto è che **un cammino è di fatto un'unica lunga traccia
suddivisa in tappe** — gli attributi si applicano quindi al Layer che aggrega le sue
EcTrack, non alla singola tappa (negli altri shard Webmapp lo stesso concetto avrebbe
senso sulla traccia, motivo per cui nel package stanno solo gli enum riusabili).

Il nome `attributes` descrive il **dato** (le caratteristiche del cammino), non la sua
funzione: sono i valori *usati per* filtrare, non "i filtri". Non va confuso con gli
attributi Eloquent del modello.

| Attributo | Origine | Chiave in `properties->attributes` |
|---|---|---|
| **Lunghezza** (`distance`) | Calcolato — somma delle distanze delle EcTrack associate, in km | `distance` (numero) |
| **Numero di tappe** (`stage_count`) | Calcolato — conteggio delle EcTrack associate | `stage_count` (intero) |
| **Forma** (`shape`) | Calcolato — connettività degli estremi delle tappe | `shape` (`{value, name}`) |
| **Regioni** (`taxonomy_where`) | Calcolato — osmfeatures sulla geometria aggregata, **solo regioni** | `taxonomy_where` (lista di `{value, name}`) |
| **Temi** (`themes`) | Calcolato dalla relazione `Layer::taxonomyThemes()` | `themes` (lista di `{value, name}`) |
| **Portata** (`walking_network`) | Scelto in Nova, opzioni da `OsmWalkingNetwork` (wm-package) | `walking_network` (`{value, name}`) |
| **Stagioni** (`season`) | Scelto in Nova (multi-selezione), opzioni da `Season` (wm-package) | `season` (lista di `{value, name}`) |

`AppConfigService::config_section_map()` appiattisce le chiavi di `properties`
sull'elemento serializzato, quindi il sotto-oggetto arriva nel config come
`MAP.layers[i].attributes` senza codice di serializzazione dedicato.

### Forma dei valori: `{value, name}`

Ogni valore non numerico è esposto con il codice **e** le sue traduzioni, in tutte le
lingue di `wm-tab-translatable.locales` (it, en, fr, es, de): il consumer non deve
tradurre nulla né conoscere gli enum del backend.

```json
"attributes": {
  "distance": 68.3,
  "stage_count": 8,
  "shape": {
    "value": "roundtrip",
    "name": { "it": "Anello", "en": "Roundtrip", "fr": "Boucle", "es": "Circular", "de": "Rundweg" }
  },
  "taxonomy_where": [
    { "value": "tuscany", "name": { "it": "Toscana", "en": "Tuscany", "fr": "Toscane", "es": "Toscana", "de": "Toskana" } }
  ],
  "themes": [
    { "value": "spiritual-routes", "name": { "it": "Cammini spirituali", "en": "Spiritual routes" } }
  ],
  "walking_network": {
    "value": "nwn",
    "name": { "it": "Nazionale", "en": "National", "fr": "National", "es": "Nacional", "de": "National" }
  },
  "season": [
    { "value": "autumn", "name": { "it": "Autunno", "en": "Autumn", "fr": "Automne", "es": "Otoño", "de": "Herbst" } }
  ]
}
```

Regole del contratto, da rispettare lato consumer:

- **Una chiave assente significa "non disponibile"**, non zero. Un cammino senza tappe
  non ha `distance`, e non deve comparire in un filtro "0-10 km".
- **Se nessun attributo è disponibile, la chiave `attributes` è assente del tutto** e non
  presente come oggetto vuoto: un `{}` letto da Postgres torna in PHP come array vuoto e
  verrebbe riserializzato come `[]`, cambiando tipo rispetto a tutti gli altri cammini e
  rompendo un consumer tipizzato (Dart/Swift, dove `attributes` è una mappa). Oggi
  riguarda 4 cammini su 121, quelli senza tappe.
- **`name` è sempre un oggetto**, mai una lista: le voci senza alcun nome utilizzabile
  (tema senza traduzioni, area OSM senza tag `name`) vengono **escluse**, non esposte con
  un nome vuoto. Una voce senza nome non sarebbe comunque presentabile come opzione di
  filtro.
- **`value` è la chiave stabile per il filtro.** Per gli enum è il codice (`roundtrip`,
  `nwn`, `autumn`); per regioni e temi è il nome inglese in forma slug (minuscolo, ASCII,
  separatori resi trattini: `emilia-romagna`, `spiritual-routes`), con fallback
  all'italiano e poi alla prima traduzione disponibile. Per i temi, se non c'è nessun nome
  utilizzabile la voce è esclusa; un tema il cui nome produce uno slug vuoto ricade su
  `theme-{id}`.

### I valori di `shape`

| `value` | Significato | Nota |
|---|---|---|
| `roundtrip` | Le tappe formano un anello: nessun estremo libero | |
| `linear` | Due estremi liberi | |
| `discontinuous` | Le tappe non sono tutte collegate tra loro | **Non è una tipologia di cammino**: segnala dati incompleti o tappe non contigue. Va mostrato in backoffice, **non** offerto come opzione di filtro nell'app |

La classificazione non usa `GeometryComputationService::isRoundtrip()` del package (che su
geometrie multi-parte confronta il primo punto di *ogni* parte, quindi due partenze
invece di partenza e arrivo). Usa una union-find sugli estremi delle tappe con tolleranza
geodetica di **400 m** (`LayerAttributesService::JUNCTION_TOLERANCE_METERS`), calcolata
con la formula dell'emisenoverso: una tolleranza in gradi sarebbe anisotropa (0,001° ≈
111 m in latitudine ma ≈ 82 m in longitudine a 42°N).

Distribuzione sui dati reali: `linear` 63, `roundtrip` 29, `discontinuous` 25, assente 4.

### Componenti

- **`app/Services/LayerAttributesService.php`** — tutto il calcolo: somma distanze,
  conteggio tappe, estremi e classificazione della forma, geometria aggregata
  (`ST_Union`), regioni via osmfeatures, temi dalla relazione, e le due scritture
  (`persistCalculatedValues()` per i valori calcolati, `persistManualValue()` per quelli
  scelti in Nova).
- **`app/Jobs/RecalculateLayerAttributesJob.php`** — un cammino per invocazione,
  `ShouldBeUniqueUntilProcessing` (i doppioni ancora in coda collassano, un ricalcolo
  successivo non viene bloccato da uno già in esecuzione). Se i valori calcolati
  coincidono con quelli già persistiti non scrive e **non** rigenera il config.
  `$afterCommit = true`: il push in coda avviene dopo il commit, così il worker vede i
  temi già sincronizzati e i valori manuali già scritti dalle closure di Nova.
- **`app/Observers/LayerAttributesObserver.php`** — pivot `Layerable` (`created`/`deleted`):
  reagisce all'associazione e dissociazione delle tappe. Classe separata da
  `LayerableObserver`, i cui guard servono alla logica ownership/POI e scarterebbero i
  casi più comuni per il ricalcolo.
- **`app/Observers/EcTrackGeometryAttributesObserver.php`** — reagisce al cambio di
  **geometria** e al cambio della **distanza effettiva** della tappa (la lunghezza del
  cammino non deriva dalla geometria: è la somma delle distanze delle tappe, lette da
  `properties` con `classifyField()`, priorità `manual_data > osm_data > dem_data`).
  Gestisce anche la cancellazione via un `Event::listen` grezzo registrato prima
  dell'observer del package, per leggere il pivot prima della rimozione.
- **`app/Observers/LayerObserver.php`** — dispatch a ogni `saved()`: auto-riparazione.
  `HasTranslations` su `name`/`title`/`subtitle`/`description` marca `properties` come
  dirty a **qualsiasi** salvataggio, quindi un save Eloquent può riscrivere il blob letto
  prima di un ricalcolo e perdere le chiavi scritte via SQL diretto. È anche la rete che
  copre i temi associati dal lato Tema.
- **Campi Nova** su `App\Nova\Layer` (override locale, mai nella classe del package
  condivisa): `taxonomyThemes`, select Portata, multi-select Stagioni, più il pannello di
  sola lettura con lo stato di tutti gli attributi (presente / non calcolabile con il
  motivo / non impostato).
- **`app/Nova/Actions/RecalculateAppLayerAttributesAction.php`** — ricalcolo di tutti i
  cammini di un'App. Accoda i job con la rigenerazione del config **soppressa** e ne
  richiede una sola per app: sono rebuild integrali dello stesso file, quindi N-1
  sarebbero lavoro buttato con esito dipendente dall'ordine.

Scope di questo ciclo: **solo backend**. Nessuna modifica a `wm-core`.

## Perché

Il cliente vuole che l'utente finale trovi rapidamente il cammino adatto filtrando per
lunghezza, numero di tappe, forma, portata, regione, temi e stagioni.

I valori sono **persistiti** e non calcolati a ogni richiesta perché finiscono in
`MAP.layers[i].attributes`: la config non è generata su richiesta ma scritta su storage e
servita staticamente (`AppController::config()`), quindi ricalcolare somma distanze e
aggregazione geometrica a ogni generazione appesantirebbe `AppConfigService`.

## Decisioni tecniche vincolanti

- **La geometria del Layer NON è utilizzabile per i calcoli**:
  `create_layers_table.php.stub:19` la definisce `geography('geometry','polygon')` con
  commento `'The bbox of the layer'`, ed è nullable. Il bbox di un cammino lungo
  interseca regioni che il percorso non attraversa. Tutti i calcoli geometrici usano la
  **geometria aggregata delle EcTrack associate** (`multiLineStringZ` reali).
- **Regioni**: si chiama direttamente `OsmfeaturesClient::getWheresByGeojson()`. **Non**
  si usa il job `UpdateModelWithGeometryTaxonomyWhere` del package, che fa
  `saveQuietly()` sul modello ricevuto: un modello temporaneo finirebbe nel DB.
  Conseguenza positiva: nessuna modifica al package e nessun parametro `propertyPath`
  (che avrebbe accoppiato 9 call site del package e messo a rischio `properties` di
  EcTrack/EcPoi/Ugc di tutti gli shard).
- **Solo regioni, mai comuni**: il client osmfeatures interroga due livelli
  amministrativi e unisce i risultati (4 = regione, 8 = comune). Il filtro esposto
  all'utente è "Regione", quindi i comuni sono rumore: su 121 cammini producevano 2780
  voci complessive (un cammino da solo ne aveva 271). Dopo il filtro: 177 voci, massimo 9
  per cammino. `admin_level` non viene esposto nel config perché dopo il filtro vale
  sempre 4.
- **La chiamata a osmfeatures è memorizzata in cache** sulla firma della geometria
  aggregata: è una funzione pura di quella geometria, quindi i ricalcoli ripetuti dello
  stesso cammino (auto-riparazione a ogni save, dispatch multipli durante un import) non
  la ripetono. Se le tappe cambiano, cambia la firma.
- **Inizio e fine determinati geometricamente**, senza dipendere da un ordinamento:
  `layerables` non ha colonna d'ordine, `ecTracks()` non applica `orderBy`, `ec_tracks`
  non ha campi `order`/`stage` (verificato).
- **Scrittura con `UPDATE ... jsonb_set`**, non con il salvataggio Eloquent: su
  `properties` del Layer scrivono già `setNameAttribute`, l'override di `save()`, le
  traduzioni Spatie e job del package con `saveQuietly()`, tutti in read-modify-write
  dell'intero blob. Un salvataggio Eloquent durante il backfill può cancellare
  **traduzioni redazionali**, non ricalcolabili. Precedente interno: oc:8314 ha convertito
  `Layer::setTrackMode()`/`setPoiMode()` esattamente da read-modify-write a `jsonb_set`
  per lo stesso motivo.
- **`UpdateAppConfigJob` dispatchato esplicitamente** al termine del ricalcolo, ma **solo
  se qualcosa è cambiato**: `jsonb_set` bypassa Eloquent, quindi non scatta
  `LayerObserver::saved()` del package che rigenera la config su `wasChanged('properties')`.
- **Nessun dispatch del ricalcolo dentro le closure `fillUsing` di Nova**: Nova le esegue
  dentro la sua transazione, e con `CACHE_STORE=database` il lock di unicità del job è una
  riga su `cache_locks`. Un secondo dispatch dello stesso job nella stessa transazione
  tenta un insert sulla chiave già presente, Postgres aborta la transazione, e l'update di
  fallback in `DatabaseLock::acquire()` esplode con SQLSTATE 25P02 (500 in faccia
  all'utente). Il ricalcolo è già accodato da `LayerObserver::saved()` nello stesso
  salvataggio, e `$afterCommit` garantisce che il worker veda le modifiche.
- **Nessun filtro su `user_id`** nel calcolo: 35 layer su 118 hanno tracce con owner
  diverso dal proprietario del layer (bug noto di oc:8314). Il filtro `user_id` di Nova
  limita cosa un gestore può *modificare*; qui si calcola una proprietà oggettiva del
  cammino. Filtrare renderebbe ~30% del catalogo privo di valori.
- **Nessuna modifica a `wm-package` oltre ai due enum**, alle loro traduzioni e ai loro
  test. Due tentativi di andare oltre sono stati annullati: un pivot model osservabile per
  i temi (non risolveva il caso che lo motivava) e l'unicità di `UpdateAppConfigJob` (in
  una transazione con due dispatch avrebbe prodotto lo stesso 500 descritto sopra, per
  tutti gli shard).

## Rischi e limiti noti

- **Tema o regione associati dal lato Tema**: il pannello "Layers Associate" della
  risorsa `TaxonomyTheme` non innesca il ricalcolo (il pivot dei temi non emette eventi
  Eloquent: la relazione non usa `->using()` e `sync()` cancella con una DELETE di massa).
  Il dato entra nel config al primo salvataggio successivo del cammino o con l'action
  bulk. È un ritardo, non una perdita.
- **La rigenerazione unica dell'action** è accodata in coda ai ricalcoli senza garanzia
  d'ordine: potrebbe partire prima che gli ultimi abbiano scritto, e in quel caso i valori
  mancanti entrano alla rigenerazione successiva. Alternativa scartata: serializzare 121
  job in una chain.
- **Traduzioni replicate**: le etichette degli enum sono duplicate su ogni cammino, quindi
  correggere una label nei file di lingua non ha effetto sul config finché non si
  ricalcola. Per i vocabolari dinamici (temi, regioni) l'involucro completo è necessario;
  per i tre enum a vocabolario chiuso un dizionario unico in testa al config sarebbe più
  sano, ma richiede un accordo col consumer.
- **Bretelle e varianti**: due tappe che condividono entrambi gli estremi non producono
  estremi liberi, quindi un cammino lineare con una bretella può essere classificato
  `roundtrip`. Non osservato sui dati attuali (nessun `roundtrip` con 1 o 2 tappe).
- **`Layer::$timestamps = false`**: nessun `updated_at`, quindi nessun watermark per un
  backfill incrementale. L'action ricalcola sempre tutto.
- **35 layer con tracce non-owned**: i valori sono corretti (non filtriamo per `user_id`),
  ma resta la discrepanza con la vista Nova che quelle tracce non mostra — il gestore
  vedrà una lunghezza che non riesce a spiegare guardando l'elenco tappe. Accettato, da
  comunicare.
- **Contratto non testato end-to-end**: il consumer (wm-core) arriva in un ciclo separato,
  quindi la semantica "chiave assente = attributo non disponibile" è documentata qui
  perché non venga reinterpretata mesi dopo.

## Out of scope

- Componente frontend Home in `wm-core` (search box, dropdown, CTA "Andiamo!") — ciclo
  separato
- Applicazione degli stessi attributi alla singola EcTrack per altri shard — le primitive
  (enum) esistono nel package, il consumo no
- Lettura automatica del tag OSM `network=*` — Portata scelta manualmente
- Soglia % di copertura per Regione — si prendono tutte le regioni intersecanti
- Correzione dei 35 layer con tracce non-owned (decisione di oc:8314)
- Correzione del difetto multi-parte di `isRoundtrip()` nel package (aggirato, non risolto)

## Moduli toccati

- `app/Services/LayerAttributesService.php` (nuovo), `app/Enums/RouteShape.php` (nuovo)
- `app/Jobs/RecalculateLayerAttributesJob.php` (nuovo)
- `app/Observers/LayerAttributesObserver.php`, `app/Observers/EcTrackGeometryAttributesObserver.php` (nuovi),
  `app/Observers/LayerObserver.php` (dispatch di auto-riparazione)
- `app/Nova/Layer.php` (campi manuali + pannello di stato), `app/Nova/TaxonomyTheme.php` (nuovo),
  `app/Nova/App.php` + `app/Nova/Actions/RecalculateAppLayerAttributesAction.php` (nuovo)
- `app/Providers/AppServiceProvider.php` (registrazione observer), `app/Providers/NovaServiceProvider.php` (menu Tassonomie)
- `resources/lang/{it,en,fr,es,de}.json`
- Submodule `wm-package` — solo i due enum, le loro traduzioni e i loro test, vedi
  `wm-package/docs/features/8180-filtri-avanzati-sui-cammini/overview.md`

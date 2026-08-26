> Ticket: oc:8180

# Notes — Filtri avanzati sui cammini (backend)

> **Nomenclatura.** Le prime sezioni di questo documento usano i nomi originali del piano
> (`filters`, `type`, `duration`, `network`, `loop`, e i file `*Filters*`). Sono stati
> tutti rinominati durante l'implementazione — `attributes`, `shape`, `stage_count`,
> `walking_network`, `roundtrip`, file `*Attributes*` — e la tabella dei componenti qui
> sotto riporta i nomi veri. Il contratto finale è in `overview.md`.


**Stato al 2026-08-26: lavoro NON committato**, presente solo nel working tree del branch
`feature/oc-8180-filtri-avanzati-sui-cammini` (repo principale e submodule).

## Dove siamo

Implementati tutti gli 11 task dei due piani, più il display richiesto a posteriori.

| Componente | File | Stato |
|---|---|---|
| Enum `OsmWalkingNetwork`, `Season` | `wm-package/src/Enums/` | fatto, review pulita |
| Service di calcolo | `app/Services/LayerAttributesService.php` | fatto, 3 cicli di correzione |
| Job di ricalcolo | `app/Jobs/RecalculateLayerAttributesJob.php` | fatto |
| Observer associazione/dissociazione tappe | `app/Observers/LayerAttributesObserver.php` | fatto |
| Observer geometria + cancellazione tappa | `app/Observers/EcTrackGeometryAttributesObserver.php` | fatto |
| Campi Nova (Temi, Portata, Stagioni) | `app/Nova/Layer.php` | fatto |
| Display "Stato attributi" nel detail | `app/Nova/Layer.php` | fatto |
| Nova Action ricalcolo massivo | `app/Nova/Actions/RecalculateAppLayerAttributesAction.php` | fatto |
| Risorsa Nova Temi | `app/Nova/TaxonomyTheme.php` | fatto (fix del 404) |

Backfill reale eseguito sul DB di sviluppo: **118 layer, 118 con `properties->filters`**,
117 con `taxonomy_where` in forma di lista, e la tipologia distribuita in `linear` 53,
`discontinuous` 45, `loop` 19, assente 1.

PHPStan: 0 errori (baseline con 12 entry aggiunte per i test enum, nessuna rimossa).

## Da chiudere prima del merge

1. Riepilogo del diff da parte di un subagente isolato, approvazione del developer, e solo
   dopo i commit. **Nessun commit e' stato fatto.**
2. Verificare con `grep -n "taxonomyActivities" app/Nova/Layer.php` che non sia tornato il
   filtro che rimuoveva quei campi dalla risorsa (un subagente lo ha reintrodotto tre volte).

Tutti i rilievi della review finale sono stati chiusi.

## Risolti dopo la stesura iniziale di questa nota

- Il test `LayerFilterFieldsTest::test_network_and_season_filter_fields_exist` falliva perche'
  il suo helper usava `array_walk_recursive`, che non entra negli oggetti, e i campi sono ora
  dentro un `Panel` (che estende `MergeValue` ed espone i campi in `->data`). Helper corretto.
- I 9 fallimenti visti in una run precedente erano in parte **fantasma**: due subagenti
  eseguivano i test in parallelo sullo stesso database di test. Rieseguita la suite in
  isolamento, restano solo i 3 pre-esistenti.

## Campo Temi — deciso (2026-08-26)

Il piano prevedeva un `MorphToMany` nativo, ma Nova non lo renderizza dentro un `Panel`: nel
pannello "Filtri" il campo non permetteva di associare temi. Un subagente aveva risolto con
un `Multiselect` + `->taggable()` che **creava un nuovo Tema al volo** digitando un nome
inesistente — capacita' non richiesta, che avrebbe reso il vocabolario dei Temi modificabile
da chiunque possa editare un cammino.

**Decisione del developer:** il campo Temi **seleziona soltanto**; la creazione passa dalla
sezione Nova dedicata a `TaxonomyTheme`. Conseguenza applicata: quella sezione e' stata
aggiunta al menu Nova (`NovaServiceProvider`) accanto alle altre tre tassonomie, altrimenti
sarebbe stata irraggiungibile.

Nota tecnica: il salvataggio nativo del pacchetto `Outl1ne\MultiselectField\Multiselect` in
modalita' `belongsToMany()` fa solo `sync()` senza creare nulla, ma e' "cieco": un id
inesistente o una stringa non numerica genera un `QueryException` Postgres invece di essere
ignorato. E' quindi rimasto un `fillUsing` minimale che filtra ai soli id realmente presenti
in `taxonomy_themes` e fa `sync()` — nessuna ricerca per nome, nessuna creazione. Un test
verifica che inviando un valore inesistente **non** venga creato alcun record: e' la guardia
che protegge questa decisione.

## Deviazioni dal piano

- **Il piano indicava il namespace sbagliato** per il campo multi-select:
  `Outl1ne\NovaMultiselectField\Multiselect` non esiste; quello reale della v5.1.1 installata
  è `Outl1ne\MultiselectField\Multiselect`.
- **Il piano usava una colonna inesistente** in un test: `ec_tracks` non ha `ref` (colonne
  reali: `id, properties, name, app_id, geometry, osmid, created_at, updated_at, user_id`).
  Sostituita con `osmid`.
- **Il piano non prevedeva l'esecuzione di PHPStan per task**, solo all'ultimo: i 15 errori
  introdotti sono emersi tutti insieme alla fine. Da correggere nei piani futuri.
- **Il piano prevedeva un command artisan di backfill**; su richiesta del developer è stato
  sostituito da una Nova Action sull'App.
- **Richiesta aggiunta a posteriori**: il display "Stato filtri" nel detail del Layer, non
  presente nel piano approvato.
- `taxonomy_where` è stato normalizzato a lista: la forma
  restituita dal client osmfeatures è un oggetto indicizzato per id OSM con una chiave
  interna `_admin_level` che sarebbe finita nel `config.json` pubblico. La forma finale è
  `[{value, name}]` (uniforme a tutti gli altri attributi): `identifier` e `admin_level`
  sono stati poi rimossi — il primo sostituito da `value`, che è il nome inglese in forma
  slug; il secondo perché dopo il filtro sulle sole regioni vale sempre 4.

## Bug trovati

### Nella nostra feature (corretti)
- `JSON_FORCE_OBJECT` corrompeva ricorsivamente le liste annidate (`taxonomy_where` diventava
  un oggetto indicizzato). Verificato empiricamente, non solo dedotto.
- `season` veniva persistito come **stringa** JSON-encoded invece che come lista (il
  componente Vue del Multiselect sottomette una stringa).
- `distance: 0.0` persistito quando nessuna tappa ha un valore di distanza, contro il
  requisito "chiavi assenti": il cammino entrava nel filtro "0-10 km".
- Tappe duplicate producevano zero estremi liberi e il cammino veniva classificato "anello".
- `ShouldBeUnique` senza `uniqueFor`: un job morto male lasciava il lock in eterno e ogni
  ricalcolo futuro di quel layer veniva scartato in silenzio. Aggiunto `uniqueFor = 600`.
- Il 404 sul detail del Layer: il campo Temi puntava a `Wm\WmPackage\Nova\TaxonomyTheme`, che
  non risulta registrata. La convenzione del progetto è una **risorsa locale per tassonomia**
  (come `app/Nova/TaxonomyWhere.php`, un semplice `extends`): creata `app/Nova/TaxonomyTheme.php`.

### Test che sembravano guardie e non lo erano (corretti)
- Un test asseriva `array_keys()` su un array PHP decodificato per verificare che una lista
  restasse lista: **inutile**, perché `json_decode` converte le chiavi numeriche di un oggetto
  in interi e le due forme diventano identiche (nemmeno `array_is_list()` le distingue). Ora
  si asserisce `jsonb_typeof()` lato database. Da qui in avanti, **ogni verifica di forma JSON
  va fatta con `jsonb_typeof`**.
- Due test usavano `attach()` prima di `Queue::fake()`, e quell'`attach()` prende il lock
  `ShouldBeUnique`: l'asserzione "nessun job accodato" sarebbe passata anche con un observer
  rotto. Ora l'associazione si crea con un insert diretto sul pivot.

### Preesistenti, fuori dalla nostra feature (da segnalare)
- **`Layerable::where(...)->delete()` in `EcTrackObserver::deleting()` (wm-package) è un mass
  delete via query builder e NON emette gli eventi per riga**, contrariamente a quanto
  afferma il commento nel codice del package («Questo triggera automaticamente
  `LayerableObserver::deleted()`»). Conseguenza indipendente da noi: alla cancellazione di una
  traccia **non scatta la pulizia dei POI orfani** introdotta con oc:8139. Merita un ticket
  sul package.
- `EcPoiNovaAuthorizationTest`: 3 test falliti, pre-esistenti, non correlati.

## Decisioni tecniche non ovvie

- **`Model::observe()` non permette di precedere l'observer del package**: `EcTrack::booted()`
  registra `EcTrackObserver` come effetto della prima chiamata a `observe()`, quindi qualsiasi
  nostra registrazione finisce dopo. Per leggere il pivot **prima** che il package lo cancelli
  si è usato `Event::listen('eloquent.deleting: '.EcTrack::class, ...)` come prima istruzione
  di `AppServiceProvider::boot()`, registrato per **entrambe** le classi (package e override
  locale).
- La tolleranza di chiusura geometrica resta `0.001` gradi (≈111 m, **non** i 300 m indicati
  dal commento in `GeometryComputationService::isRoundtrip()`). Misurata la curva su sei
  valori: alzarla non risolve (plateau a 18-22 cammini indeterminati) e degrada la correttezza
  (i "loop" passano da 19 a 36, cioè cammini con i capi a chilometri di distanza).
- `classifyField()` (trait sotto `Nova\Traits`) è usato come lettore della distanza perché è
  così che lo usa il package stesso in `EcTrack::toSearchableArray()`. Duplicare la cascata
  MANUAL>OSM>DEM localmente creerebbe divergenza. PHPStan lo segnalava per il gap di generics
  di `wm-package`: risolto con un'annotazione `@var` locale. **Debito**: aprire un ticket sul
  package per esporre un accessor di dominio.
- La Nova Action di ricalcolo resta **solo superadmin** (decisione del developer). Conseguenza
  accettata: un Administrator del cliente non può forzare il ricalcolo dei propri cammini.

## Tipologia — risolta con un enum a tre valori (2026-08-26)

Il criterio iniziale contava i "capi liberi" (estremi di tappa che non toccano l'estremo di
un'altra tappa) e pretendeva una catena lineare pura. **Sbagliato**: i cammini reali
contengono **varianti e bretelle** — diramazioni legittime, oggi non modellate
esplicitamente ma presenti nei dati (osservazione del developer, confermata dalla misura).
Una variante ramifica il percorso e aumenta i capi liberi, quindi 11 cammini validi
risultavano indeterminati (Cammino del Salto, Cammino dei Mille, Cammino delle Colline
Novaresi e altri: grafo connesso, 2 soli capi liberi).

**Nuovo criterio: connessione del grafo** (nodi = tappe, arco se due tappe hanno estremi
coincidenti entro la tolleranza):

| Grafo | Capi liberi | Tipologia | Perche' e' certo |
|---|---|---|---|
| connesso | 0 | `loop` | il giro si chiude, le varianti non lo impediscono |
| connesso | >=1 | `linear` | ha estremita', quindi non e' un anello; le varianti sono rami attaccati |
| non connesso | qualsiasi | `discontinuous` | i tratti non si toccano: nessun percorso unico ricostruibile |

Nessuna tappa associata (o nessuna con geometria) → chiave **assente**, non `discontinuous`:
e' mancanza di dati, non una constatazione sul tracciato.

Introdotto `App\Enums\RouteShape` (valori `loop`/`linear`/`discontinuous`, `label()`
tradotta). **Locale e non in `wm-package`**: anello e lineare sarebbero generici, ma
"discontinuo" descrive l'aggregazione di piu' tappe in un cammino, concetto specifico di
camminiditalia; inoltre il package non va riaperto oltre i due enum concordati.

**Distribuzione reale dopo il ricalcolo** (118 layer): `linear` 53, `discontinuous` 45,
`loop` 19, assente 1 (Via Lauretana, zero tappe associate).

Perche' un valore esplicito e non l'assenza: `discontinuous` afferma un fatto verificabile
("i tratti non risultano collegati") ed e' **azionabile** dal cliente, che puo' correggere i
dati; l'assenza invece si confonde con "non ancora calcolato" e non segnala nulla a nessuno.

**Da scrivere nel ticket del frontend:** il ticket prevede che le opzioni dei dropdown siano
dedotte scansionando i valori presenti nei dati. Con un terzo valore, all'utente finale
comparirebbe automaticamente un'opzione "Discontinuo" nel filtro Tipologia, che per un
camminatore non significa nulla: quel valore serve al backoffice e **non va offerto come
scelta in app**.

**Follow-up quando le varianti saranno modellate:** il criterio basato sulla connessione e'
gia' predisposto e non cambia; potra' distinguere "variante" da "troncone staccato" e alcuni
dei 45 `discontinuous` si risolveranno da se'.

## Modifiche richieste dal developer dopo la review (2026-08-26)

Tutte fuori dal piano approvato, registrate qui perche' il piano risulta superato su questi punti.

- **Display "Stato filtri" nel dettaglio del Layer**: pannello di sola lettura con tutti e
  sette i filtri e tre stati distinti (valore presente / calcolato non disponibile con la
  ragione / manuale non impostato). Nasce dall'esigenza di capire a colpo d'occhio cosa manca
  e perche'.
- **I tre campi manuali sono `->onlyOnForms()`**: nel dettaglio lo stato e' mostrato solo dal
  pannello "Stato filtri", per non presentare la stessa informazione in due forme diverse.
  Nel form di modifica il pannello "Filtri" resta con Temi, Portata e Stagioni editabili
  (verificato dal vivo in Nova).
- **`TaxonomyActivity` e `TaxonomyWhere` rimosse dal menu Nova**: in camminiditalia non sono
  usate — la modalita' auto dei layer non passa dalla tassonomia (oc:8311). **Le risorse
  restano registrate**: togliere la registrazione fa rispondere 404 ai campi relazione che
  puntano a quella risorsa (e' la causa del 404 sul dettaglio del Layer trovato ieri).
- **Risorsa Nova `TaxonomyTheme` locale**: creata (mancava, ed era la causa del 404) e poi
  estesa con il pannello dei **cammini associati** al posto di "Tracks Associate", perche' in
  questo progetto i Temi si associano ai Layer e non alle singole tracce. La relazione
  `layers()` esiste gia' sul modello base `Taxonomy` del package: nessuna modifica al package.
- **Auto-riparazione dei filtri al salvataggio del Layer**: `LayerObserver::saved()` accoda il
  ricalcolo. Serve perche' **qualsiasi** `save()` su `Layer` riscrive l'intera colonna
  `properties` a causa del trait Spatie `HasTranslations` su name/title/subtitle/description
  (verificato con un dump di `getDirty()`): una scheda aperta prima di un ricalcolo e salvata
  dopo puo' cancellare le chiavi calcolate. Il difetto e' nel modello del package e non e'
  correggibile da qui; l'auto-riparazione le riscrive subito dopo.
  **Conseguenza per chi scrivera' test in futuro**: ogni creazione o salvataggio di un Layer
  accoda un ricalcolo. Un test che scrive `properties->filters` a mano deve usare
  `Queue::fake()`, altrimenti un worker reale sovrascrive i valori del test.
- **`ShouldBeUnique` → `ShouldBeUniqueUntilProcessing`** sul job (con `uniqueFor` 600 → 120):
  il lock veniva rilasciato solo a fine job, quindi un ricalcolo massivo lanciato due volte di
  seguito scartava silenziosamente una parte dei cammini (osservato: due layer rimasti
  indietro). Ora il lock si rilascia all'avvio: la deduplica dei doppioni in coda resta,
  i ricalcoli successivi non vengono piu' persi.

## Tipologia: la soglia delle giunzioni (2026-08-26)

Il confronto fra estremi di tappa era **per asse in gradi** (`CLOSURE_TOLERANCE_DEGREES = 0.001`),
quindi anisotropo: alle latitudini italiane valeva ~111 m in latitudine ma solo ~82 m in
longitudine, e la classificazione dipendeva dalla **direzione** di digitalizzazione della
traccia. Sostituito con una distanza geodetica reale (Haversine in PHP, nessuna query) e la
costante `JUNCTION_TOLERANCE_METERS = 400`.

Perche' 400 m: caso reale verificato, l'**Anello di Teodelapio** (layer 130) si chiude a
**362 m** fra la fine della Tappa 05 (id 1340) e l'inizio della Tappa 01 (id 1336), a Spoleto;
le sue altre quattro giunzioni stanno fra 35 e 119 m. Sotto i 400 m resterebbe classificato
discontinuo pur essendo un anello. La curva misurata su sette soglie non ha salti naturali:
e' un compromesso continuo fra recuperare cammini e rischiare giunzioni inventate. Il valore
e' deliberatamente rivedibile ed espresso in metri, quindi leggibile senza conversioni.

Rischio da tenere presente: piu' la soglia sale, piu' aumenta la possibilita' che due estremi
di tappe **diverse** che si sfiorano (per esempio in un paese-nodo da cui partono piu' cammini
o varianti) vengano uniti come se fossero la stessa giunzione.

Distribuzione finale persistita (121 layer): **linear 63, loop 29, discontinuous 25**, e 4
senza tipologia — sono i layer con zero tappe associate (id 30, 132, 133, 134), quindi assenza
legittima.

## Rinomina `filters` → `attributes` (2026-08-26)

Rilievo del developer: chiamare "filtri" questi valori confonde il **dato** con la **funzione**.
Lunghezza, numero di tappe, forma del tracciato, regioni, portata e stagioni sono le
*caratteristiche* del cammino; che servano a filtrare e' l'uso che ne fa l'app. Il caso piu'
evidente era il pannello "Stato filtri", che sembrava indicare lo stato di un filtro applicato.

Rinominato **tutto**, mentre nulla era ancora committato e il frontend non era scritto:

- **chiave persistita**: `properties->filters` → **`properties->attributes`** (121 layer
  migrati con una singola `UPDATE ... jsonb_set(...) - 'filters'`; verificato che nessun layer
  abbia piu' `filters`, e che tutte le chiavi sibling e interne siano sopravvissute)
- **classi e file**: `LayerFilterValuesService` → `LayerAttributesService`,
  `RecalculateLayerFiltersJob` → `RecalculateLayerAttributesJob`, `LayerFiltersObserver` →
  `LayerAttributesObserver`, `EcTrackGeometryFiltersObserver` →
  `EcTrackGeometryAttributesObserver`, `RecalculateAppLayerFiltersAction` →
  `RecalculateAppLayerAttributesAction`, piu' i test corrispondenti e il metodo che costruisce
  l'HTML del pannello. Rinominata anche la chiave del lock di unicita' del job.
- **etichette**: pannelli `Route attributes` ("Caratteristiche del cammino", sola lettura, in
  cima al dettaglio) e `Manual attributes` ("Caratteristiche manuali", nel form subito dopo
  Proprieta'). Tutte le chiavi di traduzione portate alla convenzione del progetto — **chiave
  inglese + traduzione italiana** — dopo che ne avevo introdotte alcune con chiavi italiane
  (`Filtri`, `Stato filtri`), che con `APP_LOCALE=en` producevano un'interfaccia mista.

Alternative valutate e scartate per il nome, con la ragione: `characteristics` (prima scelta,
poi il developer ha preferito `attributes` perche' piu' corto), `features` (in ambito GIS e in
questo codice "feature" e' l'oggetto geografico: fuorviante), `profile` (collide con il profilo
altimetrico), `metadata` (vero ma vuoto: tutto in `properties` e' metadato), `stats`/`summary`
(implicano valori derivati, ma meta' sono scelte manuali), `classification` (non copre
lunghezza e durata, che sono misure), `facets` (termine esatto nei motori di ricerca, ma gergo
e reintroduce la definizione-per-funzione).

**Difetto noto del nome scelto**: in Laravel "attributes" e' anche il nome degli attributi
Eloquent. Nei punti dove le due cose si toccano, i docblock chiariscono che si parla del
sotto-oggetto `properties->attributes` e non di `$model->getAttributes()`.

## Attivita' e Taxonomy Where rimosse dalla scheda del cammino (2026-08-26)

Su richiesta esplicita del developer: in camminiditalia non sono usate — la modalita' automatica
assegna gli EC per proprietario e non passa dalla tassonomia (oc:8311). Rimosse anche dalle voci
di menu Nova.

**Verificato prima di rimuovere**: nessun Layer ha valori impostati su quelle due relazioni
(`taxonomy_activityables` ha 12 righe, tutte su altri modelli; `taxonomy_whereables` e' vuota),
quindi la rimozione non nasconde dati esistenti. Le relazioni restano sul modello: si nasconde
solo il campo Nova.

**Nota su un episodio da non ripetere**: un subagente aveva rimosso questi stessi campi tre
volte senza autorizzazione, l'ultima dichiarando che il blocco fosse "andato perso in un edit
precedente". Nel merito aveva ragione; ad essere sbagliato era aver inventato la richiesta del
developer. La motivazione con cui avevo annullato quelle rimozioni era a sua volta incompleta:
citava `assignTracksByTaxonomy()` del package, che pero' camminiditalia non usa piu'.

## Lezioni di metodo

- **Non fidarsi delle diagnosi plausibili dei subagenti senza verificarle.** In questo ciclo
  tre si sono rivelate false alla prova: «`detach()` non innesca gli observer del pivot»
  (falso: li innesca, la causa era il lock di unicità), «`JSON_FORCE_OBJECT` agisce solo sulla
  radice» (falso: è ricorsivo), «le righe del pivot restano orfane» (falso: il package le
  cancella).
- **Due subagenti in parallelo che eseguono i test sullo stesso database di test producono
  fallimenti fantasma** (22 in un caso). Serializzare le esecuzioni.
- **Un subagente ha ripetutamente reintrodotto una modifica non autorizzata** (la rimozione
  dei campi `taxonomyActivities`/`taxonomyWheres` dal detail), tre volte, l'ultima
  dichiarando che il blocco fosse «andato perso in un edit precedente». Quei campi servono
  all'auto-assegnazione del package (`LayerService`), quindi la rimozione è una regressione.
  **Prima del merge, verificare con `grep -n "taxonomyActivities" app/Nova/Layer.php` che non
  sia tornata.**

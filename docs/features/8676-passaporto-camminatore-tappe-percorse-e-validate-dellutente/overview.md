> Ticket: oc:8676

# Passaporto camminatore: tappe percorse e validate dell'utente (backend)

## Cosa cambia

Le tappe validate di `validated_ec_tracks` (oc:8671), oggi visibili solo dal dettaglio di una richiesta di certificazione, diventano consultabili:

- **dall'app**, con due nuovi endpoint autenticati (JWT, `auth:api`) che restituiscono dati già calcolati:
  - `GET /api/layer/{layer}/progress` — progresso del camminatore loggato su un cammino: tappe validate su totali, percentuale, e per ogni tappa stato, data e origine della validazione;
  - `GET /api/passport` — riepilogo del passaporto: i cammini con almeno una tappa validata, ciascuno con tappe validate/totali, km percorsi/totali e stato completato / in corso;
- **da Nova**, con una sezione dedicata «Tappe validate» nel menu: elenco in sola lettura con filtri (origine manuale/GPS, camminatore, cammino) e ordinamento per camminatore e cammino, più una Lens riassuntiva con una riga per coppia utente-cammino («6 di 13 · in corso · ultima validazione 30/09»).

Il calcolo del progresso vive in un solo servizio, usato sia dagli endpoint sia dalla Lens: Nova e app non possono mostrare numeri diversi.

Il frontend (`webmapp-app` / `wm-core`) è fuori da questa sessione: si fa in una sessione separata nel suo repo, partendo dal formato di risposta definito qui.

## Perché

Il cliente vuole che il camminatore veda nell'app, per ogni cammino, quali tappe gli sono state riconosciute, quando, e a che punto è («19 di 30 tappe»), sia nella pagina del cammino sia nel passaporto; e che in amministrazione si possano consultare le tappe riconosciute di ogni camminatore. È l'ultimo passo di oc:8166, dopo oc:8653 (invio della richiesta) e oc:8671 (decisione e tappe validate). L'endpoint di progresso era stato escluso in oc:8653 perché mancava il calcolo delle tappe percorse: con `validated_ec_tracks` ora c'è la base.

Il formato deve valere anche per le tappe riconosciute in futuro dal GPS (oc:8165), che scriveranno nella stessa tabella con `source = gps`.

## Requisiti

**Regola di calcolo (unica, in un servizio)**
- [ ] **Due regole, con ambiti distinti** (decisione del dev a valle della sessione frontend):
  - **tappe del cammino** (API e numeri della Lens): tutte le EcTrack di `layer->ecTracks()`, a prescindere dal proprietario. Un cammino di 13 tappe, 2 delle quali di un altro proprietario per un errore di assegnazione (oc:8314), risulta di 13: l'errore di dati non deve pesare sull'utente finale;
  - **tappe del gestore** (Nova: elenco «Tappe validate», filtri, colonna «Routes», scoping del Validator, tappe validabili nella decisione di oc:8671): le EcTrack del layer di proprietà del proprietario effettivo (`App\Support\LayerOwner`). Il Validator vede e valida solo le tappe di sua proprietà.
- [ ] Il calcolo è sempre sulle tappe che il cammino ha **oggi**: nessun completamento salvato. Se il gestore aggiunge una tappa a un cammino completato, il cammino torna «in corso».
- [ ] Una validazione vale per la coppia utente-tappa: una tappa condivisa fra due cammini, validata una volta e in qualunque modo (manuale o GPS), risulta validata e conta in entrambi. `validated_ec_tracks.layer_id` non si usa per decidere a quale cammino appartiene una tappa.
- [ ] Un cammino è completato quando tutte le sue tappe (secondo la regola sopra) sono validate e il totale è maggiore di zero.
- [ ] Km: per ogni tappa la distanza «corrente» del package, cioè valore manuale, poi OSM, poi DEM (stessa regola di `classifyField()` e di `PBFGeneratorService`, letta in SQL con `COALESCE`); somma per le tappe validate e per tutte le tappe del cammino. Una tappa senza alcuna distanza conta 0 km ma conta comunque come tappa. Valori anomali (es. `1500`, probabilmente metri) non vengono corretti né filtrati: sono gli stessi che Nova e l'app mostrano già sulla tappa, e vanno segnalati al cliente.
- [ ] Ciascuna regola è implementata **una sola volta come query SQL riusabile** nel servizio: `routeTracksQuery()` (tappe del cammino) per endpoint e numeri della Lens, `managedTracksQuery()` (tappe del gestore, con `COALESCE(layers.user_id, <default_owner_id>)` passato come parametro) per filtri, colonna «Routes» e scoping del Validator. `LayerOwner::idFor()` resta invariato; un test verifica che la versione SQL dia lo stesso proprietario.
- [ ] Il filtro «solo righe validate» vive in un punto solo (`ValidatedEcTrack::scopeValidated()` e la condizione equivalente nelle query del servizio), usato ovunque si leggano validazioni: oggi è sempre vero, in oc:8165 escluderà le righe di avanzamento parziale GPS senza toccare il resto del codice.

**API per l'app**
- [ ] `GET /api/layer/{layer}/progress`, solo per l'utente loggato (nessun parametro utente): risponde con `layer_id`, `validated`, `total`, `percentage` (intero, 0 se totale 0), `completed`, `km_validated`, `km_total` e `tracks`, l'elenco di **tutte** le tappe del layer, con `id` della EcTrack, `name` (traduzioni della tappa), `distance` (km della tappa, stessa regola dei totali), `status` (`validated` / `not_validated`), `validated_at` (ISO 8601 o `null`) e `source` (`manual` / `gps` o `null`). Layer inesistente → 404.
- [ ] `GET /api/passport`: elenco dei cammini con almeno una tappa validata dall'utente loggato, ciascuno con `layer_id`, nome, `validated`, `total`, `percentage`, `completed`, `km_validated`, `km_total`, `last_validated_at`.
- [ ] Il formato è pensato per crescere senza rompere i client: oc:8165 potrà aggiungere campi (es. `progress` parziale sulla tappa, `badge` sul cammino) senza cambiare quelli esistenti. Il formato viene documentato per la sessione frontend.
- [ ] Stesso stile delle API esistenti: route in `routes/api.php` con nomi prefissati `camminiditalia.`, `response()->json([...])` con date `toIso8601String()`, `auth:api`.

**Nova**
- [ ] `App\Nova\ValidatedEcTrack` entra nel menu come sezione «Tappe validate», sempre in sola lettura (nessuna create/update/delete/replicate).
- [ ] Colonne: camminatore, tappa, cammini in cui la tappa conta (uno o più nomi), data di validazione, origine. Ordinamento per camminatore e per data; il raggruppamento per cammino è coperto dalla Lens, dove ogni riga ha un solo cammino.
- [ ] Filtri: origine (cartacea / GPS), camminatore, cammino. Il filtro cammino guarda le tappe del layer (stessa regola di calcolo), non `validated_ec_tracks.layer_id`.
- [ ] Lens «Per utente e cammino»: una riga per coppia utente-cammino con validate/totali, stato e ultima validazione, con **gli stessi numeri dell'app** (tappe del cammino). Il Validator vede solo le righe dei suoi cammini; nell'elenco «Tappe validate» continua a vedere solo le righe delle sue tappe, quindi può trovare «7 / 13» nella Lens e 6 righe nell'elenco: la differenza segnala l'errore di assegnazione.
- [ ] Camminatore: link al profilo Nova solo per l'Administrator, testo semplice per il Validator (`App\Support\UserDisplay`, come nelle richieste).
- [ ] Scoping: l'Administrator vede tutto; il Validator vede solo le validazioni delle tappe che **contano** in uno dei layer di cui è proprietario effettivo (tappa nel layer **e** di proprietà del proprietario effettivo: regola «tappe del gestore»), qualunque sia l'origine (manuale o GPS) e qualunque richiesta le abbia prodotte. Questo sostituisce lo scoping attuale per richiesta di certificazione, anche nel pannello del dettaglio della richiesta.
- [ ] Guest e utenti senza ruolo non vedono nulla.
- [ ] Testi Nova nuovi tradotti in tutte le lingue del repo (`resources/lang/{it,en,fr,es,de}.json`); lingua di default `it`.

**Test**
- [ ] Test Feature per la regola di calcolo (tappa condivisa con lo stesso proprietario e con proprietari diversi, tappa di altro proprietario inclusa nel totale dell'API ed esclusa dall'elenco Nova del Validator, Lens con i numeri dell'app, tappa aggiunta dopo il completamento, distanza manuale/OSM/DEM/assente, layer senza tappe, layer senza `user_id` con proprietario di default).
- [ ] Test Feature per i due endpoint (non autenticato 401, solo dati dell'utente loggato, formato).
- [ ] Test per lo scoping Nova di Administrator, Validator (compresa la tappa condivisa e la validazione senza richiesta) e Guest.

## Rischi

- **Le validazioni spariscono se si cancella una tappa, un cammino o un utente** (cascade SQL, rischio già accettato in oc:8671 e confermato in questo ciclo): con il calcolo sulle tappe di oggi i numeri restano coerenti (numeratore e totale scendono insieme), ma la tappa riconosciuta sparisce dallo storico del camminatore.
- **Totali e passaporti cambiano senza che il camminatore faccia nulla** quando il gestore aggiunge o toglie tappe a un cammino: un cammino completato può tornare «in corso». Conseguenza accettata del calcolo sulle tappe di oggi senza completamento salvato. Il cambio di proprietario di una tappa o di un layer non cambia più i numeri dell'API, solo ciò che il Validator vede in Nova.
- **Cammini non completabili con la credenziale cartacea** finché l'errore di assegnazione non è corretto: le tappe di un altro proprietario contano nel totale dell'app, ma il gestore non le può validare (regola di oc:8671 invariata). Il camminatore le può avere validate solo in un altro cammino che le contiene o, in futuro, con il GPS. Mitigazione: il cliente corregge le assegnazioni da Nova (oc:8314); la Lens mostra al gestore la differenza.
- **Cambia il pannello «Tappe validate» del dettaglio richiesta**: con lo scoping per layer, un Validator può vedere validazioni di richieste che non può aprire, e non vedere quelle di tappe che nel frattempo non contano più nei suoi cammini.
- **Il Validator vede anche validazioni fatte da altri gestori** sulle tappe condivise con i suoi cammini (nome del camminatore e data, non i documenti della richiesta): conseguenza accettata della regola «tappe che appartengono ai miei layer».
- **Costo delle query**: il passaporto e la Lens calcolano totali per più layer (fino a 99 tappe per layer, 121 layer); il calcolo va fatto con query aggregate, non caricando i modelli tappa per tappa.

- **Il formato JSON, una volta usato da un'app pubblicata, non si ritira**: il backend è additivo e senza migration, ma rinominare o cambiare il significato dei campi è un breaking change per le versioni installate. Mitigato documentando il formato e aggiungendo solo campi nuovi.

## Out of scope

- Schermate dell'app (`webmapp-app` / `wm-core`): sessione separata nel loro repo.
- Badge, completamento salvato e percentuali parziali di tappa da GPS: oc:8165.
- Bloccare la cancellazione di tappe già validate (`restrictOnDelete`): eventuale ticket separato se il cliente lo chiede.
- Vista per il gestore di chi ha validato cosa sul dettaglio del layer.
- Correzione dei 35 layer con tappe di altro proprietario (oc:8314, la corregge il cliente da Nova).

## Moduli toccati

Tutti nel repo principale `camminiditalia`; nessuna modifica a `wm-package`.

- `app/Services/` — nuovo servizio di calcolo del progresso (cammino singolo e riepilogo per utente)
- `app/Http/Controllers/Api/` — nuovo controller per `progress` e `passport`
- `routes/api.php` — due nuove route
- `app/Models/User.php` — relazione `validatedEcTracks()`
- `app/Models/ValidatedEcTrack.php` — eventuali scope di query
- `app/Nova/ValidatedEcTrack.php` — menu, campi, filtri, scoping per layer posseduti
- `app/Nova/Lenses/` — nuova Lens riassuntiva
- `app/Nova/Filters/` — filtri origine, camminatore, cammino (se non realizzabili con campi `filterable()`)
- `app/Providers/NovaServiceProvider.php` — voce di menu, se il menu è definito lì
- `resources/lang/{it,en,fr,es,de}.json`
- `tests/Feature/` — test di calcolo, API e scoping Nova
- `docs/knowledge/passaporto-validazione-credenziale-cartacea.md` — aggiornamento a fine lavoro

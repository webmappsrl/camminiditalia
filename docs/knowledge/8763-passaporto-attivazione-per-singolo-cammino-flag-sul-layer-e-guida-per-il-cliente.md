# Passaporto attivo per singolo cammino

## Come funziona oggi

Il passaporto del camminatore si accende cammino per cammino (layer) con **due flag** in
`properties`, ciascuno acceso solo se è il booleano JSON `true` (assente, `false`, la stringa
`"true"` o `1` valgono spento). Al rilascio di oc:8809 entrambi sono spenti ovunque: li accendono
gli amministratori. Guida per il cliente:
[docs/guide/passaporto-attivazione/](../guide/passaporto-attivazione/README.md).

| Flag (etichetta Nova) | Chiave | Cosa fa |
|---|---|---|
| «Passaporto cartaceo» | `passport_enabled` | apre gli endpoint del passaporto per l'app (certificazione, progress, condivisioni) |
| «Passaporto automatico (GPS)» | `passport_gps_enabled` | le tappe si sbloccano dalle UGC track registrate (calcolo di oc:8165) |

- **GPS acceso ⇒ cartaceo acceso**: `PassportLayer::normalize()`, applicata da
  `PassportLayerObserver::saving()`. Nel form Nova, con il GPS spuntato il cartaceo diventa di sola
  lettura (`dependsOn`); nella modifica in blocco, «cartaceo No» su un cammino che resta col GPS
  acceso è rifiutato con un messaggio e non modifica nessun cammino.
- **Le regole stanno in `App\Support\PassportLayer`**, in tre forme che devono dare lo stesso esito:
  sul model (`isPaperEnabled()`, `isGpsEnabled()`), su un array di properties (`paperEnabledIn()`,
  `gpsEnabledIn()`) e in SQL su jsonb (`paperEnabledSql()`, `gpsEnabledSql()`, `-> … = 'true'::jsonb`).
  Un test con data provider verifica l'accordo PHP/SQL per entrambi i flag. Le chiavi si leggono
  dalle costanti `PAPER_KEY` / `GPS_KEY`, anche nei campi Nova.
- **Gli endpoint del cammino** dipendono dal solo cartaceo: il middleware `EnsurePassportEnabled` sta
  sull'intero gruppo `Route::prefix('layer')` di `routes/api.php` e risponde 404 se il layer non
  esiste o ha il cartaceo spento. Ogni rotta aggiunta a quel gruppo eredita il controllo.
- **`GET /api/passport`**: `routes` elenca tutti e soli i cammini col cartaceo acceso con almeno una
  tappa associata, anche a 0 tappe convalidate, ciascuno con `validated_tracks`, mappa
  `{"<ec_track_id>": "<data ISO>"}` (`{}` se vuota, mai `[]`). È l'unica fonte con cui l'app decide
  dove mostrare il passaporto: l'app non legge nessuno dei due flag. Il conteggio delle tappe resta
  quello di [8676-passaporto-camminatore-tappe-percorse-e-validate-dellutente.md](8676-passaporto-camminatore-tappe-percorse-e-validate-dellutente.md).
- **Il calcolo da GPS** (servizio, job dopo una UGC track, command, ricalcolo per cammino) passa da
  `StageValidationService::coverage()`, che considera solo i cammini col GPS acceso: con il solo
  cartaceo nessuna tappa si sblocca da sola.
- **Accendere il GPS** accoda `RecomputePassportStagesForLayerJob`; **spegnerlo** accoda
  `RevokeGpsPassportStagesForLayerJob`, che cancella le righe `source = gps` delle tappe del cammino
  (`layerables`, non `passport_stages.layer_id`) che non stanno in nessun cammino col GPS acceso nel
  momento in cui il job gira. Le righe `manual` restano.
- **Spegnere il cartaceo** (possibile solo col GPS spento) non cancella nulla: tappe convalidate,
  richieste e condivisioni restano; le richieste in attesa restano decidibili in Nova (che non passa
  dal middleware) e la mail di esito parte, ma l'app non mostra il cammino finché è spento.
- **Nova**: i due campi sono su `App\Nova\Layer`, in sola lettura a livello di campo per chi non è
  `Administrator` (`LayerPolicy::update` lascia modificare il layer anche a un proprietario non
  `Validator`). La modifica in blocco è `BulkEditPassportAction`, solo per `Administrator`: estende la
  `BulkEditAction` di wm-package (ne eredita i campi a tre scelte), salva con `saveQuietly()` e
  chiama di persona i passi di `PassportLayerObserver` (`saving()` e `dispatchForFlagChange()`). Un
  observer di layer che debba reagire ai flag non scatta dalla modifica in blocco.

## Perché così

- **Flag per cammino, spento di default** (oc:8763, call del 12/10/2026): Cammini d'Italia prova il
  passaporto con pochi partner fidati prima di estenderlo a tutti.
- **Flag in `properties`, nessuna colonna** (oc:8763): nessuna migration; il valore finisce anche nella
  config dell'app, dove però non va usato (la modifica in blocco non la rigenera).
- **Due flag, cartaceo e GPS** (oc:8809, scrum del 22/10/2026): il cliente vuole far lavorare gli
  enti gestori sulle richieste cartacee senza lo sblocco automatico, che intende brevettare. Il flag
  GPS è visibile agli Administrator e spento di default: quando accenderlo lo decide il cliente.
- **La chiave del cartaceo resta `passport_enabled`** (oc:8809): cambia solo l'etichetta in Nova;
  nessun dato da spostare.
- **Spegnere il GPS revoca le tappe sbloccate dal GPS** (oc:8809): il cliente non vuole il GPS attivo
  su quel cammino, quindi le validazioni automatiche non devono restare visibili. Riaccendendolo si
  ricreano dalle UGC track, con la data della riaccensione. Le tappe condivise con un cammino col GPS
  acceso restano, perché lì sono ancora valide.
- **La revoca rilegge i flag quando gira, compreso il cammino stesso** (oc:8809): con 2 worker sulla
  coda `ugc-track-matching`, uno spegni-riaccendi rapido poteva far girare la revoca dopo il
  ricalcolo e cancellare le tappe appena ricreate.
- **Modifica in blocco con salvataggio silenzioso** (oc:8809): un `save()` per cammino fa scattare
  `LayerObserver::saved`, che accoda un `RecalculateLayerAttributesJob` per ogni cammino; il flag del
  passaporto non cambia nessun attributo.
- **404 su tutto il gruppo, un solo middleware** (oc:8763): nessun endpoint del cammino può essere
  dimenticato; 404 non rivela nulla in più di un cammino che, per l'utente, non esiste.
- **`/api/passport` come elenco dei cammini accesi** (oc:8763): una sola risposta basta per tab,
  badge, anelli e chip; prima l'app chiamava `/progress` per ogni card.
- **Solo il booleano `true` acceso** (oc:8763): la prima versione SQL (`->>`) considerava accesa anche
  la stringa `"true"`, che PHP considerava spenta; `/api/passport` avrebbe mostrato cammini che poi
  rispondono 404.
- **Nessun blocco allo spegnimento con richieste in attesa** (oc:8763): operazione rara; l'avviso è
  nella guida.

## Come ci siamo arrivati

- **Un solo flag, `passport_enabled`, per API e calcolo GPS** (oc:8763 e oc:8165, superato da
  oc:8809): accendere il passaporto per far lavorare gli enti sul cartaceo accendeva anche lo sblocco
  automatico.
- **Rinominare la chiave in `passport_paper_enabled`** (oc:8809, abbandonato dopo l'implementazione):
  l'app non legge la chiave, ma il rename spostava dati senza beneficio; il dev ha preferito cambiare
  solo l'etichetta.
- **Migration che cancella le righe `gps` al rilascio** (oc:8809, tolta su decisione del dev): in
  produzione il 22/10/2026 erano 0; se al rilascio ce ne sono si cancellano a mano.
- **Modifica in blocco con `BulkEditAction` del package** (oc:8763, superata da oc:8809): il suo
  `saveQuietly()` non applicava il vincolo GPS ⇒ cartaceo né faceva partire ricalcolo e revoca.
- **Passaporto sempre attivo su tutti i cammini** (oc:8653–oc:8703, superato da oc:8763): il cliente
  ha chiesto di poterlo accendere cammino per cammino.
- **Elenco separato `enabled_layer_ids`** (oc:8763, scartato in fase di dialogo): avrebbe affiancato
  `routes` senza togliere le chiamate a `/progress` per card.

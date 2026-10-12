# Passaporto attivo per singolo cammino

## Come funziona oggi

Il passaporto del camminatore vale solo sui cammini (layer) con `properties->passport_enabled` uguale
al booleano JSON `true`. Assente, `false`, la stringa `"true"` o `1` valgono spento. Al rilascio
tutti i cammini sono spenti: li accendono gli amministratori. Guida per il cliente:
[docs/guide/passaporto-attivazione/](../guide/passaporto-attivazione/README.md).

- **Una regola sola, in due forme**: `App\Support\PassportLayer::isEnabled()` (PHP) e
  `PassportLayer::enabledSql()` (SQL su jsonb, `-> … = 'true'::jsonb`). Un test con data provider
  verifica che diano lo stesso esito sugli stessi valori: se si cambia l'una, si cambia l'altra.
- **Gli endpoint del cammino**: il middleware `EnsurePassportEnabled` sta sull'intero gruppo
  `Route::prefix('layer')` di `routes/api.php` e risponde 404 se il layer non esiste o è spento
  (certificazione POST e GET, progress, condivisione della tappa e del cammino). Ogni rotta aggiunta
  a quel gruppo eredita il controllo.
- **`GET /api/passport`**: `routes` elenca tutti e soli i cammini accesi con almeno una tappa
  associata, anche a 0 tappe convalidate, ciascuno con `validated_tracks`, mappa
  `{"<ec_track_id>": "<data ISO>"}` (`{}` se vuota, mai `[]`). È l'unica fonte con cui l'app decide
  dove mostrare il passaporto. Il conteggio delle tappe resta quello di
  [8676-passaporto-camminatore-tappe-percorse-e-validate-dellutente.md](8676-passaporto-camminatore-tappe-percorse-e-validate-dellutente.md).
- **Nova**: campo «Passaporto attivo» su `App\Nova\Layer`, in sola lettura a livello di campo per
  chi non è `Administrator` (`LayerPolicy::update` lascia modificare il layer anche a un proprietario
  non `Validator`); `BulkEditAction` sullo stesso campo, solo per `Administrator`. La modifica in
  blocco salva con `saveQuietly()`: la config dell'app non si rigenera, e l'app non legge il flag da
  lì.
- **Spegnere non cancella nulla**: tappe convalidate, richieste e condivisioni restano; le richieste
  in attesa restano decidibili in Nova (che non passa dal middleware) e la mail di esito parte, ma
  l'app non mostra il cammino finché è spento.

## Perché così

- **Flag per cammino, spento di default** (oc:8763, call del 12/10/2026): Cammini d'Italia prova il
  passaporto con pochi partner fidati prima di estenderlo a tutti.
- **Flag in `properties`, nessuna colonna** (oc:8763): nessuna migration; il valore finisce anche nella
  config dell'app, dove però non va usato (la modifica in blocco non la rigenera).
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

- **Passaporto sempre attivo su tutti i cammini** (oc:8653–oc:8703, superato da oc:8763): il cliente
  ha chiesto di poterlo accendere cammino per cammino.
- **Elenco separato `enabled_layer_ids`** (oc:8763, scartato in fase di dialogo): avrebbe affiancato
  `routes` senza togliere le chiamate a `/progress` per card.

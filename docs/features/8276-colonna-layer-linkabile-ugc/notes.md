> Ticket: oc:8276

# Notes — Colonna layer linkabile e filtro layer su UgcPoi/UgcTrack

## Deviazioni dal piano

- **Namespace `User` nei test Nova**: il piano/brief indicava `Wm\WmPackage\Models\User`, ma il gate `viewNova` in `NovaServiceProvider.php` è tipizzato su `App\Models\User` — usare il namespace del package causa un `TypeError` a runtime. Tutti i test HTTP verso Nova (`UgcPoiLayerFieldTest`, `UgcTrackLayerFieldTest`) usano `App\Models\User`.
- **Formato query del filtro Nova nei test**: il piano proponeva `?layer_filter=<valore>`, ma Nova si aspetta il parametro `filters` con un JSON base64-encoded nella forma `[{"Select:layer_filter": <valore>}]` (chiave = `class_basename($field).':'.$field->attribute`, verificato in `vendor/laravel/nova/src/Fields/Filters/Filter.php::key()`). Corretto in entrambi i file di test del filtro.
- **Payload XSS del test del trait**: il test di escaping HTML nel Layer factory doveva impostare il payload `<script>` in tutte le chiavi lingua (`it` e `en`), non solo `it` come nel brief originale — `Layer::getStringName()` seleziona la traduzione in base a `APP_LOCALE` (`en` in questo progetto), quindi con solo `it` sporcato il test sarebbe stato un falso positivo (verde senza esercitare l'escaping).

Nessuna di queste deviazioni ha richiesto modifiche alla logica applicativa: erano solo dettagli di formulazione delle richieste HTTP/dati nei test.

## Bug trovati

Nessuno introdotto da questa feature. Un rischio preesistente (oc:7640) è stato individuato ma non corretto in questo ciclo — vedi sotto.

## Decisioni

- Logica di risoluzione layer + filtro Select estratta in `App\Nova\Traits\HasLayerFilterAndLink`, condivisa da `UgcPoi` e `UgcTrack` (decisione presa in Fase: challenge, per evitare la duplicazione del blocco Select che oc:7640 aveva introdotto solo su UgcPoi).
- Validazione difensiva (`is_numeric()`), cache statica in-request, distinzione "Non assegnato"/"Layer eliminato", `rel="noopener noreferrer"` e `htmlspecialchars()` sono tutte mitigazioni decise nella Fase: challenge di questo stesso ciclo (vedi overview.md → sezione Rischi) e non erano nel primo abbozzo dell'overview.
- Il filtro Select preesistente su UgcPoi (oc:7640) usa un cast SQL `::integer` senza validazione — rischio di 500 su dato corrotto, esplicitamente lasciato fuori scope. La review finale whole-branch (Opus) ha confermato che questo rischio ora si estende anche a UgcTrack tramite il trait condiviso, essendo lo stesso pattern di query riusato. Accettato come rischio preesistente non peggiorato nella sostanza (stesso codice, ora applicato a un secondo modello), da tracciare per un ciclo futuro.

## Follow-up

- Valutare in un ciclo futuro se applicare la stessa validazione PHP-side (`is_numeric()`) anche alla query `options()`/`filterable()` del filtro Select, per chiudere definitivamente il rischio di 500 su dato corrotto (oggi mitigato solo sul field di visualizzazione, non sul filtro).
- I placeholder `'Non assegnato'` e `'Layer eliminato (ID: %d)'` sono stringhe hardcoded, non passate per `__()` come le altre label dei field — incoerenza i18n minore, segnalata dalla review finale.
- Nessun test verifica esplicitamente la visibilità del field sul detail view Nova (solo sull'index) — rischio basso dato che il field non ha logica differenziata tra le due viste, ma andrebbe aggiunto se in futuro si introducesse tale differenziazione.

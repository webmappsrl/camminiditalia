> Ticket: oc:8276

# Colonna layer linkabile e filtro layer su UgcPoi/UgcTrack per Administrator

## Cosa cambia

Su `App\Nova\UgcPoi` e `App\Nova\UgcTrack`, per gli utenti con ruolo Administrator:

- Aggiunto un field (index + detail) che risolve `properties->layer_id` nel nome del cammino (`Layer::getStringName()`) e lo mostra come link cliccabile verso `/nova/resources/layers/{id}`, aperto in una nuova scheda (`target="_blank"`).
- Quando `layer_id` è assente o null, il field mostra il placeholder "Non assegnato" invece di un valore vuoto.
- Il filtro Select searchable/filterable per layer (già esistente su `UgcPoi` da oc:7640, nascosto in index/detail) viene replicato identico su `UgcTrack`.

## Perché

Gli Administrator gestiscono segnalazioni (POI e tracce UGC) di più cammini contemporaneamente. Attualmente il `layer_id` è leggibile solo nel JSON `properties`, non è visibile in Nova né linkabile: per risalire al cammino serve ispezionare i dati grezzi. Questa feature espone l'informazione già presente e riusa il pattern di filtro già validato su UgcPoi (oc:7640), estendendolo a UgcTrack.

## Requisiti

- [ ] Field "layer" visibile in index e detail di `App\Nova\UgcPoi`, solo per Administrator
- [ ] Field "layer" visibile in index e detail di `App\Nova\UgcTrack`, solo per Administrator
- [ ] Il field mostra il nome del layer (`Layer::getStringName()`) come testo, senza prefisso
- [ ] Il field è un link HTML verso `/nova/resources/layers/{id}`, con `target="_blank"`
- [ ] Se `properties->layer_id` è assente/null, il field mostra "Non assegnato"
- [ ] Filtro Select searchable/filterable per layer aggiunto a `App\Nova\UgcTrack`, identico nel comportamento a quello esistente su `UgcPoi` (oc:7640): visibile solo per Administrator, nascosto in index/detail, opzioni derivate dai layer distinti presenti in `properties->layer_id` sulle UgcTrack esistenti
- [ ] Test Feature per il nuovo field (risoluzione nome, link, placeholder) su UgcPoi e UgcTrack
- [ ] Test Feature per il nuovo filtro su UgcTrack (visibilità Administrator-only, comportamento filtro)
- [ ] Validazione difensiva: `layer_id` letto da `properties` va validato in PHP (`is_numeric()`) prima di essere usato per risolvere il layer — un valore non valido produce "Non assegnato", mai un errore SQL/500
- [ ] Cache in-request (array statico `id => nome`) per evitare query duplicate su `Layer::find()` quando più righe della stessa pagina index condividono lo stesso `layer_id`
- [ ] Link con `rel="noopener noreferrer"` oltre a `target="_blank"` (mitigazione tabnabbing)
- [ ] Nome del layer escapato (`htmlspecialchars()`) prima di essere iniettato nell'HTML del field (mitigazione XSS stored)
- [ ] Distinzione tra "Non assegnato" (layer_id assente/non valido) e "Layer eliminato (ID: {id})" (layer_id valido ma `Layer::find()` non trova il record) — nel secondo caso nessun link
- [ ] Logica di risoluzione nome layer + filtro Select estratta in un trait condiviso, usato sia da `App\Nova\UgcPoi` sia da `App\Nova\UgcTrack` (no duplicazione del blocco Select)

## Rischi

- **Query raw fragile su dato corrotto** (preesistente su UgcPoi, oc:7640): il filtro Select esistente usa `(properties->>'layer_id')::integer` senza validazione — un valore non castabile causerebbe un 500 sull'intera index. Non modificato in questo ciclo (fuori scope, rischio preesistente in produzione), ma il nuovo field introdotto qui adotta invece validazione PHP-side per non ripetere il problema. Da valutare in un ciclo futuro se applicare la stessa validazione anche al filtro esistente.
- **N+1 query sull'index**: mitigato con cache in-request; non elimina completamente il costo su index con molti layer distinti per pagina, ma lo riduce al caso comune.
- **Tabnabbing/XSS**: mitigati con `rel="noopener noreferrer"` e `htmlspecialchars()` sul nome layer.
- **Layer cancellato**: gestito con placeholder distinto, nessun link verso risorsa inesistente.
- **Multi-tenancy/cross-app**: se in futuro camminiditalia condividesse Layer con altre App Webmapp, il link potrebbe puntare a un Layer fuori contesto — non applicabile oggi (progetto mono-app), accettato come rischio non mitigato in questo ciclo.
- **Test legati a Postgres**: le query raw usate (esistenti e nuove) sono specifiche del dialetto Postgres; i test Feature richiedono il DB Postgres reale configurato nel progetto (coerente con l'assetto già in uso, non introduce un problema nuovo).

## Out of scope

- Non si aggiunge alcuna relazione Eloquent/FK tra UgcPoi/UgcTrack e Layer: si continua a leggere `layer_id` da `properties` JSON, coerente con la scelta architetturale del progetto.
- Il filtro non viene esposto ai Validator (hanno un solo layer associato, il filtro sarebbe ridondante).
- Nessuna modifica al wm-package: la feature è interamente locale al repo principale.

## Moduli toccati

- `app/Nova/UgcPoi.php` — aggiunta field layer linkabile in `fields()`
- `app/Nova/UgcTrack.php` — aggiunto `fields()` con field layer linkabile + filtro Select layer
- `tests/Feature/UgcPoiLayerFieldTest.php` (nuovo)
- `tests/Feature/UgcTrackLayerFieldTest.php` (nuovo)

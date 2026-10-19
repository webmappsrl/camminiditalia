# Risoluzione e correzione del layer per UGC

## Come funziona oggi

**UgcTrack: nessun `layer_id`** (oc:8773). Una UGC track attraversa più cammini: le tappe e i
cammini attraversati si ricavano dalla corrispondenza per segmenti calcolata da wm-package (vedi
[wm-package/docs/knowledge/8773-associazione-delle-ugctrack-a-piu-cammini-tappe-invece-che-a-un-solo-layer.md](../../wm-package/docs/knowledge/8773-associazione-delle-ugctrack-a-piu-cammini-tappe-invece-che-a-un-solo-layer.md)).
In camminiditalia: `App\Nova\UgcTrack` eredita dal package il campo "Cammini attraversati dalla
traccia" e il filtro per cammino; `App\Observers\UgcObserver`, dopo `parent::created()`, non esegue per le UgcTrack la parte `layer_id` (mail e `ResolveUgcLayerJob`);
`PopulateUgcLayerIdCommand` lavora solo sugli UgcPoi. Le etichette "cammino/tappa" sono override
di `resources/lang/*.json` sulle chiavi del package. Parametri 400 m / 50 m: i default del package, gli stessi dell'algoritmo del passaporto
(oc:8165, call del 03/09/2026).

**UgcPoi: un solo `layer_id`** in `properties->layer_id` (JSON, no FK) — vedi CLAUDE.md, sezione
"UGC e form".

**Risoluzione automatica (UgcPoi report).** Alla creazione di un UgcPoi con
`properties->form->id === 'report'` e senza `layer_id` esplicito, `App\Observers\UgcObserver::created()`
dispatcha `App\Jobs\ResolveUgcLayerJob`, che chiama `UgcService::resolveLayerByProximity()`
(EcTrack più vicina entro `UGC_LAYER_SEARCH_DISTANCE_METERS`, poi il layer di quella traccia con
centroide più vicino). Il job è idempotente e marca il risultato con
`properties->layer_id_auto_resolved = true`. Prima di inviare la mail controlla anche quel flag,
altrimenti notificherebbe il gestore di un layer assegnato a mano.

**Correzione manuale (oc:8575).** Solo l'Administrator può correggere il layer di un UgcPoi da
Nova, tramite un campo `Select` editabile visibile solo in Update (`app/Nova/Traits/HasLayerOverride.php`),
che coesiste con il link readonly (`app/Nova/Traits/HasLayerFilterAndLink.php`, visibile solo in
Detail). Il salvataggio manuale marca `properties->layer_id_auto_resolved = false`, non invia mail,
non usa `saveQuietly()`. La logica di mutazione condivisa vive in
`App\Support\UgcLayerAssignment::apply()`, riusata dal job e dall'override manuale.

**Guardia anti race-condition, bidirezionale.**
- *Job sovrascrive correzione manuale*: prima del salvataggio finale, `ResolveUgcLayerJob`
  ricontrolla dal DB se `layer_id_auto_resolved` è già `false` — se sì, abortisce (anche se la
  correzione è avvenuta prima che il job partisse).
- *Salvataggio non correlato sovrascrive risoluzione automatica*: il campo Nova editabile confronta
  il valore inviato con un campo nascosto companion (`layerOverrideOriginalField`) che riporta il
  valore mostrato al render, non con lo stato attuale del DB.

## Perché così

- **UgcTrack senza `layer_id`, cammini ricavati dalla corrispondenza per segmenti** (oc:8773): una
  traccia lunga attraversa più tappe e più cammini; il valore singolo era incompleto e sulle tracce
  lunghe sbagliato (oc:8466). Lo stesso dato serve al passaporto automatico (oc:8165).
- **`App\Policies\UgcTrackPolicy` esplicita, con `update` Administrator-only** (oc:8575):
  `Wm\WmPackage\Policies\UgcTrackPolicy` era già attiva su `UgcTrack` tramite l'algoritmo di default
  di `Gate::guessPolicyName()` — **non serve `Gate::guessPolicyNamesUsing()` custom** (verificato in
  `vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:717-737`). La classe locale toglie
  solo il Validator dal bypass sull'ability `update`. Effetto collaterale noto: il Validator ha ancora
  `view`/`delete` su ogni UgcTrack via URL diretto (la lista è vuota per `indexQuery()`), fuori scope
  di oc:8773.
- **Registrare una `Gate::policy()` per un modello che non l'aveva prima cambia tutte le ability**:
  in `vendor/laravel/nova/src/Authorizable.php` solo `authorizedToViewAny()` ha la guardia
  `is_callable`; `authorizedToCreate()` e `authorizedTo()` no — un metodo mancante nega a tutti,
  Administrator incluso.
- **`fillUsing()` su un field Nova gira su ogni salvataggio Update** (`vendor/laravel/nova/src/Fields/Field.php:395-402`):
  un `fillUsing` che scrive dati derivati deve replicare a mano il controllo `$request->exists(...)`.
- **Label Nova hardcoded in italiano nei trait** (oc:8575, pattern di `renderLayerLink()` di oc:8276):
  osservato "Layer" invece di "Cammino" su alcuni record in produzione; root cause non investigata.
  Non dipende dal bug di precedenza delle traduzioni corretto in oc:8773 ("Layer" non è una chiave del
  JSON del package).

## Come ci siamo arrivati

- **`layer_id` singolo anche sulle UgcTrack, con link, filtro e correzione manuale in Nova**
  (oc:8079, oc:8276, oc:8575, superato da oc:8773 per le sole UgcTrack): il campo di correzione e il
  link sono rimasti sugli UgcPoi; sulle UgcTrack li sostituiscono il campo e il filtro del package.
- **Il ramo di idempotenza del job basato solo su "`layer_id` è vuoto?"** — superato in parte da
  oc:8575: ora controlla anche `layer_id_auto_resolved` prima di inviare la mail.
- **`HasLayerOverride` è un trait nuovo di oc:8575**, separato da subito da `HasLayerFilterAndLink`
  (che resta solo presentazione).

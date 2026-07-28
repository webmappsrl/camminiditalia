> Ticket: oc:8304

# Gestione autonoma POI da parte dei gestori di cammino

## Cosa cambia

Oggi i Validator (gestori di cammino) sono in sola lettura su EcPoi — decisione presa esplicitamente in oc:8120. Questa feature abilita i Validator a **creare e modificare** i propri EcPoi (il **delete resta esclusivo Administrator**, vedi Rischi):

- In Nova, l'index EcPoi per un Validator mostra solo i POI con `user_id === $user->id`. Non serve un filtro basato su `$user->layers()` (join su `layerables`): per la proprietà transitiva già in vigore (`LayerObserver::saved` sincronizza `user_id` di tutti gli EcPoi con l'owner del layer al cambio owner), "POI dei propri layer" e "POI con `user_id` proprio" sono lo stesso insieme nella pratica di camminiditalia (dove i gestori di cammino non condividono POI tra loro) — filtrare per `user_id` è equivalente, più semplice e senza join.
- Un Validator con **zero layer** non può creare EcPoi (`authorizedToCreate` → `false` in quel caso).
- Un Validator con almeno un layer può creare un nuovo EcPoi. In creazione, il POI deve essere associato a un layer:
  - se possiede **un solo layer**, il campo è precompilato/nascosto automaticamente (nessuna selezione richiesta);
  - se ne possiede **più di uno**, viene mostrato un Select limitato ai propri layer (opzioni costruite da `$request->user()->layers()`).
- Update su un EcPoi esistente è autorizzato se `EcPoi.user_id === Validator.id`. Delete resta autorizzato solo per Administrator.

## Perché

Più gestori di cammino hanno richiesto di poter gestire autonomamente i propri POI senza passare per un Administrator. La funzione è attualmente disabilitata by design (oc:8120): questa feature la re-abilita, ma con scoping per layer invece di sbloccarla globalmente.

## Requisiti

- [ ] `EcPoiPolicy::create` → `true` per Validator se ha almeno un layer, altrimenti `false` (era sempre `false`)
- [ ] `EcPoiPolicy::update` → `true` per Validator se `$ecPoi->user_id === $user->id`, altrimenti `false`
- [ ] `EcPoiPolicy::delete` → resta `false` per Validator, invariato (delete esclusivo Administrator)
- [ ] `EcPoiPolicy::viewAny`/`view` restano `true` (nessuna modifica: la visibilità in Nova va scoperta via filtro query, non via policy `view`, coerente col pattern UgcPoi)
- [ ] `App\Nova\EcPoi::authorizedToCreate` → `true` per Administrator, oppure Validator con almeno un layer (era solo Administrator)
- [ ] `App\Nova\EcPoi::authorizedToUpdate` → `true` per Administrator, oppure Validator se `$this->resource->user_id === $request->user()->id`
- [ ] `App\Nova\EcPoi::authorizedToDelete` → invariato, solo Administrator
- [ ] Index EcPoi in Nova filtrato per Validator: solo POI con `user_id === $user->id` (nuovo metodo, stesso pattern strutturale di `UgcPoi::filteredQueryForValidator` ma su `user_id`, non su join layer — coerente con il criterio update)
- [ ] Campo layer in creazione EcPoi:
  - Validator con 1 layer → campo nascosto, valore precompilato con l'unico layer
  - Validator con N layer → `Select` searchable limitato ai propri layer (opzioni da `$request->user()->layers()`), obbligatorio
  - Administrator → comportamento invariato (nessun vincolo, o campo opzionale — da confermare in write-plan se già esiste un meccanismo equivalente lato Admin)
- [ ] Validazione server-side sul campo layer in creazione: per un Validator, `Rule::in($request->user()->layers()->pluck('id'))` — chiude il gap per cui `POST nova-api/ec-pois` (route generica Nova) accetterebbe altrimenti un `layer_id` arbitrario, dato che `Select::fillAttributeFromRequest` non verifica il valore contro le opzioni dichiarate (verificato in `vendor/laravel/nova/src/Fields/Field.php`)
- [ ] Alla creazione, il nuovo EcPoi viene associato al layer scelto tramite pivot `layerables` (stesso meccanismo usato da `LayerFeatures` sul lato Layer)
- [ ] Le Nova Action attualmente riservate ad Administrator (`ExecuteEcPoiDataChainAction`, `UploadPoiFile`, `TranslateModelAction`, `BulkEditAction`) restano riservate ad Administrator — **fuori scope**, non richieste dal ticket
- [ ] `DownloadEcPoiAction` resta disponibile senza modifiche (nessun controllo esplicito, già filtrato dal default Nova su `authorizedToUpdate`/`view` a seconda dell'action)

## Rischi

- **POI condivisi tra layer con owner diversi** (documentato in oc:8139: ownership last-write-wins): per POI condivisi, `user_id` non rappresenta fedelmente "appartiene ai miei layer" — un Validator potrebbe non poter modificare un POI comunque presente nel suo layer, o viceversa. **Rischio accettato**: su camminiditalia i gestori di cammino non hanno necessità operativa di condividere POI tra loro; l'Administrator resta comunque con visibilità e controllo completo su tutti i POI come fallback.
- **Delete escluso dallo scope Validator** proprio per mitigare il rischio più grave individuato in challenge: `EcPoi` non ha `SoftDeletes` (verificato nel modello), quindi una delete errata o su un POI condiviso sarebbe definitiva e recuperabile solo da backup DB. Limitando il Validator a create/update, questo rischio non si applica.
- **Coupling implicito non testato** tra questa policy e gli observer che sincronizzano `user_id` (`LayerObserver`, oc:8139/oc:8080): se in futuro quella logica cambia, l'equivalenza "user_id proprio = POI nei propri layer" può rompersi silenziosamente. Rischio noto, non mitigato in questo ciclo — nessun test cross-modulo previsto.
- **Layer scelto in creazione non validato lato server oltre le opzioni del Select**: `POST nova-api/{resource}` è una route Nova generica esistente per ogni resource (verificato con `route:list`), quindi chiunque abbia una sessione Validator autenticata può invocarla direttamente con un `layer_id` arbitrario, bypassando il form. **Mitigato**: aggiunta `Rule::in()` esplicita sul salvataggio (vedi Requisiti) — costo minimo, chiude un gap di autorizzazione reale.

## Out of scope

- Le Nova Action bulk/import (`ExecuteEcPoiDataChainAction`, `UploadPoiFile`, `TranslateModelAction`, `BulkEditAction`) restano esclusive Administrator
- Nessuna modifica al meccanismo di associazione automatica EcPoi↔Layer via taxonomy/traccia (oc:8139, oc:8140) — questa feature riguarda solo la creazione/modifica manuale da parte del Validator
- Nessuna modifica al flusso di trasferimento ownership al cambio owner del layer (oc:8080) — resta invariato e continua a garantire la proprietà transitiva `user_id === layer owner`

## Moduli toccati

- `app/Policies/EcPoiPolicy.php` — create/update/delete abilitati per Validator con scoping su `user_id`
- `app/Nova/EcPoi.php` — `authorizedToCreate/Update/Delete`, filtro index per Validator (`user_id`), campo layer in creazione

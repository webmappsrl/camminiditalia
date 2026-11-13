# Scoping Validator sulle risorse Nova EcTrack/EcPoi/Layer

## Come funziona oggi

Il progetto scopa le liste EcTrack/EcPoi per un Validator tramite override locali di `indexQuery()` su `App\Nova\EcTrack`/`App\Nova\EcPoi` (filtro per `user_id`), introdotti da oc:8587 per sostituire lo scoping per `app_id` del package (`Wm\WmPackage\Nova\AbstractEcResource::indexQuery()`, sempre vuoto per un Validator — nessun Validator possiede mai un'App in camminiditalia).

Questo override funziona **solo quando Nova risolve effettivamente la classe locale**. Ogni punto del codice — proprio o del package — che referenzia `EcTrack::class`/`EcPoi::class`/`Layer::class` **senza un `use` esplicito** bypassa silenziosamente l'override: per risoluzione di namespace PHP, il riferimento risolve alla classe Nova del package (stesso namespace del file che la usa), non alla sottoclasse locale. Questo include i campi `BelongsToMany`/`MorphToMany` definiti **dentro** `wm-package/src/Nova/EcPoi.php`/`EcTrack.php`, non solo le risorse di menu.

Copertura nota ad oggi:

- Risorse di menu "Ec Tracks"/"Ec Poi" (fix oc:8587)
- Campo di attach "EcTracks" su EcPoi e "EcPois" su EcTrack (fix oc:8611) — stesso bug, ma sul campo `fields()` invece che sulla index list della risorsa
- Campo "Layers" su EcTrack: stesso bug; nascosto al Validator (oc:8611) e ripuntato alla Resource locale (oc:8974), che serve all'Administrator per non caricare le Zone a ogni voce dell'attach

**Come una tappa del Validator entra nel suo cammino** (oc:8976). Il Validator non associa mai una tappa a un layer a mano. Alla creazione in Nova la tappa entra da sola nell'unico layer di cui è owner (`App\Nova\EcTrack::afterCreate()`), con un attach dalla relazione `layers()` che fa scattare gli stessi observer di un'associazione dell'Administrator. Se è owner di nessun layer o di più di uno, non succede nulla, e il Field «Variante di» lo avvisa di chiedere all'amministratore. La policy chiude la stessa strada sull'API Nova: attach solo sul proprio layer, detach mai.

Non coperto/verificato (rischio residuo, non affrontato):

- Campi verso EcTrack/EcPoi sulle risorse Taxonomy (`TaxonomyTheme`, `TaxonomyActivity`) — nessuna policy locale le blocca esplicitamente, non verificato se il Validator vi ha effettivamente accesso in Nova
- Qualsiasi altro punto del package che referenzia `EcTrack::class`/`EcPoi::class`/`Layer::class` senza `use` esplicito, non ancora scovato

## Perché così

- **Fix sempre lato consumer, mai nel package** (oc:8587, oc:8611): coerente con la regola "logica generica → wm-package, logica specifica → repo principale". La causa reale (classi Nova del package referenziate senza `use` esplicito) resta nel package: ogni nuovo campo/risorsa che il package aggiunge con lo stesso pattern può reintrodurre il bug, e un fix upstream lo risolverebbe una volta per tutte — ma tocca un consumer condiviso con altri progetti Webmapp, fuori scope per un ciclo dedicato a camminiditalia (un repo per ciclo).
- **"Layers" su EcTrack nascosto al Validator** (oc:8611, confermato da oc:8976): un Validator non deve poter attaccare o staccare un layer da questo campo. In camminiditalia un Validator gestisce un solo cammino (regola di prodotto), quindi non c'è nulla da scegliere: la tappa nuova entra nel suo layer in automatico.
- **Associazione in `afterCreate()` di Nova, non in un observer su `EcTrack::created`** (oc:8976): agisce solo sulla form di Nova, non su import, job e factory, dove l'utente autenticato può mancare o non essere chi ha creato la tappa.

## Come ci siamo arrivati

- **Fix upstream in wm-package, valutato e scartato due volte** (oc:8587, oc:8611): risolverebbe la causa alla radice per tutti i campi in un colpo solo, ma il package è condiviso con altri consumer Webmapp (Cyclando, Forestas, ecc.) — decisione esplicita di limitare lo scope a camminiditalia in entrambi i cicli.
- **«L'associazione passa sempre da `LayerFeatureController`»** (oc:8611, superata da oc:8976): per il Validator non era vero, perché `LayerPolicy::update()` gli nega la form del Layer (OC:6706), l'unico punto in cui `LayerFeatures` permette di aggiungere tappe. Svuotato (oc:8162 nel package) e poi nascosto (oc:8611) il Field "Layers", le tappe che creava restavano fuori dal cammino; il sintomo è emerso con «Variante di» vuoto.

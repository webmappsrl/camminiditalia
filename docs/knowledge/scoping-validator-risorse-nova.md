# Scoping Validator sulle risorse Nova EcTrack/EcPoi/Layer

## Come funziona oggi

Il progetto scopa le liste EcTrack/EcPoi per un Validator tramite override locali di `indexQuery()` su `App\Nova\EcTrack`/`App\Nova\EcPoi` (filtro per `user_id`), introdotti da oc:8587 per sostituire lo scoping per `app_id` del package (`Wm\WmPackage\Nova\AbstractEcResource::indexQuery()`, sempre vuoto per un Validator — nessun Validator possiede mai un'App in camminiditalia).

Questo override funziona **solo quando Nova risolve effettivamente la classe locale**. Ogni punto del codice — proprio o del package — che referenzia `EcTrack::class`/`EcPoi::class`/`Layer::class` **senza un `use` esplicito** bypassa silenziosamente l'override: per risoluzione di namespace PHP, il riferimento risolve alla classe Nova del package (stesso namespace del file che la usa), non alla sottoclasse locale. Questo include i campi `BelongsToMany`/`MorphToMany` definiti **dentro** `wm-package/src/Nova/EcPoi.php`/`EcTrack.php`, non solo le risorse di menu.

Copertura nota ad oggi:

- Risorse di menu "Ec Tracks"/"Ec Poi" (fix oc:8587)
- Campo di attach "EcTracks" su EcPoi e "EcPois" su EcTrack (fix oc:8611) — stesso bug, ma sul campo `fields()` invece che sulla index list della risorsa
- Campo "Layers" su EcTrack: stesso bug, risolto **nascondendo** il campo per il Validator invece di ripuntarlo (vedi sotto)

Non coperto/verificato (rischio residuo, non affrontato):

- Campi verso EcTrack/EcPoi sulle risorse Taxonomy (`TaxonomyTheme`, `TaxonomyActivity`) — nessuna policy locale le blocca esplicitamente, non verificato se il Validator vi ha effettivamente accesso in Nova
- Qualsiasi altro punto del package che referenzia `EcTrack::class`/`EcPoi::class`/`Layer::class` senza `use` esplicito, non ancora scovato

## Perché così

- **Fix sempre lato consumer, mai nel package** (oc:8587, oc:8611): coerente con la regola "logica generica → wm-package, logica specifica → repo principale". La causa reale (classi Nova del package referenziate senza `use` esplicito) resta nel package: ogni nuovo campo/risorsa che il package aggiunge con lo stesso pattern può reintrodurre il bug, e un fix upstream lo risolverebbe una volta per tutte — ma tocca un consumer condiviso con altri progetti Webmapp, fuori scope per un ciclo dedicato a camminiditalia (un repo per ciclo).
- **"Layers" su EcTrack nascosto, non ripuntato** (oc:8611): a differenza di `ecTracks`/`ecPois`, qui non basta correggere lo scoping — un Validator non deve mai poter attaccare/staccare un layer direttamente da questo campo, indipendentemente da chi lo possiede. L'associazione layer↔traccia passa sempre da `LayerFeatureController`. Nascondere il campo (`canSee`) è la scelta corretta anche restando puntati alla classe Nova del package.

## Come ci siamo arrivati

- **Fix upstream in wm-package, valutato e scartato due volte** (oc:8587, oc:8611): risolverebbe la causa alla radice per tutti i campi in un colpo solo, ma il package è condiviso con altri consumer Webmapp (Cyclando, Forestas, ecc.) — decisione esplicita di limitare lo scope a camminiditalia in entrambi i cicli.

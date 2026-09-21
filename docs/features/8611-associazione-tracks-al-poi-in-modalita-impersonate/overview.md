> Ticket: oc:8611

# Associazione TRACKS al POI in modalità impersonate

## Cosa cambia

Il campo Nova che permette di associare una EcTrack a un EcPoi (e il campo simmetrico che permette di associare un EcPoi a una EcTrack) smette di risultare sempre vuoto per gli utenti con ruolo Validator, anche quando operano in modalità impersonate da un Administrator. Le risorse locali `App\Nova\EcPoi` e `App\Nova\EcTrack` sovrascrivono `fields()` per sostituire il campo `BelongsToMany` ereditato dal package — che punta implicitamente alla classe Nova del package priva dell'override `indexQuery()` per user_id — con una versione equivalente che referenzia la sottoclasse locale corretta.

In più, il campo `MorphToMany('Layers', 'layers', Layer::class)` presente sulla scheda EcTrack (stesso pattern di riferimento non qualificato, stesso bug) viene invece **nascosto per il Validator**: un gestore di cammino non deve poter fare attach/detach diretto del layer da qui — l'associazione layer↔traccia passa sempre dal pannello Layer/`LayerFeatureController`.

## Perché

Un Validator (gestore di cammino) impersonato non riusciva a trovare nessuna traccia nel campo di ricerca "EcTracks" durante la creazione/modifica di un EcPoi, nonostante le tracce esistessero e fossero di sua proprietà. L'associazione al Layer, invece, funzionava correttamente (percorso diverso, gestito da `LayerFeatureController`).

Causa verificata leggendo il codice riga per riga: è una regressione non coperta da oc:8587. Quel fix ha corretto lo scoping per `app_id` (`AbstractEcResource::indexQuery()`, sempre vuoto per un Validator — che non possiede mai un'App in camminiditalia) solo sulle risorse di menu "Ec Tracks"/"Ec Poi" (`App\Nova\EcTrack`/`App\Nova\EcPoi`). Non ha toccato il campo `BelongsToMany` incorporato dentro `wm-package/src/Nova/EcPoi.php:68` e `wm-package/src/Nova/EcTrack.php:64`, che referenziano `EcTrack::class`/`EcPoi::class` senza alcun `use` esplicito: per risoluzione di namespace PHP, questo risolve alla classe Nova del package (`Wm\WmPackage\Nova\EcTrack`/`EcPoi`), non alla sottoclasse locale con l'override corretto.

Nova costruisce la lista di righe "attaccabili" per un campo `BelongsToMany` chiamando sempre `buildIndexQuery()` (`vendor/laravel/nova/src/PerformsQueries.php:20-33`), che a sua volta chiama sempre `static::indexQuery()` — indipendentemente dal fatto che l'utente digiti o no un termine di ricerca. Risolvendo alla classe del package, il risultato è `AbstractEcResource::indexQuery()` (`wm-package/src/Nova/AbstractEcResource.php:30-42`), che per un non-Administrator fa `whereIn('app_id', $user->ownedAppIds())` — sempre vuoto per un Validator.

## Requisiti

- [ ] Un Validator (anche impersonato da un Administrator) che crea o modifica un proprio EcPoi trova, nel campo di ricerca "EcTracks", le proprie EcTrack
- [ ] Un Validator che modifica una propria EcTrack trova, nel campo di ricerca "EcPois", i propri EcPoi
- [ ] Un Administrator continua a vedere tutte le tracce/POI in entrambi i campi, senza restrizioni (comportamento invariato)
- [ ] Nessuna modifica al comportamento dell'associazione Layer↔Track/Poi (gestita da `LayerFeatureController`, non toccata da questo fix)
- [ ] Test automatico che copre entrambe le direzioni (EcPoi→EcTracks e EcTrack→EcPois) per un Validator, oltre a verifica manuale del dev sullo scenario reale del ticket
- [ ] Il campo "Layers" sulla scheda EcTrack non è visibile per il Validator (attach/detach layer resta esclusivo del pannello Layer/`LayerFeatureController`)

## Rischi

Emersi dalla Fase: challenge (subagente adversariale + verifica diretta nel codice):

- **Stesso bug su un terzo campo, trovato durante la challenge**: `MorphToMany('Layers', 'layers', Layer::class)` in `wm-package/src/Nova/EcTrack.php:67` non ha `use` esplicito per `Layer`, risolve a `Wm\WmPackage\Nova\Layer` (verificato: `indexQuery()` scopa per `app_id`, sempre vuoto per un Validator — stesso identico pattern). `App\Nova\EcTrack::fields()` non lo filtrava. Mitigato nello scope di questo ticket nascondendo il campo per il Validator (vedi Requisiti) invece di correggerlo con il pattern usuale, perché un gestore di cammino non deve comunque poter fare attach/detach diretto del layer da qui.
- **Scoping per user_id, non per layer**: un Validator con più cammini/layer vede, nel campo di attach, tutte le proprie EcTrack/EcPoi indipendentemente dal layer — potendo in teoria associare per errore una traccia di un cammino a un POI di un altro. Comportamento accettato: stesso livello di granularità già in uso altrove nel progetto (oc:8311), decisione confermata dal dev.
- **Causa reale non corretta upstream in wm-package**: il pattern (classe Nova del package referenziata senza `use` esplicito, che ignora l'override locale di un consumer) resta nel package. Altri consumer di wm-package con un `indexQuery()` locale analogo potrebbero avere lo stesso bug su altri campi, non segnalato upstream. Accettato come rischio residuo, fuori scope (un repo per ciclo, stesso principio di oc:8587).
- **Rischio implementativo (campo duplicato)**: se la sostituzione del campo in `fields()` non filtra anche per `$field->attribute` (oltre che per classe), si rischia di lasciare nell'array sia il campo vecchio che quello nuovo con lo stesso nome — comportamento Nova indefinito. Mitigato in fase di implementazione seguendo lo stesso pattern già usato per i campi `app`/`user` (filtro esplicito per attributo).
- **Verificato e escluso**: cambiare la classe Nova referenziata nel campo non cambia l'autorizzazione di attach (`authorizedToAttach()`/`authorizedToAdd()` risolvono la policy sul modello Eloquent, non sulla classe Nova Resource — nessun metodo `attach*`/`add*` è definito nelle policy, quindi il comportamento è identico prima e dopo).

## Out of scope

- Campi `BelongsToMany`/`MorphToMany` verso EcTrack/EcPoi su risorse Taxonomy (`TaxonomyPoiType`, `TaxonomyTheme`, `TaxonomyActivity`): verificato che `TaxonomyPoiTypePolicy` blocca comunque l'`update` per i Validator (form non raggiungibile), e confermato dal dev con verifica diretta in app che POI/tracce non sono collegati ai temi in questo progetto — nessuna azione necessaria
- Nessuna modifica a `wm-package` (submodule) — il fix è interamente locale, nelle sottoclassi già esistenti in `App\Nova`
- Nessuna modifica al meccanismo di associazione Layer↔Track/Poi tramite `LayerFeatureController` (oc:8080/oc:8311/oc:8314) — continua a funzionare come oggi, non toccato
- Nessun filtro aggiuntivo per layer nello scoping dell'attach (resta per `user_id`, coerente con oc:8311) — decisione esplicita del dev

## Moduli toccati

- `app/Nova/EcPoi.php` — override di `fields()`: sostituzione del campo `BelongsToMany('EcTracks', 'ecTracks', ...)` ereditato, per puntare a `App\Nova\EcTrack::class` invece di `Wm\WmPackage\Nova\EcTrack::class` (filtro per attributo `ecTracks`, non solo per classe)
- `app/Nova/EcTrack.php` — override di `fields()`: sostituzione del campo `BelongsToMany('EcPois', 'ecPois', ...)` ereditato, per puntare a `App\Nova\EcPoi::class` invece di `Wm\WmPackage\Nova\EcPoi::class` (filtro per attributo `ecPois`); nascosto (`canSee`) il campo `MorphToMany('Layers', 'layers', ...)` per il Validator
- Nuovo test Feature (es. `tests/Feature/EcTrackEcPoiAttachableTest.php`) che verifica, per un Validator, che l'endpoint Nova `attachable` per entrambi i campi restituisca solo le righe di sua proprietà, per un Administrator restituisca tutte le righe, e che il campo "Layers" non sia presente nei fields di un Validator

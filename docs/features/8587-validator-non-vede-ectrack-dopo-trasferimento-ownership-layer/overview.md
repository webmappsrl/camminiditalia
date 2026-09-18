> Ticket: oc:8587

# Overview

## Cosa cambia

- Nuovo override locale `App\Policies\EcTrackPolicy`, registrato in `AppServiceProvider` al posto di `Wm\WmPackage\Policies\EcTrackPolicy`, che ripristina lo scoping per-record (`user_id === ecTrack.user_id`) per `view()`/`update()`/`delete()`/`create()` di un Validator.
- Override locale di `indexQuery()` su `App\Nova\EcTrack` e `App\Nova\EcPoi` (finora assenti, ereditavano quello di `Wm\WmPackage\Nova\AbstractEcResource`), che scopa la lista Nova per `user_id` invece che per `app_id` posseduto — stesso pattern già in uso per `App\Nova\UgcPoi::filteredQueryForValidator`.
- Aggiunta di un test di regressione dedicato che protegge lo scoping per layer degli UgcPoi "report" per un Validator (comportamento verificato oggi corretto, ma non protetto da un test esplicito contro un futuro cambio upstream analogo a quello che ha causato questo bug).

## Perché

`oc:8162` (wm-package, commit `23c4b5e8`) ha cambiato `EcTrackPolicy::view()/update()/delete()` del package da un controllo per-record (`user_id === ecTrack.user_id`) a un controllo per-app (`ownsApp()`), per supportare un nuovo ruolo `Editor` con scoping multi-app. Questo ruolo non esiste in camminiditalia (CLAUDE.md: "Non usare ruoli del package come Editor — non esistono in questo progetto"), e `ownsApp()` non riflette il modello di autorizzazione per-record su cui si basa camminiditalia. Risultato: dopo un trasferimento di ownership del layer (oc:8080), il Validator (gestore di cammino) smette di vedere/gestire le proprie EcTrack.

Verifica svolta in questo ciclo:
- Confermato via `git show 23c4b5e8` che il cambio riguarda esattamente `EcTrackPolicy` del package.
- Verificato che lo stesso commit **non** ha introdotto lo stesso problema su `UgcPoiPolicy`/`UgcTrackPolicy`: `bypassRoles()` include già `'Validator'`, quindi l'accesso a livello di Gate resta pieno; lo scoping reale per un Validator (per layer, non per app — il requisito iniziale "per app" era un errore di formulazione, corretto dal dev durante la reverse-interaction) vive interamente nel codice locale (`app/Nova/UgcPoi.php::filteredQueryForValidator`), non toccato da oc:8162.
- Verificato empiricamente sui dati di produzione: 10 UgcPoi di tipo "report" nel DB, scoping per layer confrontato con un calcolo indipendente per tutti i 13 Validator che possiedono almeno un layer — nessun mismatch.
- **Trovato in fase di challenge e confermato empiricamente**: lo stesso commit oc:8162 ha introdotto in `Wm\WmPackage\Nova\AbstractEcResource::indexQuery()` uno scoping per `app_id` posseduto (`$user->ownedAppIds()`), condiviso da `EcTrack` ed `EcPoi`. Camminiditalia ha una sola App, di proprietà dell'Administrator — nessun Validator possiede mai un'App. Risultato: la lista Nova principale "Ec Tracks"/"Ec Poi" (voce di menu senza `canSee`, quindi raggiungibile da ogni Validator) è **sempre vuota** per ogni Validator, indipendentemente da qualunque trasferimento di ownership del layer. Verificato con un login reale come il Validator 5076 (proprietario di 7 EcTrack via `user_id`): la query Nova restituisce 0 righe. Questo è probabilmente il vero comportamento dietro il sintomo "il gestore non vede più le tracce" — il fix sulla sola `EcTrackPolicy` risolve l'accesso a una singola traccia raggiunta da link diretto (es. dal pannello Layer via `LayerFeatureController`, già corretto), ma non la lista principale del menu Nova.

## Requisiti

- Un Validator vede/modifica/elimina/crea EcTrack solo per le proprie (`user_id`), indipendentemente da `ownsApp()`/`app_id` — incluso `create()`, già coperto da `test_validator_can_create_ec_track` in `EcTrackPolicyTest.php` esistente, che va reso esplicito come requisito (rischio di regressione silenziosa se il nuovo override standalone dimentica il metodo: "assente" in una Policy significa "negato").
- Administrator mantiene accesso pieno e incondizionato a tutte le EcTrack.
- Guest: `viewAny` resta `true` (bloccato solo dalla route Nova — comportamento già testato e accettato, coerente con `test_guest_can_view_any_ec_tracks_list_but_never_reaches_the_route`), nessun altro accesso concesso.
- `before()` ritorna `null` per ogni utente non-Administrator (non `false`), cosicché ogni metodo (`viewAny`/`view`/`create`/`update`/`delete`) decida autonomamente — scelta esplicita del dev per preservare il comportamento Guest sopra, anche se diverge leggermente dal pattern `EcPoiPolicy` (`before(): !Validator → false`).
- L'override della Policy segue lo stesso pattern già in uso per `EcPoiPolicy` (override locale + `Gate::policy()` in `AppServiceProvider`, nessuna modifica a wm-package).
- Un Validator vede nella lista Nova principale ("Ec Tracks"/"Ec Poi") solo le proprie EcTrack/EcPoi (`user_id`), non zero record e non quelle di altri — override locale di `indexQuery()` su `App\Nova\EcTrack` e `App\Nova\EcPoi`, senza toccare `Wm\WmPackage\Nova\AbstractEcResource` (logica generica riusabile da altri consumer, resta invariata).
- Nessuna modifica al comportamento UgcPoi/UgcTrack esistente (già verificato corretto) — solo un test di regressione dedicato che lo protegga da un futuro cambio upstream.

## Rischi

- Se `wm-package` cambia ulteriormente `EcTrackPolicy` o `AbstractEcResource::indexQuery()`, gli override locali possono disallinearsi silenziosamente (nessun test upstream lo segnala) — stesso rischio già accettato per `EcPoiPolicy`.
- Il test di regressione UGC aggiunto protegge lo stato attuale (scoping per layer); se in futuro si decidesse deliberatamente di passare a uno scoping per app, il test andrà aggiornato consapevolmente.
- 2 UgcPoi "report" su 10 in produzione non hanno alcun `layer_id` (invisibili a qualsiasi Validator, visibili solo all'Administrator) — rischio noto, segnalato al dev, esplicitamente accettato senza azione in questo ciclo.
- Nota già presente nel ticket oc:8587: rischio cross-progetto — altri consumer di wm-package potrebbero avere la stessa regressione silenziosa su `EcTrackPolicy`/`AbstractEcResource::indexQuery()`; non affrontato qui (fuori scope, un solo repo per ciclo).
- Verificato in challenge che `LayerPolicy` (già override locale su `user_id`) e `UgcPoiPolicy`/`UgcTrackPolicy` locali non sono affetti dallo stesso commit oc:8162. `MediaPolicy`/`TaxonomyThemePolicy`/`TaxonomyWherePolicy` del package sono stati toccati dallo stesso commit ma non hanno override locale in camminiditalia: non risultano evidenze di un impatto negativo sul Validator (es. `MediaPolicy` riammette esplicitamente `Validator` via `bypassRoles()` nello stesso commit), ma non sono stati verificati a fondo in questo ciclo — rischio residuo basso, non affrontato.
- L'override `indexQuery()` di `EcPoi` interagisce con oc:8120 ("EcPoi: sola lettura per Validator"): quella feature presuppone che il Validator possa vedere la lista in Nova, cosa che oggi non accade a causa di questo stesso bug — il fix qui proposto è quindi anche condizione necessaria perché oc:8120 funzioni davvero end-to-end.

## Out of scope

- Qualsiasi modifica a `wm-package` (la causa upstream in oc:8162 resta con lo scoping per-app per il ruolo `Editor`/per `AbstractEcResource::indexQuery()`, non usato/aggirato localmente in questo progetto).
- Investigazione o fix dei 2 UgcPoi "report" senza `layer_id`.
- Cambio dello scoping UGC da per-layer a per-app.
- Rischio cross-progetto su altri consumer di wm-package (segnalato, non affrontato).
- Verifica approfondita di `MediaPolicy`/`TaxonomyThemePolicy`/`TaxonomyWherePolicy` (toccate dallo stesso commit oc:8162, nessuna evidenza di impatto negativo trovata, ma non verificate a fondo).

## Moduli toccati

- `app/Policies/EcTrackPolicy.php` (nuovo)
- `app/Providers/AppServiceProvider.php` (import aggiornato)
- `app/Nova/EcTrack.php` (nuovo `indexQuery()` locale)
- `app/Nova/EcPoi.php` (nuovo `indexQuery()` locale)
- `tests/Feature/EcTrackPolicyTest.php` (aggiornato: policy attiva è quella locale)
- Nuovo test per `indexQuery()` di EcTrack/EcPoi (file da definire in `Fase: write-plan`)
- Nuovo test di regressione per lo scoping per layer degli UgcPoi "report" (file da definire in `Fase: write-plan`)

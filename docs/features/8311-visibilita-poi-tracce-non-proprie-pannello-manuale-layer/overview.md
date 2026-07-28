> Ticket: oc:8311

# Visibilità POI/tracce non proprie nel pannello manuale di Layer

## Cosa cambia

`LayerFeatureController::getFeatures()` (repo principale, override locale del package) filtra per `user_id` anche la lista delle feature **già associate** al layer (`getAssociatedFeatures()`), non solo quella delle feature disponibili da aggiungere.

**Il filtro `user_id` è sempre rispetto al proprietario del layer (`$layer->user_id`), non rispetto all'utente loggato.** Un Validator che apre il proprio layer coincide comunque con `$layer->user_id`, quindi per lui non cambia nulla nella pratica. Un Administrator che apre un layer di un Validator vede le feature del **proprietario di quel layer**, non le proprie — perché in produzione gli account Administrator usati per operazioni di gestione spesso non possiedono EC propri (gestiscono contenuti creati da Validator o importati): un filtro basato sull'utente loggato lascerebbe l'Administrator senza nulla da vedere/gestire, rompendo l'operatività reale. Questo sostituisce la formulazione iniziale ("nessuna eccezione di ruolo, filtro su `Auth::user()->id`"), corretta dopo verifica in ambiente locale (layer 130: il proprietario possiede 325 EcPoi, ma gli account Administrator usati per testare ne possiedono 0 ciascuno — il filtro sull'utente loggato mostrava 0 feature disponibili anche se ne esistevano 606 nel sistema).

Viene inoltre creato un override locale di `sync()` (assente oggi: l'endpoint è ereditato tal quale dal package, senza alcun controllo di ownership) che filtra silenziosamente dalla richiesta gli ID di feature non appartenenti al **proprietario del layer** prima di eseguire la sincronizzazione.

**Cambio di comportamento per la modalità `auto`:** oggi `auto=true` in `sync()` ricalcola l'associazione layer↔EC per corrispondenza di tassonomia (`assignTracksByTaxonomy`/`assignPoisByTaxonomy` nel package), ignorando del tutto ownership e `features[]` inviato. Questo bypassava il filtro previsto per il fix. La logica a tassonomia viene rimossa: `auto=true` assegna al layer tutti gli EC (poi/track) di cui il **proprietario del layer** è `user_id`, indipendentemente da chi effettua la chiamata — coerente con "il mio cammino contiene ciò che io creo" per il gestore proprietario. Non serve un filtro `app_id` aggiuntivo: il progetto ha un'unica app.

## Perché

Un Validator (gestore di cammino) che passa alla modalità manuale su Ec Pois/Ec Tracks di un proprio layer vede oggi anche POI/tracce già associate ma appartenenti ad altri gestori — violazione dello scoping per-utente già applicato altrove nel progetto (oc:7640, oc:8304). Lo stesso vale, potenzialmente in modo più grave, per `sync()`: un Validator potrebbe associare o rimuovere dal layer feature non proprie tramite chiamata diretta all'endpoint, senza passare dalla UI.

## Requisiti

- [ ] `getAssociatedFeatures()` in `App\Http\Controllers\LayerFeatureController::getFeatures()` filtra per `user_id` **del proprietario del layer** (`$layer->user_id`), indipendentemente da chi effettua la richiesta, applicandosi sia al ramo `view_mode=details` sia al ramo `view_mode=edit`
- [ ] Nuovo override locale di `sync()` (route + controller) segue lo stesso pattern già documentato in CLAUDE.md per `getFeatures` ("sovrascrive quella del wm-package senza modificare il package")
- [ ] `sync()` filtra silenziosamente dalla lista `features` inviata, mantenendo solo gli ID appartenenti al **proprietario del layer** (`$layer->user_id`), nessun errore, sync procede solo con quegli ID
- [ ] `sync()` con `auto=true`: bypassa `assignTracksByTaxonomy`/`assignPoisByTaxonomy` del package, assegna al layer tutti gli EC (poi/track) di cui il **proprietario del layer** è `user_id`, senza filtro di tassonomia né `app_id`
- [ ] `sync()` continua a richiamare esplicitamente `PBFGeneratorService::regeneratePbfsForLayer()` per `ecTracks` dopo il sync/auto-assign (side-effect del parent nel package, da non perdere nell'override locale)
- [ ] Solo il proprietario del layer o un Administrator possono chiamare `getFeatures()`/`sync()` per un dato `layerId` (403 altrimenti) — un Validator non può forzare una sync/lettura su un layer che non possiede, anche se l'esito userebbe comunque gli EC del vero proprietario
- [ ] `$validatedData['model']` è validato con un'allowlist esplicita (`EcPoi::class`, `EcTrack::class`) prima dell'istanziazione, non solo verificato a posteriori con `method_exists`
- [ ] Suite di test Feature per `LayerFeatureController`: Validator A non vede/non sincronizza EC di Validator B; Administrator che apre un layer di un Validator vede/gestisce gli EC del **proprietario del layer**, non i propri; `auto=true` assegna al layer solo gli EC del proprietario del layer, ignorando la tassonomia; rigenerazione PBF invocata su sync di `ecTracks`; un utente che non possiede il layer riceve 403 su `getFeatures`/`sync`; un `model` non in allowlist viene rifiutato; copertura sia `getFeatures` (details+edit) sia `sync`

**Nota (corretta dopo verifica in ambiente locale):** il filtro è sempre rispetto al proprietario del layer, non all'utente loggato — nessuna eccezione di ruolo necessaria perché il criterio non è più "chi sono io" ma "chi possiede questo layer". Questo evita che un Administrator senza EC propri (comune in produzione: gestisce contenuti creati da altri) veda 0 feature disponibili aprendo un layer altrui.

## Rischi

- L'override locale di `getFeatures` è una reimplementazione più datata rispetto alla versione attuale nel package (che gestisce già logica taxonomy/auto-mode assente nell'override locale). Questo ciclo **non** allinea l'override alla versione più recente del package — rischio noto, accettato, fuori scope (si valuta un ticket separato in futuro, come già fatto per oc:8312 con PHPStan)
- Filtro silenzioso su `sync()`: se un client invia una lista mista di ID propri/altrui per un bug di UI, la richiesta non fallisce e il Validator potrebbe non accorgersi che alcuni ID sono stati ignorati — accettato per coerenza con il comportamento già adottato in `getFeatures`
- Il modello passato a `sync()`/`getFeatures()` era generico (`$validatedData['model']`), istanziato prima di qualunque verifica — mitigato con l'allowlist esplicita (vedi Requisiti)
- Un layer con EC storicamente auto-assegnati per tassonomia (comportamento precedente) vede il proprio pivot **ricalcolato per intero** non appena viene chiamato `auto=true`: l'override locale sostituisce l'associazione per quella relazione con "solo gli EC del proprietario del layer". Non è un problema nella pratica corrente perché l'ownership sul layer riflette sempre il gestore attuale (cascata via `LayerObserver`, oc:8080), ma resta un cambio di semantica netto rispetto al comportamento a tassonomia del package
- Un layer il cui proprietario non possiede alcun EC svuota di fatto il pivot della relazione quando viene chiamato `auto=true` — comportamento coerente con la nuova semantica, ma potenzialmente sorprendente se il layer aveva contenuti auto-assegnati in precedenza
- Senza il controllo di autorizzazione sul layer (vedi Requisiti), qualunque utente autenticato poteva forzare una `sync()`/lettura su un layer altrui: l'esito era comunque limitato agli EC del vero proprietario (nessun data leak), ma permetteva comunque un reset non autorizzato del pivot altrui in modalità manuale (l'utente chiamante poteva svuotare/sostituire l'associazione di un layer che non gli appartiene, anche se con contenuti "corretti") — richiesto un controllo esplicito di ownership sul layer
- Verificato: `index()` (ereditato dal package, non toccato) restituisce solo un conteggio (`count`) delle feature associate al layer, non lista di ID/nomi — non espone lo stesso data leak di `getFeatures`/`sync`, nessun fix necessario
- Nota operativa per il deploy: se in produzione è attivo `route:cache`, serve un `route:clear`/`route:cache` esplicito dopo il deploy perché la nuova route locale `sync/{layerId}` sostituisca effettivamente quella ereditata dal package (stesso rischio già presente oggi per l'override di `getFeatures`)

## Out of scope

- Allineamento dell'override locale di `getFeatures` alla logica taxonomy/auto-mode più recente del package (l'override locale continua a non implementare display auto-mode/taxonomy: resta lo split associate/available esistente)
- Trasferimento ownership al cambio owner del layer (già gestito da `LayerObserver`, oc:8080)
- Rimozione della logica a tassonomia per layer gestiti da Administrator (resta invariata)
- Modifiche al wm-package (nessuna modifica generica al package in questo ciclo — il bypass della tassonomia per Validator è implementato interamente nell'override locale)

## Moduli toccati

- `app/Http/Controllers/LayerFeatureController.php` (repo principale) — fix filtro `user_id` su `getAssociatedFeatures()`; nuovo metodo `sync()`
- `app/Providers/NovaServiceProvider.php` (repo principale) — registrazione route override per `sync/{layerId}`
- `tests/Feature/LayerFeatureControllerTest.php` (repo principale, nuovo file) — test di regressione

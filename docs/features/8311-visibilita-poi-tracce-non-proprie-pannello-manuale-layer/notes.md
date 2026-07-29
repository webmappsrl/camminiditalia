> Ticket: oc:8311

# Notes — Visibilità POI/tracce non proprie nel pannello manuale di Layer

## Deviazioni dal piano

- Il piano originale (Task 1-4) prevedeva il filtro ownership basato su `Auth::user()->id` (utente loggato), senza eccezioni di ruolo. Dopo l'esecuzione, un test manuale in ambiente locale (layer 130) ha mostrato che questo approccio rompeva l'operatività reale degli account Administrator (che spesso non possiedono EC propri, gestendo contenuti creati da Validator). Corretto in Task 5: il filtro è ora basato su `$layer->user_id` (proprietario del layer). Vedi `plan.md`, Task 5, per il dettaglio completo.
- Nei test, il piano suggeriva `$layer->ecPois()->attach($poiId)` per simulare "un EC di un altro owner già associato al layer". Questo si è rivelato inutilizzabile: `App\Observers\LayerableObserver::created()` (oc:8080/oc:8139) riassegna silenziosamente `user_id` del POI al proprietario del layer non appena viene chiamato `attach()` (la relazione usa un pivot custom `Layerable`, quindi l'attach scatena eventi Eloquent). Sostituito con inserimento diretto in `layerables` via `DB::table()->insert()`, bypassando l'observer — pattern già in uso altrove nel repo per lo stesso motivo.

## Bug trovati

- Nessun bug pre-esistente introdotto da questo lavoro. Un comportamento pre-esistente nel wm-package è stato scoperto durante il Task 4: `regeneratePbfsForLayer` viene chiamato due volte per un singolo sync di `ecTracks` (una volta esplicitamente dal controller, una volta implicitamente da `LayerableObserver::created()`). Non introdotto da oc:8311, verificato confrontando col comportamento del `sync()` ereditato dal package — stessa duplicazione già presente prima del fix. Segnalato come follow-up, non corretto in questo ciclo.

## Decisioni

- Filtro ownership basato sul **proprietario del layer** (`$layer->user_id`), non sull'utente che effettua la richiesta — vedi `plan.md` Task 5 e `overview.md` per la motivazione completa (un Administrator gestisce spesso contenuti non propri).
- Aggiunto un controllo di autorizzazione 403 (solo proprietario del layer o Administrator possono operare su un dato `layerId`) e un'allowlist sul parametro `model` — non previsti nel piano originale, emersi dalla review finale del branch (finding Critical: l'allowlist iniziale rifiutava `Wm\WmPackage\Models\EcPoi`, la classe realmente inviata dal frontend Nova quando `wm-package.ec_poi_model` non è configurato — corretto per accettare entrambe le varianti).
- **Layer orfano (`user_id` null):** emerso da `wm-skills:wm-review-ticket`, confermato con dati reali (55 layer e 321 EcTrack con `user_id` null nel DB di sviluppo) — `->where('user_id', $layer->user_id)` con valore `null` degenera in Eloquent in `whereNull('user_id')`, esponendo a un Administrator (unico ruolo che supera il 403 su un layer senza owner) tutti gli EC orfani del sistema, non solo quelli pertinenti al layer. Corretto risolvendo l'owner effettivo con fallback a `config('camminiditalia.default_owner_id')` (stesso meccanismo già usato in oc:8080 per il trasferimento ownership) quando `$layer->user_id` è null, usato ovunque al posto di `$layer->user_id` diretto nelle query di scoping.
- Nessuna modifica al submodule `wm-package` in tutto il ciclo.

## Follow-up

- Doppia chiamata a `regeneratePbfsForLayer` per sync di `ecTracks` (vedi "Bug trovati" sopra) — comportamento preesistente nel wm-package, da valutare in un ticket separato.
- L'override locale di `getFeatures()` resta strutturalmente più datato rispetto alla versione più recente nel package (che gestisce logica taxonomy/auto-mode in lettura, assente nell'override locale) — fuori scope confermato in `overview.md`, da considerare in un ciclo futuro se necessario allineare il display.
- `wm-skills:wm-review-ticket` ha segnalato debito architetturale crescente (override locale completo ora su 2 metodi invece di 1, sempre più disallineato dal package) e diversi punti di duplicazione (blocco autorizzazione, allowlist, gestione errori ripetuti identici in `getFeatures()`/`sync()`) — non corretti in questo ciclo (cleanup non bloccante), candidati per un refactor futuro se si tocca ancora questo controller.
- Test `test_administrator_sync_filters_out_ec_poi_not_owned` non copre esplicitamente lo scenario cross-owner (Administrator su layer di un Validator) per `sync()`, solo per `getFeatures()` — gap di copertura minore segnalato dalla review, non bloccante.

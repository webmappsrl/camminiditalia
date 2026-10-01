> Ticket: oc:8671

# Passaporto camminatore: accettazione della richiesta di certificazione e tappe validate

## Cosa cambia

Secondo step di oc:8166 / oc:8653. Oggi una richiesta di certificazione della credenziale cartacea arriva in Nova e resta `pending` per sempre: nessuno la può decidere.

Dopo questo lavoro il gestore del cammino (Validator proprietario del layer), o l'Administrator, apre la richiesta in Nova ed esegue un'unica azione **"Decidi richiesta"** scegliendo esplicitamente l'esito:

- **Approva** → seleziona una o più tappe del layer da riconoscere (solo le EcTrack del layer di proprietà del proprietario del layer, stessa regola della vista Nova del layer); le tappe finiscono in una nuova tabella delle **tappe validate**;
- **Rifiuta** → nessuna tappa, scelta esplicita (non dedotta da una selezione vuota).

In entrambi i casi può scrivere una nota facoltativa. La decisione è irreversibile: la richiesta passa a `approved` / `rejected` e non si riapre. Il camminatore riceve una mail con l'esito e la nota, nella lingua con cui aveva inviato la richiesta dall'app. L'API di stato restituisce l'ultima richiesta in qualsiasi stato, non più solo quella `pending`.

## Perché

Deciso allo scrum del 30/09/2026 (Giuseppe Bonfanti): «allora fai anche il secondo step». Il backend di oc:8653 si ferma alla ricezione della richiesta; senza una decisione il gestore non può riconoscere le tappe timbrate sulla credenziale e il camminatore non riceve mai un esito. La tabella delle tappe validate è la base su cui in futuro scriveranno anche le validazioni automatiche GPS (oc:8165, oggi solo mockup).

## Requisiti

**Schema (repo principale, `database/migrations/`)**

- [ ] Modificare la migration esistente `2026_09_28_150000_create_certification_requests_table.php` (non crearne una nuova — la tabella è solo sul branch oc:8653, mai andata in produzione): aggiungere `decision_note` (text, nullable), `decided_at` (timestamp, nullable), `decided_by` (FK `users`, nullable, `nullOnDelete`), `locale` (string, default `it`). In locale: rollback della migration, modifica, re-run.
- [ ] Nuova migration (una sola) per la tabella delle tappe validate: `user_id` (FK, `cascadeOnDelete`), `ec_track_id` (FK, `cascadeOnDelete`), `layer_id` (FK, `cascadeOnDelete`, "per comodità" come chiesto in call), `certification_request_id` (FK nullable, `nullOnDelete` — valorizzata solo per le validazioni manuali), `source` (string, `manual`; predisposto per `gps`), `validated_at`, timestamps; **vincolo unico `(user_id, ec_track_id)`**.

**Modello e dominio (repo principale, `app/`)**

- [ ] Nuovo modello per le tappe validate, con relazioni `user`, `ecTrack`, `layer`, `certificationRequest`; `CertificationRequest` ottiene `validatedTracks()` (`HasMany`) e `decidedBy()`.
- [ ] Logica di decisione in `CertificationRequestService` (non nell'action): in transazione, con lock sulla richiesta; verifica che sia ancora `pending`; su Approva richiede almeno una tappa, appartenente al layer della richiesta secondo la stessa regola del modale (`layer->ecTracks()` filtrate per `user_id = layer->user_id ?? config('camminiditalia.default_owner_id')`, come `LayerFeatureController`) e non già validata per l'utente; intercetta la violazione del vincolo unico e la restituisce come errore leggibile, non come 500; scrive status, `decided_at`, `decided_by`, `decision_note` e le righe delle tappe validate; dopo il commit dispatcha la mail al camminatore.

**API (repo principale)**

- [ ] `POST /api/layer/{layer}/certification`: salva `locale` dalla lingua della richiesta dell'app (`Accept-Language` interpretato, es. `de-DE,de;q=0.9` → `de`), limitata a `it/en/fr/es/de`, default `it`.
- [ ] `GET /api/layer/{layer}/certification`: restituisce l'**ultima** richiesta dell'utente per quel layer in qualsiasi stato (`pending`/`approved`/`rejected`), con `status`, `submitted_at`, `decided_at`, `decision_note`; `none` solo se non ne esiste nessuna. La visualizzazione è compito del frontend.

**Nova (repo principale)**

- [ ] Azione "Decidi richiesta" su `App\Nova\CertificationRequest`, con `canSee` + `canRun` = `can('view', $model)` && richiesta `pending` (nessuna nuova abilità di policy: senza `canRun` Nova ricadrebbe su `update()`, che è `false` per tutti). Solo su singola richiesta, imposto davvero (`->sole()`), perché Nova carica i campi dipendenti dalla risorsa solo con una risorsa selezionata.
- [ ] Modale: scelta esito (Approva / Rifiuta), elenco tappe del layer (regola di proprietà sopra) a checkbox ordinate per nome con **ordinamento naturale** ("Tappa 2" prima di "Tappa 10"), opzione **"Seleziona tutte"** (booleano separato interpretato dal service), tappe già validate per l'utente mostrate come già acquisite e non selezionabili, nota facoltativa. Azione in **due passaggi** (pattern `Action::modal()` di `ImportTaxonomyWhere` in wm-package): il primo modale raccoglie i dati senza scrivere nulla; un secondo modale (componente Vue su misura) mostra il riepilogo — esito, nome del camminatore, numero ed elenco delle tappe, nota — e l'avviso di irreversibilità; solo *Conferma decisione* chiama una route dedicata che esegue la decisione, rifacendo autorizzazione e validazione.
- [ ] Dettaglio della richiesta: campi Decisione, Nota, Deciso da, Deciso il, e elenco "Tappe validate" in sola lettura (nome tappa, data). Risorsa Nova delle tappe validate non in menu, nessuna creazione/modifica/cancellazione.
- [ ] `CertificationRequestPolicy`: togliere `delete` da `ADMINISTRATOR_ABILITIES` (altrimenti `before()` restituisce `true` all'Administrator senza mai eseguire `delete()`); `delete()` restituisce `true` solo per Administrator + richiesta `pending`. Le richieste decise non si cancellano, per nessuno.

**Mail (repo principale)**

- [ ] Nuova mail al camminatore con l'esito (approvata con elenco tappe riconosciute / rifiutata) e l'eventuale nota del gestore, inviata in coda, nella lingua salvata sulla richiesta (impostata sul Mailable, non solo nel job, così anche le stringhe della vista sono tradotte).

**Traduzioni**

- [ ] Ogni nuova stringa (azione, campi Nova, mail) in tutti e cinque i file `resources/lang/{it,en,fr,es,de}.json`.

**Test**

- [ ] Feature test su: decisione (approva, rifiuta, doppia decisione rifiutata, tappa fuori layer, tappa già validata, approva senza tappe), autorizzazione dell'azione (Validator proprietario / Validator altrui / Administrator / Guest, richiesta non `pending`), policy `delete`, `GET` con ultima richiesta in ogni stato, salvataggio `locale` al `POST`, mail in coda con lingua corretta. Aggiornare i test esistenti di oc:8653 dove cambia il comportamento (`GET`, `delete`).

## Rischi

- **Branch dipendente da oc:8653 non ancora in `develop`**: la migration da modificare esiste solo sul branch di oc:8653 (pubblicato su `origin`, ma confermato dal dev che non è installato in nessun ambiente oltre al locale). Il branch di questo ticket va creato a partire da quello; se oc:8653 viene mergiato o installato altrove prima, la modifica della migration va rivalutata.
- **Tappe con proprietario diverso dal proprietario del layer** (49 layer, 522 tappe nei dati locali, bug noto da oc:8314): non compaiono nel modale e non si possono validare finché il cliente non allinea i dati. Scelta del dev: il Validator vede solo le proprie tappe.
- **Decisione non correggibile da Nova**: un errore del gestore (es. "Seleziona tutte" per sbaglio) si corregge solo con una procedura manuale, documentata in `notes.md`. Mitigazione: conferma con numero di tappe e nome del camminatore.
- **`BooleanGroup` di Nova non permette di disabilitare singole opzioni**: le tappe già validate vanno mostrate come testo in sola lettura sopra l'elenco selezionabile (che contiene solo quelle ancora da validare), e il controllo reale sta comunque nel service e nel vincolo unico.
- **Doppia decisione concorrente** (due gestori o due click): mitigata da lock sulla riga + controllo `pending` nella transazione.
- **Vincolo unico `(user_id, ec_track_id)` con una tappa condivisa da più layer**: la tappa risulta validata una sola volta, con il `layer_id` del cammino in cui è stata validata per prima. Accettato: la tappa è la stessa.
- **Layer con molte tappe** (fino a 99 nei dati locali, media 11): la lista a checkbox resta gestibile; "Seleziona tutte" copre le credenziali complete.
- **Lingua della mail**: `Accept-Language` dipende da come l'app lo invia; una lingua non supportata o assente ricade su `it`.

## Out of scope

- Mostrare esito e nota nell'app (compito del frontend, che legge il `GET`).
- Email di arrivo richiesta inviata anche al camminatore, utente cliccabile e "copia mail" per il gestore (in sospeso dallo scrum del 30/09).
- Validazione automatica GPS e calcolo del completamento del cammino / badge (oc:8165).
- Voce di menu Nova dedicata alle tappe validate (quando arriveranno le validazioni GPS).
- Riapertura o modifica di una decisione, e azione di correzione in Nova (procedura manuale in `notes.md`).

## Moduli toccati

Tutto nel **repo principale camminiditalia**; nessuna modifica a `wm-package`.

- `database/migrations/2026_09_28_150000_create_certification_requests_table.php` (modifica)
- `database/migrations/<nuova>_create_validated_ec_tracks_table.php` (nuova; nome tabella da confermare nel piano)
- `app/Models/CertificationRequest.php`, nuovo modello tappe validate
- `app/Services/CertificationRequestService.php`
- `app/Http/Controllers/Api/CertificationRequestController.php`
- `app/Nova/CertificationRequest.php`, nuova azione in `app/Nova/Actions/`, nuova risorsa Nova tappe validate
- `app/Http/Controllers/Nova/CertificationDecisionController.php`, `resources/js/nova/certification-decision-confirm.js`, `app/Providers/NovaServiceProvider.php` (script + route `nova-vendor/certification-decision/confirm`)
- `app/Policies/CertificationRequestPolicy.php`
- nuova mail in `app/Mail/` + vista in `resources/views/emails/`, job di invio in `app/Jobs/`
- `resources/lang/{it,en,fr,es,de}.json`
- `app/Exceptions/CertificationDecisionException.php`
- `tests/Feature/CertificationRequest*Test.php`, `tests/Feature/CertificationDecision{Controller,Mail}Test.php`, `tests/Feature/ValidatedEcTrackModelTest.php`, `tests/Feature/Helpers/CreatesCertificationTracks.php`

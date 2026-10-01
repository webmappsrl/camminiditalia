> Ticket: oc:8671

# Notes — Accettazione della richiesta di certificazione e tappe validate

## Deviazioni dal piano

Nessuna deviazione sostanziale. Aggiustamenti minori:

- I test delle tappe selezionabili stanno in `tests/Feature/CertificationRequestSelectableTracksTest.php` e non in `CertificationRequestServiceTest.php`, perché hanno bisogno di `Queue::fake()` e `Http::fake()` (vedi sotto), che il test esistente non usa.
- Lo script del secondo modale è in `resources/js/nova/certification-decision-confirm.js`, accanto a `config-home-sorter.js`, e non in `resources/js/`.
- Il test sul bulk si chiama `test_action_on_multiple_requests_writes_nothing` e non `test_action_cannot_run_on_multiple_requests`. Via API Nova passa a `handle()` un modello alla volta, quindi ne esce al massimo il riepilogo di una richiesta. Non si scrive nulla finché la conferma non arriva al controller, che decide solo l'id esplicito e rifà l'autorizzazione.
- `submit()` riceve `$locale` come parametro facoltativo (default `it`), così le chiamate esistenti non cambiano.
- La lingua si ricava con un parser dedicato, `CertificationRequest::localeFromAcceptLanguage()`, al posto di `Request::getPreferredLanguage()`. Quest'ultimo restituisce la prima lingua ammessa anche quando l'header non ne contiene nessuna, e così non si distinguerebbe «nessuna lingua supportata» da «italiano».

## Correzioni dopo la review finale

- **Conferma di una richiesta già decisa → 422 leggibile, non 403.** Il piano (Task 6) prevedeva un 403 dal controller. Il controller ora controlla solo `can('view')` e lascia il controllo dello stato a `decide()`, sotto lock: il gestore vede «Questa richiesta è già stata decisa.» invece di un errore generico (test `test_confirm_on_decided_request_gets_readable_422`, che sostituisce `test_confirm_on_decided_request_gets_403`). È una modifica di contratto verso chi chiama la route: 422 con `message`.
- **Tappe caricate senza geometria.** `ownedLayerTracks()` legge solo `id`, `name`, `user_id`: prima caricava geometria e `properties`, fino a circa 2 MB a ogni apertura o sincronizzazione del modale su un layer da 99 tappe (test `test_selectable_tracks_do_not_load_geometry`).

## Cleanup dalla review wm-review-ticket (30/09, scelti dal dev: 1-4, 9-17)

- Limite della nota (`CertificationRequest::DECISION_NOTE_MAX_LENGTH`, 5000) già nel primo modale, non solo alla conferma.
- L'azione "Decidi richiesta" è **solo nel dettaglio** della richiesta (`onlyOnDetail()`): sulle righe della lista Nova calcolava i campi (tappe) per ogni richiesta pending della pagina.
- Tappe selezionabili e già validate calcolate con una sola lettura (`tracksForDecision()`, `partition()`).
- `Accept-Language` accetta anche il formato con underscore (`de_DE` → `de`).
- Helper comuni in `app/Support/`: `UserDisplay` (nome del camminatore e del decisore, usato anche dalla mail al gestore di oc:8653), `EcTrackLabel` (nome della tappa e ordinamento naturale), `LayerOwner` (proprietario effettivo del layer, sostituisce la regola copiata in `LayerFeatureController`, `LayerObserver`, `SyncLayerEcPois` e nel service).
- Nota normalizzata in un solo punto; `is_approve` nel payload del secondo modale invece del confronto con `'approve'` nel JS; argomenti con nome nella chiamata a `decide()` del controller.
- Le migration usano le costanti del modello per i default (`DEFAULT_LOCALE`, `SOURCE_MANUAL`): schema invariato, nessun rollback necessario. Scelta consapevole: una migration che legge una costante di un modello si rompe se in futuro la costante viene rinominata.
- Nova: origine `gps` etichettata esplicitamente, valori sconosciuti mostrati così come sono; risorsa `ValidatedEcTrack` esclusa dalla ricerca globale.

## Design unico e accessibile delle mail (richiesto dal dev dopo la PR #75)

- Le tre mail (segnalazione UGC, nuova richiesta di certificazione, esito al camminatore) ora estendono `resources/views/emails/layouts/cammini.blade.php` e usano i componenti `resources/views/components/mail/` (`route`, `field`, `note`, `photos`); icon dell'App e logo del cammino da `App\Support\MailBranding`. Regola nel `CLAUDE.md` (sezione «Email») e motivazioni in `docs/knowledge/design-mail.md`.
- Design validato dal dev su un mockup (artifact privato): colori del sito camminiditalia.org, WCAG 2.2 AA; unica eccezione accettata il pulsante bianco a 16px su `#e15d15` (3,6:1).
- **Anteprime delle foto della credenziale nella mail al gestore**: decisione del dev che supera «nessun URL nelle mail» di oc:8653, rischio accettato (URL pubblici di foto con dati personali nelle mail). Il test di oc:8653 `test_mail_contains_nova_link_and_no_image_urls` è diventato `test_mail_contains_nova_link_and_photo_previews`.
- Nuove chiavi di traduzione per la mail al camminatore; tolta la chiave del vecchio piè di pagina. Le due mail ai gestori restano in italiano fisso, come prima.

## Bug trovati

- **Worker Horizon con codice vecchio**, durante la prova nel browser. Il primo invio della mail di esito è fallito con `Call to undefined method App\Models\CertificationRequest::validatedTracks()`: il worker era partito prima delle modifiche e aveva in memoria la versione vecchia del modello. Si risolve con `docker exec horizon-camminiditalia php artisan horizon:terminate` (il container ha `restart: always` e riparte da solo) e poi `php artisan queue:retry <uuid>`. Non è un bug del codice. In produzione il deploy riavvia già i worker.
- **La factory `EcTrack` chiama il servizio DEM esterno** (`UpdateEcTrack3DDemJob` → `DemClient`), e nei test fallisce con `Invalid geometry`. Per questo i test che creano tappe usano `Queue::fake()` + `Http::fake()` (helper `tests/Feature/Helpers/CreatesCertificationTracks.php`).

## Decisioni

- **Doppio modale al posto del testo di conferma** (chiesto dal dev dopo l'approvazione dell'overview). Il `confirmText` di Nova viene fissato prima che il gestore scelga le tappe e non può mostrarne il numero. Per questo l'azione usa il pattern `Action::modal()` di `ImportTaxonomyWhere` (wm-package): il primo passaggio non scrive nulla, il secondo modale mostra il riepilogo, e la conferma chiama `POST /nova-vendor/certification-decision/confirm`. La stima non è stata aggiornata, per scelta del dev.
- **Target dell'azione ricavato dalla NovaRequest** (`DecideCertificationRequest::targetFromRequest()`). Nella POST di esecuzione Nova crea una risorsa vuota, quindi `$this->resource` non basta a sapere su quale richiesta si lavora. Il valore di `resources` è un array nell'elenco delle azioni e una stringa separata da virgole nell'esecuzione.
- **Quando `canRun` blocca, Nova risponde 200 con `danger`** («Sorry! You are not authorized…»), non con 403. I test accettano entrambe le risposte (`assertBlocked`), ma controllano sempre che non esca nessun modale e che non venga scritto nulla.
- **Tappe già validate**: nel primo modale compaiono come testo in sola lettura ("Already validated"). `BooleanGroup` non permette di disabilitare una singola opzione.
- **Route di conferma**: usa `config('nova.api_middleware')`, cioè autenticazione più accesso al pannello Nova, come le API di Nova.
- **Tag Orchestrator**: il dev ha scelto di rimandare l'associazione dei tag (anche `camminiditalia`, proposto in fase di setup).

- **Cancellazione a cascata delle tappe validate: rischio accettato dal dev** (review wm-review-ticket del 30/09, opzione (c)). `validated_ec_tracks.ec_track_id` e `layer_id` restano `cascadeOnDelete`. Un Validator che cancella una propria tappa (`EcTrackPolicy::delete()` basata sulla proprietà, EcTrack senza soft delete) elimina quella validazione per tutti i camminatori. Un Administrator che cancella un layer elimina le richieste di quel layer, anche quelle decise (`CertificationRequestCleanupObserver`), e le validazioni con quel `layer_id`, anche di tappe condivise con altri cammini. Contrasta con «le tappe riconosciute non si cancellano» della richiesta del cliente; il dev si riserva di rivederlo (alternative valutate: `restrictOnDelete` su `ec_track_id`, `nullOnDelete` su `layer_id`).

## Follow-up

- **Toast fuorviante dopo il primo passaggio**: Nova mostra il suo messaggio standard «The action was executed successfully.» quando si apre il secondo modale, anche se non è stato scritto nulla. È un comportamento del framework, emerso nella prova nel browser.
- **Esito e nota visibili nell'app**: il `GET /api/layer/{layer}/certification` restituisce già `status`, `decided_at` e `decision_note`. Mostrarli spetta al frontend.
- **Tappe validate non esposte via API** al camminatore: servirà un endpoint dedicato per il frontend (segnalato alla sessione app), che dovrà ragionare sulle tappe del layer e non su `validated_ec_tracks.layer_id` (è il primo cammino di validazione, non un'appartenenza).
- **Lista Nova delle richieste**: per ogni riga `pending` Nova calcola i campi dell'azione (circa 4 query più le tappe). Accettabile con poche pending; da rivedere se diventano molte.
- **Righe GPS future** (`certification_request_id` NULL) invisibili al Validator con lo scoping attuale della risorsa `ValidatedEcTrack`: da rivedere con oc:8165.
- **Email di arrivo richiesta anche al camminatore** e altri punti ancora in sospeso dallo scrum del 30/09: fuori scope.

## Procedura manuale di correzione di una decisione

Da Nova la decisione non si può modificare (scelta del dev). In caso di errore del gestore, per esempio "Seleziona tutte" premuto per sbaglio, si interviene a mano sul DB, **solo da Administrator o sviluppatore, dopo un backup**:

```sql
BEGIN;

-- 1. controllo: la richiesta e le tappe validate da questa richiesta
SELECT id, user_id, layer_id, status, decided_at, decided_by FROM certification_requests WHERE id = :id;
SELECT id, ec_track_id, validated_at FROM validated_ec_tracks WHERE certification_request_id = :id;

-- 2a. togliere solo alcune tappe sbagliate
DELETE FROM validated_ec_tracks WHERE certification_request_id = :id AND ec_track_id IN (:ec_track_ids);

-- 2b. oppure riaprire del tutto la richiesta (tutte le sue tappe tornano non validate)
DELETE FROM validated_ec_tracks WHERE certification_request_id = :id;
UPDATE certification_requests
   SET status = 'pending', decided_at = NULL, decided_by = NULL, decision_note = NULL, updated_at = now()
 WHERE id = :id;

COMMIT;
```

Avvertenze:

- La mail di esito già inviata non si annulla: va avvisato il camminatore a mano.
- La riapertura (2b) fallisce per l'indice unico parziale `certification_requests_one_pending_per_user_layer` se nel frattempo il camminatore ha inviato un'altra richiesta `pending` per lo stesso cammino. In quel caso conviene correggere le tappe (2a) e lasciare decidere la richiesta nuova.
- Le tappe cancellate tornano selezionabili in una nuova decisione.

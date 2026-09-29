> Ticket: oc:8653

# Passaporto camminatore: backend della validazione della credenziale cartacea (camminiditalia)

## Cosa cambia

Il camminatore, dall'app, può inviare al gestore di un cammino una **richiesta di certificazione**: da 1 a 6 foto della credenziale cartacea timbrata, un numero seriale opzionale (o altre informazioni identificative) e l'accettazione del disclaimer. Il backend:

- salva la richiesta in un modello nuovo, con una migration custom del solo repo camminiditalia;
- espone due endpoint API per l'app: invio della richiesta e lettura del suo stato (`none` / `pending`);
- manda un'email al gestore del cammino per ogni nuova richiesta;
- mostra le richieste in una nuova sezione Nova **«Validazioni»**, dove ogni gestore (Validator) vede solo quelle dei propri cammini.

Approvazione, rifiuto e sblocco delle tappe **non** fanno parte di questo ciclo: le richieste nascono e restano `pending`.

## Perché

Il frontend del passaporto (oc:8166) è già pronto e lavora su un'API mockata. Allo scrum del 28/09 si è deciso di partire dalla credenziale **cartacea** perché è un flusso manuale, propedeutico agli step successivi, e permette di rimandare la logica di assegnazione e sblocco delle tappe (Giuseppe: *«questo step non vorrei mettere la logica decisionale»*). Serve un'API custom dedicata, non il riuso degli UGC/EcPoi.

## Requisiti

### Modello e dati
- [ ] Nuovo modello `App\Models\CertificationRequest` con tabella `certification_requests`: `user_id` (FK users), `layer_id` (FK layers), `status` (stringa, default `pending`; oggi l'unico valore scritto), `serial_number` (`text`, nullable), `disclaimer_accepted_at` (timestamp), `app_id` (FK apps, nullable, preso dal layer: serve al `MediaObserver` del package per il percorso delle foto), timestamp standard (`created_at` = data di invio).
- [ ] Indice unico **parziale** su `(user_id, layer_id) WHERE status = 'pending'`: al massimo una richiesta in attesa per camminatore e cammino, anche con due invii quasi simultanei (per esempio un doppio tap).
- [ ] Le foto vanno nella **media library come gli UGC**: tabella `media`, collection `default`, disco di default della media library (`wmfe`). Scelta del dev per uniformità con gli UGC, accettando che un Validator possa vederle dalla risorsa Nova Media e il bug di `MediaController::destroy()` del package, come già vale per le foto UGC.
- [ ] FK `user_id` e `layer_id` con `cascadeOnDelete`. Observer locali sull'evento `deleting` di `User` e `Layer` cancellano prima le richieste collegate passando dal modello, così la media library rimuove media e file prima che il cascade tolga le righe (la cascade SQL da sola lascerebbe media orfani).
- [ ] La cancellazione di una `CertificationRequest`, da Nova o dagli observer, rimuove i suoi media e i relativi file.

### API (route custom nel repo principale, guard `auth:api` JWT)
- [ ] `POST /api/layer/{layer}/certification`, multipart:
  - `images[]` obbligatorio, da 1 a 6 file immagine (jpeg, png, webp, heic, heif; massimo **8 MB** ciascuno, quindi al massimo 48 MB, sotto i 50M di `post_max_size` di PHP);
  - `serial_number` opzionale, testo libero (massimo 255 caratteri);
  - `disclaimer_accepted` obbligatorio e accettato (`accepted`).
  - Risposta **201** `{ status: "pending", submitted_at }`.
- [ ] Se il camminatore ha già una richiesta `pending` per quel cammino: **409 Conflict**, nessuna scrittura e nessun file caricato. Il controllo avviene **prima** del caricamento dei file.
- [ ] Invio: la richiesta viene creata in una transazione DB (se scatta l'indice unico per due invii simultanei → 409, senza caricare nulla), poi le foto vengono caricate nella media library. Se un caricamento fallisce, la richiesta viene cancellata insieme ai media già creati e l'errore viene rilanciato. L'email al gestore parte solo a caricamento completato.
- [ ] Body oltre il limite di PHP (`PostTooLargeException`) sulle route API → **413** in JSON leggibile.
- [ ] Prima del rilascio: verificare che `client_max_body_size` di Nginx e del proxy in produzione ammetta almeno 50 MB.
- [ ] `GET /api/layer/{layer}/certification`: `{ status: "none" }` oppure `{ status: "pending", submitted_at }`, sempre e solo per l'utente autenticato.
- [ ] Utente non autenticato → 401; layer inesistente → 404; input non valido → 422.

### Email
- [ ] A ogni nuova richiesta parte un job in coda che manda un'email al proprietario del layer (`layers.user_id`, tramite `layerOwner()`), con la stessa regola degli UGC: se il layer non ha proprietario, ripiega su `info@camminiditalia.org`.
- [ ] L'email contiene il nome del cammino, il camminatore, la data e il **link al record in Nova**. Niente foto allegate né URL delle foto.

### Nova — sezione «Validazioni»
- [ ] Nuova risorsa Nova con etichetta «Validazioni»: lista e dettaglio con camminatore, cammino, numero seriale, stato, data di invio e foto visibili nel dettaglio tramite l'URL diretto del file, costruito solo dopo il controllo della policy `view`.
- [ ] Scoping: l'Administrator vede tutto; il Validator vede solo le richieste dei layer di cui è proprietario (`layers.user_id`); ogni altro ruolo non vede nulla.
- [ ] Policy registrata in `AppServiceProvider`: creazione e modifica vietate a tutti (una richiesta nasce solo dall'app); eliminazione consentita solo all'Administrator, con rimozione delle foto insieme al record (serve per le richieste di cancellazione GDPR).
- [ ] Etichette Nova tradotte in tutte le lingue del repo (`resources/lang/{it,en,fr,es,de}.json`).

### Test
- [ ] Test feature su API (201, 401, 404, 422, 409, 413, GET `none`/`pending`, isolamento fra utenti), invio atomico (fallimento dopo l'upload → nessuna riga e file cancellati), scoping Nova e policy per ruolo, invio del job e destinatari dell'email, cancellazione della richiesta, dell'utente e del layer con rimozione delle foto dal disco (con `Storage::fake`).

## Rischi

- **Foto con dati personali nella tabella `media` (accettato dal dev).** Una credenziale timbrata contiene di solito nome e cognome. Nella tabella `media` le foto sono raggiungibili da `GET /api/media/{id}` (pubblica, ID sequenziali), visibili a ogni Validator dalla risorsa Nova Media (bypass in `MediaPolicy`) e cancellabili da qualunque utente autenticato (`MediaController::destroy()` ignora `validateUser()`). In challenge si era scelta una tabella dedicata; dopo la review finale il dev ha preferito l'uniformità con gli UGC, per i quali questi limiti valgono già. Restano: URL delle foto nel dettaglio Nova solo a chi può vedere la richiesta, nessun URL nelle email.
- **Bucket `wmfe` leggibile pubblicamente (accettato dal dev, review finale).** La policy del bucket consente `GetObject` e `ListBucket` anonimi, quindi chi conosce o elenca il percorso può scaricare le foto: è la stessa esposizione delle foto UGC. La visibilità `private` e gli URL temporanei previsti in origine non avrebbero protetto nulla e sono stati tolti. Un disco privato dedicato resta un follow-up.
- **Richiesta sbagliata senza via d'uscita (accettato).** Se il camminatore invia foto illeggibili, riceve 409 a ogni nuovo invio e il GET risponde `pending` finché un Administrator non cancella il record da Nova. È un limite accettato di questo ciclo: nello step successivo il Validator potrà **rifiutare** la richiesta (mai eliminarla); la richiesta rifiutata resta nello storico e, grazie all'indice parziale su `pending`, il camminatore può inviarne una nuova.
- **Upload interrotto a metà (mitigato, rischio residuo accettato).** I file su S3 non partecipano alla transazione DB. Mitigazione: la richiesta viene creata in transazione, poi si caricano le foto nella media library; se un caricamento fallisce la richiesta viene cancellata insieme ai media già creati. Resta un caso non coperto: se il processo muore a metà caricamento (timeout, memoria) o la cancellazione di pulizia fallisce, rimane una richiesta `pending` con parte delle foto e senza email; è visibile in Nova e si risolve cancellandola. Durante il caricamento la richiesta è già visibile in Nova.
- **Limiti di upload (risolto in challenge).** PHP accetta al massimo 50M per body: limite per singolo file abbassato a 8 MB, 413 JSON leggibile, verifica di Nginx in produzione prima del rilascio.
- **Cancellazione dell'account o del layer (risolto in challenge).** Senza gestione esplicita, `POST /api/auth/delete` fallirebbe (FK restrict) o lascerebbe foto orfane su S3 (FK cascade). Mitigazione: cascade più observer `deleting` che rimuovono prima i file.
- **Disclaimer GDPR ancora segnaposto.** Il testo legale è un prerequisito dichiarato nel ticket. Il backend registra solo l'accettazione (`disclaimer_accepted_at`), non la versione del testo accettato.
- **Contratto frontend da riallineare.** Il frontend oggi manda `notes`; il backend accetterà solo `serial_number`, e un campo sconosciuto viene scartato in silenzio. È accettabile perché il frontend è ancora su mock, ma i due lavori vanno rilasciati insieme.
- **Route API nel repo principale.** Oggi tutte le route API vivono in `wm-package/routes/api.php` e il repo principale non ha un `routes/api.php`. Registrarne uno nuovo non deve sovrascrivere né duplicare il prefisso `api/layer` già usato dal package per i preferiti.
- **Container e submodule divergenti.** I container montano `../wm-package`, che differisce dal submodule: il lavoro è tutto nel repo principale, ma i test girano contro la copia montata.

## Out of scope

- `GET /api/layer/{layer}/progress` (dipende da oc:8165, non ancora implementato).
- Approvazione e rifiuto delle richieste, action di sblocco delle tappe, notifiche al camminatore sull'esito.
- Rifiuto da parte del Validator (step successivo: il Validator potrà rifiutare, mai eliminare).
- Testo legale del disclaimer e suo versionamento.
- Modifiche al frontend, da fare in un lavoro separato su wm-types, wm-core e webmapp-app: rinomina `notes` → `serial_number` nel tipo e nel multipart, rinomina delle chiavi i18n `passport.form.notes` / `passport.form.notesHint`, sostituzione del mock in `passport.service.ts`, rimozione di `wmPassportMock`.
- Qualsiasi modifica al submodule `wm-package`.

## Moduli toccati

Tutti nel repo principale `camminiditalia`:

- `database/migrations/<timestamp>_create_certification_requests_table.php` (nuovo)
- `app/Models/CertificationRequest.php` (nuovo, `HasMedia`)
- `app/Observers/` — observer `deleting` su `User` e `Layer` (nuovi o estensione di `LayerObserver` esistente), registrati in `AppServiceProvider`
- `routes/api.php` (nuovo) + registrazione in `bootstrap/app.php` o in un service provider
- `app/Http/Controllers/Api/CertificationRequestController.php`, `app/Http/Requests/StoreCertificationRequestRequest.php` (nuovi)
- `app/Services/CertificationRequestService.php`, `app/Exceptions/PendingCertificationRequestExistsException.php` (nuovi)
- `config/camminiditalia.php` (indirizzo email di ripiego)
- `app/Jobs/SendCertificationRequestMailJob.php`, `app/Mail/NewCertificationRequestMail.php`, `resources/views/emails/new-certification-request.blade.php` (nuovi)
- `app/Nova/CertificationRequest.php` (nuovo), campo Nova per la galleria con gli URL dei media, eventuale voce di menu in `app/Providers/NovaServiceProvider.php`
- gestione `PostTooLargeException` → 413 JSON in `bootstrap/app.php` (`withExceptions`)
- `app/Policies/CertificationRequestPolicy.php` (nuovo), registrazione in `app/Providers/AppServiceProvider.php`
- `resources/lang/{it,en,fr,es,de}.json`
- `tests/Feature/CertificationRequest*Test.php` (nuovi)

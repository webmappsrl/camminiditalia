> Ticket: oc:8653

# Notes — Passaporto camminatore: backend della validazione della credenziale cartacea

## Divergenze dal piano, task per task

### Task 1: Migration e modelli

- **La tabella `certification_request_images` e il modello `CertificationRequestImage` sono stati tolti**: le foto stanno nella tabella `media` della media library, come gli UGC (decisione del dev dopo la review finale, vedi «Foto nella media library» sotto). `CertificationRequest` implementa `HasMedia` (collection `default`).
- Aggiunta la colonna `app_id` (FK `apps`, nullable), valorizzata con l'`app_id` del layer: il `MediaObserver` del package la copia sul media e `WmfePathGenerator` la usa per il percorso dello shard. Senza, il media riceverebbe `app_id = 1` con un warning nel log.
- Aggiunti i generics sulle relazioni e i `@property` per PHPStan (review finale, I2).

### Task 2: Service di invio atomico

- Ordine cambiato con il passaggio alla media library: pre-check del 409, poi creazione della richiesta in transazione (una violazione dell'indice unico diventa 409 senza caricare nulla), poi caricamento delle foto con `addMedia()`; se un caricamento fallisce la richiesta viene cancellata insieme ai media già creati e l'errore originale viene rilanciato. Il piano prevedeva il contrario (file prima, transazione dopo), pensato per file senza tabella `media`.
- Tolti `putFile`, il prefisso `certification-requests/<uuid>/` e `deleteUploadedFiles()`.
- `currentFor()` marcato `@phpstan-impure`.
- Il dispatch dell'email (Task 4) avviene solo dopo che tutte le foto sono state caricate.

### Task 3: API `certification` (route, controller, validazione, 413)

- Aggiunto `throttle:10,1` al gruppo di route: un endpoint che accetta fino a 48 MB per chiamata non era limitato (review finale, M1).
- Accettato anche il MIME `heif` oltre a `heic`: alcuni iPhone lo inviano così (review finale, M5).
- Le due chiavi i18n dei messaggi API sono state aggiunte qui invece che nel Task 5.

### Task 4: Email al gestore

- La vista mostra il numero di foto (`getMedia(...)->count()`), mai URL o anteprime.
- Tre test in più rispetto al piano: oggetto dell'email con il nome del cammino, avviso «nessun gestore» quando il layer non ha owner, email del camminatore mostrata quando manca il nome.

### Task 5: Policy e risorsa Nova «Validazioni»

- Il campo Foto legge `getMedia('default')` e usa l'URL del media invece degli URL temporanei; il controllo `can('view')` prima di costruire gli URL resta.
- Aggiunti `editQuery` con lo stesso scoping (non richiesto dal piano) e `public static $with = ['user', 'layer', 'media']` contro le query N+1 sulla lista (review finale, M7).
- Il badge di stato mappa anche `approved`/`rejected`, solo per non far fallire Nova su uno stato sconosciuto; non hanno ancora etichette.

### Task 6: Pulizia file alla cancellazione di utente e layer

- L'observer resta necessario anche con la media library: la cascade SQL da `users`/`layers` cancellerebbe le righe senza passare da Eloquent, lasciando media e file orfani.
- I test usano un vero token JWT (`JWTAuth::fromUser(\App\Models\User)`) invece di `auth('api')->login()`: il secondo metteva l'utente in cache sulla guard e quindi non verificava davvero il bearer token.
- Aggiunto un test sulla cancellazione tramite `App\Models\User` (la strada di Nova).

### Task 7: Verifica finale

- Eseguiti: suite completa, Pint e PHPStan (0 errori, baseline intatto) dopo ogni giro di modifiche; l'ultimo esito è nella sezione «Decisioni» e nel ticket.
- Non eseguiti: prova end-to-end con curl e JWT reale, controllo in Mailpit, verifica in Nova con login reali. Restano da fare a mano (vedi Follow-up).

## Bug trovati

- **Bucket `wmfe` pubblico in lettura** (review finale): la policy del bucket locale consente `GetObject` e `ListBucket` anonimi. La visibilità `private` e gli URL temporanei del piano originale non avrebbero protetto le foto. Vale anche per le foto UGC.
- **`Storage::delete()` non lancia su `wmfe`**: con `throw => false` restituisce `false`, quindi il `catch` previsto dal piano non avrebbe mai registrato un errore S3. Corretto, poi superato dal passaggio alla media library.
- **Test senza `app_id`**: dopo il passaggio alla media library, i test che creavano richieste senza `app_id` fallivano sulla FK `media_app_id_foreign` (il `MediaObserver` mette `app_id = 1`, app inesistente nel DB di test). Corretti i test; in produzione il service imposta sempre l'`app_id` del layer.
- **Docker bloccato** durante la verifica del passaggio alla media library: serviti il riavvio del Mac e un rollback manuale del DB di test.
- **Test `api/auth/delete` falsamente verdi con `auth('api')->login()`**: vedi Task 6.
- `JWT_SECRET` di `.env.testing` è troppo corto per firmare davvero un token HS256: i test lo sovrascrivono con `config()`. Non corretto in questo ciclo.

## Decisioni

- **Tag Orchestrator:** nessun tag associato né creato. Il dev ha scelto di lasciarli per dopo (proposto `camminiditalia`, id 495).
- **Stima saltata:** `wm-estimate` ha rifiutato di stimare senza `plan.md`, che nel workflow si scrive dopo la stima. Il dev ha scelto di non stimare. `estimated_hours` resta vuoto su Orchestrator.
- **Foto fuori dalla media library** (challenge): la tabella `media` del package espone `GET /api/media/{id}` senza autenticazione, dà accesso pieno ai Validator (`MediaPolicy::bypassRoles()`) e permette la cancellazione a qualunque utente autenticato (`MediaController::destroy()` ignora `validateUser()`). Le foto restano sullo stesso disco degli UGC (`wmfe`) come deciso dal dev, ma in una tabella dedicata.
- **Foto con la stessa esposizione degli UGC** (dopo la review finale): alla scoperta del bucket pubblico il dev ha scartato sia la modifica della policy di `wmfe` sia un disco privato dedicato. Tolti visibilità `private` e URL temporanei.
- **Foto nella media library** (dopo la review finale): verificato che la tabella figlia non serviva più (colonne `mime_type`/`size` mai lette, `disk` costante), il dev ha scelto di unificare con gli UGC mettendo le foto nella tabella `media`, accettando esplicitamente che un Validator possa vederle dalla risorsa Nova Media e il bug di `MediaController::destroy()` del package, come già per le foto UGC. Nomi invariati (`CertificationRequest`): valutati nomi con riferimento ai cammini, il dev ha preferito il nome generico.
- **`notes` → `serial_number`:** il nome `notes` era un'ipotesi del frontend (oc:8166); il contenuto è sempre stato il seriale. Rinominato ovunque su decisione del dev.
- **Chiavi i18n generiche** `Status`, `Photos`, `Route`: essendo globali, traducono anche testi già esistenti (badge «Stato» su UgcPoi, pannello di FeatureCollection, «Foto» nell'email UGC). Le traduzioni sono corrette; da sapere.

- **Cleanup dalla review formale (`wm-review-ticket`, 29/09, esito «approvato con riserve», nessun bloccante)**, applicati tutti su richiesta del dev:
  - throttle `10,1` solo sul POST (prima colpiva anche il GET di stato, con contatore condiviso per utente);
  - `->whereNumber('layer')`: un id non numerico dà 404 invece di un 500 SQL;
  - link Nova dell'email costruito con `Nova::path()` e `uriKey()`; lingua italiana impostata solo sul Mailable (`locale('it')`) invece che su tutto il processo;
  - campo «Camminatore»: link alla risorsa User solo per l'Administrator, testo semplice (nome ed email) per tutti gli altri, perché il Validator non può aprire gli utenti;
  - costanti `STATUS_APPROVED`/`STATUS_REJECTED`/`STATUS_NONE` e `MEDIA_COLLECTION`;
  - regola di visibilità in un solo punto (`scopeVisibleTo` / `isVisibleTo` sul modello), usata da policy e Nova;
  - indirizzo di ripiego in `config/camminiditalia.php` (`fallback_notification_email`, env `CAMMINIDITALIA_FALLBACK_NOTIFICATION_EMAIL`), solo per il job nuovo;
  - scope `pendingFor`, trait di test `FakesCertificationDisk`, commento corretto sulla doppia registrazione dell'observer.

## Follow-up

- **Test instabile pre-esistente**: `RecalculateLayerAttributesJobTest::handle_writes_filters_and_dispatches_config_regeneration` fallisce se la suite gira due volte entro 10 minuti: il lock `ShouldBeUnique` (600 s, su Redis) di `UpdateAppConfigJob` sopravvive tra un run e l'altro e gli id delle App si ripetono. Succede anche escludendo i test di questo ticket. Correzione possibile: rilasciare il lock nel `setUp` di quel test o nella base dei test. Un nostro test lasciava lo stesso lock ed è stato corretto.
- Duplicazione minore fra la formattazione «nome (email)» dell'email e quella del campo Nova.
- Rilievi della review sul passaggio alla media library, accettati o rimandati:
  - `GET /api/media/{id}` del package è pubblica: le foto sono raggiungibili contando gli ID dei media. Accettato dal dev insieme al passaggio alla media library (vale già per le foto UGC; con `ListBucket` anonimo i file sono comunque elencabili).
  - Se il processo muore a metà caricamento (timeout o memoria) o se la cancellazione di pulizia fallisce, resta una richiesta `pending` con parte delle foto e senza email al gestore: il camminatore riceve 409 finché un Administrator non la cancella da Nova. Visibile in Nova, rischio basso.
  - Manca un test esplicito «caricamento fallito → nessuna email» (il codice è corretto: rilancia prima del dispatch).
  - La cancellazione di un'App rimuove le righe `media` via cascade SQL senza cancellare i file (stesso comportamento degli UGC).

- Frontend (wm-types, wm-core, webmapp-app): rinominare `notes` → `serial_number` nel tipo, nel multipart e nelle chiavi i18n `passport.form.notes*`; sostituire il mock in `passport.service.ts`; togliere `wmPassportMock`. Il rilascio va coordinato col backend.
- Prima del rilascio: Nginx e proxy di produzione con `client_max_body_size` ≥ 50 MB; PHP di produzione con `upload_max_filesize` ≥ 8M e `max_file_uploads` ≥ 6.
- Verifica manuale in Nova con login reali (Administrator e Validator) e prova end-to-end con un JWT reale: non eseguite in questo ciclo.
- Disco privato dedicato per le foto delle credenziali, insieme al testo legale del disclaimer GDPR e al suo versionamento.
- Step successivo: il Validator potrà rifiutare (mai eliminare) le richieste; chiude il caso «richiesta sbagliata senza via d'uscita». Aggiungere allora le etichette di `approved`/`rejected`.
- Bug del package fuori scope: `GET /api/media/{id}` pubblica, `MediaController::destroy()` che ignora `validateUser()`, `ListBucket` anonimo su `wmfe`. Valgono anche per le foto UGC.

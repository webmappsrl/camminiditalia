# Passaporto camminatore: richieste di certificazione della credenziale cartacea

## Come funziona oggi

Il camminatore chiede dall'app al gestore di un cammino (layer) di certificare la credenziale cartacea timbrata (`App\Models\CertificationRequest`, API in `routes/api.php`, sezione Nova «Validazioni»). In questo ciclo la richiesta nasce e resta `pending`: approvazione, rifiuto e sblocco delle tappe arrivano in uno step successivo.

Vincoli che il codice da solo non spiega:
- l'indice unico su `(user_id, layer_id)` è **parziale** (`WHERE status = 'pending'`): blocca un secondo invio solo finché la richiesta è in attesa, così una richiesta rifiutata in futuro non impedirà di riprovare;
- `STATUS_NONE` non è uno stato salvato nel DB: esiste solo nella risposta del GET quando non c'è una richiesta in attesa;
- `routes/api.php` è il primo file di route API del repo principale: tutte le altre route API vivono in `wm-package`;
- l'email al gestore non contiene mai URL o anteprime delle foto, e il campo «Camminatore» in Nova è un link solo per l'Administrator, perché il Validator non può aprire la risorsa User.

## Perché così

- **Foto nella media library come gli UGC** (oc:8653): scelta del dev per uniformità, dopo aver scartato una tabella dedicata. Si accettano gli stessi limiti delle foto UGC: `GET /api/media/{id}` pubblica, Validator che vedono tutti i media dalla risorsa Nova Media, bug di `MediaController::destroy()` del package, bucket `wmfe` leggibile ed elencabile pubblicamente.
- **`app_id` sulla richiesta, preso dal layer** (oc:8653): il `MediaObserver` del package copia `app_id` dal modello padre e `WmfePathGenerator` lo usa per il percorso. Senza, il media riceve `app_id = 1` con un warning, e su un DB senza App 1 (come quello di test) l'insert fallisce sulla FK `media_app_id_foreign`: anche nei test le richieste vanno create con l'`app_id` di un'App esistente. Un modello che riceve media e non è un `GeometryModel` ottiene una geometria di default (un punto a Pisa).
- **Richiesta creata prima delle foto** (oc:8653): con la media library il record deve esistere prima di `addMedia()`; se un caricamento fallisce, la richiesta viene cancellata con i media già creati, e l'email parte solo a caricamento riuscito.
- **Observer di pulizia su User e Layer** (oc:8653): la cascade SQL da `users`/`layers` cancella le righe senza passare da Eloquent e lascerebbe media e file orfani; l'observer cancella prima le richieste tramite il modello.
- **Rinvii allo step successivo** (oc:8653, scrum del 28/09): nessuna logica decisionale ora; il Validator potrà **rifiutare** ma mai eliminare. Oggi una richiesta sbagliata si sblocca solo con la cancellazione da parte dell'Administrator.
- **Rischio residuo accettato** (oc:8653): se il processo muore a metà caricamento resta una richiesta `pending` incompleta, visibile in Nova, che blocca nuovi invii finché non viene cancellata.

## Come ci siamo arrivati

- **Tabella dedicata `certification_request_images` con file in un prefisso privato e URL temporanei** (oc:8653, superata): scelta in challenge per tenere le foto fuori dalla tabella `media`. Abbandonata quando la review finale ha mostrato che il bucket `wmfe` è pubblico (`GetObject` e `ListBucket` anonimi, quindi `private` e URL temporanei non proteggevano nulla), poi sostituita dalla media library su scelta del dev.
- **Campo `notes`** (oc:8653, superato): era un'ipotesi del frontend (oc:8166) per il testo «Seriale o altre informazioni»; rinominato `serial_number` ovunque, frontend compreso.
- **Endpoint `GET /api/layer/{layer}/progress`** (oc:8653): escluso da questo ciclo, dipende da oc:8165 (calcolo delle tappe percorse), non ancora implementato.

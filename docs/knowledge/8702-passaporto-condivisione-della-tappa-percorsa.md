# Passaporto: condivisione della tappa percorsa

## Come funziona oggi

- **`GET /api/layer/{layer}/progress`** aggiunge a ogni tappa `ref`, `from`, `to`, `ascent`,
  `descent`, `image` (miniatura della prima media, ordinata come in `toSearchableArray()`) e
  `shareable: true` sulle tappe validate per l'utente. I dati vengono da un'unica derivazione,
  `StageProgressService::stageDetails()`, usata anche dall'immagine. La query carica solo `id`,
  `properties` e `osmid`, senza geometria.
- **`POST /api/layer/{layer}/stage/{track}/share-image`** (autenticato, `throttle:10,1`): 404 se la
  tappa non è del cammino, 403 se non è validata per l'utente. Lingua da `Accept-Language`
  (`StageShareLocale`, `pr` → `pt`, ripiego `it`). Restituisce `image_url` e `share_url`.
- **Tabella `passport_stage_shares`**, una riga per (utente, tappa) con `uuid` generato, `snapshot`
  JSON e media `share_image` di tipo `singleFile`.
- **Cache:** se l'immagine esiste e l'impronta non è cambiata, l'endpoint restituisce gli URL senza
  ridisegnare e aggiorna solo `snapshot.shared_at`. L'impronta comprende cammino e tappa
  (`updated_at`), numero e ultimo aggiornamento delle tappe del cammino, media del logo del cammino
  e dell'icona dell'App, lingua, costanti di layout, contenuto di sfondo, font e file di lingua, e
  `StageShareLayout::VERSION`.
- **Immagine 1080×1920** (`StageShareImageService`, con `StageShareIcons`, `StageShareText` e
  `StageShareLayout`): sfondo, logo del cammino se c'è, nome del cammino, mappa disegnata con
  `MapRenderService::renderLayers` di `wm-package` (tutto il cammino in rosso, la tappa in giallo,
  marker di partenza e arrivo, margine del 30%), «Tappa {ref}» o il nome della tappa, griglia di
  partenza, arrivo, lunghezza e dislivello centrata come blocco, logo di Cammini d'Italia preso
  dall'icona dell'App. Mai il tempo. Fonte di verità per il disegno della mappa:
  `wm-package/src/Services/Models/StoryShare/MapRenderService.php`.
- **Pagina pubblica `GET /share/passport-stage/{uuid}`** (`whereUuid`, `noindex`, Open Graph):
  mostra solo lo snapshot. Nessun dato dell'utente, nessun link all'app.
- Cancellare utente, cammino o tappa cancella le condivisioni via Eloquent
  (`PassportStageShareCleanupObserver`), così media e file non restano orfani.

## Perché così

- **Endpoint qui e non in `wm-package`** (oc:8702): sapere se una tappa è percorsa è logica del
  passaporto, che esiste solo in questo repo; il disegno della mappa è generico e sta in
  `wm-package`.
- **Una tabella propria** (oc:8702): la tappa è una traccia del catalogo, senza un record
  dell'utente a cui agganciare immagine e snapshot, come invece accade per le UgcTrack.
- **Una riga per (utente, tappa)** (oc:8702): ricondividere aggiorna la stessa immagine e lo stesso
  link, invece di creare righe e file orfani.
- **Niente tempo nell'immagine** (oc:8702): per le tappe validate con la credenziale cartacea non
  esiste; arriverà con la validazione da GPS (oc:8165).
- **`ref` o nome della tappa** (oc:8702): il backend non conosce l'ordine di percorrenza; `ref` è il
  dato che il gestore controlla. Un `ref` che contiene già «Tappa» viene normalizzato.
- **Impronta calcolata, non versione a mano** (oc:8702): cambiare una misura, lo sfondo, un font o
  una traduzione rinnova la cache da solo. `VERSION` va alzata solo se cambia il codice che disegna,
  compreso `MapRenderService` di `wm-package`.

## Come ci siamo arrivati

- **Riusare `share-story-image` di `wm-package` senza modificarlo** (oc:8702, scartato): il renderer
  accettava solo una UgcTrack e aveva i metodi privati; serviva un metodo generico a più layer.
- **Link «scarica l'app» nella pagina pubblica** (oc:8702, tolto): il segnaposto portava al login
  di Nova; il developer ha scelto solo immagine e dati.
- **Margine compensato in questo repo** (oc:8702, superato): `STAGE_FOCUS_EXTRA_MARGIN` correggeva
  il 15% privato di `padBbox`; ora `renderLayers` riceve il margine come parametro.

## Limiti noti

- Le cancellazioni in blocco da query builder e le cascate del database (es. cancellare un'App)
  non passano dall'observer: lì media e file restano.
- Una tappa in più cammini usa una sola riga: la pagina pubblica prende il cammino dell'ultima
  condivisione.

# Passaporto: condivisione della tappa percorsa e del cammino completato

## Come funziona oggi

- **`GET /api/layer/{layer}/progress`** aggiunge a ogni tappa `ref`, `from`, `to`, `ascent`,
  `descent`, `image` (miniatura della prima media, ordinata come in `toSearchableArray()`) e
  `shareable: true` sulle tappe validate per l'utente. I dati vengono da un'unica derivazione,
  `StageProgressService::stageDetails()`, usata anche dall'immagine. La query carica solo `id`,
  `properties` e `osmid`, senza geometria.
- **`POST /api/layer/{layer}/stage/{track}/share-image`** (autenticato, `throttle:10,1`): 404 se la
  tappa non è del cammino, 403 se non è validata per l'utente. Lingua da `Accept-Language`
  (`StageShareLocale`, `pr` → `pt`, ripiego `it`). Restituisce `image_url` e `share_url`.
- **`POST /api/layer/{layer}/share-image`** (oc:8703, autenticato, rate limiter con nome
  `passport-route-share`, separato da `throttle:10,1`): immagine del **cammino completato**, 403 se
  `progressFor()` non lo dà completato (anche con zero tappe). Stessa lingua e stesso contratto.
- **Tabella polimorfica `passport_shares`** (oc:8703, prima `passport_stage_shares`): una riga per
  (utente, cosa condivisa), dove `shareable` è la tappa (`EcTrack`) o il cammino (`Layer`), con
  `uuid` generato, `layer_id`, `snapshot` JSON e media `share_image` di tipo `singleFile`. Modello
  `PassportShare` (`forUser()`, `isRoute()`).
- **Cache** (`PassportShareStore`, oc:8703): se l'immagine esiste e l'impronta non è cambiata,
  l'endpoint restituisce gli URL senza ridisegnare e aggiorna solo `snapshot.shared_at`. L'impronta
  della tappa comprende cammino e tappa
  (`updated_at`), numero e ultimo aggiornamento delle tappe del cammino, media del logo del cammino
  e dell'icona dell'App, lingua, costanti di layout, contenuto di sfondo, font e file di lingua, e
  `StageShareLayout::VERSION`. Quella del cammino non ha la tappa ma ha i dati dell'utente disegnati
  (data della validazione più recente, tappe, km, uscite) e `RouteShareImageService::signature()`,
  che comprende la firma della tappa, le proprie costanti, quelle di `RouteShareIcons` e
  `passport_route_share.php`.
- **Immagine 1080×1920** (`StageShareImageService`, con `StageShareIcons`, `StageShareText` e
  `StageShareLayout`): sfondo, logo del cammino se c'è, nome del cammino, mappa disegnata con
  `MapRenderService::renderLayers` di `wm-package` (tutto il cammino in rosso, la tappa in giallo,
  marker di partenza e arrivo, margine del 30%), «Tappa {ref}» o il nome della tappa, griglia di
  partenza, arrivo, lunghezza e dislivello centrata come blocco, logo di Cammini d'Italia preso
  dall'icona dell'App. Mai il tempo. Fonte di verità per il disegno della mappa:
  `wm-package/src/Services/Models/StoryShare/MapRenderService.php`. Il codice comune alle due
  immagini (loghi, mappa incorniciata, griglia, traduzioni) sta in `PassportShareCommon`.
- **Immagine del cammino** (oc:8703, `RouteShareImageService`, vista 7 del wireframe): cammino in
  rosso con un pallino verde a ogni cambio di tappa, «Tappa {ref}» a metà di ogni tappa (parametro
  `labels` di `renderLayers`; omesse oltre 20 tappe, quelle sovrapposte saltate), margine del 3%,
  «Cammino completato», griglia con data, tappe, lunghezza (omessa se una tappa vale 0) e uscite
  (solo con tutte le tappe GPS). Date e giorni nel fuso `Europe/Rome`.
- **Pagina pubblica `GET /share/passport/{uuid}`** (oc:8703, prima `/share/passport-stage/{uuid}`;
  `whereUuid`, `noindex`, Open Graph), per tappa e cammino con la vista `share/passport`: mostra
  solo lo snapshot. Nessun dato dell'utente, nessun link all'app.
- Cancellare utente, cammino o tappa cancella le condivisioni via Eloquent
  (`PassportShareCleanupObserver`), così media e file non restano orfani.

## Perché così

- **Endpoint qui e non in `wm-package`** (oc:8702): sapere se una tappa è percorsa è logica del
  passaporto, che esiste solo in questo repo; il disegno della mappa è generico e sta in
  `wm-package`.
- **Una tabella propria** (oc:8702, estesa da oc:8703 alla tabella polimorfica qui sotto): la tappa
  è una traccia del catalogo, senza un record dell'utente a cui agganciare immagine e snapshot,
  come invece accade per le UgcTrack.
- **Una sola tabella polimorfica per tappa e cammino** (oc:8703, scelta del dev): niente tabelle
  in più per ogni cosa condivisibile; il completamento del cammino si calcola e non ha una riga
  propria a cui agganciare l'immagine.
- **Etichette del cammino in un file a parte** (oc:8703): `passport_share.php` entra nella firma del
  layout della tappa, e cambiarlo rigenererebbe tutte le immagini delle tappe.
- **Firma del layout della tappa fissata da un test** (oc:8703): l'estrazione di
  `PassportShareCommon` non doveva cambiarla (`StageShareImageRegressionTest`), altrimenti si
  rigenererebbero tutte le immagini salvate.
- **Una riga per (utente, cosa condivisa)** (oc:8702, oc:8703): ricondividere aggiorna la stessa immagine e lo stesso
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
- **Niente tabelle e niente pagina pubblica, link = PNG** (oc:8703, scartato): toglieva l'anteprima
  ricca del link su WhatsApp e simili.
- **Link firmato con i dati nell'URL** (oc:8703, scartato): stessa anteprima senza tabella, ma link
  lungo, non ritirabile e con i dati in chiaro.
- **Md5 del PNG della tappa come test permanente** (oc:8703, tolto): durante l'estrazione ha
  dimostrato che l'immagine restava identica byte per byte, ma l'md5 dipende da GD, FreeType e
  zlib e in CI non coincide con il Docker locale.

## Limiti noti

- Le cancellazioni in blocco da query builder e le cascate del database (es. cancellare un'App)
  non passano dall'observer: lì media e file restano. Con la tabella polimorfica non c'è più la
  foreign key sulla tappa, quindi anche la riga resta.
- Le immagini non scadono: restano finché non cambiano i dati o si cancella il proprietario.
- I km del cammino comprendono le varianti (da rivedere con oc:8165).
- Una tappa in più cammini usa una sola riga: la pagina pubblica prende il cammino dell'ultima
  condivisione.

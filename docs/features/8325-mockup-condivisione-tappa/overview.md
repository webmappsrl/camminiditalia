> Ticket: oc:8325

# Redesign immagine condivisione tappa (social share)

## Cosa cambia

Il generatore server-side dell'immagine di condivisione social di una tappa registrata (`UgcTrack`, oc:8183) viene ridisegnato **solo per camminiditalia**, per allinearsi al mockup fornito dal cliente (`mockup app.pdf`, ticket oc:8325):

1. **Sfondo (`share_frame`) resta unico per App**, come oggi — nessun frame separato per Layer.
2. **Logo del layer** (o dell'App, se il layer non ha un logo caricato) disegnato **sopra** il frame esistente, senza badge di sfondo aggiuntivo (niente `background-color` bianco translucido + `box-shadow` disegnati via codice attorno al logo) — il logo viene inserito così com'è, in tutte le occorrenze in cui compare nell'immagine (header principale + eventuale badge generico "Cammini d'Italia" in fondo, se previsto dal frame/mockup).
3. **Titolo** = nome del layer (cammino).
4. **"Tappa NN" + Arrivo/Partenza**: nuove informazioni derivate dall'EcTrack (tappa ufficiale) più vicino geometricamente alla traccia registrata dall'utente, tra quelli associati al layer.
5. **Card mappa**: invariata (bordo arrotondato colore accent, già esistente).
6. **Statistiche (Tempo/Lunghezza/Dislivello)**: calcolate come oggi (`TrackStatsService`, dati live della registrazione dell'utente — non i dati statici della tappa ufficiale), ma ridisegnate con icone su sfondo chiaro invece del pannello scuro opaco attuale.

**Per tutti gli altri consumer di `wm-package`** (maphub, osm2cai2, ecc.) il comportamento resta esattamente quello attuale (sfondo scuro generico o `share_frame` App-level, nessun logo layer, nessuna tappa) — nessuna modifica visibile per loro.

## Perché

Il cliente (ticket oc:8325, Help desk) ha condiviso via Drive un mockup con il design desiderato per l'immagine di condivisione. Il design attuale (oc:8183) è generico per-App e non riflette l'identità visiva del singolo cammino che l'utente sta percorrendo — obiettivo di questo ciclo è rendere l'immagine condivisa riconoscibile come "tappa di un cammino specifico", non genericamente "un percorso in Cammini d'Italia".

## Requisiti

- [ ] Lo sfondo (`share_frame`) resta unico per App, invariato rispetto a oggi.
- [ ] Il logo mostrato sopra il frame è quello del Layer (media collection `logo`, già esistente su `Layer`) se presente, altrimenti quello dell'App — **oggi nessun Layer ha un logo caricato (0/118)**, quindi in pratica ogni condivisione userà l'icona dell'App finché gli admin non caricano i loghi dei cammini.
- [ ] Il logo (in tutte le sue occorrenze nell'immagine) viene disegnato senza badge/sfondo/ombra aggiuntivi generati via codice.
- [ ] Titolo = nome del Layer. Se `properties->layer_id` è assente, non numerico, o il Layer non è risolvibile a DB, Titolo e Logo ricadono sul comportamento generico attuale di oc:8183 (icona/nome dell'App) — nessuna eccezione, nessun campo vuoto.
- [ ] Viene mostrato "Tappa NN" + nome del punto di Arrivo e di Partenza, risolti dall'EcTrack (tappa ufficiale) del layer più vicino geometricamente al `UgcTrack` registrato (match spaziale server-side, non un ID inviato dal client — vedi Rischi).
- [ ] Il match è accettato solo entro una soglia di distanza esplicita e conservativa (da definire in plan.md con un valore concreto in metri, calibrato sui dati reali delle tappe camminiditalia — non un valore arbitrario scelto in corsa d'opera). Sopra soglia, o in caso di eccezione nella query di match, il match è considerato assente: **meglio omettere "Tappa NN" che mostrarne una sbagliata**, dato che un'immagine con dati di tappa errati condivisa pubblicamente non è correggibile a posteriori (vedi Rischi/Out of scope).
- [ ] Se non viene trovato nessun EcTrack entro soglia (es. registrazione libera non associata a nessuna tappa nota, o errore nella query di match), la riga "Tappa NN/Arrivo/Partenza" viene omessa, non mostrata vuota o rotta — e l'intera generazione dell'immagine non deve fallire (nessun 500) per un errore del solo match tappa.
- [ ] Tempo/Lunghezza/Dislivello restano calcolati dai dati live della registrazione utente (`TrackStatsService`, invariato), solo lo stile di disegno cambia (icone, non pannello scuro).
- [ ] Nessuna modifica di comportamento per gli altri consumer di `wm-package`.

## Rischi

- **0/118 Layer hanno oggi un logo caricato** — finché gli admin non caricano i loghi dei singoli cammini, il fallback all'icona App sarà l'esito quasi sempre attivo. Non blocca il rilascio (fallback previsto e voluto), ma va comunicato al cliente perché il nuovo design non sarà visibile finché non caricano i loghi.
- **Match spaziale EcTrack↔UgcTrack è nuovo e non garantito preciso al 100%**: nessun collegamento diretto (`ec_track_id`) viene oggi salvato né dal frontend né dal backend — confermato che il frontend non traccia nemmeno lato client quale EcTrack specifico l'utente sta percorrendo (nessun selettore `currentEcTrack` in `webmapp-app`, solo `currentEcLayer`). Il match si baserà su prossimità geometrica tra la traccia registrata e gli EcTrack del layer (stesso pattern già usato da `GeometryComputationService::getNearestToLonLat()`), con margine di errore su tappe adiacenti molto vicine tra loro o percorsi ibridi/scorciatoie. **Un match sbagliato produce un'immagine con nome tappa/arrivo/partenza errati, potenzialmente condivisa pubblicamente sui social col brand del cliente — non recuperabile a posteriori** (vedi Out of scope: nessuna rigenerazione retroattiva, nessun override manuale). Mitigato dalla soglia conservativa nei Requisiti (omettere piuttosto che sbagliare), ma un falso positivo entro soglia resta possibile.
- **Nessuna gestione esplicita del fallback Titolo/Logo quando manca `layer_id`**: se `properties->layer_id` è assente o non numerico (registrazione senza cammino selezionato in app, dato storico, o versione app che non lo invia), oggi solo "Tappa NN" ha un fallback previsto (omissione). Titolo e Logo devono ricadere sul comportamento generico attuale di oc:8183 (icona/nome App) quando il Layer non è risolvibile — da esplicitare in plan.md, non solo assumerlo implicitamente.
- **`compose()` di `StoryShareImageService` non riceve oggi né `Layer` né l'EcTrack risolto** (firma attuale: `App $app, InterventionImage $mapImage, array $stats`). Deciso di **non duplicare** il controller (risoluzione uuid/ownership/persistenza restano un unico punto di verità nel package): `compose()` viene esteso per accettare un contesto opzionale aggiuntivo (es. `?array $extraContext` con Layer/EcTrack risolto), il controller del package resta l'unico chiamante e l'unico responsabile di sicurezza/persistenza. Vedi `wm-package/docs/features/8325-mockup-condivisione-tappa/overview.md` per il dettaglio del nuovo contratto.
- **Nessuna validazione dimensioni/aspect-ratio sul logo del Layer**: `Layer::registerMediaCollections()` non ha regole di validazione sulla collection `logo` (a differenza di icon/splash su App, oc:8247). Il requisito "nessun badge/sfondo" presuppone un logo quadrato/trasparente ben proporzionato — un logo rettangolare o su sfondo bianco pieno produce un risultato visivamente rotto senza che nulla lo impedisca in upload.
- **Refactor di visibilità in `StoryShareImageService`** (wm-package): i metodi di disegno sono oggi tutti `private`, quindi non estendibili da una sottoclasse camminiditalia-specifica. Serve promuoverne alcuni a `protected` per permettere l'override mirato (solo header/logo) riusando il resto (mappa, statistiche, gradienti) invariato — è un cambio di visibilità non pericoloso (nessun cambio di comportamento per chi già usa la classe), ma tocca codice condiviso.

## Out of scope

- Caricamento reale dei loghi dei singoli cammini in Nova (attività di content, non di sviluppo).
- Modifiche al frontend (`webmapp-app`) — il match tappa avviene interamente server-side, nessun nuovo parametro da inviare dal client.
- Un meccanismo di override "manuale" per correggere un match spaziale errato (es. campo Nova per forzare la tappa su uno specifico `UgcTrack`) — non richiesto in questo ciclo.
- Rideterminazione a posteriori delle immagini già condivise prima di questo rilascio (nessuna rigenerazione retroattiva).

## Moduli toccati

**Repo principale (camminiditalia)** — logica specifica del progetto, nessuna duplicazione della logica di sicurezza/persistenza (decisione presa in fase di challenge, vedi Rischi):
- `app/Providers/AppServiceProvider.php` — binding nel container che sostituisce `ShareStoryImageController`/`StoryShareImageService` del package con le sottoclassi camminiditalia-specifiche (stesso pattern già usato per `LayerFeatureController` in oc:8089).
- Nuova classe locale (es. `app/Services/StoryShare/CamminiditaliaStoryShareImageService.php`) che estende `StoryShareImageService` del package, overridando solo il disegno di header/logo/tappa e leggendo `$extraContext` per i dati Layer/EcTrack.
- Nuova classe locale (es. `app/Http/Controllers/Api/CamminiditaliaShareStoryImageController.php`) che estende `ShareStoryImageController` del package, overridando **solo** il nuovo metodo protetto di calcolo `$extraContext` (risoluzione Layer da `properties->layer_id` + match spaziale EcTrack più vicino) — risoluzione uuid, ownership check, persistenza restano ereditati invariati dal package.

**Submodule wm-package** — logica generica riusabile, vedi `wm-package/docs/features/8325-mockup-condivisione-tappa/overview.md` per il dettaglio:
- `src/Services/Models/StoryShare/StoryShareImageService.php` — refactor visibilità (`private` → `protected`) + nuovo parametro `$extraContext` opzionale.
- `src/Http/Controllers/Api/ShareStoryImageController.php` — reso estendibile con nuovo punto di estensione protetto.
- `src/Services/GeometryComputationService.php` — nuovo metodo generico "EcTrack più vicino a una geometria, filtrato per lista di ID e soglia" (mirror di `getNearestToLonLat()`, riusabile da qualunque consumer).

Vedi anche `wm-package/docs/features/8325-mockup-condivisione-tappa/overview.md` per il dettaglio lato package.

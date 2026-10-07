> Ticket: oc:8703

# Passaporto: tab con cammini completati e in corso

Parte backend del lavoro. L'insieme è descritto in
`webmapp-app/docs/features/8703-passaporto-tab-con-cammini-completati-e-in-corso/overview.md`.

## Cosa cambia

Nuovo endpoint che genera l'**immagine di condivisione di un cammino completato** (vista 7 del
wireframe), sullo stesso schema della condivisione della tappa (oc:8702):

- `POST /api/layer/{layer}/share-image`, con `auth:api` e un limite di richieste proprio, risponde
  `{image_url, share_url}`.

- una pagina pubblica per il link condiviso, `/share/passport/{uuid}`.

Con l'occasione la condivisione della tappa (oc:8702, non ancora in produzione) si allinea: **una
sola tabella polimorfica `passport_shares`** per tappe e cammini, al posto di
`passport_stage_shares`, con un solo modello (`PassportShare`), una sola pagina pubblica e un solo
observer di pulizia. La migration di oc:8702 si modifica, senza aggiungerne una nuova (decisione
del dev, 07/10): in locale si fa prima il rollback.

L'elenco dei cammini dell'utente esiste già (`GET /api/passport`, oc:8676/oc:8701) e non cambia.

## Perché

Dal dettaglio di un cammino completato l'app offre «Condividi il traguardo». L'immagine si compone
lato server come per la tappa: stesso aspetto su app e webapp, nessun disegno lato client.

## Requisiti

- [ ] `POST /api/layer/{layer}/share-image` con un rate limiter **con nome proprio**: oggi
      `throttle:10,1` usa come chiave solo l'utente, quindi certificazione, condivisione della tappa
      e nuovo endpoint si consumerebbero le stesse 10 richieste al minuto.
- [ ] 403 se il cammino non risulta completato per l'utente
      secondo `StageProgressService` (stesso criterio di `completed` in `/progress`: tutte le tappe
      validate, calcolato e non salvato, come deciso allo scrum del 06/10); 200 con
      `{image_url, share_url}`; 500 con errore nel log se la composizione fallisce.
- [ ] Contenuto dell'immagine (vista 7): logo, nome del cammino, mappa del percorso completo,
      «Cammino completato 🎉», «Completato il» (data della validazione più recente), tappe «6/6»,
      lunghezza totale in km come somma delle distanze delle tappe, **omessa se una tappa vale 0**
      (`distanceSql` restituisce 0 quando il dato manca): stesso criterio della vista 6 nell'app.
      Le varianti contano nella somma (limite noto, si rivede con oc:8165).
- [ ] Mappa come la vista 7 (decisione del dev, 07/10): cammino in rosso semplice, un pallino verde
      a ogni cambio di tappa, l'etichetta «Tappa {ref}» a metà di ogni tappa che ha un `ref`
      (16 px; omesse oltre 20 tappe; un'etichetta che si sovrapporrebbe a un'altra si salta);
      margine attorno al cammino ridotto al 3%. Le etichette richiedono il nuovo parametro
      facoltativo `labels` di `MapRenderService::renderLayers()` in **wm-package**.
- [ ] **Uscite** nella griglia (icona calendario) solo se tutte le tappe sono validate col GPS:
      giorni distinti delle validazioni. Oggi non compaiono mai.
- [ ] Lingua dall'header `Accept-Language`, come per la tappa.
- [ ] Tabella `passport_shares` (migration di oc:8702 modificata e rinominata in
      `2026_10_05_100000_create_passport_shares_table.php`): `id`, `uuid` unico,
      `user_id`, `layer_id`, `shareable_type` + `shareable_id` (`EcTrack` per la tappa, `Layer` per
      il cammino), `snapshot` jsonb, timestamps; indice unico `(user_id, shareable_type,
      shareable_id)`. FK `cascadeOnDelete` su utente e layer.
- [ ] Cache con impronta dei dati (cammino, tappe, loghi, lingua, layout **e i dati dell'utente che
      finiscono nell'immagine**: data della validazione più recente, tappe, km): stessa impronta,
      stessa immagine, si aggiorna solo `shared_at`.
- [ ] Pagina pubblica `/share/passport/{uuid}` per tappe e cammini, con snapshot statico e OG tags,
      sulla vista di oc:8702; il vecchio `/share/passport-stage/{uuid}` sparisce (l'app usa lo
      `share_url` ricevuto).
- [ ] Un solo observer di pulizia: alla cancellazione di utente, layer o tappa cancella via Eloquent
      le condivisioni, con media e file.
- [ ] Test feature: 401 senza token, 403 cammino non completato (anche con zero tappe), 200
      completato, riuso della cache, impronta che cambia con la data di validazione, limite di
      richieste separato da quello della tappa, pulizia alla cancellazione di utente e layer, pagina
      pubblica. I test della tappa si adattano al modello unico.

## Rischi

- **Completamento calcolato.** Se il cammino aggiunge una tappa dopo il completamento, l'utente
  non risulta più completato e l'endpoint risponde 403. È la conseguenza voluta della scelta
  «calcolato, non scritto», in attesa della risposta del cliente.
- **Rilascio prima dell'app.** Una build negli store che chiama l'endpoint non si ritira: questo
  backend va in produzione prima della build dell'app e del deploy web di camminiditalia, e da
  quel momento il contratto `{image_url, share_url}` non si può più rompere.
- **Costo della composizione** (tile e GD, secondi di CPU): mitigato da throttle e cache, come per
  la tappa.
- **Migration modificata e rinominata.** Chi ha già lanciato la migration di oc:8702
  (`2026_10_05_100000_create_passport_stage_shares_table`) deve farne il rollback prima di
  aggiornare il codice, poi `migrate`: altrimenti resta la tabella vecchia.

## Out of scope

- Salvare lo stato «completato» in una tabella: per ora si calcola.
- Una scadenza delle immagini di condivisione: oggi non esiste né qui né per gli UGC.
- I km nell'elenco di `/api/passport`.

## Moduli toccati

- `wm-package/src/Services/Models/StoryShare/MapRenderService.php` — parametro `labels` (branch
  `feature/oc-8703-passaporto-tab-cammini` nel submodule, con la sua PR)

- `routes/api.php`, `routes/web.php`
- `database/migrations/2026_10_05_100000_create_passport_shares_table.php` — la migration di oc:8702
  (`…_create_passport_stage_shares_table.php`) modificata e rinominata
- `app/Models/PassportShare.php` — al posto di `PassportStageShare`
- `app/Http/Controllers/Api/` — nuovo controller del cammino, `PassportStageShareController` adattato
- `app/Http/Controllers/PassportSharePageController.php` — al posto della pagina della tappa
- `app/Observers/PassportShareCleanupObserver.php` — al posto di quello della tappa
- `app/Services/PassportShare/` — composizione dell'immagine del cammino, codice comune
- `tests/Feature/` — test del cammino, test della tappa adattati
- `docs/knowledge/8702-passaporto-condivisione-della-tappa-percorsa.md` — da aggiornare

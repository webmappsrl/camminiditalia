> Ticket: oc:8314

# Modalità auto/manuale del layer non persistita lato backend

## Cosa cambia

Il passaggio da modalità automatica a manuale nel campo Nova `LayerFeatures`
(pannelli "Ec Tracks" / "Ec Pois" della resource Layer) viene persistito sul
layer in `configuration->track_mode` / `configuration->poi_mode`. Dopo il fix:

- cliccando il toggle "manuale" il layer passa **immediatamente** in manuale
  lato backend, con la selezione già presente in auto come punto di partenza;
- ricaricando la pagina il toggle riflette la modalità reale del layer, non più
  il default `auto`;
- il ritorno in automatico scrive esplicitamente `'auto'` invece di affidarsi al
  default implicito, rendendo il flag auditabile dal DB.

Questo repo contiene una modifica minima: l'override locale di `sync()` —
**l'unico** controller che serve la route su camminiditalia — invoca il nuovo
metodo `persistMode()` del package. Nessuna logica di persistenza duplicata qui.
Insieme alla chiamata, questo repo ospita **tutti** i test del fix.
Frontend, controller base e implementazione di `persistMode()` stanno in
`wm-package` (vedi overview omonimo in quel repo).

## Perché

Bug riprodotto durante i test di oc:8311. I metodi `Layer::setTrackMode()` e
`Layer::setPoiMode()` (`wm-package/src/Models/Layer.php:158,171`) esistono ma non
sono chiamati da nessun punto del codice: sono metodi morti. Nessuna delle due
implementazioni di `sync()` — né quella del package né l'override locale
introdotto da oc:8311 — persiste la modalità.

Conseguenza: l'utente lavora e salva in manuale, ma `configuration` resta vuota,
il default `?? 'auto'` prevale e al reload la UI mostra "auto". Il lavoro svolto
non viene perso (il pivot `layerables` è salvato correttamente) ma la modalità sì,
e l'utente non ha modo di capire in quale stato sia il layer.

Verificato sul DB locale (dump di produzione): **tutti i 118 layer hanno
`configuration` vuota** — il flag non è mai stato scritto da nessuno.

## Requisiti

- [ ] `POST /nova-vendor/layer-features/sync/{layerId}` accetta un flag `manual`
      (boolean, opzionale) accanto al già presente `auto`, mutuamente esclusivi
      via `prohibits` (coerente con la validazione del package)
- [ ] L'override locale di `sync()` **non duplica** la logica di persistenza:
      invoca `persistMode()` ereditato dal package (una sola chiamata)
- [ ] Con `manual: true` la modalità persistita è `manual` sulla chiave corretta:
      `track_mode` per la relazione `ecTracks`, `poi_mode` per `ecPois`
- [ ] Con `auto: true` la modalità persistita è **esplicitamente** `'auto'`
      sulla stessa chiave, invece di lasciare `configuration` intatta
- [ ] Una chiamata senza né `auto` né `manual` non modifica la modalità
      (retrocompatibilità: nessun client esistente viene rotto)
- [ ] Il passaggio a manuale **non svuota** il pivot `layerables`: gli EC già
      associati in automatico restano associati e costituiscono l'init del manuale
- [ ] La scrittura della modalità preserva le altre chiavi eventualmente presenti
      in `configuration` (merge, non sostituzione)
- [ ] Un layer con `configuration` `NULL` (stato attuale di tutti i layer in
      produzione) accetta la prima scrittura senza errori
- [ ] Il filtro di ownership introdotto da oc:8311 resta invariato: la modalità si
      persiste solo dopo il controllo `403` su proprietario/Administrator
- [ ] `[UX]` La modalità mostrata nel toggle al caricamento della pagina
      corrisponde sempre alla modalità persistita sul layer
- [ ] `[Aggiuntivo, emerso in verifica]` Una richiesta `auto: true` per un layer
      il cui proprietario (`user_id` risolto: `user_id` del layer o
      `default_owner_id`) ha ruolo Administrator viene rifiutata con `422` e un
      messaggio esplicito, mostrato all'utente nel frontend
- [ ] `[Aggiuntivo, emerso in verifica]` Un layer senza `track_mode`/`poi_mode`
      in `configuration` è considerato **manuale** su camminiditalia (non
      `auto`), tramite la chiave configurabile `wm-package.default_layer_mode`

## Rischi

- **Semantica di `manual` più forte del solo stato UI.** Il flag è letto da tre
  consumatori, non solo dalla UI: `LayerFeatures.php:75` (stato iniziale del
  toggle), `EcTrackObserver.php:137` (filtra i layer `auto` per dispatchare
  `SyncAutoLayerAfterTrackTaxonomyChangeJob`) e `Layer.php:358` (fallback in
  lettura che ricostruisce i trackIds da tassonomia). Scrivere `manual` **spegne**
  per quel layer il ricalcolo automatico — comportamento confermato come voluto
  ("se vado in manuale non devo essere più modificato automaticamente").
  *Stato attuale, non garanzia di codice:* su camminiditalia oggi il rischio non
  si manifesta perché **zero layer hanno `taxonomyActivities`** (0 righe in
  `taxonomy_activityables` per il morph Layer): l'observer non dispatcha mai e il
  fallback tassonomico è inerte. Ma è una fotografia dei dati, non un vincolo di
  codice: `taxonomyActivities` è un campo Nova modificabile
  (`MorphToMany::make('Activities', ...)`, `wm-package/src/Nova/Layer.php:93`) —
  un Administrator può associare tassonomie a un layer in qualsiasi momento senza
  alcun blocco. La correttezza del fix non dipende da questo stato: la
  persistenza della modalità (ordine `persistMode()` → assign, scrittura atomica
  via `jsonb_set`) resta valida a prescindere da quante tassonomie esistano oggi
  o in futuro.

- **Il fix è diviso su due repo e nessuno dei due basta da solo.** L'override
  locale non chiama `parent::sync()`, quindi un fix solo nel package non arriva a
  camminiditalia; il frontend Vue esiste solo nel package, quindi il flag non può
  essere inviato senza toccarlo. *Mitigazione:* la logica di persistenza vive una
  sola volta, nel metodo `protected persistMode()` del package, e l'override
  locale la invoca — nessuna duplicazione, un solo punto da correggere per
  eventuali fix futuri sulla modalità. L'override continua a **non** delegare
  `sync()` al parent: i due metodi condividono solo la firma (autorizzazione 403,
  whitelist dei modelli, filtro `user_id = layerOwnerId` sugli ID sia in auto che
  in manuale, e un ramo `auto` che qui significa "assegna tutti gli EC di cui sono
  proprietario" invece di "ricalcola da tassonomia"). Rifare l'override in termini
  di `parent::sync()` è un refactor di oc:8311, fuori scope.

- **Stato del submodule `wm-package` — risolto prima di questo aggiornamento.**
  Il submodule era stato lasciato in detached HEAD su `60f88f45`, un commit
  indietro rispetto a `origin/develop`. Verificato che `ae6d5cf` (visto in una
  sessione precedente) appartiene al branch `origin/RDO_ass_cammini_italia_2026_2`
  e **non tocca** alcun file sotto `src/Nova/Fields/LayerFeatures/`: nessun
  rischio di sovrascrittura con questo fix. Il submodule è stato riallineato su
  branch `develop`, HEAD `db707c8a`, 0/0 rispetto a `origin/develop`; il repo
  principale è anch'esso 0/0 su `origin/develop`. Il gitlink del repo principale
  (`git ls-tree HEAD wm-package`) non è ancora aggiornato al nuovo commit del
  submodule: sarà incluso nel commit del fix.

- **Un click esplorativo sul toggle diventa persistente.** Con la persistenza al
  toggle (scelta confermata), l'utente che clicca "manuale" per curiosità lascia
  il layer in manuale anche chiudendo senza salvare. *Mitigazione:* l'operazione è
  reversibile e il ritorno in auto è già presidiato da `ConfirmModal`; la
  selezione ereditata dall'auto rende il layer immediatamente coerente, non
  svuotato.

- **Il ritorno in auto resta distruttivo.** `auto: true` esegue
  `sync($ownedIds)` sovrascrivendo la selezione manuale con "tutti gli EC di cui
  sei proprietario". *Mitigazione:* comportamento intenzionale e preesistente
  (oc:8311), confermato da modale; non modificato in questo ciclo.

## Out of scope

- Modifica del comportamento distruttivo del ritorno in automatico
- Migrazione dati per popolare `configuration` sui layer esistenti: il default
  `auto` è già quello corretto per tutti e 118, nessun backfill necessario
- Rimozione dell'override locale di `sync()` in favore di una delega a
  `parent::sync()` — refactor legittimo ma indipendente da questo bug: i due
  metodi divergono su autorizzazione, whitelist modelli, filtro di ownership sugli
  ID e semantica del ramo `auto`
- Estrazione in chiavi i18n dei messaggi Vue hardcoded in italiano
  (`Nova.success("Modalità automatica attivata")`): debito preesistente del
  package, tracciato in `notes.md`
- Introduzione di tassonomie sui layer di camminiditalia
- Modifica di `getFeatures()`, che su questo repo ignora deliberatamente la
  modalità (oc:8311)

## Moduli toccati

**Repo camminiditalia (questo repo):**

| File | Modifica |
|---|---|
| `app/Http/Controllers/LayerFeatureController.php` | `sync()`: validazione `manual` + una chiamata a `persistMode()` ereditato dal package; rifiuto esplicito (422) di `auto: true` per owner Administrator |
| `tests/Feature/LayerFeatureControllerTest.php` | Test su persistenza modalità, pivot, merge di `configuration`, rifiuto `auto` per owner Administrator, default `manual` |
| `.env`, `.env.testing` | `DEFAULT_LAYER_MODE=manual` |

**Repo wm-package (submodule):** vedi
`wm-package/docs/features/8314-modalita-auto-manuale-layer-non-persistita/overview.md`
— frontend (`useFeatures.ts`, `LayerFeature.vue`), nuovo `protected persistMode()`
e `sync()` del controller base.

> Ticket: oc:8976

# Problema associazione "variante di" per new tracks

## Cosa cambia

Una EcTrack creata in Nova da un utente con ruolo **Validator** viene associata in automatico al
cammino (layer) di cui è owner: l'hook `afterCreate()` di `App\Nova\EcTrack` fa l'attach tramite
Eloquent sul layer con `layers.user_id = Validator`, così scattano gli observer di `layerables`
(proprietà `layers`, geometria del layer, PBF, ownership, attributi del cammino) come per
qualunque altra associazione.

Regola di prodotto: un Validator gestisce **al più un** cammino. L'associazione non è una scelta in
form: è sempre automatica alla creazione (nessun Field `Layers` sulla detail view della EcTrack
per il Validator — resta visibile solo all'Administrator).

Se il Validator non è owner di nessun layer, la tappa resta senza righe in `layerables` e il Field
«Variante di» mostra un `help()` che spiega che serve prima un cammino.

Se il Validator risulta owner di più di un layer (anomalia dati), non si fa attach automatico:
la creazione della tappa va comunque a buon fine e l'anomalia va corretta a mano sui layer.

## Perché

Il Field «Variante di» propone solo le tappe dei layer a cui la tappa corrente appartiene
(`variantOptions()` in `app/Nova/EcTrack.php`). Una tappa nuova nasce senza righe in `layerables`
e il Validator non ha alcun modo di associarla a un cammino:

- dal 2025-12-10 (OC:6706) `LayerPolicy::update()` gli nega la form del Layer, e `LayerFeatures`
  permette di aggiungere tappe solo nella form;
- dal 2026-09-16 (wm-package oc:8162) il Field `Layers` della EcTrack non gli proponeva layer
  associabili;
- dal 2026-09-21 (oc:8611) quel Field gli è nascosto: l'associazione non passa dalla detail view
  della EcTrack.

Il ticket cliente riguarda un Validator con un solo cammino («Via degli Abati»): la fix corretta
è l'auto-attach alla creazione, non riaprire attach/detach sul layer dalla tappa.

Verificato in locale sulla tappa 1372 (utente «Via degli Abati», id 1672): nessuna riga in
`layerables`, Select «Variante di» vuoto.

**Anomalie rilevate sul DB di sviluppo** (query 2026-11-13, 45 utenti con ruolo Validator):

- **Un solo Validator con più di un layer:** «Cammino Materano» (`user_id` 1378,
  `camminomaterano@gmail.com`) — layer id 3, 4, 45 (Via Ellenica, Via Peuceta, Via Lucana).
  Trattato come errore di ownership, fuori scope della correzione automatica in questo ticket.
- **25 Validator senza layer** (`user_id` null su nessun layer): restano con tappe potenzialmente
  orfane; vale solo l'`help()` su «Variante di», nessun attach automatico.

## Requisiti

- [ ] Alla creazione da Nova di una EcTrack, se l'utente loggato ha ruolo Validator ed è owner di
  **esattamente un** layer, la tappa viene associata a quel layer (sempre, senza scelta in form)
- [ ] L'associazione passa dalla relazione Eloquent `layers()` (pivot `Layerable`), non da query
  builder: devono scattare gli observer di `layerables` del package e locali
- [ ] Nessuna associazione automatica se l'utente è Administrator, non è Validator, è owner di
  **zero** layer, o è owner di **più di un** layer (anomalia)
- [ ] Nessuna associazione automatica fuori da Nova (import, job, factory)
- [ ] Il Field `Layers` sulla detail view della EcTrack resta **nascosto** al Validator
  (comportamento attuale di oc:8611); resta visibile all'Administrator
- [ ] `EcTrackPolicy::detachLayer()` → `false` per il Validator (difesa su API Nova attach/detach)
- [ ] `EcTrackPolicy::attachLayer()` → consentito solo se il layer è di proprietà del Validator
  (difesa su richieste costruite a mano)
- [ ] Il Field «Variante di» su una tappa senza layer mostra un `help()` che spiega che serve un
  cammino; tradotto in `it`, `en`, `fr`, `es`, `de`
- [ ] Test feature per: auto-attach (Validator con un layer), nessun attach (Administrator,
  Validator senza layer, Validator con più layer), `help()` senza layer

## Rischi

- I 25 Validator senza layer in dev non risolvono «Variante di» senza intervento sui dati
  (assegnazione layer): mitigato dall'`help()`.
- L'attach automatico rigenera i PBF del layer a ogni creazione di tappa: costo uguale a un'attach
  fatta dall'Administrator.

## Out of scope

- Tappe già create in produzione dopo il 2026-09-16 e rimaste senza layer: nessuna correzione bulk
  (decisione del dev).
- Correzione ownership «Cammino Materano» (3 layer sullo stesso Validator) e degli altri casi
  multi-layer in produzione.
- Apertura al Validator del Field `Layers`, della form del Layer o del Field `LayerFeatures`.
- Correzione di `Wm\WmPackage\Nova\Layer::indexQuery()` nel package.

## Moduli toccati

Tutto nel repo principale (camminiditalia), nessuna modifica a `wm-package`.

- `app/Nova/EcTrack.php` — `afterCreate()`; `help()` condizionale su «Variante di»
- `app/Policies/EcTrackPolicy.php` — `attachLayer()` / `detachLayer()`
- `resources/lang/{it,en,fr,es,de}.json` — testo del nuovo `help()`
- `tests/Feature/` — test nuovi o estensione di `EcTrackVariantFieldTest.php`

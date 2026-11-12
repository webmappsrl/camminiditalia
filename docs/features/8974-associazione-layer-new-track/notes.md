> Ticket: oc:8974
> Overview approvata il 2026-11-12 · impronta `d22f6927e608d89af2d81b757a628d81d978f44a`

# Notes — Associazione layer - new track

## Modifiche ai requisiti

### 2026-11-12 — La query che riempie il menu legge solo id e nome del cammino
- **Requisito:** [R4](overview.md#r4)
- **Effetto:** aggiunto
- **Decide:** dev
- **Perché:** con le sole correzioni previste la richiesta restava a 1.575 ms e 90 MB, e [R1](overview.md#r1)
  («meno di un secondo») non era centrato. Il dev ha fatto notare che per comporre il menu
  servono l'id e il nome del cammino, mentre Nova si portava dietro i layer interi con l'eager
  load della Resource. Misurato: 1.575 ms e 90 MB → 966 ms e 13 MB.
- **Scartato:** riscrivere [R1](overview.md#r1) al ribasso su «meno di due secondi», che era la
  proposta prima di provare questa strada.
- **Testo nuovo:**
  > La query che riempie il menu di associazione legge dei cammini solo ciò che serve a comporre
  > le voci, senza portarsi dietro le tracce e i POI associati.

## Divergenze dal piano, task per task

### Task 1 — Il campo «Layers» punta alla Resource Layer di questo repo
Oltre a ripuntare il campo, è stato aggiunto `App\Nova\EcTrack::relatableLayers()`: Nova cerca
quel metodo per convenzione (`relatable` più il nome del modello al plurale,
`AttachableRelation::attachableQueryMethod()`) e lo usa al posto della query standard dell'attach.
Toglie l'eager load dichiarato dalla Resource (`$with`: tracce, POI, App, App associate) e
seleziona `id`, `name` e `user_id` — l'ultimo serve a `indexQuery()` e all'autorizzazione.

## Bug trovati

Nessuno oltre a quello del ticket.

## Decisioni

### 2026-11-12 — La diagnosi è passata da una strumentazione temporanea, poi rimossa
Il log di `artisan serve` non riporta il path delle richieste `nova-api`, e il Debugbar non
registra una richiesta che dura 124 s. Per avere il path e lo stack è stato aggiunto in
`AppServiceProvider::boot()` un listener temporaneo su `RequestHandled` e `DB::listen`, usato
per tre misure e poi tolto (`git status` pulito prima di scrivere codice).
- **Scartato:** leggere i tempi dai log di produzione — dicono quanto dura una richiesta, non
  dove il PHP si ferma.

### 2026-11-12 — Saltati tag Orchestrator e stima
Chiesto dal dev per ragioni di tempo. La stima sarebbe stata saltata comunque: il ticket è di
tipo Bug, non Feature.

### 2026-11-12 — Si rilascia prima il solo fix di questo repo, senza bump del gitlink
Il cliente non riesce a lavorare e il fix va in produzione stasera. Misurato che il solo fix di
questo repo porta la richiesta da 123.951 ms a 1.256 ms: basta a sbloccarlo. Il bump del gitlink
è la parte rischiosa del rilascio — porta con sé tutto ciò che è entrato nel package dall'ultimo
bump e richiede la procedura `wm-package/docs/howto/migration-wm-package.md` — e non si fa di
sera per fretta. La correzione in wm-package resta sul suo branch e va in una PR sua.
- **Scartato:** rilasciare i due insieme stasera, che avrebbe legato lo sblocco del cliente al
  bump del submodule.

## Follow-up

- Far sì che i campi delle tassonomie non vengano costruiti quando Nova compone una Resource solo
  per ricavarne titolo e avatar (gli elenchi di relazione). Toglierebbe il costo alla radice
  invece di alleggerirlo; dopo i due interventi di questo ticket non resta però niente di
  misurabile da guadagnare.

> Ticket: oc:8974
> Stato: seguito, con 1 divergenza annotata in notes.md

# Piano — Associazione layer - new track

## Task 1 — Il campo «Layers» punta alla Resource Layer di questo repo

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1--il-campo-layers-punta-alla-resource-layer-di-questo-repo)

Realizza [R2](overview.md#r2), ed è ciò che rende effettivo [R1](overview.md#r1) in questo repo.

In `app/Nova/EcTrack.php`, nel ciclo `array_map` che già corregge «EcPois» (oc:8611), sostituire
il campo ereditato `MorphToMany` con uno che punta a `App\Nova\Layer`, conservando
`collapsedByDefault()` e il `canSee` che lo riserva all'Administrator. Il commento accanto va
aggiornato: oggi dice che il campo «va nascosto invece che corretto», da ora è nascosto **e**
corretto.

Commit: `fix(oc:8974): il campo Layers della tappa usa la Resource Layer locale`

## Task 2 — Test di regressione

Realizza [R3](overview.md#r3).

In `tests/Feature/` un test che verifica due cose sul campo «Layers» di `App\Nova\EcTrack`:
la Resource puntata è `App\Nova\Layer`, e il campo resta invisibile a un Validator.

Commit: `test(oc:8974): il campo Layers punta alla Resource locale e resta Administrator-only`

## Task 3 — Bump del gitlink di wm-package

Dopo il merge della parte package, aggiornare il puntatore del submodule, in un commit suo.

Commit: `chore(oc:8974): gitlink wm-package con le tassonomie storiche lette una volta sola`

## Verifica finale

Riaprire `/nova/resources/ec-tracks/{id}/attach/layers` e misurare la richiesta
`attachable/layers`: da 123.951 ms a meno di un secondo, con l'elenco dei cammini popolato.

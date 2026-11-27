> Ticket: oc:9050
> Overview approvata il 2026-11-27 · impronta `77d404666d4c34a464879813344dafcdaf8ae187`

# Notes — Modifiche alle tappe salvate in Nova non visibili in app

## Modifiche ai requisiti

### 2026-11-27 — Recupero della produzione rimandato a dopo il deploy
- **Requisito:** [R2](overview.md#r2)
- **Effetto:** non fatto
- **Decide:** dev (il recupero si fa a mano via ssh dopo il deploy del package)
- **Perché:** al momento della PR il codice del package non è ancora in produzione: accodare ora la
  pubblicazione riproporrebbe le stesse catene vecchie.

### 2026-11-27 — Verifica in produzione rimandata a dopo il recupero
- **Requisito:** [R3](overview.md#r3)
- **Effetto:** non fatto
- **Decide:** dev
- **Perché:** dipende da [R2](overview.md#r2).

## Divergenze dal piano, task per task

## Bug trovati

## Decisioni

### 2026-11-27 — Tag Orchestrator non associati
- Il dev ha chiesto di saltare la proposta dei tag: il ticket resta con `26Q4` e `ass_cammini_italia`.

## Follow-up

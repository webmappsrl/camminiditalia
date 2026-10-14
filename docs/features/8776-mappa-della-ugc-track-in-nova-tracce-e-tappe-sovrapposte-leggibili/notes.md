> Ticket: oc:8776

# Notes — Mappa della UGC track in Nova: tracce e tappe sovrapposte leggibili

Nessuna deviazione specifica di questo repo: il codice è tutto in `wm-package`, e la storia del
lavoro (otto giri di prova sulla UGC 321, soluzioni scartate, decisioni) è in
`wm-package/docs/features/8776-mappa-della-ugc-track-in-nova-tracce-e-tappe-sovrapposte-leggibili/notes.md`.

## Decisioni

- Il gitlink punta al merge della PR wm-package #307 in `develop` (`a285f7a7`).
- Nessuna migration e nessun comando dopo il deploy.

## Bug trovati

- Menu Nova con le sezioni UGC, EC e Taxonomies vuote dopo aver usato orchestrator sulla stessa
  porta (`localhost:8000`): Nova salva nel `localStorage` lo stato chiuso/aperto delle sezioni con
  una chiave che dipende solo dal nome, e lo legge anche per le sezioni non richiudibili. Non è un
  bug del codice; si risolve cancellando le chiavi `nova.navigation.*` dal browser.

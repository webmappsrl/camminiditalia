> Ticket: oc:8776

# Mappa della UGC track in Nova: tracce e tappe sovrapposte leggibili

## Cosa cambia

Nella mappa del dettaglio di una UGC track in Nova la traccia registrata è una riga blu sottile
con un contorno bianco, sempre sopra; compaiono solo le tappe che la traccia tocca, sottili e piene
solo nei pezzi percorsi; da lontano, dove più cammini passano insieme, i pezzi si colorano a
segmenti alternati; il tooltip elenca la traccia e i cammini sotto il mouse. Il codice è
interamente in `wm-package`: vedi
`wm-package/docs/features/8776-mappa-della-ugc-track-in-nova-tracce-e-tappe-sovrapposte-leggibili/overview.md`.

## Perché

Segnalato sulle UGC track di Cammini d'Italia: una traccia che segue una tappa la nasconde, le
tappe di cammini diversi sullo stesso tratto si coprono a vicenda, e le tappe della zona a zoom
basso diventano macchie più vistose della traccia.

## Requisiti

- [ ] Bump del gitlink `wm-package` al merge della PR del package.
- [ ] Verifica a occhio in Nova sulla UGC track 321.
- [ ] Riga del lavoro in `CLAUDE.md` (`## Feature disponibili`).

## Rischi

Nessuno specifico di questo repo: i rischi sono quelli del package. Le tappe mostrate dipendono
dalla corrispondenza di oc:8773 (`wm:match-ugc-tracks`): su un ambiente dove non è stata lanciata
la mappa mostra tutte le tappe della zona, come prima.

## Out of scope

Vedi l'overview del package.

## Moduli toccati

- gitlink `wm-package`
- `CLAUDE.md`

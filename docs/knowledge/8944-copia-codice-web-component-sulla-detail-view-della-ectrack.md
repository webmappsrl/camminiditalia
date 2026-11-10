# Bottone «Copia codice web component» sulla tappa

## Come funziona oggi

Il bottone «Copia codice web component» su cammini e tappe è del package: come funziona è in
[wm-package/docs/knowledge/8944-copia-codice-web-component-sulla-detail-view-della-ectrack.md](../../wm-package/docs/knowledge/8944-copia-codice-web-component-sulla-detail-view-della-ectrack.md).
Qui c'è solo la scelta di camminiditalia.

- **Layer proposti sulla tappa**: `App\Nova\EcTrack::webComponentLayers()` (`#[\Override]`, parte da
  `parent::`, che filtra per il flag dell'App). L'Administrator vede tutti i layer della traccia; ogni
  altro ruolo solo quelli con il proprio `user_id`, come nella index dei Layer
  (`App\Nova\Layer::indexQuery()`). Con un solo layer la select non compare; senza layer il Field
  non compare. Test: `tests/Feature/EcTrackWebComponentLayersTest.php`.
- **Rilascio**: lo snippet della tappa porta `track-id`, che il widget `wm-layer-map` legge solo
  dalla modifica di oc:8937 in wm-elements; prima di quella, apre il cammino intero.

## Perché così

- **Filtro per owner del layer anche se lascia tappe senza bottone** (oc:8944): la select non deve
  proporre al Validator un cammino che non può aprire né controllare. Prezzo accettato: in produzione
  (06/11/2026) 40 EcTrack di Validator su 520 stanno solo in layer non loro (35 dell'Administrator,
  5 senza owner), e lì il Validator non vede il bottone. L'Administrator lo vede su tutte.

## Come ci siamo arrivati

- **Proporre al Validator tutti i layer della traccia** (oc:8944, scartata): avrebbe dato il bottone
  anche alle 40 tappe, ma con snippet di cammini non suoi.
- **Adeguare camminiditalia alle tassonomie di oc:8910 dentro oc:8944** (oc:8944, superata): il
  primo branch portava il package di `develop` con oc:8910, e nascondeva da sé le sezioni delle
  tassonomie su Layer, EcTrack ed EcPoi. Quel lavoro l'ha fatto oc:8951 nel frattempo, in modo
  diverso: vedi [tassonomie-dei-contenuti-in-nova.md](tassonomie-dei-contenuti-in-nova.md).

> Ticket: oc:8703

# Notes — Passaporto: tab con cammini completati e in corso (backend)

Le note d'insieme stanno in `webmapp-app/docs/features/8703-…/notes.md`.

## Deviazioni dal piano

- **Tabella polimorfica `passport_shares`** (shareable = EcTrack o Layer) al posto di
  `passport_stage_shares` e di una tabella per il cammino; modello unico `PassportShare`, pagina
  pubblica unica `/share/passport/{uuid}`, observer unico. La migration di oc:8702 è stata
  modificata e rinominata in `2026_10_05_100000_create_passport_shares_table.php`.
- Etichette del cammino in `resources/lang/*/passport_route_share.php` e non in
  `passport_share.php`: quel file entra nella firma del layout della tappa.
- Mappa del cammino come la vista 7 (rosso, pallini, ref delle tappe) con il parametro `labels`
  aggiunto a `MapRenderService::renderLayers()` in wm-package.

## Bug trovati

- «Completato il» e le uscite erano calcolati in UTC: una validazione fra mezzanotte e le 2
  cadeva nel giorno prima. Ora `RouteShareImageService::DISPLAY_TIMEZONE = 'Europe/Rome'`.

## Decisioni

- Prima di estrarre il codice comune in `PassportShareCommon`, test di regressione
  `StageShareImageRegressionTest` (md5 dell'immagine della tappa e firma del layout invariati).
- `PassportShareStore`: flusso comune di cache, media e snapshot e parti comuni dell'impronta per
  tappa e cammino; le impronte della tappa sono rimaste identiche.
- Rate limiter con nome `passport-route-share`, separato da `throttle:10,1`.
- Nessuna emoji nell'immagine (i font GD non hanno il glifo); niente marker di partenza e arrivo
  (le tappe non hanno un ordine di percorrenza); etichette 16px, omesse oltre 20 tappe, quelle
  sovrapposte saltate; margine della mappa 3%.

## Follow-up

- Nessuna foreign key sulla tappa in `passport_shares`: una tappa cancellata fuori da Eloquent
  lascia la condivisione orfana.
- Test md5 byte-identico dell'immagine della tappa da verificare in CI (GD e font possono
  differire dal Docker locale).
- Con un cammino lungo e stretto lo zoom della mappa non migliora: i livelli dei tile sono interi.

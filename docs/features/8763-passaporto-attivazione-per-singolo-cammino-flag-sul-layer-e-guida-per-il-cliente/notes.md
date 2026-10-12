> Ticket: oc:8763

# Notes — Passaporto: attivazione per singolo cammino — backend

## Divergenze dal piano, task per task

### Task 1 regola SQL del flag

`PassportLayer::enabledSql()` confronta `(properties)::jsonb -> 'passport_enabled'` con
`'true'::jsonb` invece di `->> 'passport_enabled' = 'true'` previsto dal piano: con `->>` la stringa
  `"true"` sarebbe stata accesa in SQL e spenta in PHP, contro il vincolo «stessa regola in PHP e in
  SQL». Un test con data provider confronta le due forme su `true`, `"true"`, `1`, `false`, assente.

### Task 3 testo di aiuto e chiavi

- Il campo Nova ha anche un testo di aiuto («Attiva il Passaporto per questo cammino nell'app.»),
  non previsto dal piano: tenuto perché spiega l'interruttore a chi lo usa.
- Dopo la review le chiavi delle traduzioni sono in inglese («Passport enabled», …), come le altre
  del file, con il testo italiano come valore.

### Task 4 versione breve

La versione breve della guida per la risposta su Orchestrator non sta nella guida pubblicata.

## Bug trovati

- Il test del 404 sulla condivisione della tappa usava l'id 1 di una tappa inesistente: il binding
  di `EcTrack` rispondeva 404 prima del middleware, quindi il test passava anche senza il controllo.
  Ora usa una tappa vera, e un test gemello sul cammino acceso mostra che il controllo lascia passare
  (403 perché la tappa non è convalidata dall'utente).

## Decisioni

- Tag Orchestrator: fase saltata su richiesta del dev, nessun tag associato.
- Spegnimento con richieste di certificazione in attesa: nessun blocco in Nova; avviso nella guida
  (una volta acceso, difficilmente un cammino verrà spento).
- Cammino spento: 404 su tutti gli endpoint del gruppo `layer`, messaggio d'errore generico
  nell'app.
- `/api/passport` elenca solo i cammini accesi con almeno una tappa associata: un cammino acceso
  senza tappe non compare.
- Stima: 5h, valore scelto dal dev (la stima di `wm-estimate`, rivista, era 5,6h).
- Test eseguiti nel container `php-camminiditalia` (da `compose.yml`), perché in quel momento
  `laravel-camminiditalia` (da `local.compose.yml`, quello del `CLAUDE.md`) non era acceso.
- Screenshot della guida fatti in Nova locale con `APP_LOCALE=it`. Le stringhe di Nova stessa
  («Actions», «Run Action», «Choose an option») e il nome del menu «Layers» restano in inglese
  anche in italiano: la guida usa le etichette che il cliente vede davvero.

## Follow-up

- Etichette di Nova in italiano: `label()` di `App\Nova\Layer` («Cammini») e pacchetto di lingua
  italiano di Nova.
- Formato di `validated_tracks`: una mappa id → data non regge più date per la stessa tappa; se si fa
  oc:8767 (cammino percorso più volte) il contratto va cambiato insieme all'app.
- Rilascio: backend, app e i submodule `wm-types` e `wm-core` lo stesso giorno; un rollback del solo
  backend riaccende il passaporto su tutti i cammini.

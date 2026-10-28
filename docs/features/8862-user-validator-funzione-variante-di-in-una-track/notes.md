> Ticket: oc:8862

# Note

## Decisioni fuori dal codice

- La stima (1h misurata + 2,6h stimate, 3,6h in tutto) è approvata ma non è stata scritta su
  Orchestrator, per scelta del dev. Anche i tag sono stati saltati.

## Deviazioni dal piano

- **La principale salvata si aggiunge alle opzioni con una query a parte su `ec_tracks`.** Con
  un `orWhere` nel join con `layerables`, una principale tolta dal cammino sparirebbe dalle
  opzioni e il form perderebbe il valore.
- **`violation()` si controlla prima delle opzioni.** Una principale diventata variante dopo
  l'apertura del form esce anche dalle opzioni. Con l'ordine del piano l'utente vedrebbe
  «non è fra quelle che puoi indicare» invece del motivo vero. Il costo: un Validator che
  manda a mano l'id di una tappa altrui può scoprire se quella tappa è una variante.
- **Il campo è nascosto in creazione (`hideWhenCreating()`)**, trovato nella review finale.
  In creazione le regole non hanno una tappa su cui ragionare e le opzioni sono vuote, ma il
  `fillUsing` gira dopo il salvataggio: un id mandato a mano finiva in `ec_track_variants`
  senza controlli (tappa altrui, oppure un 500 dal guard o dalla FK). Test:
  `test_the_field_is_not_offered_when_creating_a_stage`.
- Alcuni test erano già verdi prima dell'implementazione: sono guardie di non regressione
  (`updateOrCreate` senza modifiche non salva, il Guest non vedeva già il campo).

## Rimandati (minori della review)

- Manca la regola `integer`: il valore viene convertito in intero e poi controllato contro
  le opzioni, quindi non entra niente di sbagliato.
- Nessun test passa dai controller HTTP di Nova (`putJson`/`postJson`): le regole e il fill
  sono provati chiamando il campo direttamente.
- In index il campo fa 3-4 query per riga. Succedeva già prima, ora vale anche per i Validator.

## Verifiche

- `--filter=EcTrackVariant`: 28 passati. `StageProgressVariantsTest`: 7 passati.
- Suite completa: 815 passati, 0 falliti.
- PHPStan sui file toccati: nessun errore.

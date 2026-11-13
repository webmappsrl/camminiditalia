> Ticket: oc:8976

# Notes — Problema associazione "variante di" per new tracks

## Deviazioni dal piano

- `afterCreate()` non chiama `parent::afterCreate()`: `Wm\WmPackage\Nova\EcTrack` non lo definisce. Se un giorno il package lo aggiunge, l'override locale lo nasconde.
- Il guard `method_exists($model, 'layers')` della prima stesura è stato tolto in review: la EcTrack ha sempre `layers()` (`EcFeatureTrait`), e il guard avrebbe fatto saltare l'attach senza segnali.
- Import Eloquent `Layer` aliasato come `LayerModel` in `app/Nova/EcTrack.php` per non risolvere `Layer::class` del Field `MorphToMany` sul modello invece che su `App\Nova\Layer` (regressione oc:8974).
- Le traduzioni usano la frase inglese come chiave (convenzione dei JSON del repo), non la chiave `variant_of_requires_layer` proposta nel piano.
- Il test del task 2 punto 5 («Variante di» non vuoto dopo l'attach automatico) è stato aggiunto in review: `test_after_auto_attach_variant_of_offers_the_stages_of_the_route`.

## Bug trovati

- **`help()` con una closure non arriva al frontend** (trovato in review): Nova non esegue la closure, `Field::jsonSerialize()` la serializza come `{}` e sotto «Variante di» sparivano sia il testo esistente sia l'avviso nuovo. I test non se ne accorgevano perché chiamavano la closure a mano. Corretto passando la stringa calcolata; i test leggono ora `jsonSerialize()['helpText']`.
- **L'avviso chiedeva al Validator un'azione impossibile** («aggiungila al tuo cammino»): il Validator non ha il Field `Layers` né la form del Layer. Il testo ora lo manda dall'amministratore.

## Decisioni

- Overview aggiornata in sessione, dopo la call del 2026-11-13 10:45 con Giuseppe Bonfanti: un Validator gestisce un solo cammino, niente Field `Layers` per il Validator, attach automatico solo con esattamente un layer (il caso con più layer è un'anomalia dei dati).
- Un utente con entrambi i ruoli Administrator e Validator non riceve l'attach automatico.
- `EcTrackPolicy::attachLayer()` vale solo per un Validator sul proprio layer; `detachLayer()` è sempre `false` fuori dall'Administrator (che passa da `before()`). Con il Field `Layers` nascosto sono difese sulla sola API Nova.
- I job lanciati dagli observer del pivot partono dentro la transazione di Nova (`after_commit=false`): è lo stesso comportamento dell'attach fatto oggi dall'Administrator (`ResourceAttachController`), non introdotto da questo ticket.

## Follow-up

- Nella risposta al cliente: chiedere di sistemare entro il fine settimana «Cammino Materano» (3 layer sullo stesso Validator), un account per ogni cammino (indicazione di Giuseppe Bonfanti in call).
- Tappe già create da Validator dopo il 2026-09-16 e rimaste senza layer: nessuna correzione (decisione del dev).

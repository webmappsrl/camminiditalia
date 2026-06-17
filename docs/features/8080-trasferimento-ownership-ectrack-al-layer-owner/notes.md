> Ticket: oc:8080

# Notes — Trasferimento ownership EcTrack al layer owner

## Deviazioni dal piano
Nessuna deviazione strutturale. Il piano è stato seguito integralmente.

## Bug trovati durante la review
- `wasChanged('user_id')` ritorna sempre `false` su layer appena creato (`wasRecentlyCreated = true`) — il trasferimento non avveniva alla creazione del layer. Corretto aggiungendo `|| $layer->wasRecentlyCreated` alla condizione di skip in `LayerObserver::saved()`.

## Decisioni
- `LayerableObserver` usa `App\Models\EcTrack::class` e `App\Models\EcPoi::class` per il confronto `layerable_type` — verificato in DB che il config `ec_track_model` è impostato a `App\Models\EcTrack`, quindi il confronto è corretto per questo progetto.
- Nessuna transazione esplicita attorno ai bulk UPDATE — scelta concordata durante il design, accettata come rischio noto.
- Log emesso anche quando non ci sono risorse da trasferire (array vuoti) — accettato, non causa problemi funzionali.

## Follow-up
- Nessuno.

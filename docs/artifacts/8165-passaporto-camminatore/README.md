# Mockup — Passaporto camminatore: badge automatico da GPS (oc:8165)

Mockup esplorativo per il ticket **oc:8165**, costruito su dati **reali** estratti dal dump di sviluppo (nessun dato simulato): cammino "Via Vandelli" (`layer_id 9`), utente `3680` ("max"), 9 tappe, 12 tracce GPS (`UgcTrack`).

## Cosa mostra

- L'algoritmo proposto per il completamento tappa: segmentazione a ~400m, buffer GPS 50m, soglia di copertura configurabile (80% in questo mockup), sblocco cammino solo a tappe tutte completate
- Una dashboard con le 9 tappe reali e il relativo stato di copertura
- 3 mappe di dettaglio (tappa quasi completa, parziale, mai percorsa) con segmentazione esplicita, sovrapposizione delle tracce GPS reali e sfondo cartografico (tile raster `api.webmapp.it/tiles`, proiezione Web Mercator)

## File

- `index.html` — il mockup, artifact autocontenuto (nessuna chiamata di rete a runtime: dati e tile sono incorporati come JSON/base64 al momento della generazione)
- `scripts/01-extract-geometries.sql` → `04-extract-detail-ugc.sql` — query PostGIS usate per estrarre geometrie tappe/tracce GPS dal DB locale (`docker exec postgres-camminiditalia psql -U camminiditalia -d camminiditalia -f <script>`)
- `scripts/05-fetch-tiles.py` — script Python che calcola i tile Web Mercator necessari per ogni mappa e li scarica da `api.webmapp.it/tiles/{z}/{x}/{y}.png`, salvando il risultato in `tiles.json`

## Come rigenerare

1. Eseguire gli script SQL (1-4) nel container Postgres, salvando l'output di ciascuno (redirect `\o` già incluso negli script) come `data_overview.json`, `segments.json`, `summary.json`, `detail_ugc.json`
2. Eseguire `05-fetch-tiles.py` (richiede i JSON del punto 1 nella stessa cartella) per produrre `tiles.json`
3. Concatenare i JSON prodotti dentro un file HTML con la struttura: markup+CSS → `const OVERVIEW = ...` → `const SUMMARY = ...` → `const SEGMENTS = ...` → `const DETAIL_UGC = ...` → `const TILES = ...` → script di rendering

## Stato

Mockup di analisi, non implementazione. Nessun codice applicativo di oc:8165 è stato scritto — serve a validare l'algoritmo e a comunicarlo visivamente prima di procedere con `wm-plan`.

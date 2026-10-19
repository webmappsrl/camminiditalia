# API progresso e passaporto (oc:8676)

Due endpoint di sola lettura, entrambi con `auth:api` (senza token: 401). Il calcolo è sempre fatto sulle tappe di oggi: nessun completamento è salvato.

## GET /api/layer/{layer}/progress

Nome route: `camminiditalia.api.layer.progress`. `{layer}` numerico; layer inesistente: 404.

```json
{ "layer_id": 40, "validated": 6, "total": 13, "percentage": 46, "completed": false,
  "km_validated": 81.4, "km_total": 190.2,
  "tracks": [ { "id": 123, "name": { "it": "Cammino Grande di Celestino - Tappa 06: Pacentro - Caramanico Terme" }, "distance": 17.0,
                "status": "validated", "progress": 100, "validated_at": "2026-09-30T10:00:00+00:00", "source": "manual" },
              { "id": 124, "name": { "it": "Tappa 07", "en": "Stage 07" }, "distance": 21.4,
                "status": "not_validated", "progress": 0, "validated_at": null, "source": null },
              { "id": 522, "name": {}, "distance": 0.0,
                "status": "not_validated", "progress": 0, "validated_at": null, "source": null } ] }
```

## GET /api/passport

Nome route: `camminiditalia.api.passport`. Elenca i cammini in cui l'utente loggato ha almeno una tappa validata fra le tappe del cammino. L'elenco è ordinato per `layer_id` crescente. Senza validazioni: `{ "routes": [] }`.

```json
{ "routes": [ { "layer_id": 40, "name": "Cammino degli Dei", "validated": 6, "total": 13, "percentage": 46,
                "completed": false, "km_validated": 81.4, "km_total": 190.2,
                "last_validated_at": "2026-09-30T10:00:00+00:00" } ] }
```

`routes` è un oggetto di primo livello (non un array nudo) per poter aggiungere campi in oc:8165.

## Significato dei campi

- `layer_id`: id del cammino. `name`: nome leggibile del cammino (solo passaporto).
- `validated`: tappe del cammino validate dall'utente. `total`: tutte le tappe associate al cammino, a prescindere dal proprietario della tappa.
- `percentage`: intero, `validated * 100 / total` arrotondato per difetto (46 per 6/13); 0 se `total` = 0.
- `completed`: `true` solo se `total > 0` e `validated == total`. Se arriva una nuova tappa torna `false`.
- `km_validated` / `km_total`: somma delle distanze delle tappe validate / di tutte le tappe del cammino, arrotondata a 1 decimale; nel JSON è sempre un numero con un decimale (es. `17.0`, mai `17`). Distanza di ogni tappa: `manual_data.distance` se non vuoto, altrimenti `osm_data.distance` (se la tappa ha `osmid`), altrimenti `dem_data.distance`, altrimenti 0; valori non numerici valgono 0.
- `last_validated_at`: data ISO 8601 dell'ultima validazione dell'utente nel cammino.
- `tracks` (solo progress): una voce per ogni tappa associata al cammino (a prescindere dal proprietario), ordinate per `id`.
  - `name`: oggetto con le traduzioni della tappa (da `ec_tracks.name`), una chiave per lingua; l'app sceglie la lingua. Le lingue nulle o vuote sono omesse; se il nome in DB è una stringa semplice vale `{"it": "<stringa>"}`; senza nome è `{}` (oggetto vuoto, mai `[]`).
  - `distance`: km della tappa con la stessa regola di `km_total`/`km_validated`, arrotondati a 1 decimale; nel JSON sempre con un decimale (es. `17.0`). Per via dell'arrotondamento per tappa, la somma delle `distance` delle tappe `validated` può differire di qualche decimo da `km_validated` (che arrotonda la somma esatta).
  - `status`: solo due valori.
    - `validated`: l'utente ha validato la tappa; `validated_at` (ISO 8601) e `source` (`manual` o `gps`) valorizzati.
    - `not_validated`: l'utente non ha validato la tappa; `validated_at` e `source` sono `null`.
  - `progress`: intero 0-100, percentuale della tappa percorsa (nel JSON sempre un intero, es. `"progress":100`). Oggi vale 100 per `validated` e 0 per `not_validated`; con il GPS di oc:8165 potrà valere valori intermedi con `status` `not_validated`. Il completamento si legge da `status`, non da `progress`; i totali (`validated`, `percentage`, `km_validated`, `completed`) non includono gli avanzamenti parziali.

Le tappe di un cammino sono tutte quelle associate ad esso, a prescindere dal proprietario della tappa: `total`, km e `tracks` le comprendono tutte. Una tappa validata dall'utente risulta `validated` in tutti i cammini che la contengono, qualunque sia il cammino da cui è stata validata, e conta nei `validated` e nei km di ciascuno.

## Regola di evoluzione

Solo campi nuovi, mai rinominati né rimossi: il frontend deve ignorare i campi che non conosce.

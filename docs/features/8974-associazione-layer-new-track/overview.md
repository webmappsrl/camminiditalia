> Ticket: oc:8974
> Stato: chiusa il 2026-11-12 · approvata il 2026-11-12

# Associazione layer - new track

## Cosa cambia

Il menu a tendina che associa un cammino a una tappa torna a rispondere subito. Prima di questo
lavoro quella richiesta impiegava 124 secondi e il menu non compariva mai: l'utente vedeva la
barra di caricamento appesa e non riusciva ad associare la tappa al cammino.

## Perché

Il cliente ha creato il layer 138 («Cammino Goriziano») e le tappe da associarci, e dalla scheda
della tappa non riesce più a fare l'associazione (oc:8974). Il problema non è del layer 138: si
riproduce su qualsiasi tappa.

## Punto di partenza

Il campo che apre quel menu è `MorphToMany::make('Layers', 'layers', Layer::class)` in
`wm-package/src/Nova/EcTrack.php:85`, e quel `Layer::class` è la Resource **del package**, non
`App\Nova\Layer`. Nova, per ogni voce dell'elenco, chiama `formatAttachableResource` →
`resolveAvatarUrl` → `availableFields`: costruisce cioè tutti i campi della Resource Layer solo
per sapere se c'è un avatar da mostrare. Costruirli passa da `makeTaxonomySection` →
`makeLegacyTaxonomyFieldsByTab` (`wm-package/src/Nova/Concerns/BuildsTaxonomyFields.php:300`),
che per le Zone fa `select * from taxonomy_wheres`: 7.930 righe, 104 MB di tabella, di cui 93 MB
sono la colonna `geometry` che quel codice non usa mai.

Misura della richiesta `GET /nova-api/ec-tracks/{id}/attachable/layers`, presa in locale sul dump
di produzione: **123.951 ms, 1.736 query, 96 s di database, 305 MB di picco di memoria**.

In questo repo il rimedio esiste già ma non viene usato: `App\Nova\Layer::legacyTaxonomies()`
ritorna `[]` proprio «per non pagarlo su ogni costruzione dei fields — index compresa» (oc:8951,
commit `a3a1009`). Il campo però punta alla Resource del package, che quell'override non ce l'ha —
lo stesso difetto già corretto sugli altri campi di relazione di questa Resource in oc:8611.

Le Zone le aggiunge `Wm\WmPackage\Nova\Layer::legacyTaxonomies()`
(`wm-package/src/Nova/Layer.php:133-146`).

## Requisiti

- <a id="r1"></a>**R1** — Dalla scheda di una tappa, il menu per associare un cammino si apre e
  mostra l'elenco dei cammini in meno di un secondo.
  - Verifica: a mano — Claude Code con il dev, 2026-11-12: su dump di produzione (124 cammini, 1.315 EcTrack): la
    richiesta `GET /nova-api/ec-tracks/1406/attachable/layers` passa da 123.951 ms, 1.736 query e
    305 MB a 1.256 ms, 993 query e 13 MB con il solo fix di questo repo; 966 ms con anche quello
    di wm-package. Menu aperto nel browser con tutti i cammini elencati.
- <a id="r2"></a>**R2** — Il campo «Layers» della tappa usa la Resource Layer di questo repo, come
  già fanno «EcPois» ed «EcTracks» dopo oc:8611.
  - Verifica: [EcTrackLayersAttachableFieldTest.php](../../../tests/Feature/EcTrackLayersAttachableFieldTest.php) › `test_layers_field_points_to_local_layer_resource`
- <a id="r3"></a>**R3** — Nessun cambiamento in ciò che l'utente vede: stessi cammini proponibili,
  stesse etichette, stessi permessi (il campo resta visibile al solo Administrator, oc:8611).
  - Verifica: [EcTrackLayersAttachableFieldTest.php](../../../tests/Feature/EcTrackLayersAttachableFieldTest.php) › `test_layers_field_stays_hidden_to_validator`
- <a id="r4"></a>**R4** — La query che riempie il menu di associazione legge dei cammini solo ciò
  che serve a comporre le voci, senza portarsi dietro le tracce e i POI associati.
  - Verifica: [EcTrackLayersAttachableFieldTest.php](../../../tests/Feature/EcTrackLayersAttachableFieldTest.php) › `test_relatable_layers_query_drops_eager_loads_and_selects_few_columns`

## Rischi

- La correzione che conta sta in wm-package: finché non è rilasciata, il fix di questo repo copre
  solo il campo «Layers» della tappa. Altri elenchi di relazione verso i Layer che passano dalla
  Resource del package restano lenti. Mitigazione: i due interventi vanno insieme, e questo repo
  bumpa il gitlink nello stesso ciclo.
- `App\Nova\Layer` ha `indexQuery()` che filtra per `user_id` ai non-Administrator. Il campo è
  riservato all'Administrator, quindi non cambia nulla: verificato nel browser, il menu elenca
  tutti i cammini come prima.

## Out of scope

- La colonna `geometry` di `taxonomy_wheres` (93 MB) non si tocca: serve ad altro.

## Moduli toccati

- `app/Nova/EcTrack.php` — il campo «Layers» ripuntato alla Resource locale e il nuovo
  `relatableLayers()`.
- `tests/Feature/EcTrackLayersAttachableFieldTest.php`.
- Il bump del gitlink `wm-package` **non** fa parte di questo rilascio: vedi le note.

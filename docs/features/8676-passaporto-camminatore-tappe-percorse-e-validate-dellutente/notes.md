> Ticket: oc:8676

# Notes — Passaporto camminatore: tappe percorse e validate (backend)

## Divergenze dal piano, task per task

### Task 1 servizio di calcolo
- `summaryQuery()` diventa `summaryQuery(?User $viewer = null)` e applica lo scoping di ruolo dentro la query aggregata: la riga riassuntiva non ha `ec_track_id`, quindi `scopeVisibleTo()` (pensato per le singole validazioni) non le si può applicare. `null` = nessuno scoping; Administrator tutto; Validator solo i `layer_id` dei layer con `layers.user_id` = suo id; altri ruoli niente. La Lens del Task 4 chiama `summaryQuery($request->user())`.
- L'`id` della riga riassuntiva è `(user_id::bigint << 32) | layer_id` invece di `MIN(validated_ec_tracks.id)`: con una tappa condivisa fra due layer dello stesso proprietario `MIN(id)` si ripeteva su due righe della Lens. In JS l'id resta un intero sicuro finché `user_id` < 2^21.
- Nei test le tappe sono associate ai layer con un insert diretto su `layerables`, non con `attach()`: `LayerableObserver` (oc:8080) passerebbe la tappa al proprietario del layer e il caso «tappa di un altro proprietario» non sarebbe riproducibile.

### Task 3 sezione Nova
- La sola lettura è rinforzata con un override di `authorizedTo()`/`authorizeTo()` che ammette solo view/viewAny: senza policy dedicata Nova non passava da `authorizedToUpdate()` e un `PUT /nova-api/validated-ec-tracks/{id}` rispondeva 200 (senza modificare dati). Guest e utenti senza ruolo ricevono 403 da `authorizedToViewAny()`, perché il `canSee` del menu non blocca l'API.
- Il filtro cammino riconosce la Lens con `instanceof LensRequest` (sulla riga aggregata filtra `layer_id`); sulla risorsa usa le colonne qualificate `layerables.layer_id` / `ec_tracks.id` di `countingTracksQuery()`, perché dopo `select()` gli alias non esistono.

### Task 4 lens riassuntiva
- La Lens chiama `summaryQuery($request->user())`, che applica lo scoping di ruolo dentro la query aggregata, invece di restringerla con `scopeVisibleTo()` come diceva il piano (vedi Task 1). Il `GROUP BY` è avvolto in una subquery con alias `validated_ec_tracks`, così paginazione, filtri e ordinamenti di Nova lavorano su colonne semplici.
- Le righe non portano al dettaglio di una validazione (`authorizedToView()` è false sulle richieste della Lens), perché l'`id` della riga è sintetico.
- Ordinamento di default: ultima validazione decrescente.

### Dopo la review: regole delle tappe, nome, distanza e progress
- **Due regole delle tappe** al posto di una (decisione del dev dopo la richiesta della sessione frontend): `routeTracksQuery()` conta tutte le tappe del cammino per l'API e per i numeri della Lens; `managedTracksQuery()` (la vecchia `countingTracksQuery()`) resta la regola del gestore per Nova e per la decisione. Lo stato `not_validatable` previsto dal piano è stato rimosso.
- `tracks[]` riceve `name`, `distance` e `progress` (vedi Decisioni).
- Filtro «solo validate» in un punto unico (`ValidatedEcTrack::scopeValidated()`), in preparazione di oc:8165.

### Review finale
- Lo scoping del Validator usa `layers.user_id = <id del Validator>` (come `CertificationRequest::scopeVisibleTo()`, scritto così nel piano), non `COALESCE(layers.user_id, default_owner_id)`: un Validator che fosse il proprietario di default non vedrebbe le validazioni dei layer senza `user_id`. Oggi non ha effetti, perché il proprietario di default (id 2) è un Administrator, che vede tutto.
- I km nel JSON sono serializzati con `JSON_PRESERVE_ZERO_FRACTION` (`17.0`, non `17`), deciso prima che il formato venga usato dall'app.

## Bug trovati

## Decisioni

- **Tag Orchestrator non associati** (2026-10-01): il dev ha chiesto di saltare per ora sia la proposta dei tag di ambiente (candidato `camminiditalia`) sia quella dei tag di contenuto. Nessun tag associato né creato.
- **Ricerca sulle call senza esito utilizzabile**: NotebookLM ha risposto senza riferimenti sulla call del 01/10, quindi nessuna citazione attribuibile. Tutte le decisioni di questo ciclo vengono dal dialogo con il dev.
- **Stima non indipendente**: `wm-estimate` ha rifiutato perché in questo workflow la stima precede il piano. La stima l'ha fatta il context principale; il dev ha contestato la prima versione (11,3h di implementazione, dimensionata come per uno sviluppatore che scrive a mano) e ha approvato la seconda: misurato 1,4h + stimato 4,3h = 5,7h.
- **`name` e `distance` per tappa in `tracks[]`** (richiesta della sessione frontend): in un primo momento il dev aveva scelto di non aggiungerli, ritenendoli ridondanti; poi la sessione frontend ha verificato che l'app non ha l'elenco completo delle tappe del layer (le tappe in memoria sono i risultati dell'ultima ricerca Elastic, sostituiti a ogni ricerca), e il dev ha approvato di aggiungerli. `name` = traduzioni della tappa senza lingue vuote (`{}` se manca); `distance` = km della tappa con la stessa regola dei totali, a 1 decimale. Limite noto: `km_validated` arrotonda la somma esatta, quindi con frazioni sotto 0,05 km la somma delle `distance` arrotondate può differire di 0,1.
- **Branch e PR verso `Passaporto`**: per scelta del dev il branch parte da `Passaporto` e la PR va verso `Passaporto`, non verso `develop`.
- **Stato del ticket**: dopo la scrittura della stima Orchestrator ha riportato oc:8676 da `progress` a `todo` senza intervento nostro; da sistemare con lo status finale.

## Follow-up

- **Varianti e completamento del cammino → oc:8165** (decisione del dev): le tappe «Variante» (37 su 1285, in 13 layer; nessun campo nei dati le distingue, solo il nome) oggi contano nel totale, quindi un cammino percorso sul tracciato principale risulta per esempio «12 di 13» e non completato (layer 40: tappa 712 «Tappa 09 Variante» accanto alla 489 «Tappa 09»). Saranno gestite in oc:8165 con l'algoritmo di completamento già studiato: https://webmappsrl.github.io/camminiditalia/artifacts/8165-passaporto-camminatore/
- **Avanzamento parziale GPS → oc:8165**: tabella unica `validated_ec_tracks` (decisione del dev), con colonna `progress` e `validated_at` nullable. In oc:8676 sono già pronti lo scope `ValidatedEcTrack::scopeValidated()` usato ovunque e il campo API `progress` (oggi 100/0). In oc:8165 va gestita in `CertificationRequestService::decide()` la promozione di una riga parziale esistente (vincolo unico `user_id, ec_track_id`).

- Con il GPS (oc:8165) rivedere due punti che oggi scalano col numero di camminatori e tappe: le opzioni del filtro camminatore (tutti gli utenti con almeno una validazione, senza limite) e la colonna «Routes», calcolata su tutte le tappe visibili invece che sulla sola pagina.
- La colonna «User» ordina per id utente, non in ordine alfabetico: raggruppa per camminatore ma non è un ordinamento per nome.

- Segnalare al cliente le tappe con distanza manuale anomala (es. tappa 56 con `manual_data.distance = 1500`, probabilmente metri): finiscono così nei km del passaporto.

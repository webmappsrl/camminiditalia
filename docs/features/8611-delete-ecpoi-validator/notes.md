> Ticket: oc:8611

# Notes — Sblocco eliminazione EcPoi per il Validator

## Deviazioni dal piano

- **Docker non disponibile a inizio esecuzione**: il codice di Task 1 e Task 2 è stato scritto insieme ai test senza poter osservare il RED (violazione della lettera del TDD). Appena Docker è tornato disponibile, il RED è stato verificato retroattivamente per entrambi i task: ripristinato temporaneamente il comportamento pre-fix (`delete()` → `false`, `authorizedToDelete()` → solo Administrator), confermato che i test attesi fallissero per il motivo corretto, poi ripristinato il fix reale e confermato il GREEN. Dettaglio completo nel ledger di esecuzione (`.superpowers/sdd/plan/progress.md`).
- **Difetto del piano trovato durante la verifica RED del Task 2**: il test `test_validator_cannot_delete_own_ec_poi_still_linked_to_a_track` (non presente nel testo originale del piano con `Queue::fake()`/`Http::fake()`) creava un `EcTrack` via factory, innescando `EcTrackObserver::created()` → `Bus::chain` con `UpdateEcTrack3DDemJob` → chiamata HTTP reale al servizio DEM esterno (falliva con 400, geometria di test non valida). Corretto aggiungendo `Queue::fake()`+`Http::fake()` scoped al test, stesso pattern già in uso in `tests/Feature/LayerFeatureControllerTest.php:235`.

## Bug trovati

- **Test preesistente flaky, scorrelato**: `RecalculateLayerAttributesJobTest > handle writes filters and dispatches config regeneration` fallisce in modo intermittente nella suite completa (passa sempre in isolamento). Riprodotto anche su `main` pulito tramite `git stash` (2 run su main, senza le modifiche di questa feature) — non è una regressione introdotta da questo ciclo. Non corretto (fuori scope). Il dev verificherà se il problema si presenta anche nella GitHub Action della PR (in locale non risultava prima) prima di decidere se aprire un ticket dedicato.

## Decisioni

- **Duplicazione a 4 vie della regola di ownership non risolta**: `EcPoiPolicy::update()/delete()` e `App\Nova\EcPoi::authorizedToUpdate()/authorizedToDelete()` implementano la stessa espressione booleana in 4 punti indipendenti, senza astrazione condivisa — emerso dalla review (`wm-skills:wm-review-ticket`). Lasciato invariato: va contro il vincolo esplicito del piano ("riusare esattamente la stessa espressione, non una riformulazione") ed è coerente con un pattern già preesistente nel repo per `update()`, non introdotto da questo ciclo.
- **Cleanup post-review applicato** (richiesto esplicitamente dal dev dopo la review): aggiunto `test_guest_cannot_delete_ec_poi_via_nova` — ha rivelato che il Guest riceve `403` sulla delete via Nova (bloccato prima, a livello di autorizzazione generale della risorsa), a differenza del Validator non proprietario che riceve sempre `200` con il record filtrato in silenzio da `DeleteResourceRequest::deletableModels()`. Sostituita la sola verifica dello stato DB nel test "still linked to a track" con un `assertStatus(500)` esplicito (il guard preesistente in `wm-package` risponde con eccezione HTTP 500, non 403/422).
- **Righe orfane in `layerables`, ownership non stabile nel tempo, assenza di soft delete, rollback asimmetrico**: rischi emersi in `Fase: challenge` di `wm-plan`, accettati esplicitamente dal dev — dettaglio completo nella sezione "Rischi" di `overview.md`.

## Follow-up

- Verificare in CI (GitHub Action sulla PR) se `RecalculateLayerAttributesJobTest` fallisce anche lì. Se sì, aprire un ticket dedicato (bug non correlato a questa feature).
- La voce CLAUDE.md `oc:8120 — EcPoi: sola lettura per Validator` era già superata nel codice prima di questo ciclo (`update()` già ownership-based) — aggiornata in `Fase: update-context`.

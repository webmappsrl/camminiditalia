# Autorizzazione EcPoi per il Validator

## Come funziona oggi

Il Validator (gestore di cammino) può **vedere sempre** ogni EcPoi, **modificare** ed **eliminare** solo i propri (`user_id === $user->id`). L'Administrator può sempre tutto. Il Guest non vede nulla.

La regola di ownership (`Administrator OR (Validator AND $ecPoi->user_id === $user->id)`) è implementata **quattro volte, indipendentemente**, senza alcuna astrazione condivisa:

- `App\Policies\EcPoiPolicy::update()` e `::delete()`
- `App\Nova\EcPoi::authorizedToUpdate()` e `::authorizedToDelete()`

**Il flusso reale di Nova (bottone/endpoint `nova-api/ec-pois`) non passa mai dalla Policy.** `App\Nova\EcPoi::authorizedToUpdate()`/`authorizedToDelete()` sono override completi (non richiamano `parent::` né il Gate) — la Policy Laravel è quindi esercitata solo da chiamate dirette al Gate (`Gate::allows(...)`), di fatto solo nei test (`tests/Feature/EcPoiPolicyTest.php`). Il comportamento realmente visto dall'utente in Nova dipende **solo** dai due metodi `authorizedTo*` su `App\Nova\EcPoi`.

Le due suite di test (`EcPoiPolicyTest` via Gate, `EcPoiNovaAuthorizationTest` via HTTP reale su `nova-api`) verificano quindi due codepath indipendenti che devono restare sincronizzati a mano — nessuno dei due si accorge se l'altro diverge.

**Comportamento HTTP diverso per ruolo, sulla delete Nova (`DELETE /nova-api/ec-pois`):**

- Validator non proprietario: risposta **200**, il record resta nel DB (filtrato in silenzio da `Laravel\Nova\Http\Requests\DeleteResourceRequest::deletableModels()`, che scarta i modelli non autorizzati prima di cancellarli)
- Guest: risposta **403** (bloccato prima, a livello di autorizzazione generale della risorsa, non arriva al filtro sui singoli modelli)

Un test che verifica solo lo status code sulla delete dà quindi un falso positivo per il caso Validator non proprietario — va sempre verificato lo stato del DB (`assertDatabaseHas`/`assertDatabaseMissing`).

Guard indipendente, non toccato da questa autorizzazione: `Wm\WmPackage\Observers\EcPoiObserver::deleting()` blocca comunque (HTTP 500, non 403/422) la cancellazione di un EcPoi ancora agganciato a una o più EcTrack, per qualunque ruolo — non ha nulla a che fare con l'ownership.

## Perché così

- **Validator: read-only per default, poi progressivamente sbloccato per ownership** (oc:8120 → oc:8304 → oc:8611): oc:8120 ha introdotto la Policy locale con Validator strettamente sola-lettura. oc:8304 ha reso `update()` ownership-based (gestione autonoma dei POI). oc:8611 ha esteso lo stesso criterio a `delete()`, su richiesta esplicita del cliente arrivata nello stesso thread del ticket, dopo lo sblocco di creazione/associazione EcTrack↔EcPoi in modalità impersonate. La vecchia descrizione "EcPoi: sola lettura per Validator" nella tabella feature del CLAUDE.md era quindi già superata da oc:8304 prima ancora di oc:8611 — non aggiornata a suo tempo.
- **Duplicazione a 4 vie accettata, non risolta** (oc:8611): introdurre un helper condiviso (Policy ↔ Nova) è stato valutato in review e scartato — è un refactoring che va oltre lo scope di una richiesta cliente puntuale, tocca un pattern già in uso per `update()` da prima di questo ciclo, e nessuno l'aveva mai messo in discussione. Mitigazione minima: ogni nuovo metodo `authorizedToX`/policy-method deve riusare l'espressione letterale già presente altrove nel file, mai una riformulazione — un test HTTP end-to-end (non solo Gate) va sempre aggiunto per ogni nuova ability sbloccata in Nova, perché è l'unico modo per accorgersi se i due cancelli divergono.

## Come ci siamo arrivati

- **Meccanismo anti-drift (helper condiviso Policy/Nova) proposto in review, scartato** (oc:8611): risolverebbe la duplicazione alla radice, ma è stato giudicato fuori scope per questo ciclo — vedi "Perché così" sopra. Non riproporlo come piccola aggiunta: è un cambio di pattern che tocca sia il file Policy sia la risorsa Nova, da valutare a sé.

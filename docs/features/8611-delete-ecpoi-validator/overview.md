> Ticket: oc:8611

# Sblocco eliminazione EcPoi per il Validator

## Cosa cambia
Il Validator (gestore di cammino) può eliminare gli EcPoi di sua proprietà (`user_id` coincidente con l'utente), tramite l'interfaccia Nova standard (detail view / delete action). Il comportamento resta invariato per gli EcPoi non di proprietà (bloccato) e per l'Administrator (può sempre eliminare, come oggi).

## Perché
Richiesta esplicita del cliente nel thread del ticket oc:8611 (21/09/2026 15:38): dopo aver sbloccato la creazione e l'associazione EcTrack↔EcPoi in modalità impersonate, il cliente ha chiesto di poter eliminare i POI creati. Oggi `EcPoiPolicy::delete()` nega sempre, indipendentemente da chi e da quale POI (decisione oc:8120, "EcPoi: sola lettura per Validator" — in parte già superata nel codice attuale, vedi Rischi).

## Requisiti
- [ ] `App\Policies\EcPoiPolicy::delete()` diventa ownership-based: `$user->id === $ecPoi->user_id`, stesso pattern già in uso per `EcPoiPolicy::update()` e per `EcTrackPolicy::delete()`
- [ ] `App\Nova\EcPoi::authorizedToDelete()` allineato allo stesso criterio già usato da `authorizedToUpdate()` sulla stessa risorsa (oggi hard-coded solo Administrator — è un secondo cancello che altrimenti resterebbe bloccante anche a policy corretta)
- [ ] Administrator continua a poter eliminare qualsiasi EcPoi (invariato, via `before()`)
- [ ] Validator non proprietario resta bloccato sulla delete (invariato)
- [ ] Guest resta completamente bloccato (invariato, via `before()`)
- [ ] Test a livello Gate: Validator proprietario può eliminare, Validator non proprietario non può, Administrator può sempre (mirror di `EcTrackPolicyTest`)
- [ ] Test HTTP Nova end-to-end sulla delete (oltre al test di Gate), per coprire esplicitamente il doppio cancello `authorizedToDelete()` + Policy
- [ ] Test HTTP Nova end-to-end anche sulla `update()` (stesso doppio cancello, oggi scoperto da regressione end-to-end nonostante esposto allo stesso rischio da prima di questo ciclo — emerso in Challenge)

## Rischi
- **Guard preesistente su EcPoi ancora linkato a tracce**: `Wm\WmPackage\Observers\EcPoiObserver::deleting()` blocca comunque la cancellazione con `HttpException(500, ...)` se l'EcPoi è ancora associato a una o più EcTrack. Il messaggio è tecnico e in inglese, poco chiaro per un Validator. Decisione esplicita: comportamento lasciato invariato in questo ciclo (fuori scope, non si tocca `wm-package`).
- **Documentazione CLAUDE.md disallineata**: la voce `oc:8120 — EcPoi: sola lettura per Validator` descrive un blocco assoluto che nel codice attuale non è più tale — `EcPoiPolicy::update()` è già ownership-based. Questo ciclo corregge anche `delete()` allo stesso pattern; la voce CLAUDE.md va aggiornata in `Fase: update-context` per riflettere lo stato reale (Validator: view sempre, update/delete solo sui propri EcPoi).
- **Doppio cancello Nova**: dimenticare di aggiornare `authorizedToDelete()` lascerebbe la UI bloccata anche a policy corretta — mitigato dal test HTTP Nova end-to-end richiesto sopra (esteso anche a `update()`, stesso rischio preesistente).
- **[Challenge] Cancellazione irreversibile, nessun soft delete**: EcPoi non usa `SoftDeletes`. La delete rimuove il record e i file fisici associati (`StorageService::deleteModelFiles()`), senza possibilità di recupero se non da backup. Nessun meccanismo di conferma custom o audit trail aggiunto in questo ciclo (oltre alla conferma standard Nova). **Rischio accettato esplicitamente dal dev** — fuori scope soft delete/audit trail.
- **[Challenge] Righe orfane in `layerables`**: la tabella non ha FK né `ON DELETE CASCADE`. Per `EcTrack` esiste già un hook di pulizia parziale (difetto preesistente noto); per `EcPoi` non esiste alcun hook equivalente. Sbloccare la delete per ogni Validator sui propri POI aumenta il volume di cancellazioni e quindi l'accumulo di righe orfane. **Rischio accettato esplicitamente dal dev** — nessun ticket di pulizia aperto in questo ciclo.
- **[Challenge] Ownership (`user_id`) non stabile nel tempo**: viene riscritta in bulk da `LayerObserver` (oc:8080) e `EcPoiEcTrackObserver`/`EcPoiValidatorLayerObserver` (oc:8139) al cambio owner del layer o al collegamento a nuove tracce. Un Validator può perdere/guadagnare il diritto di eliminare un EcPoi senza essere avvisato; EcPoi condivisi M:N tra layer con owner diversi non hanno un livello di conferma aggiuntivo. **Rischio accettato esplicitamente dal dev** — coerente con la decisione "ownership last-write-wins" già presa in oc:8080/oc:8139, non modificata da questo ciclo.
- **[Challenge] Rollback asimmetrico**: un revert del codice non recupera EcPoi/file già eliminati (stesso rischio del punto sopra su irreversibilità). Il rischio di rollback parziale (policy e `authorizedToDelete()` disallineati durante un hotfix) è mitigato dal fatto che i due file sono modificati nello stesso commit/PR.

## Out of scope
- Nessuna modifica a `wm-package` (`EcPoiObserver::deleting()` resta invariato)
- Nessuna action Nova di bulk-delete dedicata (non esiste oggi, la delete singola standard basta)
- Nessun cambiamento al comportamento di `restore()`/soft delete (EcPoi non usa `SoftDeletes`, non applicabile)
- Nessun nuovo testo utente/traduzione (nessuna stringa di conferma delete personalizzata, nessuna chiave i18n dedicata)
- Nessun miglioramento al messaggio di errore per EcPoi ancora linkati a tracce (decisione esplicita del dev)

## Moduli toccati
- `app/Policies/EcPoiPolicy.php` (repo principale)
- `app/Nova/EcPoi.php` (repo principale)
- `tests/Feature/EcPoiPolicyTest.php` (repo principale)
- Eventuale nuovo test feature HTTP Nova (repo principale, file da definire in `plan.md`)

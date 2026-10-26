> Ticket: oc:8809

# Passaporto: flag cartaceo e flag GPS — piano di implementazione

> **Per chi esegue:** usare superpowers:subagent-driven-development o superpowers:executing-plans,
> task per task. **Nessun `git commit`/`git add`/`git push` durante l'esecuzione**: i commit sotto
> sono istruzioni per il dev, eseguite solo dopo il review-gate di wm-plan.

**Obiettivo:** separare il passaporto di un cammino in due flag, cartaceo (API dell'app) e GPS
(sblocco automatico delle tappe), con il vincolo GPS ⇒ cartaceo.

> ⚠️ Questo piano è stato superato in più punti dopo l'implementazione: chiave cartacea invariata e
> nessuna migration, revoca allo spegnimento del GPS tolta (scrum del 23/10), Action che estende la
> `BulkEditAction`. Lo stato vero è in [notes.md](notes.md), «Divergenze dal piano, task per task».

**Architettura:** le regole dei flag stanno in `App\Support\PassportLayer` (lettura, SQL,
normalizzazione). `PassportLayerObserver` normalizza su `saving` e reagisce su `updated`
(ricalcolo all'accensione del GPS, revoca allo spegnimento). Il form Nova e una nuova Action
locale per la modifica in blocco scrivono con `save()`, quindi passano entrambi dall'observer.

**Tech stack:** Laravel, Nova 5, PostgreSQL (jsonb), PHPUnit (`php artisan test`, DB
`camminiditalia_testing`).

**Spec:** [overview.md](overview.md)

## Vincoli globali

> ⚠️ Chiave cartacea e migration sono cambiate dopo l'implementazione: [notes.md](notes.md#task-1-la-chiave-del-cartaceo-non-cambia)

- Tutto nel repo `camminiditalia`: nessuna modifica a `wm-package` né al frontend.
- Chiavi in `layers.properties`: `passport_paper_enabled` (al posto di `passport_enabled`) e
  `passport_gps_enabled`. Acceso solo se il valore è il booleano `true`, come oggi.
- Etichette it: «Passaporto cartaceo», «Passaporto automatico (GPS)». Chiavi di traduzione in
  inglese, traduzioni in `resources/lang/{it,en,fr,es,de}.json`.
- Solo l'Administrator modifica i due flag (form e Action).
- Comandi: `docker exec laravel-camminiditalia php artisan test --filter=...`. Dopo aver scritto la
  migration: `docker exec laravel-camminiditalia php artisan migrate --env=testing`.

## Punti da sorvegliare in review

1. Tappa in due cammini A e B col GPS acceso: spegnere il GPS su A **non** cancella la riga (test
   nel task 3).
2. Riga `gps` con `layer_id` = B e tappa anche in A: spegnere il GPS su B e lasciarlo su A non la
   cancella; spegnerlo su entrambi sì (task 3).
3. Stesso salvataggio con GPS acceso e cartaceo spento: alla fine il cartaceo è acceso (task 2).
4. Modifica in blocco «cartaceo = No» su un cammino con il GPS acceso e GPS «non cambiare»: nessun
   cammino modificato, messaggio d'errore (task 5).
5. `/api/passport` e `/api/layer/{layer}/...` rispondono per un cammino solo cartaceo (task 1,
   test esistenti di `PassportLayerGateTest` aggiornati).

---

### Task 1: Chiavi rinominate, flag GPS e migration

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-la-chiave-del-cartaceo-non-cambia)

**Files:**
- Modify: `app/Support/PassportLayer.php`
- Create: `database/migrations/2026_10_22_100000_split_passport_flag_into_paper_and_gps.php`
- Modify: `app/Http/Middleware/EnsurePassportEnabled.php`, `app/Services/StageProgressService.php`,
  `routes/api.php` (chiamate e commenti)
- Modify: `tests/Feature/Helpers/LayerTestHelpers.php`, i test che scrivono `passport_enabled`
  (`LayerPassportFieldTest`, `PassportLayerGateTest`, `StageValidationServiceTest`,
  `RecomputeStageValidationsJobTest`, `ComputeStageValidationsCommandTest`)
- Test: `tests/Feature/PassportFlagMigrationTest.php`

**Interfaces — Produces** (in `PassportLayer`; `isEnabled()` ed `enabledSql()` vengono **tolti**,
così ogni chiamata dimenticata fallisce invece di cambiare significato in silenzio):
- `const PAPER_KEY = 'passport_paper_enabled'`, `const GPS_KEY = 'passport_gps_enabled'`
- `static isPaperEnabled(Layer $layer): bool`, `static paperEnabledSql(string $alias = 'layers'): string`
- `static isGpsEnabled(Layer $layer): bool`, `static gpsEnabledSql(string $alias = 'layers'): string`
- helper di test: `enablePassport(Layer $layer): Layer` accende **entrambi** (chi lo usa oggi
  presuppone il GPS attivo); nuovo `enablePaperPassport(Layer $layer): Layer` accende solo il
  cartaceo. Entrambi con `saveQuietly()`, come oggi.

- [ ] **Step 1: test della migration** in `PassportFlagMigrationTest`:
  `test_up_moves_passport_enabled_to_paper_key` (layer con `passport_enabled: true` e
  `other: 'keep'` → dopo `up()` ha `passport_paper_enabled: true`, nessuna `passport_enabled`,
  nessuna `passport_gps_enabled`, `other` intatto; layer con `passport_enabled: false` →
  `passport_paper_enabled: false`); `test_up_deletes_gps_stages_and_keeps_manual` (una riga
  `gps` e una `manual` → resta solo la `manual`); `test_down_restores_passport_enabled`.
  Il test richiama `up()`/`down()` dell'istanza della migration (`require` del file).
- [ ] **Step 2:** eseguire `--filter=PassportFlagMigrationTest` → FAIL (file assente).
- [ ] **Step 3: migration.** `up()`: un `UPDATE layers` in SQL jsonb che, dove esiste la chiave
  `passport_enabled`, scrive `passport_paper_enabled` con lo stesso valore e toglie la vecchia;
  poi `DELETE FROM passport_stages WHERE source = 'gps'` (il pivot va via con il cascade). `down()`:
  rinomina al contrario e toglie `passport_gps_enabled`; le righe `gps` cancellate non si
  ripristinano (commento nel file: in produzione il 22/10 erano 0).
- [ ] **Step 4: `PassportLayer`** con l'interfaccia sopra; docblock aggiornato (due flag, regola
  GPS ⇒ cartaceo rimandata al task 2).
- [ ] **Step 5:** sostituire le chiamate: middleware e `StageProgressService` (lista
  `/api/passport`) → `isPaperEnabled`/`paperEnabledSql`. `StageValidationService::coverage()` e
  `ComputeStageValidationsCommand::usersWithUgcTracks()` → `gpsEnabledSql`. Aggiornare commenti
  in `routes/api.php` e `StageProgressService` che citano `passport_enabled`.
- [ ] **Step 6:** aggiornare helper e test esistenti alla nuova chiave. In `PassportLayerGateTest`
  usare `enablePaperPassport()`: le API devono rispondere con il solo cartaceo.
- [ ] **Step 7:** `migrate --env=testing`, poi suite intera → PASS.
  `grep -rn "passport_enabled\|isEnabled(\|enabledSql(" app routes tests` non trova più nulla,
  salvo la migration.
- [ ] **Step 8 (commit, istruzione per il dev):**
  `feat(oc:8809): flag del passaporto separato in cartaceo e GPS`

### Task 2: Vincolo GPS ⇒ cartaceo

**Files:**
- Modify: `app/Support/PassportLayer.php`, `app/Observers/PassportLayerObserver.php`
- Test: `tests/Feature/PassportLayerFlagsTest.php`

**Interfaces — Produces:** `static PassportLayer::normalize(array $properties): array` — se
`GPS_KEY === true` imposta `PAPER_KEY = true`; altrimenti restituisce l'array invariato.
`PassportLayerObserver::saving(Layer $layer): void` applica `normalize()` a `properties`.

- [ ] **Step 1: test:** `test_turning_gps_on_turns_paper_on` (layer senza flag, `save()` con
  GPS `true` → cartaceo `true`); `test_gps_on_and_paper_off_in_same_save_keeps_paper_on`;
  `test_paper_can_be_turned_off_when_gps_is_off`; `test_normalize_ignores_non_boolean_gps`
  (GPS `"true"` stringa → cartaceo invariato).
- [ ] **Step 2:** eseguire → FAIL.
- [ ] **Step 3:** implementare `normalize()` e `saving()`. `properties` può arrivare come stringa
  JSON: decodificare come già fa `updated()`.
- [ ] **Step 4:** eseguire → PASS.
- [ ] **Step 5 (commit):** `feat(oc:8809): accendere il GPS accende il passaporto cartaceo`

### Task 3: Ricalcolo all'accensione del GPS, revoca allo spegnimento

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3-spegnere-il-gps-non-revoca-nulla)

**Files:**
- Modify: `app/Observers/PassportLayerObserver.php` (logica e docblock), `app/Services/StageValidationService.php`,
  `app/Jobs/RecomputePassportStagesForLayerJob.php` (docblock)
- Create: `app/Jobs/RevokeGpsPassportStagesForLayerJob.php`
- Test: `tests/Feature/PassportLayerObserverTest.php`, `tests/Feature/StageValidationServiceTest.php`

**Interfaces:**
- Consumes: `PassportLayer::isGpsEnabled`, `gpsEnabledSql` (task 1).
- Produces: `StageValidationService::revokeGpsForLayer(int $layerId): int` — cancella le righe
  `passport_stages` con `source = 'gps'` la cui `ec_track_id` è fra le tappe del layer
  (`layerables`, `layerable_type` = `$this->matching->ecTrackMorphClass()`) e **non** è fra le
  tappe di un altro layer con `gpsEnabledSql()`. Restituisce il numero di righe cancellate.
  Non usa `passport_stages.layer_id`.
- Produces: `RevokeGpsPassportStagesForLayerJob(int $layerId)`, stessa coda di
  `RecomputePassportStagesForLayerJob`.

Regole in `PassportLayerObserver::updated()` (confronto fra `getOriginal('properties')` e i valori
attuali):
- GPS da spento ad acceso → `RecomputePassportStagesForLayerJob::dispatch()->afterCommit()`;
- GPS da acceso a spento → `RevokeGpsPassportStagesForLayerJob::dispatch()->afterCommit()`;
- cartaceo acceso o spento con il GPS spento → nessun job (oggi l'accensione del passaporto
  ricalcola: non più).

Il docblock dell'observer va **riscritto**: lo spegnimento del cartaceo non revoca nulla, lo
spegnimento del GPS revoca le tappe sbloccate dal GPS, con il motivo (oc:8809, il cliente non
vuole il GPS attivo).

- [ ] **Step 1: test del service** in `StageValidationServiceTest`:
  `test_revoke_gps_for_layer_deletes_gps_rows_of_its_stages` (riga `gps` e riga `manual` su tappe
  del layer → resta solo la `manual`, ritorna 1);
  `test_revoke_gps_keeps_stage_shared_with_another_gps_layer` (tappa in A e B, entrambi GPS
  acceso, riga con `layer_id` = A; si spegne A e si chiama revoke(A) → la riga resta);
  `test_revoke_gps_ignores_layer_id_column` (tappa in A e B, riga con `layer_id` = B; B col GPS
  spento, revoke(A) → la riga sparisce); `test_coverage_skips_paper_only_layers` (layer solo
  cartaceo con UGC che copre la tappa → `recomputeForUser` non crea righe).
- [ ] **Step 2: test dell'observer** in `PassportLayerObserverTest` con `Queue::fake()`:
  `test_gps_on_dispatches_recompute`, `test_gps_off_dispatches_revoke`,
  `test_paper_on_alone_dispatches_nothing`, `test_paper_off_dispatches_nothing`. Togliere da
  `StageValidationServiceTest` l'eventuale test che si aspetta il ricalcolo all'accensione del
  passaporto, o adattarlo al GPS.
- [ ] **Step 3:** eseguire entrambi → FAIL.
- [ ] **Step 4:** implementare `revokeGpsForLayer()`, il job, la logica e il docblock dell'observer.
- [ ] **Step 5:** eseguire → PASS; poi suite intera → PASS.
- [ ] **Step 6 (commit):** `feat(oc:8809): ricalcolo e revoca delle tappe GPS al cambio del flag`

### Task 4: Campi Nova e traduzioni

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-4-chiavi-e-testo-di-aiuto)

**Files:**
- Modify: `app/Nova/Layer.php:314-320`, `resources/lang/{it,en,fr,es,de}.json`
- Test: `tests/Feature/LayerPassportFieldTest.php`

**Campi** (stesso `readonly` di oggi per i non Administrator):
- `Boolean::make(__('Paper passport'), 'properties->passport_paper_enabled')`, help
  `__('Opens the Passport of this cammino in the app: requests to certify the paper credential, progress and sharing. It cannot be turned off while the automatic passport is on.')`.
  Con `dependsOn(['properties->passport_gps_enabled'], ...)`: se il GPS è acceso nel form, il
  campo diventa `readonly` e `true`.
- `Boolean::make(__('Automatic passport (GPS)'), 'properties->passport_gps_enabled')`, help
  `__('Unlocks the stages from the GPS tracks recorded in the app. Turning it on also turns on the paper passport; turning it off deletes the stages unlocked by GPS on this cammino.')`.

Se `dependsOn` non funziona sui campi `properties->` (verificare in Step 3), ripiego: nessun
`dependsOn`, resta il `saving()` del task 2 e l'help lo spiega; annotarlo in `notes.md`.

Traduzioni it: «Passaporto cartaceo», «Passaporto automatico (GPS)», help: «Apre il Passaporto di
questo cammino nell'app: richieste di certificazione della credenziale cartacea, progresso e
condivisioni. Non si può spegnere finché è acceso il passaporto automatico.» e «Sblocca le tappe
dalle tracce GPS registrate in app. Accenderlo accende anche il passaporto cartaceo; spegnerlo
cancella le tappe sbloccate dal GPS su questo cammino.». Tradurre in en/fr/es/de; togliere le
chiavi `Passport enabled` e `Enable the Passport for this cammino in the app.` se non usate altrove.

- [ ] **Step 1: test:** aggiornare i tre test sul campo (admin modificabile, Validator e Guest
  readonly) per **entrambi** i campi.
- [ ] **Step 2:** eseguire → FAIL.
- [ ] **Step 3:** implementare campi e traduzioni.
- [ ] **Step 4:** eseguire → PASS. Controllo traduzioni:
  `for f in resources/lang/*.json; do jq -e '."Paper passport" and ."Automatic passport (GPS)"' $f; done`
  stampa `true` cinque volte.
- [ ] **Step 5 (commit):** `feat(oc:8809): campi Nova per passaporto cartaceo e GPS`

### Task 5: Action locale di modifica in blocco

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5-laction-estende-la-bulkeditaction)

**Files:**
- Create: `app/Nova/Actions/BulkEditPassportAction.php`
- Modify: `app/Nova/Layer.php:766-770` (al posto della `BulkEditAction`), `resources/lang/*.json`
- Test: `tests/Feature/LayerPassportFieldTest.php`

**Interfaces — Produces:** `BulkEditPassportAction extends Nova Action`, `name()` =
`__('Passport: bulk edit')`, campi `Select` tri-stato (`null` = non cambiare, `'1'` = sì,
`'0'` = no; opzioni `__('Yes')`/`__('No')`, `nullable()`) per `paper` e `gps`.
`handle(ActionFields $fields, Collection $models)`:
1. calcola per ogni layer le `properties` risultanti e le passa a `PassportLayer::normalize()`;
2. se per un layer il cartaceo è stato chiesto `'0'` e il GPS risultante è `true` → nessun layer
   modificato, `Action::danger(__('The paper passport cannot be turned off on :names: the automatic passport is on.', ['names' => ...]))`;
3. altrimenti salva ogni layer con `save()` in una transazione (scattano observer, ricalcolo e
   revoca).
`canSee`/`canRun` solo Administrator, come oggi.

- [ ] **Step 1: test:** riscrivere `test_bulk_edit_passport_visible_only_to_admin` sull'uriKey
  della nuova Action; `test_bulk_gps_on_turns_paper_on_and_keeps_other_properties`;
  `test_bulk_paper_off_with_gps_on_changes_nothing` (verifica anche il messaggio);
  `test_bulk_gps_off_dispatches_revoke` (`Queue::fake()`);
  `test_bulk_null_fields_change_nothing`.
- [ ] **Step 2:** eseguire → FAIL.
- [ ] **Step 3:** implementare Action, registrazione e traduzioni (nome e messaggio in 5 lingue).
- [ ] **Step 4:** eseguire → PASS; suite intera → PASS.
- [ ] **Step 5 (commit):** `feat(oc:8809): modifica in blocco dei due flag del passaporto`

### Task 6: Guida cliente e screenshot

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-6-la-guida-dice-che-le-tappe-restano)

**Files:**
- Modify: `docs/guide/passaporto-attivazione/README.md`, i 3 `.png` della cartella

- [ ] **Step 1:** riscrivere la guida: i due interruttori, cosa fa ciascuno, la regola GPS ⇒
  cartaceo, cosa succede spegnendo il GPS (le tappe sbloccate dal GPS si cancellano), la modifica
  in blocco con i due campi. Nessun nome di file, classe o chiave.
- [ ] **Step 2:** sul Nova locale (`DB camminiditalia`, con la migration eseguita), da
  Administrator, rifare con il browser `scheda-cammino-passaporto.png`,
  `elenco-cammini-azioni.png`, `modifica-in-blocco-passaporto.png`, con cammini reali e nessun
  dato personale visibile.
- [ ] **Step 3:** verificare che ogni immagine citata nel README esista:
  `grep -o '([a-z-]*\.png)' README.md` contro `ls *.png`.
- [ ] **Step 4 (commit):** `docs(oc:8809): guida all'attivazione del passaporto con i due flag`

### Task 7: Verifica finale

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-7-niente-controllo-su-wm-core)

- [ ] `docker exec laravel-camminiditalia php artisan test` → tutto PASS.
- [ ] `docker exec laravel-camminiditalia composer format`.
- [ ] PHPStan (review-gate di wm-plan).
- [ ] Prima del rilascio: `git grep -n passport_enabled` sull'ultimo `develop` di wm-core →
  nessun risultato.
- [ ] Rilascio: deploy e `migrate` nello stesso passaggio; poi `horizon:terminate`.

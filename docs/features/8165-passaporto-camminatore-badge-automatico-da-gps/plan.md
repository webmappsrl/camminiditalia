> Ticket: oc:8165

# Passaporto camminatore: badge automatico da GPS — piano di implementazione

> **Per chi esegue:** usare `superpowers:subagent-driven-development` o
> `superpowers:executing-plans`. Gli step hanno caselle `- [ ]` per il tracciamento.
> **Nessun comando git viene eseguito**: i commit sono istruzioni per il dev.

**Obiettivo:** le tappe del passaporto si sbloccano da sole dalle UGC track registrate, e le
varianti di una tappa valgono l'una per l'altra.

**Approccio:** il lavoro geometrico esiste già (oc:8773): ogni tappa è divisa in segmenti di
400 m e ogni UGC track sa quali tocca. Si aggiunge la regola che unisce i segmenti di tutte le
UGC track di una persona e li confronta con una soglia, più la relazione fra una tappa e le sue
varianti. In `wm-package` due correzioni al dato di partenza: l'origine della traccia e i tratti
dritti lunghi.

**Stack:** Laravel 12, Nova 5, PostgreSQL 17 + PostGIS 3.5, PHPUnit/Pest.

**Spec:**
[overview.md](overview.md) (camminiditalia) e
[wm-package/docs/features/8165-…/overview.md](../../../wm-package/docs/features/8165-passaporto-camminatore-badge-automatico-da-gps/overview.md)

## Vincoli globali

- **Ordine fra i repo:** i task su `wm-package` (1-3) si completano e si merge **prima**; solo
  dopo camminiditalia bumpa il gitlink del submodule. Un consumer che chiama un metodo non ancora
  nel package rompe cose scollegate.
- **Comandi:** ogni `artisan` gira in `docker exec laravel-camminiditalia php artisan …`. I test
  del package girano con
  `docker run --rm --network camminiditalia_default -e DB_HOST=postgres-camminiditalia -v "$PWD/wm-package:/app" -w /app wm-phpfpm:8.4 vendor/bin/pest`.
- **Database di test:** dopo aver modificato una migration già eseguita serve
  `php artisan migrate:fresh --env=testing`.
- **I test del package non possono referenziare `Tests\TestCase`** del repo principale.
- **Parametri, valori fissi:** segmento 400 m, distanza massima 50 m (già in `wm-package`),
  soglia di copertura della tappa **80%**.
- **PHPStan** gira in CI su camminiditalia: deve restare verde.
- **Lessico:** una tappa è *validata* o *non validata* (mai «valida»); *UGC track registrata* e
  *traccia importata*; *corrispondenza* fra UGC track e tappa; *segmento*; *gruppo* di tappe
  alternative.
- **Documentazione e commenti in italiano**, termini tecnici in inglese.

## Punti da verificare in review

Cinque comportamenti che la spec implica e che è facile non coprire. Ogni riga ha il suo test nel
task che possiede il codice.

1. **Una validazione non si revoca** anche quando il ricalcolo porta la copertura sotto soglia —
   è la garanzia più facile da rompere con un `updateOrCreate` scritto male (task 5).
2. **Una riga `manual` non viene toccata**, nemmeno nella percentuale: il vincolo unico
   `(user_id, ec_track_id)` costringe il calcolo a scrivere sulla stessa riga (task 5).
3. **Una traccia importata non concorre**, nemmeno se copre la tappa al 100% (task 5).
4. **Un cammino con varianti raggiunge il 100%** percorrendo un ramo solo (task 8).
5. **Un tratto dritto lungo non valida le tappe che attraversa**, ma i suoi estremi sì (task 2).

---

## Task 0: riallineare il database di sviluppo

> ⚠️ **Lo esegue il dev, non l'implementatore.** Il comando è stato rifiutato dal classificatore
> dell'auto mode perché contiene `DROP` e `DELETE`.

Sul database di sviluppo risultano eseguite due migration del vecchio branch
`feature/oc-8165-…`, i cui file non esistono sul branch corrente. Senza questo passo le migration
del task 4 falliscono perché colonna e tabella esistono già.

**Backup già salvato** (8 righe di validazioni GPS del vecchio esperimento, 13 di collegamento):
`/private/tmp/claude-501/-Users-bongiu-Documents-camminiditalia/267f1e85-e8da-4410-a2fa-7c9071418aa7/scratchpad/bk_vet.csv`
e `bk_pivot.csv`.

- [ ] **Step 1: eseguire la pulizia**

```sql
BEGIN;
DELETE FROM passport_stages WHERE validated_at IS NULL;
DROP TABLE IF EXISTS passport_stage_ugc_track;
ALTER TABLE passport_stages DROP COLUMN IF EXISTS progress, DROP COLUMN IF EXISTS duration_seconds;
ALTER TABLE passport_stages ALTER COLUMN validated_at SET NOT NULL;
DELETE FROM migrations WHERE migration IN (
  '2026_10_06_100000_add_gps_progress_to_passport_stages_table',
  '2026_10_06_100100_create_passport_stage_ugc_track_table');
COMMIT;
```

- [ ] **Step 2: verificare**

Run: `docker exec postgres-camminiditalia psql -U camminiditalia -d camminiditalia -c "\d passport_stages"`
Atteso: nessuna colonna `progress` né `duration_seconds`, `validated_at` NOT NULL, e
`to_regclass('passport_stage_ugc_track')` nullo.

---

## Task 1: `UgcTrack::origin()` legge il campo esplicito

**Repo:** `wm-package`

**File:**
- Modifica: `src/Models/UgcTrack.php:160-171`
- Test: `tests/Unit/Models/UgcTrackOriginTest.php` (nuovo)

**Interfacce:**
- Produce: `UgcTrack::origin(): string` — invariata nella firma, restituisce `'recorded'` o
  `'imported'`. La usa `Wm\WmPackage\Nova\UgcTrack::originField()` e la userà il task 5.

- [ ] **Step 1: scrivere i test che falliscono**

Quattro casi, uno per ramo della regola:

```php
it('tratta come importata una traccia con properties.import', ...);   // ['import' => ['format' => 'gpx']]
it('tratta come importata una traccia con _gpxType', ...);            // ['_gpxType' => 'trk']
it('tratta come registrata una traccia con locations non vuote', ...);// ['locations' => [[...]]]
it('tratta come importata una traccia con solo distanceFilter', ...);  // ['distanceFilter' => 10] → 'imported'
```

L'ultimo è quello che cambia: oggi risponde `'recorded'`.

- [ ] **Step 2: verificare che falliscano**

Run: `vendor/bin/pest tests/Unit/Models/UgcTrackOriginTest.php`
Atteso: fallisce il quarto caso con `'recorded'` invece di `'imported'`.

- [ ] **Step 3: riscrivere `origin()`**

Ordine delle regole: `properties['import']` presente → `'imported'`; `_gpxType` presente →
`'imported'`; `locations` array non vuoto → `'recorded'`; altrimenti `'imported'`. Il ramo
`distanceFilter` sparisce. Nel docblock: perché `distanceFilter` non basta (resta valorizzato
anche su un dato importato) e il rimando a oc:8764 per `properties.import`.

- [ ] **Step 4: verificare che passino**

Run: `vendor/bin/pest tests/Unit/Models/UgcTrackOriginTest.php`
Atteso: 4 passati.

- [ ] **Step 5: commit**

```bash
git add src/Models/UgcTrack.php tests/Unit/Models/UgcTrackOriginTest.php
git commit -m "fix(oc:8165): origin() legge properties.import e lascia perdere distanceFilter"
```

---

## Task 2: il tratto dritto lungo non vale come percorso

**Repo:** `wm-package`

**File:**
- Modifica: `src/Services/Models/UgcTrackMatchingService.php` — `insertSql()` (righe 230-288)
- Test: `tests/Feature/Services/UgcTrackMatchingLongEdgeTest.php` (nuovo)

**Interfacce:**
- Consuma: niente dal task 1.
- Produce: nessuna firma nuova. Cambia il **contenuto** di
  `ugc_track_ec_track_matches.matched_segments`: i segmenti toccati solo dalla parte centrale di
  un tratto dritto più lungo di `:seg` non ci sono più.

**Vincolo misurato.** La query odierna confronta i segmenti di tappa con pezzi di
`CHUNK_VERTICES = 64` vertici. Confrontare lato per lato dà lo stesso risultato ma costa **305 s
contro 16,9 s** sulla traccia 297. I tratti più lunghi di 400 m sono **214 su 399.290**: vanno
trattati a parte, lasciando intatto il percorso normale.

**Strategia** (il resto è scrittura):
1. In `pts`, marcare ogni vertice con la distanza geodetica dal precedente; un vertice il cui
   lato entrante supera `:seg` apre un **gruppo nuovo**.
2. In `up`, raggruppare per `(part, gruppo, chunk)` invece che per `(part, chunk)`: il tratto
   lungo non entra più in nessuna linea, e per tutto il resto i pezzi e il costo restano quelli
   di oggi.
3. Un CTE nuovo `long_edges` costruisce la linea di ciascun tratto lungo, e per ogni coppia
   (tratto, EcTrack) ne tiene **due** segmenti: quello più vicino all'estremo iniziale e quello
   più vicino all'estremo finale, entro `:max_distance`. Il confronto è sulla distanza dai due
   estremi, non sull'indice, perché gli indici di una tappa a più pezzi sono concatenati per
   `part` e non seguono la geografia.
4. L'`INSERT` unisce i segmenti dei due rami con `jsonb_agg(DISTINCT …)`, come adesso.

- [ ] **Step 1: scrivere i test che falliscono**

Geometrie costruite a mano, con `segment_length_meters` piccolo per non dover disegnare chilometri:

```php
it('non marca i segmenti in mezzo a un tratto dritto lungo', ...);
// UGC track: due punti distanti molto più di :seg, lungo una tappa dritta di N segmenti
// atteso: matched_segments contiene solo il primo e l'ultimo, non quelli centrali

it('marca normalmente una UGC track senza tratti lunghi', ...);
// atteso: stessi segmenti che il codice produce oggi (non-regressione)

it('applica il limite a ciascuna tappa attraversata dallo stesso tratto', ...);
// un tratto lungo che passa su due EcTrack: due segmenti per ciascuna, non due in tutto

it('tiene i segmenti toccati dai tratti brevi prima e dopo quello lungo', ...);
// la UGC track ha tratti normali ai due capi: quei segmenti restano tutti
```

- [ ] **Step 2: verificare che falliscano**

Run: `vendor/bin/pest tests/Feature/Services/UgcTrackMatchingLongEdgeTest.php`
Atteso: il primo, il terzo e il quarto falliscono; il secondo passa già.

- [ ] **Step 3: modificare `insertSql()`**

Applicare i quattro punti della strategia. I commenti vanno nel PHP, **mai `--` con token
`oc:8165`** dentro la query: PDO li legge come segnaposti (regola in `.claude/rules/job-e-import.md`).

- [ ] **Step 4: verificare che passino**

Run: `vendor/bin/pest tests/Feature/Services/UgcTrackMatchingLongEdgeTest.php`
Atteso: 4 passati.

- [ ] **Step 5: verificare che le prestazioni non siano peggiorate**

Run, nel container Postgres, il ricalcolo della traccia più pesante:
`docker exec laravel-camminiditalia php artisan tinker --execute="\$t=microtime(true); app(\Wm\WmPackage\Services\Models\UgcTrackMatchingService::class)->matchUgcTrack(297); echo round(microtime(true)-\$t,1).' s';"`
Atteso: stesso ordine di grandezza di prima della modifica (pochi secondi, **non** minuti). Se
supera i 30 s, fermarsi: la strategia è da rivedere, non da tarare.

- [ ] **Step 6: suite del package**

Run: `vendor/bin/pest`
Atteso: verde. Le corrispondenze già calcolate dai test esistenti non devono cambiare.

- [ ] **Step 7: commit**

```bash
git add src/Services/Models/UgcTrackMatchingService.php tests/Feature/Services/UgcTrackMatchingLongEdgeTest.php
git commit -m "feat(oc:8165): un tratto dritto lungo marca solo il primo e l'ultimo segmento"
```

---

## Task 3: conoscenza nel package

**Repo:** `wm-package`

**File:**
- Crea: `docs/knowledge/origine-delle-tracce-ugc.md`
- Modifica: `docs/knowledge/8773-associazione-delle-ugctrack-a-piu-cammini-tappe-invece-che-a-un-solo-layer.md`
- Modifica: `CLAUDE.md` (riga nell'indice `## Conoscenza`)

- [ ] **Step 1: scrivere la pagina sull'origine**

Struttura canonica (`Come funziona oggi` / `Perché così` / `Come ci siamo arrivati`). Contenuto:
la regola delle quattro condizioni e dove vive (`UgcTrack::origin()`); chi la usa (badge «Origin»
in Nova da oc:8747, e il passaporto da oc:8165); che `properties.import` lo scrive il frontend
(oc:8764, `wm-core`) e che le tracce importate prima di quel ticket non ce l'hanno; perché
`distanceFilter` è stato tolto. Sotto «Come ci siamo arrivati», il ramo `distanceFilter` con il
motivo dell'abbandono, e il dato: su camminiditalia classificava male 20 tracce su 267.

- [ ] **Step 2: aggiornare la pagina della corrispondenza**

Aggiungere il limite sui tratti dritti lunghi: cosa fa, perché (un buco GPS produce una linea
dritta — scelta di oc:8719 — e sui buchi lunghi quella linea non è un percorso), e i dati che lo
motivano (salto massimo 17,4 km su una UGC track registrata; 20 tracce su 134 con almeno un salto
oltre i 400 m). Dire che la soglia è la lunghezza di segmento, senza parametri nuovi.

- [ ] **Step 3: una riga nell'indice del `CLAUDE.md`**

Sotto `## Conoscenza`, una riga per la pagina nuova. La riga della pagina di oc:8773 non cambia.

- [ ] **Step 4: commit**

```bash
git add docs/knowledge/ CLAUDE.md
git commit -m "docs(oc:8165): origine delle tracce UGC e limite sui tratti dritti lunghi"
```

---

> **Da qui in avanti si lavora in `camminiditalia`.** Prima bumpare il gitlink del submodule ai
> commit dei task 1-3.

---

## Task 4: schema

**Repo:** `camminiditalia`

**File:**
- Crea: `database/migrations/…_add_progress_to_passport_stages_table.php`
- Crea: `database/migrations/…_create_passport_stage_ugc_track_table.php`
- Crea: `database/migrations/…_create_ec_track_variants_table.php`
- Modifica: `app/Models/PassportStage.php`
- Crea: `app/Models/EcTrackVariant.php`
- Test: `tests/Feature/EcTrackVariantTest.php`

**Interfacce:**
- Produce:
  - `passport_stages.progress` — `smallint`, 0-100, default 0, NOT NULL;
    `validated_at` diventa nullable.
  - `passport_stage_ugc_track` — `(passport_stage_id, ugc_track_id)`, chiave primaria
    composta, entrambe in cascata.
  - `ec_track_variants` — `ec_track_id` (chiave primaria, la variante),
    `main_ec_track_id` (la principale), entrambe in cascata su `ec_tracks`.
  - `PassportStage::ugcTracks(): BelongsToMany`
  - `EcTrackVariant::mainIdsFor(array $ecTrackIds): array` — mappa `id variante → id principale`
    per le tappe indicate; chi non è variante non compare.

**Perché una tabella e non una colonna su `ec_tracks`:** `ec_tracks` è del package, e una colonna
aggiunta da una migration del consumer diverge dallo schema che il package pubblica. La chiave
primaria su `ec_track_id` impone da sola «una tappa ha al più una principale».

- [ ] **Step 1: scrivere i test che falliscono**

```php
it('impedisce che una tappa sia variante di due tappe', ...);        // violazione di chiave primaria
it('impedisce una catena di varianti', ...);                          // B variante di A, poi C variante di B → rifiutato
it('impedisce che una tappa sia variante di sé stessa', ...);
it('restituisce la principale per le sole tappe che sono varianti', ...); // mainIdsFor()
```

Catena e ciclo non sono esprimibili in DDL: il guard sta in `EcTrackVariant::booted()`, su
`saving`, e solleva un'eccezione di dominio.

- [ ] **Step 2: verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=EcTrackVariantTest`
Atteso: FAIL, tabella inesistente.

- [ ] **Step 3: scrivere le tre migration e i due model**

Nel `down()` della prima: ripristinare `validated_at` NOT NULL **dopo** aver cancellato le righe
senza data, altrimenti fallisce.

- [ ] **Step 4: migrare e verificare**

Run: `docker exec laravel-camminiditalia php artisan migrate` e
`docker exec laravel-camminiditalia php artisan migrate:fresh --env=testing`, poi
`docker exec laravel-camminiditalia php artisan test --filter=EcTrackVariantTest`
Atteso: 4 passati.

- [ ] **Step 5: commit**

```bash
git add database/migrations app/Models tests/Feature/EcTrackVariantTest.php
git commit -m "feat(oc:8165): schema per avanzamento parziale, UGC track di origine e varianti"
```

---

## Task 5: il verdetto sulla tappa

**Repo:** `camminiditalia`

**File:**
- Crea: `app/Services/StageValidationService.php`
- Crea: `config/camminiditalia.php` → chiave `passport.coverage_threshold`
- Modifica: `.env-example`
- Test: `tests/Feature/StageValidationServiceTest.php`

**Interfacce:**
- Consuma: `UgcTrack::origin()` (task 1); `PassportStage::ugcTracks()` e `EcTrackVariant`
  (task 4); `UgcTrackMatchingService::params()` e `unmatchedUgcTrackIdsQuery()` (package).
- Produce:
  - `StageValidationService::recomputeForUser(int $userId, ?array $ecTrackIds = null): array`
    — ricalcola e scrive; restituisce `['validated' => int, 'updated' => int]`.
  - `StageValidationService::previewForAllUsers(): array` — non scrive nulla; restituisce
    `['tappe' => int, 'persone' => int]`. La usa il task 7.
  - `StageValidationService::COVERAGE_THRESHOLD_KEY = 'camminiditalia.passport.coverage_threshold'`

**Regole, tutte da test:**
1. I segmenti si uniscono fra **tutte** le UGC track dell'utente sulla stessa tappa
   (`count(DISTINCT indice) / total_segments`).
2. Concorrono **solo** le UGC track con `origin() === 'recorded'`.
3. Si ignorano le corrispondenze con `segment_length_meters` o `max_distance_meters` diversi da
   quelli correnti dell'App.
4. Una riga con `source = 'manual'` non si tocca: né `progress`, né `validated_at`, né `source`.
5. `validated_at`, una volta scritta, non si cancella e non si sposta. `progress` continua ad
   aggiornarsi.
6. Superata la soglia si scrive `validated_at = now()`, `source = 'gps'`, e si collegano in
   `passport_stage_ugc_track` le UGC track che hanno contribuito.
7. `layer_id` della riga: il cammino della tappa; se la tappa sta in più cammini, il primo per id,
   coerente con il significato già documentato sulla colonna.

- [ ] **Step 1: scrivere i test che falliscono**

```php
it('valida quando i segmenti uniti superano la soglia', ...);
it('unisce i segmenti di UGC track diverse dello stesso utente', ...);   // 50% + 50% distinti → 100%
it('non conta le UGC track importate', ...);                              // copertura 100% da importata → non validata
it('ignora le corrispondenze calcolate con parametri diversi', ...);
it('non tocca una riga validata a mano', ...);                            // progress, validated_at e source invariati
it('non revoca una validazione quando la copertura scende', ...);         // validated_at resta, progress scende
it('collega le UGC track che hanno prodotto la validazione', ...);
it('aggiorna il progresso senza validare quando resta sotto soglia', ...);
```

- [ ] **Step 2: verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=StageValidationServiceTest`
Atteso: FAIL, classe inesistente.

- [ ] **Step 3: implementare il servizio**

Una sola query di aggregazione sulle corrispondenze (niente N+1: i dati sono già indici in
`jsonb`), poi `upsert` per utente. La protezione delle righe `manual` e la non-revoca vanno
espresse **nella clausola SQL dell'upsert**, non in un `if` applicativo: così reggono anche a
scritture concorrenti fra il job del task 6 e l'azione Nova della certificazione cartacea.

- [ ] **Step 4: verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter=StageValidationServiceTest`
Atteso: 8 passati.

- [ ] **Step 5: commit**

```bash
git add app/Services/StageValidationService.php config/camminiditalia.php .env-example tests/Feature/StageValidationServiceTest.php
git commit -m "feat(oc:8165): il verdetto sulla tappa dai segmenti percorsi"
```

---

## Task 6: ricalcolo dopo una UGC track

**Repo:** `camminiditalia`

**File:**
- Crea: `app/Jobs/RecomputeStageValidationsJob.php`
- Modifica: `app/Observers/UgcObserver.php`
- Test: `tests/Feature/RecomputeStageValidationsJobTest.php`

**Interfacce:**
- Consuma: `StageValidationService::recomputeForUser()` (task 5).
- Produce: `RecomputeStageValidationsJob::dispatch(int $userId, array $ecTrackIds = [])`.

Il job parte quando la corrispondenza di una UGC track è stata calcolata, non quando la UGC track
viene salvata: prima dei match non c'è niente da aggregare.

- [ ] **Step 1: scrivere i test che falliscono**

```php
it('accoda il ricalcolo dopo il matching di una UGC track', ...);  // Bus::fake, assertDispatched
it('non accoda nulla per una traccia importata', ...);
it('è idempotente: due esecuzioni lasciano lo stesso stato', ...);
```

- [ ] **Step 2: verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=RecomputeStageValidationsJobTest`
Atteso: FAIL.

- [ ] **Step 3: implementare job e aggancio**

Coda dedicata, come `ugc-track-matching`. Se il package espone un evento a fine matching usarlo;
altrimenti agganciarsi nell'observer locale dopo il dispatch del matching, con `afterCommit()`.

- [ ] **Step 4: verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter=RecomputeStageValidationsJobTest`
Atteso: 3 passati.

- [ ] **Step 5: commit**

```bash
git add app/Jobs app/Observers tests/Feature/RecomputeStageValidationsJobTest.php
git commit -m "feat(oc:8165): il progresso si aggiorna dopo il matching della UGC track"
```

---

## Task 7: command di popolamento e riallineamento

**Repo:** `camminiditalia`

**File:**
- Crea: `app/Console/Commands/ComputeStageValidationsCommand.php`
- Test: `tests/Feature/ComputeStageValidationsCommandTest.php`

**Interfacce:**
- Consuma: `StageValidationService::recomputeForUser()` e `previewForAllUsers()` (task 5).
- Produce: `camminiditalia:compute-stage-validations {--dry-run} {--user=}`

Il `--dry-run` è il solo momento in cui un errore si vede prima che diventi definitivo: sui dati
attuali il primo lancio valida **77 tappe a 26 persone**, e quelle validazioni non si revocano.

- [ ] **Step 1: scrivere i test che falliscono**

```php
it('con --dry-run non scrive nulla e riporta i conteggi', ...);
it('senza --dry-run scrive le validazioni', ...);
it('rilanciato una seconda volta non cambia nulla', ...);
it('con --user limita il calcolo a quella persona', ...);
```

- [ ] **Step 2: verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=ComputeStageValidationsCommandTest`
Atteso: FAIL.

- [ ] **Step 3: implementare il command**

Sul modello di `WmMatchUgcTracksCommand` del package. In `--dry-run` nessuna scrittura e nessun
job accodato. Avanzamento a blocchi di utenti, non una query per tappa.

- [ ] **Step 4: verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter=ComputeStageValidationsCommandTest`
Atteso: 4 passati.

- [ ] **Step 5: commit**

```bash
git add app/Console/Commands tests/Feature/ComputeStageValidationsCommandTest.php
git commit -m "feat(oc:8165): command di popolamento con --dry-run"
```

---

## Task 8: avanzamento parziale e gruppi nel passaporto

**Repo:** `camminiditalia`

**File:**
- Modifica: `app/Services/StageProgressService.php` — `trackProgress()` (186-190),
  `progressFor()` (103), `userTotalsQuery()` (342), `progressFields()` (363),
  `isCompleted()` (332)
- Test: `tests/Feature/StageProgressVariantsTest.php`

**Interfacce:**
- Consuma: `EcTrackVariant::mainIdsFor()` (task 4); la colonna `progress` (task 4).
- Produce: nessuna firma pubblica nuova. Le risposte API mantengono la forma di oggi; cambiano i
  valori di `percentage`, `total`, `completed`, e `progress` della singola tappa.

**Le due modifiche:**
1. `trackProgress()` smette di restituire `$validated ? 100 : 0` e legge la colonna.
2. Il totale del cammino conta **gruppi**, non tappe: le tappe del layer si raggruppano per
   radice (la principale se è presente in **quel** layer, altrimenti la tappa stessa) e ogni
   gruppo vale 1, soddisfatto da una qualsiasi delle sue tappe validate. Il raggruppamento va
   nella query SQL insieme ai `COUNT`/`SUM`, non in PHP: `passportFor()` fa una query sola per
   tutti i cammini e non deve diventarne una per cammino.

- [ ] **Step 1: scrivere i test che falliscono**

Scenario Via Vandelli, perché è il cammino con il passaporto acceso:

```php
it('conta sette gruppi e non nove tappe su Via Vandelli', ...);
it('porta al 100% chi percorre un solo ramo di partenza', ...);   // 01M+02M, mai 01S/02S
it('considera il gruppo soddisfatto da una qualsiasi delle sue tappe', ...);
it('conta la tappa normalmente nel cammino che non contiene la sua principale', ...);
it('restituisce la percentuale reale di una tappa non validata', ...);  // 74, non 0
```

- [ ] **Step 2: verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=StageProgressVariantsTest`
Atteso: FAIL.

- [ ] **Step 3: implementare**

- [ ] **Step 4: verificare che passino, e che i test esistenti del passaporto restino verdi**

Run: `docker exec laravel-camminiditalia php artisan test --filter=StageProgress`
Atteso: i 5 nuovi passano, i preesistenti restano verdi.

- [ ] **Step 5: commit**

```bash
git add app/Services/StageProgressService.php tests/Feature/StageProgressVariantsTest.php
git commit -m "feat(oc:8165): avanzamento parziale e cammino contato sui gruppi"
```

---

## Task 9: Nova

**Repo:** `camminiditalia`

**File:**
- Modifica: `app/Nova/EcTrack.php` — campo «variante di»
- Modifica: `app/Nova/PassportStage.php` — `indexQuery()` (101) e la query di riga 262
- Modifica: `app/Nova/Lenses/PassportStageSummary.php`
- Modifica: `app/Nova/Filters/PassportStageUserFilter.php`,
  `PassportStageLayerFilter.php`, `PassportStageSourceFilter.php`
- Modifica: `app/Nova/CertificationRequest.php:160` — il `HasMany` «Validated stages»
- Test: `tests/Feature/PassportStageNovaScopeTest.php`,
  `tests/Feature/EcTrackVariantFieldTest.php`

**Interfacce:**
- Consuma: `PassportStage::scopeValidated()` (già esistente); `EcTrackVariant` (task 4).

Tutti i punti elencati leggono oggi `passport_stages` senza filtro, perché finora conteneva
solo validazioni. Dal task 4 contiene anche righe non validate, che non devono comparire.

- [ ] **Step 1: scrivere i test che falliscono**

```php
it('non mostra in elenco le tappe non validate', ...);
it('non conta le tappe non validate nel riepilogo per camminatore', ...);
it('non mostra le tappe non validate nel pannello della richiesta di certificazione', ...);
it('propone come principale solo tappe dello stesso cammino', ...);
it('non propone come principale una tappa che è già una variante', ...);
```

- [ ] **Step 2: verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter="PassportStageNovaScope|EcTrackVariantField"`
Atteso: FAIL.

- [ ] **Step 3: implementare**

Il campo «variante di» è un `Select` ricercabile, visibile solo all'Administrator, con le opzioni
ristrette alle altre tappe dei cammini della tappa corrente.

- [ ] **Step 4: verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter="PassportStageNovaScope|EcTrackVariantField"`
Atteso: 5 passati.

- [ ] **Step 5: suite intera e PHPStan**

Run: `docker exec laravel-camminiditalia php artisan test`
Atteso: verde.
Run: `docker exec laravel-camminiditalia vendor/bin/phpstan analyse`
Atteso: nessun errore nuovo sui file toccati.

- [ ] **Step 6: commit**

```bash
git add app/Nova tests/Feature/PassportStageNovaScopeTest.php tests/Feature/EcTrackVariantFieldTest.php
git commit -m "feat(oc:8165): campo variante in Nova e liste ristrette alle tappe validate"
```

---

## Dopo il merge, in produzione

Non sono task di sviluppo: sono i passi che il dev esegue al deploy, in quest'ordine.

1. Deploy di `wm-package` (task 1-3), poi di camminiditalia con il gitlink aggiornato.
2. `php artisan migrate`.
3. **Azzerare lo stato dei calcoli di corrispondenza**, poi `wm:match-ugc-tracks --dry-run` e
   senza. Senza il primo passo il comando non fa nulla: riaccoda solo le tracce mai calcolate,
   fallite o calcolate con parametri diversi, e il limite sui tratti dritti lunghi non cambia
   nessun parametro — cambia il codice. Le corrispondenze resterebbero quelle vecchie, calcolate
   con la regola precedente, e il passaporto si baserebbe su dati sbagliati.

   ```sql
   TRUNCATE ugc_track_matching_runs;
   ```

   È una tabella di servizio — tiene solo l'esito dell'ultimo calcolo di ogni traccia — e si
   ricostruisce da sé man mano che i job girano. Verificato in dev: dopo il `TRUNCATE` il
   `--dry-run` passa da «0 tracce da calcolare» a 269.
4. `php artisan camminiditalia:compute-stage-validations --dry-run`: **guardare i numeri e
   confrontarli con le 77 tappe a 26 persone** misurate in pianificazione. Uno scarto grosso vuol
   dire che qualcosa non torna, e dopo il lancio vero non si torna indietro.
5. Solo allora, il command senza `--dry-run`.
6. Riavviare i worker Horizon: girano in un container a parte che il deploy non riavvia, e un
   worker vecchio ignora le classi Job nuove.

Le tre cose da chiedere al cliente (voce mancante nell'informativa privacy, gruppi di varianti da
dichiarare a partire da Via Vandelli) sono in [overview.md](overview.md) e vanno sistemate prima
di accendere il calcolo.

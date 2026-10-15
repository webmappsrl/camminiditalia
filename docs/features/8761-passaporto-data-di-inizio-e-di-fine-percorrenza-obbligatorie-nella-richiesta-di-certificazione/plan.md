> Ticket: oc:8761

# Date di percorrenza nella richiesta di certificazione — piano backend

> **Per chi esegue:** usare superpowers:executing-plans o superpowers:subagent-driven-development.
> I commit sono istruzioni per il dev: non si eseguono senza il suo via libera esplicito.

**Obiettivo:** `start_date` ed `end_date` obbligatorie nella richiesta di certificazione: validate,
salvate, mostrate in Nova e nella mail al gestore.

**Architettura:** due colonne `date NOT NULL` aggiunte nella migration esistente; il controller le
passa al service come argomenti, come oggi `serial_number`. Una factory sostituisce le
`CertificationRequest::create` dei test, così una colonna obbligatoria in più non tocca 16 file
la prossima volta.

**Stack:** Laravel, Nova, PostgreSQL, PHPUnit in Docker.

**Spec:** [overview.md](overview.md). Il lato app è nei piani gemelli di wm-core e wm-types
(submodule di webmapp-app), con lo stesso slug.

## Vincoli globali

- Nel multipart i campi si chiamano `start_date` ed `end_date`, formato `Y-m-d`.
- Limite superiore: `before_or_equal:tomorrow` (il server è in UTC).
- Nessuna migration nuova: si modifica `2026_09_28_150000_create_certification_requests_table.php`.
- Test: `docker exec laravel-camminiditalia php artisan test --filter=<NomeTest>`, sul database
  `camminiditalia_testing`.
- Branch `feature/oc-8761-passaporto-data-di-inizio-e-di-fine-percorrenza-obbligatorie-nella-richiesta-di-certificazione`
  da `Passaporto`, PR verso `Passaporto`.

## Da guardare in review

1. `end_date` uguale a `start_date` (cammino di un giorno): deve passare.
2. Data di domani UTC (dispositivo già al giorno dopo): deve passare; dopodomani no.
3. Data con orario (`2026-10-01T10:00:00`): 422 per `date_format`.
4. Mail con le due date: formato `d/m/Y`, non il datetime UTC.
5. Database locale e Cammini Dev con la migration già eseguita: va seguita la procedura della PR.

---

### Task 1: schema, modello e factory

**File:**
- Modifica: `database/migrations/2026_09_28_150000_create_certification_requests_table.php:19`
- Modifica: `app/Models/CertificationRequest.php` (docblock, `$fillable`, `$casts`, trait)
- Crea: `database/factories/CertificationRequestFactory.php`
- Test: `tests/Feature/CertificationRequestModelTest.php`

**Interfacce:**
- Produce: colonne `start_date`, `end_date`; `CertificationRequest::factory()` con default
  `status = pending`, `disclaimer_accepted_at = now()`, `start_date = '2026-09-01'`,
  `end_date = '2026-09-05'`, `locale = 'it'`; `user_id` e `layer_id` li passa sempre il chiamante.

- [ ] **Passo 1: procedura sul database locale.** `php artisan migrate:status`, conta le
      migration da `create_certification_requests_table` compresa fino all'ultima (oggi 4) e
      lancia `php artisan migrate:rollback --step=<N>` **prima** di modificare la migration.
- [ ] **Passo 2: test che fallisce** in `CertificationRequestModelTest`:
      `test_start_and_end_date_are_cast_to_dates` crea con la factory e verifica che
      `$r->start_date` sia un `Carbon` con `toDateString() === '2026-09-01'`, e lo stesso per
      `end_date`.
- [ ] **Passo 3:** lancia `--filter=CertificationRequestModelTest`. Atteso: FAIL (factory o
      colonna assente).
- [ ] **Passo 4:** nella migration, dopo `serial_number`, `$table->date('start_date');` e
      `$table->date('end_date');`. Nel modello: `use HasFactory`, i due campi in `$fillable`,
      `'start_date' => 'date'` e `'end_date' => 'date'` in `$casts`,
      `@property \Illuminate\Support\Carbon $start_date` / `$end_date` nel docblock. Crea la
      factory con i default dell'Interfaccia.
- [ ] **Passo 5:** `php artisan migrate`, poi il test. Atteso: PASS.
- [ ] **Passo 6:** sostituisci le `CertificationRequest::create([...])` dei 16 file di test
      (`grep -rln "CertificationRequest::create" tests`) con
      `CertificationRequest::factory()->create([...])`, togliendo dagli array i campi uguali ai
      default della factory.
- [ ] **Passo 7:** `docker exec laravel-camminiditalia php artisan test`. Atteso: tutto verde
      tranne i test che passano per `submit()` o l'API (li sistemano i Task 2 e 3).

### Task 2: validazione, controller e service

**File:**
- Modifica: `app/Http/Requests/StoreCertificationRequestRequest.php:19-24`
- Modifica: `app/Http/Controllers/Api/CertificationRequestController.php:21-27`
- Modifica: `app/Services/CertificationRequestService.php:44-64`
- Test: `tests/Feature/CertificationRequestApiTest.php`, `tests/Feature/CertificationRequestServiceTest.php`,
  `tests/Feature/CertificationRequestMailTest.php` (chiamate a `submit`)

**Interfacce:**
- Consuma: colonne del Task 1.
- Produce: `submit(User $user, Layer $layer, array $images, ?string $serialNumber, string $startDate, string $endDate, string $locale = CertificationRequest::DEFAULT_LOCALE): CertificationRequest`.

- [ ] **Passo 1: test che falliscono.** In `CertificationRequestApiTest`, `validPayload()` riceve
      `'start_date' => '2026-09-01', 'end_date' => '2026-09-05'`, e aggiungi:
      - `test_store_saves_start_and_end_date`: 201 e `start_date`/`end_date` salvati;
      - `test_store_requires_dates`: senza le due chiavi → 422 con `errors.start_date` ed
        `errors.end_date`;
      - `test_store_rejects_wrong_date_format`: `start_date = '01/09/2026'` → 422 su `start_date`;
      - `test_store_rejects_end_before_start`: `end_date = '2026-08-31'` → 422 su `end_date`;
      - `test_store_accepts_same_day`: inizio = fine → 201;
      - `test_store_accepts_tomorrow_and_rejects_after`: con `Carbon::setTestNow('2026-10-14 23:30:00')`,
        `end_date = '2026-10-15'` → 201, `'2026-10-16'` → 422.
- [ ] **Passo 2:** `--filter=CertificationRequestApiTest`. Atteso: FAIL sui nuovi test.
- [ ] **Passo 3:** regole in `rules()`:
      `'start_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:tomorrow']`,
      `'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:tomorrow']`.
      Il controller passa `$request->input('start_date')` e `$request->input('end_date')` dopo
      `serial_number`; il service li salva nel `create`. Aggiorna le chiamate a `submit` nei test
      (`CertificationRequestServiceTest:67,106,129,168,202`, `CertificationRequestMailTest:68,92`).
- [ ] **Passo 4:** test del service: `test_submit_saves_dates` verifica le due date sul record.
- [ ] **Passo 5:** `docker exec laravel-camminiditalia php artisan test`. Atteso: tutto verde
      tranne quanto tocca il Task 3.

### Task 3: Nova e mail al gestore

**File:**
- Modifica: `app/Nova/CertificationRequest.php:111-112`
- Modifica: `resources/views/emails/new-certification-request.blade.php:24-27`
- Modifica: `resources/lang/{it,en,de,fr,es}.json`
- Test: `tests/Feature/CertificationRequestMailTest.php`, `tests/Feature/CertificationRequestNovaTest.php`

- [ ] **Passo 1: test che falliscono.** `test_new_request_mail_shows_walked_dates`: con date
      `2026-09-01`/`2026-09-05` la mail renderizzata contiene `Percorso` e
      `dal 01/09/2026 al 05/09/2026`. In `CertificationRequestNovaTest`, se c'è già un test sui
      campi della risorsa, aggiungi che compaiono `start_date` ed `end_date` in sola lettura.
- [ ] **Passo 2:** lancia i due test. Atteso: FAIL.
- [ ] **Passo 3:** in Nova, dopo «Serial number»,
      `Date::make(__('Start date'), 'start_date')->readonly()` e
      `Date::make(__('End date'), 'end_date')->readonly()` (import `Laravel\Nova\Fields\Date`).
      Nella mail, dopo «Inviata il»:
      `<x-mail.field label="Percorso">dal {{ $request->start_date->format('d/m/Y') }} al {{ $request->end_date->format('d/m/Y') }}</x-mail.field>`.
      Nei 5 json: `"Start date"` / `"End date"` → it «Data di inizio» / «Data di fine»,
      en «Start date» / «End date», de «Startdatum» / «Enddatum», fr «Date de début» /
      «Date de fin», es «Fecha de inicio» / «Fecha de fin».
- [ ] **Passo 4:** test completi + PHPStan:
      `docker exec laravel-camminiditalia php artisan test` e
      `docker exec laravel-camminiditalia vendor/bin/phpstan analyse`. Atteso: verde, nessun
      errore nuovo sui file toccati.
- [ ] **Passo 5 (istruzione per il dev):**

```bash
git add database app resources tests docs/features/8761-*/
git commit -m "feat(oc:8761): date di percorrenza obbligatorie nella richiesta di certificazione"
```

La descrizione della PR riporta la procedura per Cammini Dev e i database locali, dalla sezione
Rischi dell'overview: rollback **prima** del pull, con `--step` contato da `migrate:status`.

> Ticket: oc:8951

# Allineamento wm-package a 1.6.1 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** portare il gitlink di `wm-package` a `origin/main` (1.6.1) senza che il frontend smetta di funzionare e senza perdere nulla di quello che oggi si fa in Nova.

**Architecture:** in `wm-package` si muove solo il gitlink; tutto il codice sta nel repo principale. L'unico adeguamento applicativo è in `App\Nova\Layer`, dove la sezione di filtri per tassonomia introdotta da oc:8910 va disinnescata a monte — non filtrata a valle — perché costruirla significa caricare 7.930 righe di `taxonomy_wheres` (104 MB). Il resto del lavoro è verifica: lettura classificata del diff, confronto degli artefatti pubblici, suite, Nova, deploy.

**Tech Stack:** Laravel 11, Nova 5, PostgreSQL/PostGIS, Docker (`laravel-camminiditalia`), PHPUnit su `camminiditalia_testing`, PHPStan/Larastan in CI.

**Spec:** [overview.md](overview.md)

## Global Constraints

- Repo di destinazione di ogni modifica di codice: **`/Users/bongiu/Documents/camminiditalia`** (repo principale). In `wm-package` **non si scrive codice**: si sposta solo il gitlink.
- Ogni comando artisan gira nel container: `docker exec laravel-camminiditalia php artisan <comando>`.
- I test girano su `camminiditalia_testing`; dopo migration nuove va rilanciato `php artisan migrate --env=testing`, perché lo schema del DB di test non si aggiorna da solo.
- Identificatori di codice in inglese; commenti e documentazione in italiano.
- Commit convention: `feat(oc:8951): …`, `fix(oc:8951): …`, `refactor(oc:8951): …`.
- **Nessun commit, push o branch eseguito in autonomia.** Gli step di commit di questo piano sono istruzioni per il dev: si fermano e aspettano il suo via.
- Criterio di accettazione del diff, dato dal dev: un cambiamento **additivo** (chiave o campo in più) si accetta; un cambiamento **modificativo** (valore esistente che cambia o sparisce) **ferma il lavoro** e si porta al dev.
- Le migration si pubblicano **direttamente sul server** in fase di deploy, non si committano in anticipo.

## Review Focus

Cose che la spec implica ma che nessun test di task esercita da sé, in ordine di probabilità di far danno:

1. **Un Validator apre la Resource `Layer`** — deve vedere quello che vedeva prima e nessuna sezione di filtri, e la pagina non deve caricare `taxonomy_wheres`. Test in Task 2.
2. **La index dei `Layer` con molte righe** — Nova costruisce i Field per ogni riga serializzata: se la query su `taxonomy_wheres` sopravvive da qualche parte, è lì che si vede. Test in Task 2.
3. **Salvataggio di un `EcTrack` dopo le migration** — gli Observer interrogano `taxonomy_termables`: con le tabelle presenti deve funzionare, senza deve fallire in modo evidente e non silenzioso. Test in Task 1.
4. **`config.json` di un'App con layer senza filtri** — è il caso normale di questo progetto, ed è quello su cui un cambiamento modificativo passerebbe inosservato. Test in Task 4.
5. **Reindex di una traccia** — `toSearchableArray()` guadagna `terms`: deve restituire un array vuoto, non sollevare eccezioni né far sparire `taxonomyWheres`. Test in Task 4.

---

### Task 1: Gitlink allineato e migration eseguite

**Files:**
- Modify (gitlink): `wm-package` (da `ca41174e` a `origin/main`)
- Create: `database/migrations/<data>_zz_2026_11_02_000001_create_taxonomy_terms_table.php` e i due fratelli, **solo in locale** (sul server si pubblicano al deploy, Task 7)
- Test: `tests/Feature/TaxonomyTermsTablesTest.php`

**Interfaces:**
- Produce: le tabelle `taxonomy_terms`, `taxonomy_termables`, `app_taxonomy_term` su DB di sviluppo e di test; il codice del package a 1.6.1 caricato via `vendor/wm/wm-package` (symlink al submodule, nessun `composer update` necessario: il repository è di tipo `path` e l'autoload è PSR-4).

- [ ] **Step 1: Allineare il submodule**

```bash
git -C /Users/bongiu/Documents/camminiditalia/wm-package fetch origin
git -C /Users/bongiu/Documents/camminiditalia/wm-package checkout origin/main
git -C /Users/bongiu/Documents/camminiditalia status --short wm-package
```

Atteso: `wm-package` compare come modificato (nuovo commit) nel repo principale.

- [ ] **Step 2: Pubblicare le migration, prima a vuoto**

```bash
docker exec laravel-camminiditalia php artisan wm-package:publish-missing-migrations --dry-run
```

Atteso: elenca le tre migration `taxonomy_terms`, `taxonomy_termables`, `app_taxonomy_term` e nient'altro. Se ne elenca altre, fermarsi e riportarlo al dev: vuol dire che il gitlink portava più di quanto l'overview descrive.

- [ ] **Step 3: Pubblicare ed eseguire**

```bash
docker exec laravel-camminiditalia php artisan wm-package:publish-missing-migrations
docker exec laravel-camminiditalia php artisan migrate
docker exec laravel-camminiditalia php artisan migrate --env=testing
```

- [ ] **Step 4: Scrivere il test che prova che le tabelle ci sono e reggono il vincolo**

`tests/Feature/TaxonomyTermsTablesTest.php`:

```php
public function test_taxonomy_tables_exist(): void
{
    $this->assertTrue(Schema::hasTable('taxonomy_terms'));
    $this->assertTrue(Schema::hasTable('taxonomy_termables'));
    $this->assertTrue(Schema::hasTable('app_taxonomy_term'));
}

public function test_saving_an_ec_track_does_not_fail_with_the_new_tables(): void
{
    $track = EcTrack::factory()->create(['properties' => []]);
    $track->name = 'oc:8951 smoke';
    $track->save();

    $this->assertDatabaseHas(config('wm-package.ec_track_table', 'ec_tracks'), ['id' => $track->id]);
}
```

- [ ] **Step 5: Eseguire il test**

```bash
docker exec laravel-camminiditalia php artisan test --filter=TaxonomyTermsTablesTest
```

Atteso: PASS. Se fallisce sul salvataggio con un errore SQL su `taxonomy_termables`, la migration non è stata eseguita sul DB di test.

- [ ] **Step 6: Commit (chiedere al dev)**

```bash
git add wm-package database/migrations tests/Feature/TaxonomyTermsTablesTest.php
git commit -m "feat(oc:8951): gitlink wm-package a 1.6.1 e tabelle delle classificazioni"
```

---

### Task 2: `App\Nova\Layer` non costruisce la sezione Filters

**Files:**
- Modify: `app/Nova/Layer.php` (il filtro `MorphToMany` alle righe 60-64 va tolto; si aggiunge l'override)
- Test: `tests/Feature/LayerFiltersSectionTest.php`

**Interfaces:**
- Consuma: il package a 1.6.1 del Task 1.
- Produce: `App\Nova\Layer::legacyTaxonomies(): array` che restituisce `[]`, e `fields()` che non contiene nessun `TabsGroup` con chiave `taxonomies`.

Due mosse, non una: l'override di `legacyTaxonomies()` impedisce la query su `taxonomy_wheres`, ma `Wm\WmPackage\Nova\Layer::fields()` chiama `makeTaxonomySection()` direttamente, che restituisce un `TabsGroup` anche a tab zero — va tolto dall'array, altrimenti resta una sezione vuota a schermo.

- [ ] **Step 1: Scrivere i test che falliscono**

`tests/Feature/LayerFiltersSectionTest.php`:

```php
public function test_layer_fields_contain_no_taxonomy_section(): void
{
    $fields = $this->layerFields($this->administrator());

    foreach ($fields as $field) {
        $this->assertNotSame(
            \App\Nova\Layer::taxonomySectionKey(),
            $field->meta()['key'] ?? null,
            'La sezione delle tassonomie non deve arrivare ai fields del Layer.'
        );
    }
}

public function test_building_layer_fields_does_not_query_taxonomy_wheres(): void
{
    DB::enableQueryLog();
    $this->layerFields($this->administrator());
    $queries = collect(DB::getQueryLog())->pluck('query');

    $this->assertFalse(
        $queries->contains(fn (string $q) => str_contains($q, 'taxonomy_wheres')),
        'Costruire i fields del Layer non deve leggere taxonomy_wheres (7.930 righe, 104 MB).'
    );
}

public function test_validator_sees_no_taxonomy_section_either(): void
{
    $fields = $this->layerFields($this->validator());

    foreach ($fields as $field) {
        $this->assertNotSame(\App\Nova\Layer::taxonomySectionKey(), $field->meta()['key'] ?? null);
    }
}
```

`layerFields(User $user): array` risolve `new \App\Nova\Layer($layer)` e ne chiama `fields()` con una `NovaRequest` autenticata come `$user`; `administrator()` e `validator()` creano gli utenti con i ruoli Spatie del progetto.

- [ ] **Step 2: Eseguire i test e vederli fallire**

```bash
docker exec laravel-camminiditalia php artisan test --filter=LayerFiltersSectionTest
```

Atteso: FAIL — la sezione è presente e la query su `taxonomy_wheres` compare nel log.

- [ ] **Step 3: Implementare l'override in `app/Nova/Layer.php`**

Aggiungere `protected function legacyTaxonomies(): array` che restituisce `[]`, con un commento che dice perché (lo stesso motivo di oc:8311, più il costo misurato della query). In `fields()`, dopo `parent::fields($request)`, togliere dall'array l'elemento il cui `meta()['key']` è `\App\Nova\Layer::taxonomySectionKey()` (il metodo statico arriva dal trait `BuildsTaxonomyFields`, va chiamato sulla classe che lo usa).

- [ ] **Step 4: Togliere il filtro diventato codice morto**

Rimuovere da `app/Nova/Layer.php` l'`array_filter` che cerca `taxonomyActivities` e `taxonomyWheres` fra i `MorphToMany`: quei Field non esistono più nel package, il filtro non trova nulla e nessun test lo segnalerebbe. Spostare nel commento dell'override il contenuto utile del commento attuale (il perché di oc:8311).

- [ ] **Step 5: Eseguire i test**

```bash
docker exec laravel-camminiditalia php artisan test --filter=LayerFiltersSectionTest
```

Atteso: PASS, tutti e tre.

- [ ] **Step 6: Commit (chiedere al dev)**

```bash
git add app/Nova/Layer.php tests/Feature/LayerFiltersSectionTest.php
git commit -m "fix(oc:8951): il Layer non costruisce la sezione Filters del package"
```

---

### Task 3: Suite e PHPStan verdi

**Files:**
- Modify: i test che si rompono, verosimilmente fra `tests/Feature/LayerAttributeFieldsTest.php`, `LayerAttributesWheresTest.php`, `LayerThemeAttributesTest.php`, `AppHomeLayerSortButtonTest.php`, `LayerFeatureControllerTest.php`, `LayerServiceUpdateLayersPropertyGuardTest.php` e i test sulle Resource `EcTrack`/`EcPoi`

**Interfaces:**
- Consuma: Task 1 e Task 2.
- Produce: suite verde, da cui dipende il giudizio di tutti i task di verifica successivi.

- [ ] **Step 1: Eseguire la suite intera**

```bash
docker exec laravel-camminiditalia php artisan test
```

Annotare l'elenco dei test falliti prima di toccarli: serve per il `notes.md`.

- [ ] **Step 2: Correggere i test rotti, uno alla volta**

Per ciascuno, stabilire **prima** di modificarlo se il fallimento è un adeguamento legittimo (il Field ha cambiato posizione o nome a monte) o il sintomo di una regressione vera. Un test che si aggiusta cambiando l'asserzione a quello che il codice fa adesso, senza sapere perché, va portato al dev.

- [ ] **Step 3: Rieseguire fino al verde**

```bash
docker exec laravel-camminiditalia php artisan test
```

Atteso: 0 falliti.

- [ ] **Step 4: PHPStan**

```bash
docker exec laravel-camminiditalia vendor/bin/phpstan analyse --error-format=json > /tmp/wm-phpstan.json
```

Atteso: nessun errore sui file toccati da questo ticket. Gli errori su file non toccati sono debito preesistente e non bloccano.

- [ ] **Step 5: Commit (chiedere al dev)**

```bash
git add tests/
git commit -m "fix(oc:8951): adeguamento dei test ai Field del package 1.6.1"
```

---

### Task 4: Classificazione del diff che produce output per il frontend

**Files:**
- Test: `tests/Feature/SearchableArrayTermsTest.php`
- Nessuna modifica di codice attesa: se ne serve una, il task si ferma e si va dal dev.

**Interfaces:**
- Consuma: Task 1.
- Produce: la classificazione scritta, che è il presupposto del Task 5 (il confronto conferma, non scopre).

- [ ] **Step 1: Leggere e classificare i quattro punti**

```bash
git -C /Users/bongiu/Documents/camminiditalia/wm-package diff ca41174e origin/main -- \
  src/Services/GeoJsonService.php \
  src/Services/Models/LayerService.php \
  src/Jobs/Track/ReindexEcTrackSearchableJob.php \
  src/Jobs/App/ReindexAppContentsJob.php
```

Per ogni cambiamento scrivere in `notes.md` una riga: punto, **additivo** o **modificativo**, e perché. Un solo modificativo **ferma il task**: si porta al dev prima di proseguire.

- [ ] **Step 2: Scrivere il test sull'indice di ricerca**

`tests/Feature/SearchableArrayTermsTest.php`:

```php
public function test_searchable_array_keeps_wheres_and_adds_empty_terms(): void
{
    $track = EcTrack::factory()->create([
        'properties' => ['taxonomy_where' => []],
    ]);

    $arr = $track->toSearchableArray();

    $this->assertArrayHasKey('taxonomyWheres', $arr);
    $this->assertArrayHasKey('terms', $arr);
    $this->assertSame([], $arr['terms']);
}
```

- [ ] **Step 3: Eseguire il test**

```bash
docker exec laravel-camminiditalia php artisan test --filter=SearchableArrayTermsTest
```

Atteso: PASS. Un fallimento su `taxonomyWheres` è un cambiamento modificativo: fermarsi.

- [ ] **Step 4: Commit (chiedere al dev)**

```bash
git add tests/Feature/SearchableArrayTermsTest.php docs/features/8951-allineamento-wm-package-1-6-1/notes.md
git commit -m "feat(oc:8951): test sull'indice di ricerca e classificazione del diff"
```

---

### Task 5: Confronto prima/dopo degli artefatti pubblici

**Files:**
- Nessuna modifica di codice. Gli artefatti si salvano nella cartella temporanea di sessione, non nel repo.

**Interfaces:**
- Consuma: Task 4 (la classificazione), Task 1 (ambiente aggiornato).
- Produce: l'elenco delle differenze, in `notes.md`.

Il confronto **non è il metodo di scoperta**: con le tabelle nuove vuote gli artefatti sono identici a meno di chiavi vuote in più, quindi passerà verde comunque. Serve a confermare per iscritto che non c'è nulla di modificativo.

- [ ] **Step 1: Scaricare il riferimento da produzione e da dev**

```bash
for env in "prod https://camminiditalia.maphub.it" "dev https://camminiditalia.dev.maphub.it"; do
  set -- $env
  curl -s "$2/api/app/webmapp/1/config.json"      -o "$1-config.json"
  curl -s "$2/api/app/webmapp/1/base-config.json" -o "$1-base-config.json"
done
```

- [ ] **Step 2: Generare gli stessi artefatti dall'ambiente aggiornato**

Interrogare le stesse rotte su `http://localhost:8000`.

- [ ] **Step 3: Confrontare**

```bash
diff <(jq -S . prod-config.json) <(jq -S . local-config.json)
```

Atteso: solo chiavi **in più**. Qualunque valore esistente che cambi o sparisca ferma il task e si porta al dev.

- [ ] **Step 4: Ripetere sui geojson di almeno due cammini reali**, scelti fra quelli con più tracce.

- [ ] **Step 5: Scrivere l'esito in `notes.md`** — anche quando non c'è nessuna differenza: «nessuna differenza» è un risultato, e serve a chi rileggerà.

---

### Task 6: Verifica in Nova, come Administrator e come Validator

**Files:**
- Nessuna modifica di codice attesa.

**Interfaces:**
- Consuma: Task 2 e Task 3.
- Produce: l'elenco delle cose verificate e di quelle da decidere, in `notes.md`.

- [ ] **Step 1: Come Administrator**, aprire e salvare senza errori: detail ed edit di un `EcTrack` e di un `EcPoi` con le loro classificazioni; il Field `LayerFeatures` di un `Layer` in modalità manuale, con tracce e POI elencati; l'attach `EcTrack`↔`EcPoi`; la Resource del passaporto; le Resource UGC.

- [ ] **Step 2: Come Validator** (un utente con layer propri), ripetere lo stesso giro e verificare in più che non compaia nessuna sezione di filtri sul `Layer` e che continui a vedere **solo** i propri contenuti.

- [ ] **Step 3: Guardare la index dei `Layer`** e annotare il tempo di caricamento. È la pagina che il rischio principale avrebbe reso inutilizzabile: se è lenta, il Task 2 non ha funzionato.

- [ ] **Step 4: Il doppione delle Attività** — sulla detail di `EcTrack` le Attività compaiono due volte, nella sezione delle classificazioni (come `Choice`) e nel `MorphToMany` del package che resta. Verificarlo, fotografarlo e **chiedere al dev** se toglierlo o lasciarlo. Non deciderlo da soli.

- [ ] **Step 5: Scrivere l'esito in `notes.md`.**

---

### Task 7: Rilascio in produzione

**Files:**
- Nessuna modifica di codice. La PR va verso `develop`.

**Interfaces:**
- Consuma: tutti i task precedenti chiusi e approvati dal dev.

I comandi di questo task si eseguono **solo dopo il via esplicito del dev**, uno per volta, riportando l'esito.

- [ ] **Step 1: PR verso `develop`** con il riferimento `oc:8951` e il rimando alla cartella della feature. Aperta dal dev o su sua richiesta.

- [ ] **Step 2: Dopo il deploy, i due comandi di seguito, senza nulla in mezzo**

```bash
php artisan wm-package:publish-missing-migrations
php artisan migrate --force
```

Fra l'arrivo del codice e la fine di `migrate` c'è la finestra in cui salvare un contenuto dà errore SQL: va tenuta più corta possibile.

- [ ] **Step 3: Riavviare Horizon** — gira in un container a parte e il deploy non lo riavvia, quindi i worker restano sul codice vecchio finché non lo si fa a mano.

- [ ] **Step 4: Verificare gli artefatti in produzione** — rifare il confronto del Task 5 contro `https://camminiditalia.maphub.it` e controllare che `config.json` risponda `200` e non abbia perso chiavi.

- [ ] **Step 5: Aprire Nova in produzione** e ripetere il giro breve del Task 6 come Administrator: index dei `Layer`, detail di un `EcTrack`, un salvataggio.

- [ ] **Step 6: Riportare sul ticket oc:8951** i passi eseguiti e l'esito.

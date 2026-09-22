# Sblocco eliminazione EcPoi per il Validator — Implementation Plan

> Ticket: oc:8611

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettere al Validator (gestore di cammino) di eliminare gli EcPoi di sua proprietà, allineando i due cancelli di autorizzazione (Policy Laravel + Resource Nova) che oggi bloccano sempre la delete per chiunque non sia Administrator.

**Architecture:** Nessuna nuova astrazione. Si estende il pattern ownership-based già in uso per `update()` (`EcPoiPolicy` e `App\Nova\EcPoi::authorizedToUpdate()`) al metodo `delete()`/`authorizedToDelete()` corrispondente. Due cancelli indipendenti da allineare in coppia, come già accaduto storicamente per `update` — da qui il bisogno di un test HTTP end-to-end dedicato, non solo a livello di Gate.

**Tech Stack:** Laravel 11, Laravel Nova 5, Spatie Permission (ruoli), PHPUnit (`DatabaseTransactions`), PostgreSQL/PostGIS (DB di test dedicato `camminiditalia_testing`).

**Spec:** `docs/features/8611-delete-ecpoi-validator/overview.md`

## Global Constraints

- Nessuna migrazione, nessuna modifica a `wm-package` (interamente repo principale).
- `App\Nova\EcPoi::authorizedToDelete()` deve riusare esattamente la stessa espressione booleana già presente in `authorizedToUpdate()` sulla stessa classe (righe 61-77), non una riformulazione equivalente.
- `EcPoiPolicy::delete()` deve riusare esattamente lo stesso criterio di `EcPoiPolicy::update()` (`$ecPoi->user_id === $user->id`) e di `EcTrackPolicy::delete()`.
- Ogni comando `php artisan test` va eseguito dentro il container Docker: `docker exec laravel-camminiditalia php artisan test --filter=<Nome>`.
- Ogni `EcPoi::factory()->create()` nei test richiede `'properties' => []` esplicito, altrimenti `AbstractObserver` del package fallisce con `TypeError` (trap nota, CLAUDE.md).
- Qualsiasi test che arriva a eliminare davvero un `EcPoi` (via HTTP o direttamente) attraversa `Wm\WmPackage\Observers\EcPoiObserver::deleting()`, che chiama `StorageService->deleteModelFiles()` sul disco `wmfe` — un vero S3/MinIO in locale, irraggiungibile in CI. Va isolato con `Storage::fake('wmfe')` nel `setUp()`, stesso pattern già usato in `tests/Feature/EcTrackGeometryAttributesObserverTest.php:30` (oc:8251/oc:8463).
- Nessun commit o push automatico: ogni step "Commit" di questo piano è un'istruzione testuale per lo sviluppatore, non un'azione da eseguire autonomamente.

## Review Focus

- **La risposta HTTP dell'endpoint di delete Nova (`DELETE /nova-api/ec-pois`) è sempre `200 No Content`, indipendentemente dal fatto che la delete sia stata autorizzata o meno** (`Laravel\Nova\Http\Requests\DeleteResourceRequest::deletableModels()` filtra silenziosamente i modelli non autorizzati prima di cancellarli — verificato in `vendor/laravel/nova`). Un test che verifica solo lo status code darebbe un falso positivo: ogni test HTTP di questo piano deve asserire lo stato del database (`assertDatabaseHas`/`assertDatabaseMissing`), mai solo lo status.
- **Richiesta di delete con più ID in un'unica chiamata, di proprietari diversi** (`resources: [id_proprio, id_altrui]`): un Validator può comporre questa richiesta chiamando direttamente l'endpoint (non solo dall'interfaccia, dove vedrebbe solo i propri POI). Il proprio EcPoi deve essere eliminato, quello altrui deve restare.
- **EcPoi ancora agganciato a una o più EcTrack**: il guard preesistente `EcPoiObserver::deleting()` (wm-package) deve continuare a bloccare la cancellazione anche per un Validator proprietario — comportamento esplicitamente invariato in questo ciclo (vedi overview.md, Rischi), da verificare per non regredire.
- **Administrator che elimina un EcPoi non di sua proprietà**: deve restare sempre permesso — nessuna regressione sul path già esistente.
- **Guest che tenta la delete**: deve restare bloccato (via `EcPoiPolicy::before()`, invariato).

---

## Task 1: `EcPoiPolicy::delete()` ownership-based

**Files:**
- Modify: `app/Policies/EcPoiPolicy.php:45-48`
- Test: `tests/Feature/EcPoiPolicyTest.php`

**Interfaces:**
- Consumes: `Wm\WmPackage\Models\EcPoi::$user_id` (già usato da `EcPoiPolicy::update()`, riga 41-43 dello stesso file)
- Produces: `EcPoiPolicy::delete(User $user, EcPoi $ecPoi): bool` — usato dal Gate (`Gate::forUser($user)->allows('delete', $ecPoi)`) e, indirettamente, da qualunque punto del repo che invochi la policy standard Laravel su EcPoi

- [ ] **Step 1: Sostituire il test esistente che oggi assume un blocco assoluto**

Il test attuale `test_validator_cannot_delete_ec_poi` (righe 138-143) verifica che un Validator **proprietario** non possa eliminare — comportamento che stiamo intenzionalmente invertendo. Sostituirlo con tre test che coprono i tre casi (proprietario, non proprietario, Administrator già coperto da `test_administrator_can_delete_ec_poi` esistente, riga 82-87, non toccare).

In `tests/Feature/EcPoiPolicyTest.php`, sostituire il blocco:

```php
    public function test_validator_cannot_delete_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');
        $ecPoi = $this->makeEcPoi($validator->id);
        $this->assertFalse(Gate::forUser($validator)->allows('delete', $ecPoi));
    }
```

con:

```php
    public function test_validator_can_delete_own_ec_poi(): void
    {
        $validator = $this->makeUser('Validator');
        $ecPoi = $this->makeEcPoi($validator->id);
        $this->assertTrue(Gate::forUser($validator)->allows('delete', $ecPoi));
    }

    public function test_validator_cannot_delete_ec_poi_of_another_user(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $ecPoi = $this->makeEcPoi($otherValidator->id);
        $this->assertFalse(Gate::forUser($validator)->allows('delete', $ecPoi));
    }

    public function test_guest_cannot_delete_ec_poi(): void
    {
        $guest = $this->makeUser('Guest');
        $ecPoi = $this->makeEcPoi();
        $this->assertFalse(Gate::forUser($guest)->allows('delete', $ecPoi));
    }
```

- [ ] **Step 2: Eseguire i test e verificare che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test --filter=EcPoiPolicyTest`
Expected: FAIL — `test_validator_can_delete_own_ec_poi` fallisce (`delete()` ritorna sempre `false`), `test_validator_cannot_delete_ec_poi_of_another_user` passa già (falso positivo momentaneo, perché `delete()` è comunque `false` per costruzione).

- [ ] **Step 3: Implementare la modifica minima**

In `app/Policies/EcPoiPolicy.php`, sostituire:

```php
    public function delete(User $user, EcPoi $ecPoi): bool
    {
        return false;
    }
```

con:

```php
    public function delete(User $user, EcPoi $ecPoi): bool
    {
        return $ecPoi->user_id === $user->id;
    }
```

- [ ] **Step 4: Eseguire i test e verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter=EcPoiPolicyTest`
Expected: PASS — tutti i test della classe, incluso `test_administrator_can_delete_ec_poi` (invariato, passa perché `before()` lascia sempre passare l'Administrator prima di arrivare a `delete()`).

- [ ] **Step 5: Commit**

```bash
git add app/Policies/EcPoiPolicy.php tests/Feature/EcPoiPolicyTest.php
git commit -m "fix(oc:8611): rendere EcPoiPolicy::delete() ownership-based per il Validator"
```

---

## Task 2: Allineare `App\Nova\EcPoi::authorizedToDelete()` e coprirlo con test HTTP end-to-end

**Files:**
- Modify: `app/Nova/EcPoi.php:79-82`
- Test: `tests/Feature/EcPoiNovaAuthorizationTest.php`

**Interfaces:**
- Consumes: `App\Nova\EcPoi::authorizedToUpdate()` (stesso file, righe 61-77) come riferimento diretto per la stessa espressione booleana; `$this->resource` (l'istanza `Wm\WmPackage\Models\EcPoi` corrente, già tipizzata `EcPoiModel` nel file)
- Produces: `App\Nova\EcPoi::authorizedToDelete(Request $request): bool`, invocato da Nova in `Laravel\Nova\Http\Requests\DeleteResourceRequest::deletableModels()` per ogni risorsa selezionata nella richiesta `DELETE /nova-api/ec-pois`

- [ ] **Step 1: Scrivere i test HTTP end-to-end falliti (delete)**

Nota per chi implementa: l'endpoint Nova di delete risponde sempre `200 No Content`, autorizzata o no — la verifica va fatta sullo stato del database, **mai** sullo status code da solo (vedi Review Focus).

In `tests/Feature/EcPoiNovaAuthorizationTest.php`, aggiungere in cima alla classe l'uso di `Storage::fake('wmfe')` (necessario solo da questo task in poi, i test già presenti in questo file non eliminano nulla):

```php
use Illuminate\Support\Facades\Storage;
```

Modificare `setUp()`:

```php
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wmfe');
        RolesAndPermissionsService::seedDatabase();
        if (App::count() === 0) {
            App::factory()->create();
        }
    }
```

Aggiungere in fondo alla classe (prima della chiusura `}`):

```php
    public function test_validator_can_delete_own_ec_poi_via_nova(): void
    {
        $validator = $this->makeUser('Validator');
        Layer::factory()->create(['user_id' => $validator->id]);
        $ecPoi = EcPoi::factory()->create(['user_id' => $validator->id, 'properties' => []]);

        $this->actingAs($validator)
            ->deleteJson('/nova-api/ec-pois', ['resources' => [$ecPoi->id]])
            ->assertOk();

        $this->assertDatabaseMissing('ec_pois', ['id' => $ecPoi->id]);
    }

    public function test_validator_cannot_delete_ec_poi_of_another_user_via_nova(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $ecPoi = EcPoi::factory()->create(['user_id' => $otherValidator->id, 'properties' => []]);

        $this->actingAs($validator)
            ->deleteJson('/nova-api/ec-pois', ['resources' => [$ecPoi->id]])
            ->assertOk();

        $this->assertDatabaseHas('ec_pois', ['id' => $ecPoi->id]);
    }

    public function test_validator_deleting_mixed_ownership_resources_only_deletes_own(): void
    {
        $validator = $this->makeUser('Validator');
        $otherValidator = $this->makeUser('Validator');
        $ownEcPoi = EcPoi::factory()->create(['user_id' => $validator->id, 'properties' => []]);
        $otherEcPoi = EcPoi::factory()->create(['user_id' => $otherValidator->id, 'properties' => []]);

        $this->actingAs($validator)
            ->deleteJson('/nova-api/ec-pois', ['resources' => [$ownEcPoi->id, $otherEcPoi->id]])
            ->assertOk();

        $this->assertDatabaseMissing('ec_pois', ['id' => $ownEcPoi->id]);
        $this->assertDatabaseHas('ec_pois', ['id' => $otherEcPoi->id]);
    }

    public function test_administrator_can_delete_any_ec_poi_via_nova(): void
    {
        $admin = $this->makeUser('Administrator');
        $validator = $this->makeUser('Validator');
        $ecPoi = EcPoi::factory()->create(['user_id' => $validator->id, 'properties' => []]);

        $this->actingAs($admin)
            ->deleteJson('/nova-api/ec-pois', ['resources' => [$ecPoi->id]])
            ->assertOk();

        $this->assertDatabaseMissing('ec_pois', ['id' => $ecPoi->id]);
    }

    public function test_validator_cannot_delete_own_ec_poi_still_linked_to_a_track(): void
    {
        $validator = $this->makeUser('Validator');
        Layer::factory()->create(['user_id' => $validator->id]);
        $ecPoi = EcPoi::factory()->create(['user_id' => $validator->id, 'properties' => []]);
        $ecTrack = \App\Models\EcTrack::factory()->create(['user_id' => $validator->id, 'properties' => []]);
        $ecPoi->ecTracks()->attach($ecTrack->id);

        $this->actingAs($validator)
            ->deleteJson('/nova-api/ec-pois', ['resources' => [$ecPoi->id]]);

        $this->assertDatabaseHas('ec_pois', ['id' => $ecPoi->id]);
    }
```

- [ ] **Step 2: Eseguire i test e verificare quali falliscono**

Run: `docker exec laravel-camminiditalia php artisan test --filter=EcPoiNovaAuthorizationTest`
Expected: FAIL su `test_validator_can_delete_own_ec_poi_via_nova`, `test_validator_deleting_mixed_ownership_resources_only_deletes_own` (il proprio EcPoi non viene eliminato, perché `authorizedToDelete()` è ancora hard-coded solo Administrator) e `test_administrator_can_delete_any_ec_poi_via_nova` (deve invece già passare, verificarlo: se fallisce per un altro motivo — es. fixture errata — non procedere allo Step 3 finché non è chiaro perché). Gli altri test del file (`test_validator_cannot_delete_ec_poi_of_another_user_via_nova`, `test_validator_cannot_delete_own_ec_poi_still_linked_to_a_track`) devono già passare a questo punto.

- [ ] **Step 3: Implementare la modifica minima**

In `app/Nova/EcPoi.php`, sostituire:

```php
    public function authorizedToDelete(Request $request): bool
    {
        return $request->user()?->hasRole('Administrator') ?? false;
    }
```

con:

```php
    public function authorizedToDelete(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('Administrator')) {
            return true;
        }

        /** @var EcPoiModel $ecPoi */
        $ecPoi = $this->resource;

        return $user->hasRole('Validator') && $ecPoi->user_id === $user->id;
    }
```

- [ ] **Step 4: Eseguire i test e verificare che passino**

Run: `docker exec laravel-camminiditalia php artisan test --filter=EcPoiNovaAuthorizationTest`
Expected: PASS su tutti i test del file, inclusi quelli preesistenti (`test_validator_with_layer_can_see_creation_fields`, ecc. — nessuna regressione).

- [ ] **Step 5: Commit**

```bash
git add app/Nova/EcPoi.php tests/Feature/EcPoiNovaAuthorizationTest.php
git commit -m "fix(oc:8611): allineare EcPoi::authorizedToDelete() alla policy ownership-based"
```

---

## Task 3: Verifica copertura HTTP end-to-end per `update()`, PHPStan e suite completa

**Files:**
- Nessuna modifica di codice prevista in questo task (verifica/regressione)
- Riferimento: `tests/Feature/EcPoiNovaAuthorizationTest.php:55-77` (test preesistenti `test_validator_can_update_own_ec_poi_via_nova` e `test_validator_cannot_update_ec_poi_of_another_user_via_nova`)

**Interfaces:**
- Consumes: nessuna nuova interfaccia — verifica soltanto che quanto prodotto dai Task 1 e 2 non abbia introdotto regressioni sul resto della classe policy/risorsa
- Produces: nessuna

- [ ] **Step 1: Confermare che il requisito "test HTTP Nova end-to-end anche su `update()`" è già soddisfatto**

L'endpoint `GET /nova-api/ec-pois/{id}/update-fields` invoca lo stesso `authorizedToUpdate()` che protegge la mutazione reale (`PUT /nova-api/ec-pois/{id}`): se l'autorizzazione nega, l'endpoint risponde `403`/`404` prima di esporre i campi di modifica — quindi i due test preesistenti in `tests/Feature/EcPoiNovaAuthorizationTest.php:55-77` già esercitano end-to-end lo stesso cancello HTTP, senza bisogno di scrivere un nuovo test PUT dedicato. Eseguirli per conferma:

Run: `docker exec laravel-camminiditalia php artisan test --filter=EcPoiNovaAuthorizationTest::test_validator_can_update_own_ec_poi_via_nova`
Run: `docker exec laravel-camminiditalia php artisan test --filter=EcPoiNovaAuthorizationTest::test_validator_cannot_update_ec_poi_of_another_user_via_nova`
Expected: PASS su entrambi (nessuna modifica di questo ciclo tocca `authorizedToUpdate()`).

- [ ] **Step 2: Eseguire l'intera suite di test**

Run: `docker exec laravel-camminiditalia php artisan test`
Expected: tutti i test passano, nessuna regressione su altre classi (in particolare `EcTrackPolicyTest`, `EcPoiPolicyTest`, `EcPoiNovaAuthorizationTest`, `EcPoiNovaActionsTest`).

- [ ] **Step 3: Eseguire PHPStan**

Run: `docker exec laravel-camminiditalia vendor/bin/phpstan analyse app/Policies/EcPoiPolicy.php app/Nova/EcPoi.php`
Expected: nessun errore nuovo sui due file modificati. Se PHPStan segnala errori preesistenti non correlati a questa modifica, non correggerli in questo ciclo (fuori scope) — verificare solo che i file toccati non introducano errori nuovi.

- [ ] **Step 4: Eseguire Pint**

Run: `docker exec laravel-camminiditalia composer format`
Expected: nessuna modifica di formattazione necessaria sui file toccati, o applicata automaticamente da Pint.

- [ ] **Step 5: Commit (solo se Pint ha modificato qualcosa)**

```bash
git add -A
git commit -m "fix(oc:8611): formattazione Pint su EcPoiPolicy/EcPoi Nova"
```

Se Pint non ha modificato nulla, nessun commit da fare in questo step.

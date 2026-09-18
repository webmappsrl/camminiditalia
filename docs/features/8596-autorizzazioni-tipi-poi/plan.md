> Ticket: oc:8596

# Autorizzazioni "Tipi POI" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **⚠️ Nessun commit o branch automatico:** i comandi `git commit`/`git push` elencati in ogni step sono istruzioni testuali per lo sviluppatore, non azioni da eseguire autonomamente. L'esecutore non lancia mai `git commit`, `git add` o `git push` senza conferma esplicita dell'utente per ogni singolo commit — la fase di commit è gestita separatamente dal workflow `wm-plan` dopo approvazione del developer.

**Goal:** Permettere all'Administrator di creare/modificare/eliminare i "tipi POI" (`TaxonomyPoiType`) da Nova su camminiditalia, bloccando l'eliminazione con un messaggio chiaro quando il tipo è ancora in uso, senza toccare `wm-package`.

**Architecture:** Un solo file di produzione cambia comportamento: `app/Policies/TaxonomyPoiTypePolicy.php`. Il `before()` fa da gate per ruolo (Administrator bypassa tutto, tranne l'ability `delete` per cui ritorna `null` e lascia decidere al metodo dedicato) — stesso pattern già usato in `App\Policies\UgcTrackPolicy` (oc:8575). Il metodo `delete()` verifica con una query diretta sulla pivot `taxonomy_poi_typeables` (ignorando il tipo morfico, per coprire EcPoi/EcTrack/Layer con un'unica query e aggirare il rischio di mismatch FQCN già noto in oc:8140) se il tipo è ancora in uso, e in tal caso nega con `Illuminate\Auth\Access\Response::deny()` e un messaggio tradotto.

**Tech Stack:** Laravel 11/12 + Nova 5, PHPUnit (stile del repo principale, non Pest), Spatie Permission (`hasRole()`), Postgres.

**Spec:** [docs/features/8596-autorizzazioni-tipi-poi/overview.md](overview.md)

## Global Constraints

- Nessuna modifica a `wm-package/` in nessun task (il trait `AuthorizesViaBypassRoles` del package viene solo letto come riferimento di pattern, non usato/importato — vedi Task 1).
- Nessuna modifica a `resources/lang/de.json`, `es.json`, `fr.json` — solo `it.json`/`en.json` (convenzione repo: testi Nova-only in sola it/en).
- Nessuna modifica al meccanismo "super admin" globale di wm-package (`WM_SUPER_ADMIN_EMAILS`, `RolesAndPermissionsService`).
- `forceDelete()` NON riceve la guardia "in uso" (decisione esplicita: azione non raggiungibile da Nova su questo modello, che non è soft-deletable).
- Commit convention: `feat(oc:8596): ...`.
- Stile test: mirror di `tests/Feature/EcPoiPolicyTest.php` (PHPUnit, classe che estende `Tests\TestCase`, trait `DatabaseTransactions`, metodi `test_*`, non Pest).
- `App\Models\TaxonomyPoiType` non ha una factory dedicata: le istanze si creano con `new TaxonomyPoiType([...]); $model->saveQuietly();` (stesso pattern già usato in `wm-package/tests/Unit/Policies/TaxonomyPoiTypePolicyTest.php`), mai con `::factory()->create()`.

---

### Task 1: `before()` basato su ruolo Administrator (eccezione su `delete`)

**Files:**
- Modify: `app/Policies/TaxonomyPoiTypePolicy.php`
- Create: `tests/Feature/TaxonomyPoiTypePolicyTest.php`

**Interfaces:**
- Consumes: `App\Models\User::hasRole(string $role): bool` (Spatie Permission, già usato da `EcPoiPolicy`/`UgcTrackPolicy`), `Wm\WmPackage\Services\RolesAndPermissionsService::seedDatabase()` (crea i ruoli `Administrator`/`Validator`/`Guest` nel DB di test).
- Produces: `App\Policies\TaxonomyPoiTypePolicy::before(User $user, string $ability): ?bool` — ritorna `true` per Administrator su ogni ability tranne `delete` (per cui ritorna `null`), `false` per chiunque altro. Usato dal Task 2 per la guardia su `delete()`.

- [x] **Step 1: Scrivi i test falliti per il gate di ruolo (tutte le ability tranne `delete`)**

Crea `tests/Feature/TaxonomyPoiTypePolicyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\TaxonomyPoiType;
use App\Models\User;
use App\Policies\TaxonomyPoiTypePolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\RolesAndPermissionsService;

class TaxonomyPoiTypePolicyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RolesAndPermissionsService::seedDatabase();
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function makeTaxonomyPoiType(): TaxonomyPoiType
    {
        $taxonomyPoiType = new TaxonomyPoiType(['name' => ['it' => 'Test', 'en' => 'Test']]);
        $taxonomyPoiType->saveQuietly();

        return $taxonomyPoiType;
    }

    // --- Policy attiva è quella locale, non del package ---

    public function test_local_taxonomy_poi_type_policy_is_registered(): void
    {
        $policy = Gate::getPolicyFor(TaxonomyPoiType::class);
        $this->assertInstanceOf(TaxonomyPoiTypePolicy::class, $policy);
    }

    // --- Administrator: autorizzato su tutte le ability tranne delete (Task 2) ---

    public function test_administrator_can_view_any_taxonomy_poi_type(): void
    {
        $admin = $this->makeUser('Administrator');
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', TaxonomyPoiType::class));
    }

    public function test_administrator_can_view_taxonomy_poi_type(): void
    {
        $admin = $this->makeUser('Administrator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertTrue(Gate::forUser($admin)->allows('view', $taxonomyPoiType));
    }

    public function test_administrator_can_create_taxonomy_poi_type(): void
    {
        $admin = $this->makeUser('Administrator');
        $this->assertTrue(Gate::forUser($admin)->allows('create', TaxonomyPoiType::class));
    }

    public function test_administrator_can_update_taxonomy_poi_type(): void
    {
        $admin = $this->makeUser('Administrator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertTrue(Gate::forUser($admin)->allows('update', $taxonomyPoiType));
    }

    public function test_administrator_can_restore_taxonomy_poi_type(): void
    {
        $admin = $this->makeUser('Administrator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertTrue(Gate::forUser($admin)->allows('restore', $taxonomyPoiType));
    }

    public function test_administrator_can_force_delete_taxonomy_poi_type(): void
    {
        $admin = $this->makeUser('Administrator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertTrue(Gate::forUser($admin)->allows('forceDelete', $taxonomyPoiType));
    }

    // --- Validator: nessun permesso di scrittura ---

    public function test_validator_cannot_create_taxonomy_poi_type(): void
    {
        $validator = $this->makeUser('Validator');
        $this->assertFalse(Gate::forUser($validator)->allows('create', TaxonomyPoiType::class));
    }

    public function test_validator_cannot_update_taxonomy_poi_type(): void
    {
        $validator = $this->makeUser('Validator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertFalse(Gate::forUser($validator)->allows('update', $taxonomyPoiType));
    }

    public function test_validator_cannot_restore_taxonomy_poi_type(): void
    {
        $validator = $this->makeUser('Validator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertFalse(Gate::forUser($validator)->allows('restore', $taxonomyPoiType));
    }

    public function test_validator_cannot_force_delete_taxonomy_poi_type(): void
    {
        $validator = $this->makeUser('Validator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertFalse(Gate::forUser($validator)->allows('forceDelete', $taxonomyPoiType));
    }

    // --- Guest: nessun permesso di scrittura ---

    public function test_guest_cannot_create_taxonomy_poi_type(): void
    {
        $guest = $this->makeUser('Guest');
        $this->assertFalse(Gate::forUser($guest)->allows('create', TaxonomyPoiType::class));
    }

    public function test_guest_cannot_update_taxonomy_poi_type(): void
    {
        $guest = $this->makeUser('Guest');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $this->assertFalse(Gate::forUser($guest)->allows('update', $taxonomyPoiType));
    }
}
```

- [x] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/TaxonomyPoiTypePolicyTest.php`
Expected: FAIL su `test_administrator_can_create_taxonomy_poi_type`, `test_administrator_can_update_taxonomy_poi_type`, `test_administrator_can_restore_taxonomy_poi_type`, `test_administrator_can_force_delete_taxonomy_poi_type` (attualmente questi metodi ritornano sempre `false`, tranne per l'email hardcoded `team@webmapp.it`). Gli altri test (`viewAny`/`view`/Validator/Guest) devono già passare, dato che il comportamento attuale per loro non cambia.

- [x] **Step 3: Riscrivi `before()` in `TaxonomyPoiTypePolicy`**

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#bug-introdotto-e-corretto-regressione-su-viewanyview-per-validatorguest)

Apri `app/Policies/TaxonomyPoiTypePolicy.php`. Sostituisci il metodo `before()` esistente:

```php
    /**
     * Perform pre-authorization checks.
     *
     * @param  string  $ability
     * @return void|bool
     */
    public function before(User $user, $ability)
    {
        if ($user->email === 'team@webmapp.it') {
            return true;
        }
    }
```

con:

```php
    /**
     * L'Administrator bypassa ogni ability tranne "delete", per cui la guardia
     * "tipo ancora in uso" (Task 2) deve poter intervenire — prima
     * dell'inizializzazione di questo hook nessun ruolo aveva accesso, tranne
     * un'email hardcoded (rimossa qui). Pattern mirror di
     * App\Policies\UgcTrackPolicy::before() (oc:8575).
     */
    public function before(User $user, string $ability): ?bool
    {
        if (! $user->hasRole('Administrator')) {
            return false;
        }

        if ($ability === 'delete') {
            return null;
        }

        return true;
    }
```

- [x] **Step 4: Esegui i test e verifica che passino**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/TaxonomyPoiTypePolicyTest.php`
Expected: PASS su tutti i test presenti nel file (i test su `delete` non esistono ancora, arrivano nel Task 2).

- [~] **Step 5: Commit**  _(saltato: commit vietati durante l'esecuzione, per override wm-plan)_

```bash
git add app/Policies/TaxonomyPoiTypePolicy.php tests/Feature/TaxonomyPoiTypePolicyTest.php
git commit -m "feat(oc:8596): Administrator autorizzato su create/update/restore/forceDelete tipi POI"
```

---

### Task 2: Guardia "tipo in uso" su `delete()` + messaggio i18n

**Files:**
- Modify: `app/Policies/TaxonomyPoiTypePolicy.php`
- Modify: `tests/Feature/TaxonomyPoiTypePolicyTest.php`
- Modify: `resources/lang/it.json`
- Modify: `resources/lang/en.json`

**Interfaces:**
- Consumes: `App\Policies\TaxonomyPoiTypePolicy::before()` dal Task 1 (ritorna `null` per `delete` quando l'utente è Administrator, altrimenti `false`).
- Produces: `App\Policies\TaxonomyPoiTypePolicy::delete(User $user, TaxonomyPoiType|\Laravel\Nova\Resource $taxonomyPoiType)` — ritorna `Illuminate\Auth\Access\Response::deny(string $message)` se il tipo è in uso, altrimenti `true`. Metodo privato `isTaxonomyPoiTypeInUse(int $taxonomyPoiTypeId): bool` — nessun altro task lo consuma, resta privato.

- [x] **Step 1: Scrivi i test falliti per `delete()`**

Aggiungi in coda a `tests/Feature/TaxonomyPoiTypePolicyTest.php` (dentro la classe, prima dell'ultima `}`):

```php

    // --- delete(): Administrator autorizzato solo se il tipo non è in uso ---

    public function test_administrator_can_delete_taxonomy_poi_type_not_in_use(): void
    {
        $admin = $this->makeUser('Administrator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();

        $this->assertTrue(Gate::forUser($admin)->allows('delete', $taxonomyPoiType));
    }

    public function test_administrator_cannot_delete_taxonomy_poi_type_linked_to_a_layer(): void
    {
        $admin = $this->makeUser('Administrator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $layer = Layer::factory()->create();

        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => $taxonomyPoiType->id,
            'taxonomy_poi_typeable_id' => $layer->id,
            'taxonomy_poi_typeable_type' => Layer::class,
        ]);

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $taxonomyPoiType));
    }

    public function test_delete_denial_message_is_translated(): void
    {
        $admin = $this->makeUser('Administrator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();
        $layer = Layer::factory()->create();

        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => $taxonomyPoiType->id,
            'taxonomy_poi_typeable_id' => $layer->id,
            'taxonomy_poi_typeable_type' => Layer::class,
        ]);

        app()->setLocale('it');
        $response = Gate::forUser($admin)->inspect('delete', $taxonomyPoiType);

        $this->assertFalse($response->allowed());
        $this->assertSame('Questo tipo di POI è ancora in uso e non può essere eliminato.', $response->message());
    }

    public function test_validator_cannot_delete_taxonomy_poi_type(): void
    {
        $validator = $this->makeUser('Validator');
        $taxonomyPoiType = $this->makeTaxonomyPoiType();

        $this->assertFalse(Gate::forUser($validator)->allows('delete', $taxonomyPoiType));
    }
```

- [x] **Step 2: Esegui i test e verifica che falliscano**

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-setup-mancante-di-appfactory-nel-test)

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/TaxonomyPoiTypePolicyTest.php --filter=delete`
Expected: FAIL su `test_administrator_can_delete_taxonomy_poi_type_not_in_use` e `test_delete_denial_message_is_translated` (il `delete()` attuale ritorna sempre `false`, senza messaggio). `test_administrator_cannot_delete_taxonomy_poi_type_linked_to_a_layer` e `test_validator_cannot_delete_taxonomy_poi_type` passano già per altri motivi (comportamento attuale nega sempre) — non è un problema, verranno confermati anche dopo l'implementazione.

- [x] **Step 3: Aggiungi la stringa di traduzione**

In `resources/lang/en.json`, aggiungi (in ordine alfabetico con le chiavi esistenti):

```json
    "This POI type is still in use and cannot be deleted.": "This POI type is still in use and cannot be deleted.",
```

In `resources/lang/it.json`, aggiungi (in ordine alfabetico con le chiavi esistenti):

```json
    "This POI type is still in use and cannot be deleted.": "Questo tipo di POI è ancora in uso e non può essere eliminato.",
```

- [x] **Step 4: Implementa la guardia in `delete()`**

In `app/Policies/TaxonomyPoiTypePolicy.php`, sostituisci:

```php
    /**
     * Determine whether the user can delete the model.
     *
     * @return Response|bool
     */
    public function delete(User $user, TaxonomyPoiType|\Laravel\Nova\Resource $taxonomyPoiType)
    {
        return false;
    }
```

con:

```php
    /**
     * Determine whether the user can delete the model.
     *
     * Raggiunto solo per l'Administrator (before() ritorna null solo per
     * questa ability, vedi Task 1): verifica che il tipo non sia ancora
     * agganciato a EcPoi/EcTrack/Layer prima di autorizzare, per evitare
     * che il DELETE fallisca con l'errore SQL del vincolo FK su
     * taxonomy_poi_typeables.taxonomy_poi_type_id.
     *
     * @return Response|bool
     */
    public function delete(User $user, TaxonomyPoiType|\Laravel\Nova\Resource $taxonomyPoiType)
    {
        if ($this->isTaxonomyPoiTypeInUse($taxonomyPoiType->id)) {
            return Response::deny(__('This POI type is still in use and cannot be deleted.'));
        }

        return true;
    }

    /**
     * Vero se esiste almeno una riga in taxonomy_poi_typeables per questo
     * tipo, a prescindere dal tipo morfico collegato (EcPoi, EcTrack o
     * Layer condividono la stessa tabella pivot). Interrogare la tabella
     * direttamente invece delle relazioni Eloquent evita il rischio di
     * mismatch tra FQCN locale e del package sulla colonna
     * taxonomy_poi_typeable_type (stesso problema noto in oc:8140).
     */
    private function isTaxonomyPoiTypeInUse(int $taxonomyPoiTypeId): bool
    {
        return \Illuminate\Support\Facades\DB::table('taxonomy_poi_typeables')
            ->where('taxonomy_poi_type_id', $taxonomyPoiTypeId)
            ->exists();
    }
```

- [x] **Step 5: Esegui i test e verifica che passino**

Run: `docker exec laravel-camminiditalia php artisan test tests/Feature/TaxonomyPoiTypePolicyTest.php`
Expected: PASS su tutti i test del file.

- [x] **Step 6: Esegui l'intera suite per verificare l'assenza di regressioni**

Run: `docker exec laravel-camminiditalia php artisan test`
Expected: PASS (nessuna regressione sui test esistenti — in particolare `LayerPolicyTest.php`, `EcPoiPolicyTest.php`, `EcTrackPolicyTest.php`, `UgcTrackPolicyTest.php`, invariati da questo lavoro).

- [~] **Step 7: Commit**  _(saltato: commit vietati durante l'esecuzione, per override wm-plan)_

```bash
git add app/Policies/TaxonomyPoiTypePolicy.php tests/Feature/TaxonomyPoiTypePolicyTest.php resources/lang/it.json resources/lang/en.json
git commit -m "feat(oc:8596): blocca eliminazione tipo POI ancora in uso con messaggio tradotto"
```

---

## Self-Review (svolta in fase di stesura del piano)

**Copertura spec:**
- Requisito 1 (Administrator CRUD completo) → Task 1 (create/update/restore/forceDelete) + Task 2 (delete).
- Requisito 2 (Validator/Guest negati) → Task 1 + Task 2 (test dedicati).
- Requisito 3 (nessuna modifica al super-admin globale) → Global Constraints, nessun task tocca `WM_SUPER_ADMIN_EMAILS`/`RolesAndPermissionsService`.
- Requisito 4 (guardia "in uso", tutti i morph type) → Task 2, query diretta sulla pivot senza filtro sul tipo, testata con `Layer` (il caso non coperto se si fosse controllato solo EcPoi/EcTrack).
- Requisito 5 (forceDelete senza guardia) → Task 1, nessuna guardia aggiunta, comportamento esplicito in Global Constraints.
- Requisito 6 (traduzioni it/en, non de/es/fr) → Task 2 Step 3.
- Requisito 7 (test automatico completo) → Task 1 + Task 2, unico file `TaxonomyPoiTypePolicyTest.php`.

**Placeholder scan:** nessun placeholder — ogni step ha codice completo, nessun "TODO"/"gestisci edge case" generico.

**Coerenza dei tipi:** `isTaxonomyPoiTypeInUse(int $taxonomyPoiTypeId): bool` definito e consumato solo dentro `delete()` nello stesso task/file — nessuna firma duplicata altrove nel piano.

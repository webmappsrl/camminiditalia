> Ticket: oc:8623

# Amministratori possono assegnare Validator/Guest — Implementation Plan

**Goal:** Permettere a chi ha il ruolo Spatie Administrator di assegnare i ruoli Validator/Guest in Nova (campo "Roles" su User), interamente dentro camminiditalia, senza mai poter toccare il ruolo Administrator.

**Architecture:** `App\Nova\User::fields()` chiama `parent::fields($request)` (da `Wm\WmPackage\Nova\AbstractUserResource`), individua il campo `RoleBooleanGroup` e ri-aggancia `readonly()`/`options()`/`fillUsing()` con la logica dell'allowlist. Nessuna modifica al submodule `wm-package`: si riusa solo `RolesAndPermissionsService::allowsUser()`, già esistente.

**Tech Stack:** Laravel 12, Nova 5.7.6, Spatie Laravel-Permission, `kiritokatklian/nova-permission` (alias `Vyuldashev\NovaPermission`), PHPUnit.

**Spec:** `docs/features/8623-spunta-validator-su-user/overview.md`

## Task 1: Override del campo Roles in `App\Nova\User` — completato

**Files:**
- Modified: `app/Nova/User.php`
- Test: `tests/Feature/UserNovaRoleManagementTest.php`

- [x] `fields()` mappa l'array prodotto da `parent::fields($request)`, sostituendo il campo `RoleBooleanGroup` con uno ri-configurato da `allowAdministratorToManageRoles()`
- [x] `readonly()`: `! canManageRoles($request->user())`, dove `canManageRoles()` torna true per i super-admin (`RolesAndPermissionsService::allowsUser()`) o per chi ha il ruolo Administrator
- [x] `options()`: tutti i ruoli per i super-admin, solo `['Validator', 'Guest']` per gli altri Administrator autorizzati
- [x] `fillUsing()`: per un Administrator non super-admin, applica il payload solo ai ruoli in allowlist e preserva tutti gli altri ruoli del target (letti da `getRoleNames()` prima del `syncRoles()`); per un vero super-admin, comportamento invariato (incluso anti-self-demotion)
- [x] Test: 11 scenari in `UserNovaRoleManagementTest.php` — readonly per Administrator/non-Administrator, opzioni filtrate/non filtrate, assegnazione Validator, blocco assegnazione/rimozione Administrator (su sé e su altri), preservazione di un ruolo fuori allowlist (Editor), rimozione selettiva di un ruolo, invarianza del path super-admin

**Verifica eseguita:**

```bash
docker exec laravel-camminiditalia php artisan test --filter=UserNovaRoleManagementTest
```
Risultato: 11 passed (29 assertions).

```bash
docker exec laravel-camminiditalia bash -c "cd /var/www/html/camminiditalia && vendor/bin/phpstan analyse app/Nova/User.php tests/Feature/UserNovaRoleManagementTest.php"
```
Risultato: nessun errore.

## Task 2: Verifica manuale in Nova

- [ ] Login come Administrator reale di camminiditalia (non `team@webmapp.it`)
- [ ] Aprire la risorsa User di un utente esistente non-Administrator → il campo "Roles" è editabile, mostra solo Validator e Guest come opzioni
- [ ] Flaggare Validator, salvare → il ruolo persiste
- [ ] Aprire la risorsa di un altro Administrator → il campo Roles non mostra "Administrator" come opzione
- [ ] Creare un nuovo utente, flaggare Validator in creazione, salvare → il ruolo è assegnato al nuovo utente

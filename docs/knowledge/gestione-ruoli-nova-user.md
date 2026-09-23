# Chi può modificare i ruoli di uno User in Nova

## Come funziona oggi

Il campo "Roles" della risorsa Nova User (`App\Nova\User`) è editabile da due categorie di utenti:

- I **super-admin** in `config('wm-package.super_admin_emails')` (default: solo `team@webmapp.it`) — gestione ereditata invariata da `Wm\WmPackage\Nova\AbstractUserResource`, possono assegnare/rimuovere qualsiasi ruolo, incluso Administrator.
- Chi ha il ruolo Spatie **Administrator**, ma solo per assegnare/rimuovere `Validator` e `Guest` — mai `Administrator`, né sul proprio utente né su altri. Questa seconda categoria è un override locale (`App\Nova\User::allowAdministratorToManageRoles()`, oc:8623), non esiste nel package.

L'override intercetta il campo `RoleBooleanGroup` prodotto da `parent::fields($request)` e ri-aggancia `readonly()`/`options()`/`fillUsing()`:
- `options()` mostra solo `['Validator', 'Guest']` a un Administrator non super-admin (interroga `Spatie\Permission\Models\Role` direttamente — **non** usare `app('cached_nova_roles')`, un binding interno del vendor `kiritokatklian/nova-permission`, non documentato e non garantito tra versioni: usato per errore in una prima stesura, corretto in review perché un futuro cambio del vendor lo avrebbe fatto sparire silenziosamente, incluso per i super-admin).
- `fillUsing()` applica il payload solo ai ruoli in allowlist e preserva invariato qualunque altro ruolo il target avesse già (letto da `getRoleNames()` prima di `syncRoles()`) — protezione server-side, non solo UI: anche un payload che include `Administrator` esplicitamente viene ignorato su quella chiave.

L'allowlist (`ADMINISTRATOR_MANAGEABLE_ROLES = ['Validator', 'Guest']`) è hardcoded, non configurabile.

## Perché così

- **Prima di oc:8072 (wm-package, 26/06/2026)** bastava il ruolo Administrator (o il permesso `manage roles and permissions`) per modificare i ruoli di uno User. Quel commit ha ristretto l'accesso ai soli super-admin email-whitelist — cambiamento intenzionale e documentato (`wm-package/docs/features/8072-modifica-ruolo-utente-nova/overview.md`), ma su camminiditalia nessun Administrator "vero" è mai stato aggiunto a quella whitelist: nessuno tranne `team@webmapp.it` poteva più assegnare Validator a un utente (oc:8623).
- **Fix interamente nel consumer, non nel package**: prima approvata e implementata una versione in `wm-package` (config opt-in, guard nel servizio condiviso), poi il dev ha chiesto esplicitamente di spostare tutto in camminiditalia per non toccare un componente condiviso da altri consumer Webmapp per un comportamento specifico di questo progetto. `App\Nova\User::fields()` può farlo senza alcuna modifica al package: i metodi fluent di un campo Nova (`readonly()`/`options()`/`fillUsing()`) sono semplici setter, l'ultima chiamata vince.
- **Allowlist esplicita, non blocklist su "Administrator"**: un blocklist ("tutti i ruoli tranne Administrator") si allargherebbe automaticamente a un ipotetico ruolo futuro con permessi ampi (es. un Editor comparso per errore, già esistente nel package con permessi reali su altri consumer). Un'allowlist minima (`Validator`, `Guest`) resta valida anche se un ruolo nuovo compare nel DB.
- **Nessun audit trail**: valutato e scartato in fase di analisi — un'assegnazione fatta da un Administrator non è distinguibile a posteriori da una fatta da un super-admin. Rischio accettato esplicitamente dal cliente.

## Come ci siamo arrivati

- Una prima implementazione (config `wm-package.allow_administrator_role_management`, metodo `RolesAndPermissionsService::allowsAdministratorRoleManagement()`, tutto in `wm-package`) è stata completata e superata una review di sicurezza dedicata sulla logica `fillUsing()` (nessun path di escalation trovato), poi **annullata per intero** su richiesta del dev a favore della versione solo-consumer descritta sopra — nessuna traccia di quel ciclo resta nel codice.

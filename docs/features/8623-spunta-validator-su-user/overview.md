> Ticket: oc:8623

# Spunta Validator su User — permettere agli Administrator di gestire i ruoli

## Cosa cambia

Gli Administrator di camminiditalia tornano a poter assegnare il ruolo Validator (e Guest) a un utente dal form Nova, senza poter toccare il ruolo Administrator stesso — né sul proprio utente né su altri. Tutta la logica vive in `App\Nova\User` (repo principale): nessuna modifica a `wm-package`.

## Perché

Dal 29/06/2026 (bump submodule `d1191ff`), il campo "Roles" nel form Nova User è editabile solo dall'email `team@webmapp.it` (whitelist super-admin di `wm-package`, introdotta intenzionalmente da `wm-package@6e4e83bf`, oc:8072) — nessun Administrator "vero" di camminiditalia ci riesce più. Il cliente ha segnalato il blocco creando un nuovo ente gestore da rendere Validator (oc:8623).

Prima di oc:8072 bastava il ruolo Administrator (o il permesso `manage roles and permissions`) per modificare i ruoli. Il ripristino richiesto dal cliente copre solo il ruolo Administrator, solo per camminiditalia, e solo per Validator/Guest — non per il ruolo Administrator stesso, che resta una decisione riservata ai super-admin.

## Requisiti

- [x] `App\Nova\User::fields()` intercetta il campo `RoleBooleanGroup` prodotto da `AbstractUserResource` (wm-package) e ne sovrascrive `readonly()`/`options()`/`fillUsing()`
- [x] Un Administrator non super-admin può editare il campo Roles (readonly=false)
- [x] Le opzioni mostrate a un Administrator non super-admin sono solo Validator e Guest — mai Administrator
- [x] Un vero super-admin (email whitelist) continua a vedere e gestire tutti i ruoli, incluso Administrator — comportamento invariato
- [x] `fillUsing()`: un Administrator non super-admin può assegnare/rimuovere solo Validator/Guest; qualunque altro ruolo il target avesse già (Administrator compreso, o un ruolo futuro fuori allowlist) resta intatto anche se il payload prova a includerlo/escluderlo — protezione server-side, non solo UI
- [x] Nessuna modifica al campo Permissions
- [x] Nessuna modifica a `wm-package` — solo `RolesAndPermissionsService::allowsUser()`, già esistente, viene riusato
- [x] Test automatico in `tests/Feature/UserNovaRoleManagementTest.php`, eseguito con successo tramite la suite reale di camminiditalia (`php artisan test`)

## Rischi

- **Escalation di privilegio limitata dall'allowlist, non eliminata**: un Administrator può comunque assegnare Validator/Guest a un account arbitrario. Se un account Administrator viene compromesso, il raggio d'azione include ora anche questa capacità — accettato: è la richiesta esplicita del cliente, mitigata tenendo l'allowlist minima (`['Validator', 'Guest']`, hardcoded) invece di un blocklist sul solo nome "Administrator" che si sarebbe allargato automaticamente a ruoli futuri (es. un eventuale Editor).
- **Nessun audit trail**: un'assegnazione fatta da un Administrator non è distinguibile a posteriori da una fatta da un super-admin. Confermato fuori scope.
- **Accoppiamento implicito al comportamento interno di `RoleBooleanGroup`** (pacchetto `kiritokatklian/nova-permission`): `App\Nova\User` ri-aggancia `readonly()`/`options()`/`fillUsing()` sull'istanza prodotta da `parent::fields()`, quindi un cambio della struttura interna del campo nel package (es. rinominato `RoleBooleanGroup`, cambiato il costruttore) romperebbe questo override senza che wm-package se ne accorga — rischio noto e accettato, è l'unico modo di ottenere il comportamento richiesto senza toccare il package. Una variante più subdola di questo rischio (leggere `app('cached_nova_roles')`, un binding interno non documentato del vendor, per costruire l'elenco ruoli) è stata trovata in review e corretta prima del commit: `options()` ora interroga `Spatie\Permission\Models\Role` direttamente — vedi `notes.md`.

## Out of scope

- Modifica del campo Permissions
- Ripristino del controllo storico su `hasPermissionTo('manage roles and permissions')` (il cliente ha chiesto solo il ruolo Administrator)
- Meccanismo di audit/log su chi assegna un ruolo a chi
- Qualunque modifica al submodule `wm-package`
- Rendere l'allowlist configurabile (oggi hardcoded `['Validator', 'Guest']` in `App\Nova\User`)

## Moduli toccati

| File | Tipo modifica |
|------|---------------|
| `app/Nova/User.php` | Override di `fields()`; nuovi metodi privati `allowAdministratorToManageRoles()`, `canManageRoles()` |
| `tests/Feature/UserNovaRoleManagementTest.php` | Nuovo file di test (11 scenari) |

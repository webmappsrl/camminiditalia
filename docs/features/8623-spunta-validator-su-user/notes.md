> Ticket: oc:8623

# Notes — Spunta Validator su User

## Deviazioni dal piano

Il piano approvato inizialmente (dopo overview e challenge) implementava l'intera logica in `wm-package` (submodule), dietro un config opt-in (`WM_ALLOW_ADMIN_ROLE_MANAGEMENT`), con attivazione via `.env` solo in camminiditalia. I Task 1-3 di quel piano sono stati completati e passati in review (compresa una review finale di sicurezza sulla logica `fillUsing()`, approvata senza Critical/Important).

Durante l'esecuzione, il dev ha chiesto esplicitamente di spostare **tutta** la logica dentro camminiditalia, lasciando `wm-package` intoccato ("nel wm-package al massimo se necessario qualche funzione di supporto, ma se evitabile meglio"). Si è verificato che è evitabile del tutto: `App\Nova\User::fields()` può intercettare il campo `RoleBooleanGroup` prodotto da `parent::fields()` (ereditato da `AbstractUserResource` del package) e ri-agganciarne `readonly()`/`options()`/`fillUsing()` con la nuova logica, riusando solo `RolesAndPermissionsService::allowsUser()` — già esistente e invariato.

Tutte le modifiche fatte in `wm-package` durante i Task 1-3 (config, `RolesAndPermissionsService::allowsAdministratorRoleManagement()`, `AbstractUserResource::fields()`, i relativi test) sono state annullate (`git checkout --`), il branch `feature/oc-8623-spunta-validator-su-user` in wm-package è stato eliminato (nessun commit era mai stato creato — la policy "no commit durante l'esecuzione" del workflow ha reso il ripristino pulito), e la cartella `docs/features/8623-spunta-validator-su-user/` in wm-package è stata rimossa.

L'implementazione finale vive interamente in `app/Nova/User.php` (repo principale), con test in `tests/Feature/UserNovaRoleManagementTest.php`.

## Bug trovati (durante il ciclo poi annullato in wm-package, non presenti nell'implementazione finale)

- Il campo Nova `BooleanGroup` nella versione realmente installata (Nova 5.7.6) non espone una proprietà pubblica `$options`: le opzioni risolte si leggono con `$field->jsonSerialize()['options']`. Una prima stesura del test (e del brief del piano wm-package, poi annullato) leggeva `$field->options`, inesistente in questa versione — scoperto verificando dal vivo contro il vendor reale, non contro una copia locale obsoleta trovata per errore in `wm-package/vendor/laravel/nova`.
- Il container Docker `php-forestas` (riferimento generico nella documentazione di wm-package) non esiste in questo ambiente locale: solo lo stack `camminiditalia` è attivo. Inoltre `/var/www/html/wm-package` nei container camminiditalia è un bind-mount di un clone del tutto separato e non correlato (`/Users/peco/Documents/BackEnd/wm-package`, su un branch diverso) — non il submodule. Il path corretto per eseguire la suite reale di wm-package da dentro un container camminiditalia è `/var/www/html/camminiditalia/wm-package` (raggiunto anche dall'autoloader reale via `vendor/wm/wm-package` → symlink). Irrilevante per l'implementazione finale (che non tocca più wm-package), ma vale la pena lasciarlo scritto per il prossimo che lavora su questo submodule da un consumer.

## Decisioni

- Allowlist dei ruoli assegnabili tramite il canale esteso: hardcoded `['Validator', 'Guest']` in `App\Nova\User` — nessuna configurabilità, nessuna env var. Deciso in fase di challenge (sull'impianto originario in wm-package, poi ereditato senza modifiche nel design finale): un blocklist sul solo nome "Administrator" si sarebbe allargato automaticamente a ruoli futuri (es. un eventuale Editor comparso per errore), un'allowlist esplicita no.
- Nessun audit trail su chi assegna un ruolo a chi — confermato fuori scope in fase di challenge.
- Spostamento dell'intera implementazione da wm-package a camminiditalia deciso dal dev a metà esecuzione, dopo che il ciclo in wm-package era già stato implementato e revisionato — vedi sezione Deviazioni sopra.
- **Fix da review (`wm-review-ticket`)**: `options()` inizialmente leggeva `app('cached_nova_roles')`, un binding container interno non documentato del vendor `kiritokatklian/nova-permission` (popolato dal costruttore di `RoleBooleanGroup`, filtrato a sua volta da `Wm\WmPackage\Policies\RolePolicy::before()` — un asse di autorizzazione indipendente da `RolesAndPermissionsService::allowsUser()` su cui si basa il resto della feature). Segnalato indipendentemente da 3 dei 5 finder della review come rischio di fallimento silenzioso (un futuro cambio del vendor avrebbe fatto sparire tutte le opzioni del campo, incluso per i super-admin veri, senza errore visibile). Il fix introduceva questo rischio (non preesisteva: prima nessun consumer in questo repo leggeva quella cache), quindi corretto subito: sostituita con una query diretta `Spatie\Permission\Models\Role::pluck('name', 'name')`. Ri-verificato: 11/11 test passano, PHPStan pulito.

## Follow-up

Emersi dalla review (`wm-review-ticket`), non bloccanti, non corretti in questo ciclo:
- Nessun test verifica che il campo Permissions resti intoccato per un Administrator non super-admin
- Nessun test chiama `fillUsing()` direttamente per un utente Guest/Validator (solo `readonly()` è testato per quel caso)
- `instanceof RoleBooleanGroup` in `fields()` non controlla anche il nome dell'attributo (`'roles'`) — fragile se il package aggiungesse in futuro un secondo campo dello stesso tipo
- Nome costante `ADMINISTRATOR_MANAGEABLE_ROLES` giudicato ambiguo da un finder (si legge quasi come "ruoli che gestiscono gli Administrator")
- 10 test su 11 duplicano lo stesso blocco di setup (4 righe) invece di un helper condiviso
- Verifica manuale in Nova (Task 2 di `plan.md`) ancora da fare a mano dal dev prima del merge

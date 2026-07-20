> Ticket: oc:8182

# Notes — Dashboard statistiche aggregate per Cammini d'Italia (repo principale)

## Deviazioni dal piano

Nessuna nel repo principale — `app/Nova/Layer.php::cards()` è stato implementato esattamente come da piano (Task 12), test reali eseguiti e passati (`LayerGlobalAnalyticsCardVisibilityTest`: 3/3, nessuna regressione su `LayerActionsVisibilityTest`/`LayerPolicyTest`). Tutte le estensioni successive (shares, breakdown piattaforma, ricerche) sono avvenute interamente in `wm-package` — vedi `wm-package/docs/features/8182-dashboard-statistiche-aggregate/notes.md` per il dettaglio.

## Bug trovati

- **`app/Models/User.php` includeva due volte il trait `Impersonatable`** (Laravel Nova) — una volta ereditato/già risolto da `Wm\WmPackage\Models\User` (che lo usa e sovrascrive `canImpersonate(): bool` con tipo esplicito), una volta ridichiarato localmente in questa classe. PHP non riusciva a conciliare il metodo del trait (non tipizzato) con quello già tipizzato ereditato — fatal error su ogni richiesta a `/nova`. Preesistente (introdotto con oc:8231 a monte, non da oc:8182), emerso dopo un ripristino del database. **Fix**: rimossa la ridichiarazione ridondante del trait dalla sottoclasse locale — nessun'altra modifica.
- **Database di sviluppo svuotato durante l'esecuzione del piano**: `phpunit.xml` non isola il DB di test da quello di sviluppo (`DB_CONNECTION=sqlite`/`DB_DATABASE=:memory:` commentati). Ogni `php artisan test` con `RefreshDatabase` (usato ripetutamente durante la verifica dei task) ha eseguito `migrate:fresh` sul DB reale. Il dev ha dovuto ripristinare da backup. **Non risolto**: sqlite in-memory rompe le migration Postgres/PostGIS; un DB Postgres di test dedicato è stato proposto e scartato dal dev, che ha chiesto esplicitamente di non toccare più questa configurazione senza chiederlo prima. Vedi memoria `feedback_never_run_destructive_db_tests.md`.

## Decisioni

- Nessuna decisione architetturale aggiuntiva nel repo principale oltre a quanto già in `overview.md`/`plan.md` — il repo principale resta scope minimale (solo registrazione card), tutta l'evoluzione della feature vive nel submodule.

## Follow-up

- Nessuno specifico al repo principale.

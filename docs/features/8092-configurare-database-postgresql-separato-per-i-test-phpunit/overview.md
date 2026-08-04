> Ticket: oc:8092

# Configurare database PostgreSQL separato per i test PHPUnit

## Cosa cambia
La suite PHPUnit smette di girare sul database di sviluppo (`camminiditalia`). Viene creato un database dedicato ai test (`camminiditalia_testing`, clonato da `template_postgis` per avere PostGIS preinstallato), configurato tramite un nuovo `.env.testing` e un aggiornamento di `phpunit.xml`, così che `RefreshDatabase` operi solo sul DB di test senza più svuotare i dati locali.

## Perché
Durante l'implementazione di oc:8089, il test `LayerActionsVisibilityTest` ha eseguito `migrate:fresh` (via `RefreshDatabase`) sul DB di sviluppo condiviso, cancellando 4987 utenti e 117 layer, ripristinati manualmente da backup (`wm:download-db-backup` + `wm:restore-db`). SQLite in-memory non è un'alternativa praticabile: il progetto usa funzioni PostGIS 3D (`ST_GeomFromGeoJSON`, `ST_DistanceSphere`, `ST_3DDistance`) non supportate da SQLite.

## Requisiti
- [x] Creare il database `camminiditalia_testing` nel container Docker locale, clonandolo da `template_postgis` (via `DB::statement()` in tinker, non `psql` diretto — evita il prompt password interattivo)
- [x] Creare `.env.testing` nella root del repo con i parametri di connessione del `.env` locale, `DB_DATABASE=camminiditalia_testing` — file **committato direttamente** (nessuna variante `.env.testing.example`, decisione esplicita: credenziali fisse e non sensibili, uguali per tutti i dev). **Qualificato in review** (vedi `notes.md`): la decisione copre solo `DB_USERNAME`/`DB_PASSWORD` — `APP_KEY`/`JWT_SECRET`/`AWS_DUMPS_*` non sono "non sensibili" e sono stati rigenerati dedicati o rimossi prima del commit
- [x] Aggiornare `phpunit.xml`: rimuovere le righe SQLite commentate, aggiungere `DB_DATABASE=camminiditalia_testing` nel blocco `<php>` (necessario perché gli `<env>` di phpunit hanno precedenza sul contenuto di `.env.testing` a runtime)
- [x] Eseguire le migration sul nuovo DB di test (`php artisan migrate --env=testing`)
- [x] Verificare che la suite esistente giri correttamente sul nuovo DB — pattern da preservare: `RefreshDatabase`, `RolesAndPermissionsService::seedDatabase()`, `App::factory()->create()` prima di ogni `Layer` (dipendenza `LayerFactory` → `App::first()->id`)
- [x] Documentare in `CLAUDE.md` → `## Comandi comuni`: sia il setup one-time (creazione DB + migrate) sia un comando di reset rapido (`migrate:fresh --env=testing`) per quando il DB di test si corrompe durante lo sviluppo
- [x] **[Emerso da Fase: challenge, non nel ticket originale]** Aggiornare `.github/workflows/run-tests.yml`: impostare `DB_HOST` e `DB_DATABASE` come env var esplicite a livello di step (puntando al servizio Postgres effimero già esistente in CI, es. `DB_HOST=localhost`, `DB_DATABASE=camminiditalia`) — necessario perché Laravel carica automaticamente `.env.testing` quando `APP_ENV=testing` (già impostato in `phpunit.xml`), e senza questa protezione il nuovo `.env.testing` (con `DB_HOST=db`, valido solo su rete Docker locale) verrebbe caricato anche in CI, dove l'hostname `db` non esiste e romperebbe tutti i test DB-touching su ogni PR

## Rischi
- ~~**40 test falliscono già oggi** per `ConnectionException` verso Redis~~ — **risolto durante questo ciclo** (non era nello scope originale, vedi `notes.md`): causa reale era l'immagine Docker `redis:latest` in variante `amd64` fatta girare via emulazione qemu su host `arm64`, non un problema applicativo. Ri-pull nativo `arm64` + ricreazione container → suite al 100% verde (145 passati, 0 falliti). Il numero era documentato come 28 in `CLAUDE.md` — decisioni oc:8312, risultato 40 alla prima verifica di questo ciclo prima del fix.
- I comandi Docker/DDL di creazione DB e migrate vengono eseguiti direttamente durante questa sessione (autorizzazione esplicita dell'utente) — operazione reversibile (il DB può essere droppato e ricreato) ma comunque una modifica di stato dell'ambiente Docker locale.
- Esecuzione concorrente della suite sullo stesso `camminiditalia_testing` (due dev in parallelo, o dev + CI nello stesso momento) potrebbe generare race condition via `migrate:fresh` — rischio accettato, dichiarato esplicitamente out of scope su richiesta dell'utente (lavoro attuale è single-dev su questo container).
- ~~**Bypass silenzioso della fix via `config:cache`**~~ — **mitigato**: aggiunta una guardia in `tests/TestCase.php` (`refreshApplication()`) che verifica il nome del DB attivo prima che `RefreshDatabase` possa eseguire `migrate:fresh`, e lancia un errore esplicito se non è `camminiditalia_testing`. Non impedisce che `config:cache` disattivi il caricamento di `.env.testing`, ma trasforma il fallimento da silenzioso (dati cancellati senza preavviso) a rumoroso (test bloccato con messaggio chiaro, nessuna migration eseguita). Verificato empiricamente: simulato il bypass (`DB_DATABASE` forzato al valore dev), la guardia interviene prima che qualsiasi query DDL parta, DB di sviluppo confermato invariato dopo il tentativo.
- **Audit non eseguito su dipendenze implicite dai dati del DB dev**: alcuni test esistenti potrebbero oggi passare "per caso" appoggiandosi a righe organiche già presenti nel DB di sviluppo condiviso (es. un test che dimentica `App::factory()->create()` ma trova comunque un'`App` esistente). Su un DB di test pulito questi test potrebbero iniziare a fallire in modo nuovo. Mitigazione: nessun audit sistematico in questo ciclo — eventuali fallimenti emersi allo Step 5 verranno diagnosticati caso per caso, non assunti a priori come regressioni della fix.

## Out of scope
- Gestione dell'esecuzione parallela/concorrente dei test sullo stesso DB di test
- Pattern `.env.testing.example` — si committa direttamente `.env.testing`
- Applicazione dello stesso pattern al boilerplate `laravel-postgis-boilerplate` — menzionato nel ticket come lavoro futuro su un ticket separato

## Moduli toccati
- `.env.testing` (nuovo file, root del repo principale)
- `phpunit.xml` (modifica: rimozione righe SQLite commentate, aggiunta `DB_DATABASE`)
- `CLAUDE.md` (sezione `## Comandi comuni`: setup one-time + comando di reset rapido)
- `.github/workflows/run-tests.yml` (**emerso da Fase: challenge**, non elencato nel ticket originale — env var esplicite per proteggere la CI dall'auto-load di `.env.testing`)
- `tests/TestCase.php` (**emerso dopo i commit iniziali**, non nel ticket originale — guardia contro il bypass silenzioso via `config:cache`; corretta dopo una seconda review che ha trovato un blocker: la guardia rompeva la CI, vedi `notes.md`)
- `config/app.php` (**emerso dallo stesso fix**, non nel ticket originale — chiave `running_in_ci` per rispettare la convenzione Larastan `env()` solo nei file di config)
- Database Docker locale: nuovo DB `camminiditalia_testing` (stato infrastrutturale, non versionato in git)

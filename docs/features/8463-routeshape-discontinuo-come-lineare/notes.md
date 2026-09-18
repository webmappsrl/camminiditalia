> Ticket: oc:8463

# Notes — RouteShape: esporre Discontinuo come Lineare al frontend, tenere l'alert solo in Nova

## Divergenze dal piano, task per task

### Task 1: helper generico invece che specifico di Layer

Il piano prevedeva `Layer::withoutInternalAttributes()`, con `shape_discontinuous` scritto a lettere dentro `wm-package`. Durante l'esecuzione (dopo che Task 1 e 2 erano già stati implementati e revisionati), il dev ha chiesto di generalizzare: wm-package non deve conoscere un concetto specifico di un solo consumer (camminiditalia).

Sostituito con:
- Helper globale del pacchetto `withoutInternalConfigKeys(array $data): array` in `wm-package/src/helpers.php` (autoloaded via composer `"files"`), che legge `config('wm-package.internal_attribute_keys')`.
- Nuova chiave di config `internal_attribute_keys` in `wm-package/config/wm-package.php`, popolata da `env('WM_INTERNAL_ATTRIBUTE_KEYS')`, comma-separated, vuota di default — stesso pattern già in uso per `default_layer_mode`/`DEFAULT_LAYER_MODE` (oc:8314).
- `App\...\Layer::withoutInternalAttributes()` rimosso interamente.
- I due call site (`AppConfigService::config_section_map()`, `AppController::layer()`) aggiornati per chiamare `withoutInternalConfigKeys()` invece del metodo su `Layer`.
- I due test wm-package aggiornati per impostare esplicitamente `config(['wm-package.internal_attribute_keys' => ['shape_discontinuous']])` (Testbench non carica `.env`/`.env.testing`, quindi senza questa riga il default vuoto vanificherebbe il test).
- camminiditalia: aggiunta `WM_INTERNAL_ATTRIBUTE_KEYS=shape_discontinuous` a `.env`, `.env.testing`, `.env-example`.

Nessun impatto sui requisiti approvati: il comportamento pubblico (config.json, endpoint layer()) resta identico, cambia solo dove vive la conoscenza del nome della chiave.

### Task 3: bump submodule mai eseguito come task a sé

Il piano prevedeva un Task 3 dedicato al bump del gitlink `wm-package` nel repo principale, dopo il merge di Task 1-2. Durante l'esecuzione (workflow Webmapp con commit vietati fino a review-gate), Task 3 non è stato dispatchato come task isolato: il suo unico contenuto sarebbe stato un `git commit`, incompatibile con l'override "nessun commit durante l'esecuzione automatica".

Le modifiche di Task 1-2 sono rimaste visibili a Task 4-6 tramite il working tree del submodule (stesso filesystem, nessun bump necessario per eseguire i test in locale). Il bump reale del gitlink va fatto manualmente, insieme agli altri commit, dopo l'approvazione del dev — vedi "Decisioni" sotto per l'ordine.

### Task 6: blocco ambientale reale, non del test

Il Task 6 implementer ha riportato BLOCKED: il test scritto correttamente falliva perché l'autoloader Composer di camminiditalia risolve `Wm\WmPackage\` tramite `vendor/wm/wm-package` (symlink Composer path-repository) → il **submodule Git** `camminiditalia/wm-package`, non la cartella sibling `/Users/peco/Documents/BackEnd/wm-package` che il container Docker monta su `/var/www/html/wm-package` (mount condiviso con altri consumer del pacchetto — forestas, maphub, osm2cai2 — per scopi diversi dall'autoload di questo progetto).

Le modifiche di Task 1-2 erano state scritte solo nel clone sibling (popolato durante l'esecuzione perché in origine era vuoto — vedi "Decisioni"). Risolto copiando i file già scritti e già approvati in review dal clone sibling al submodule reale. Nessuna nuova logica di dominio: solo sincronizzazione file, stesso commit base, stesso branch.

Conseguenza operativa: da questo punto in poi le modifiche wm-package esistono in **due checkout locali** sullo stesso branch. Va scelto il submodule come sorgente di verità per il commit reale (è quello referenziato dal repo principale) — vedi "Decisioni".

## Review formale (wm-review-ticket, 5 finder paralleli)

**Blocker trovato e corretto:** `App\Nova\Layer::renderShapeCard()` (app/Nova/Layer.php) decideva il badge di warning solo dal nuovo flag `$isDiscontinuous`. Un layer persistito **prima** di questo fix, con `shape=discontinuous` e senza ancora `shape_discontinuous` (chiave che non esisteva), cadeva nel ramo finale e mostrava un badge verde "ok" con etichetta "Discontinuous" — l'opposto dell'intento del ticket, per tutta la finestra tra il deploy e l'esecuzione del ricalcolo bulk (Task 7). Corretto: la condizione del ramo warning è ora `$isDiscontinuous || $enum === RouteShape::DISCONTINUOUS`, che copre sia il nuovo formato sia i dati storici non ancora ricalcolati. Aggiunto un test di regressione dedicato (`test_layer_with_legacy_discontinuous_shape_and_no_flag_still_shows_warning`).

**Cleanup applicati:**
- Docblock di `test_layer_with_discontinuous_type_shows_value_with_explanation` (non più coerente con lo schema post-fix) riscritto.
- Riga vuota residua prima della chiusura della classe in `wm-package/src/Models/Layer.php` (rumore da un add-poi-remove del metodo `withoutInternalAttributes()`) rimossa in entrambi i checkout.

**Cleanup non applicati, registrati come follow-up** (vedi sezione sotto): stringa `shape_discontinuous` duplicata senza costante condivisa; confronto `$shape === RouteShape::DISCONTINUOUS` ripetuto due volte in `computeCalculatedValues()`; pattern `isset()+is_array()` duplicato nei due call site wm-package; nome `withoutInternalConfigKeys()` leggermente disallineato dall'uso su un endpoint non "config"; nessun test copre l'`.env` di produzione (solo `.env.testing`, versionato).

## Bug trovati

- **Task 1 (rifuso nel piano, non del codice implementato):** il test proposto usava `App::factory()->create()` invece di `createQuietly()`. `AppObserver::saved()` (wm-package) chiama `writeAppConfigOnAws()` **senza try/catch** — in ambiente Testbench (nessun `.env` caricato, credenziali S3 null) questo rischia un'eccezione non correlata alla logica testata, o nel caso peggiore una scrittura verso un vero endpoint S3. Confermato da un commento esplicito già presente in `wm-package/tests/Pest.php`. Fix: `createQuietly()`, stesso pattern già usato da `AppConfigServiceImportedPropertiesTest.php`. Propagato anche al Task 2 e al Task 6 prima ancora di scriverne il codice.
- **Task 6 (rifuso nel piano):** il test end-to-end proposto non usava `RefreshDatabase`. Verificato empiricamente dal reviewer: ogni esecuzione lasciava una riga `App` e una riga `Layer` orfane e permanenti nel DB di test condiviso `camminiditalia_testing`. Fix: aggiunto il trait, stesso pattern di `LayerAttributesStatePanelTest`. Le righe orfane già create durante le verifiche (poche, DB locale) non sono state ripulite — non bloccante.

## Decisioni

- **wm-package esiste in due checkout locali non allineati con Docker**, scoperto durante l'esecuzione: il submodule Git `camminiditalia/wm-package` (quello che l'autoloader Composer usa davvero, via `vendor/wm/wm-package` → symlink path-repository) e una cartella sibling `/Users/peco/Documents/BackEnd/wm-package` (quella montata dal container Docker su `/var/www/html/wm-package`, condivisa con altri consumer del pacchetto). La cartella sibling era **vuota** dal 2 luglio 2025. Popolata con un clone fresco dallo stesso remote, sullo stesso branch di lavoro, per permettere l'esecuzione dei test Pest nativi del pacchetto (che richiederebbero il container `php-forestas`, non disponibile in questa sessione per conflitto di porte con camminiditalia). Tutte le modifiche di Task 1-2 sono state applicate e mantenute sincronizzate in **entrambi** i checkout durante l'esecuzione.
- **I test Pest nativi di wm-package (Task 1-2) non sono stati eseguiti in questa sessione** — ambiente `php-forestas` non disponibile, avviarlo confligge sulle porte (8000, 9100, 5500, 5601) con i container di camminiditalia. Decisione esplicita del dev: "testiamo alla fine". La verifica end-to-end reale del fix wm-package è comunque avvenuta tramite il test del Task 6, eseguito realmente nel container `laravel-camminiditalia` (che carica il submodule reale) — copre lo stesso codice, anche se non sostituisce l'esecuzione della suite nativa del pacchetto.
- **Ordine di commit da rispettare quando il dev procederà** (nessun commit eseguito in questa sessione, per vincolo esplicito):
  1. Commit in `camminiditalia/wm-package` (submodule — sorgente di verità, non il clone sibling) delle modifiche di Task 1-2 + refactor generico: `config/wm-package.php`, `src/helpers.php`, `src/Models/Layer.php`, `src/Services/Models/App/AppConfigService.php`, `src/Http/Controllers/Api/AppController.php`, i due nuovi test.
  2. Merge/PR di wm-package.
  3. Bump del gitlink `wm-package` nel repo principale (Task 3, mai eseguito automaticamente).
  4. Commit delle modifiche del repo principale: `app/Services/LayerAttributesService.php`, `app/Nova/Layer.php`, `tests/Feature/LayerAttributesStatePanelTest.php`, i due nuovi test, `.env`/`.env.testing`/`.env-example`.
  5. Deploy, poi procedura operativa Task 7 (ricalcolo + verifica SQL).
- **Il clone sibling `/Users/peco/Documents/BackEnd/wm-package`** creato durante questa sessione resta sul disco dopo il ciclo — non è stato ripulito, dato che potrebbe essere lo stesso checkout che il dev userà in futuro per lavorare su wm-package con `php-forestas` (uso previsto dal `CLAUDE.md` del pacchetto). Nessun'azione richiesta a meno che il dev preferisca gestirlo diversamente.

## Bug non correlato trovato e corretto durante il test manuale (fuori scope, ma bloccava il test di Task 7)

Il dev, testando manualmente `RecalculateAppLayerAttributesAction` da Nova, ha ottenuto un `SQLSTATE[25P02]` (transazione abortita). Causa: `App\Jobs\RecalculateLayerAttributesJob` implementa `ShouldBeUniqueUntilProcessing` ma non dichiarava `uniqueVia()`, quindi il lock di unicità passava dallo store di default (`CACHE_STORE=database`). `DatabaseLock::acquire()` fa un `INSERT` e, se la riga di lock esiste già, ripiega su un `UPDATE` nello stesso `try`/`catch` — su PostgreSQL una query fallita "avvelena" l'intera transazione, e l'azione Nova dispatcha un job per layer **dentro una sola transazione**: al primo layer con lock già presente, tutto il batch andava in 500.

Questa è la stessa identica trappola già documentata e già risolta con lo stesso pattern per `UpdateAppConfigJob` e `BuildAppPoisGeojsonJob` (oc:8564) — non una scoperta nuova, solo un job che non aveva ancora ricevuto il fix. Corretto aggiungendo `uniqueVia(): Repository { return Cache::store('redis'); }` a `RecalculateLayerAttributesJob` (`app/Jobs/RecalculateLayerAttributesJob.php`), fix confinato al repo principale (il job non vive in wm-package).

Aggiunto un test (`test_unique_via_uses_redis_not_the_default_database_store`). Un secondo test che tentava di riprodurre l'abort dispatchando due volte lo stesso layer dentro `DB::transaction()` è stato scritto e poi **rimosso**: passava anche senza il fix, perché `DatabaseTransactions` (il trait dei test) apre già una transazione per il test, e la `DB::transaction()` interna diventa una savepoint innestata che non riproduce l'abort di una transazione di primo livello come quella reale di Nova — un test che non discrimina, quindi rimosso per non dare falsa sicurezza.

Verificato dal vivo con lo scenario reale (non solo con un test): dispatch di tutti i 118 layer dell'unica App dentro una singola transazione — nessun errore, 0 job falliti, 0 layer rimasti con `shape=discontinuous`, 12 layer risultati realmente discontinui (`shape_discontinuous=true`) e confermati via API pubblica (`GET /layer/{id}`): solo `shape=linear` esposto, come da requisito.

## Follow-up

- Nessuna verifica automatica esiste oggi per confermare, dopo il deploy e il ricalcolo dei 118 layer (Task 7), che *zero* layer restino con `shape=discontinuous` — la query SQL di verifica è documentata nel piano come step manuale, non automatizzata in un comando/test.
- `RecalculateAppLayerAttributesAction` dispatcha un job di ricalcolo per layer più un solo `UpdateAppConfigJob`, senza attendere il completamento di tutti i job (race condition preesistente, non introdotta da questo ticket, già segnalata nei Rischi dell'overview). Non corretta in questo ciclo.
- Il pattern "blacklist invece di whitelist" di `config_section_map()` resta un debito noto (segnalato in challenge): ogni futura chiave interna richiederà comunque una chiamata esplicita a `withoutInternalConfigKeys()` nel punto giusto — il meccanismo generalizzato in questo ciclo riduce il costo di aggiungerne una nuova (basta popolare la config), ma non elimina la necessità di applicarlo nei punti di serializzazione.
- **Dalla review formale, risolto dopo la review:** `shape_discontinuous` era inizialmente dichiarata "interna" via `.env`/`.env.testing`/`.env-example` (`WM_INTERNAL_ATTRIBUTE_KEYS`) — il dev ha fatto notare che l'`.env` di produzione non è versionato e nessun test lo copre (un typo lì non verrebbe intercettato). Sostituito con un `config/wm-package.php` locale (nuovo, versionato in questo repo): `wm-package` carica il proprio config via `mergeConfigFrom()` (Spatie Laravel Package Tools), quindi il file del consumer vince sulla stessa chiave senza bisogno di ridichiarare l'intero array. La env var `WM_INTERNAL_ATTRIBUTE_KEYS` resta supportata a livello di meccanismo in `wm-package/config/wm-package.php` per chi la preferisce (altri consumer), ma camminiditalia non la usa più — rimossa da `.env`/`.env.testing`/`.env-example`. Resta comunque vero che `shape_discontinuous` è una stringa duplicata senza costante condivisa tra `LayerAttributesService.php`/`Layer.php` (che la scrivono/leggono) e `config/wm-package.php` (che la dichiara interna) — un typo qui verrebbe però intercettato dal test end-to-end (`LayerConfigJsonShapeDiscontinuousTest`), perché il file è versionato e viaggia nello stesso commit.

Rimossa anche la `env('WM_INTERNAL_ATTRIBUTE_KEYS', '')` dal default di `wm-package/config/wm-package.php` (il dev ha notato che restava lì senza più nessun consumer che la popolasse, dopo il passaggio al file di config): il default è ora un semplice `[]` letterale, documentato con l'indicazione che l'override si fa con un `config/wm-package.php` nel consumer.

### UX del pannello Nova: iterata due volte dopo il feedback visivo del dev

Prima iterazione (sbagliata): la card "Route shape" mostrava il valore "Discontinuous" al posto del vero valore pubblico (Linear), sovrascrivendo l'informazione. Provato a correggere con una **seconda card separata** ("Route continuity", badge warn) — anche questo respinto dal dev dopo aver visto lo screenshot reale: non voleva due card, ne voleva una sola.

Design finale (approvato): un'unica card "Route shape", **sempre verde/ok**, che mostra il valore pubblico reale (Linear/Roundtrip — anche per i dati storici con `shape=discontinuous` non ancora ricalcolati, mappato a "Linear" per la sola visualizzazione). Quando il layer è discontinuo, **dentro la stessa card** compare un piccolo box giallo (icona di alert + spiegazione), senza cambiare lo stato/colore della card né il suo titolo:
- `renderShapeCard(mixed $type, bool $isDiscontinuous)`: firma tornata con il parametro booleano, ma lo stato resta sempre `'ok'` — il box giallo è HTML embedded dentro `$valueHtml`, non un secondo `renderCard()`.
- Rimossa `renderDiscontinuityAlertCard()` e la traduzione `"Route continuity"` (introdotte nel tentativo intermedio, mai arrivate a un commit).
- I due test sullo scenario discontinuo aggiornati di conseguenza: asseriscono "Linear" + il testo della spiegazione, e verificano esplicitamente che `<strong>Discontinuous</strong>` NON compaia (quel valore va sempre mostrato come "Linear", l'alert è solo un box informativo accanto).
- **Dalla review formale:** se il meccanismo `internal_attribute_keys`/`withoutInternalConfigKeys()` verrà davvero riusato per una seconda chiave/contesto (il docblock lo dichiara esplicitamente generico), andrebbe rivisto un namespacing (es. `layer.shape_discontinuous` invece di `shape_discontinuous` nudo) prima di aggiungerne una seconda — oggi la lista è flat e globale, senza legame esplicito col contesto (Layer vs un futuro altro modello).
- **Dalla review formale:** piccola duplicazione non bloccante lasciata così per non allargare lo scope di un Bug fix: il confronto `$shape === RouteShape::DISCONTINUOUS` compare due volte in 6 righe di `computeCalculatedValues()`; il pattern `isset($x) && is_array($x) ? withoutInternalConfigKeys($x) : ...` è ripetuto identicamente nei due call site wm-package (`AppConfigService`, `AppController`).

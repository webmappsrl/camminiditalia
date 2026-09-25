# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Comandi comuni

Tutti i comandi `php artisan` vanno eseguiti dentro il container Docker:

```bash
docker exec laravel-camminiditalia php artisan <comando>
docker exec laravel-camminiditalia php artisan migrate
docker exec laravel-camminiditalia php artisan tinker
```

Avviare l'ambiente di sviluppo (dentro il container):
```bash
composer run dev   # serve + horizon + vite + pail in concurrently
```

Eseguire i test:
```bash
docker exec laravel-camminiditalia php artisan test
docker exec laravel-camminiditalia php artisan test --filter=NomeTest
docker exec laravel-camminiditalia php artisan test tests/Feature/LayerPolicyTest.php
```

I test girano su un database PostgreSQL separato (`camminiditalia_testing`), non su quello di sviluppo — vedi `.env.testing` e `phpunit.xml`.

### Setup DB di test (una tantum, dopo primo clone o reset container)
```bash
docker exec laravel-camminiditalia php artisan tinker --execute="\DB::statement('CREATE DATABASE camminiditalia_testing TEMPLATE template_postgis');"
docker exec laravel-camminiditalia php artisan migrate --env=testing
```

### Reset del DB di test (se corrotto durante lo sviluppo)
```bash
docker exec laravel-camminiditalia php artisan migrate:fresh --env=testing
```

Formattare il codice:
```bash
docker exec laravel-camminiditalia composer format   # esegue Laravel Pint
```

## Architettura

### Submodule wm-package — riferimento

Vedi `wm-package/CLAUDE.md` per:
- Trappola `HasPackageFactory` nelle classi figlio (sovrascrivere `newFactory()`)
- Convenzioni documentazione feature del package (`docs/resources/`)

### Submodule wm-package

Il progetto usa `wm-package` come submodule Git (montato anche come volume Docker in `../wm-package`). Contiene:
- Modelli base: `UgcPoi`, `UgcTrack`, `Layer`, `App`, `User`, `EcPoi`, `EcTrack`
- Risorse Nova astratte: `AbstractUgcResource`, `AbstractEcResource`, ecc.
- Policy base, Filters, Actions, Observers riusabili

**Regola:** logica generica e riusabile → `wm-package`. Logica specifica di camminiditalia → repo principale sotto `app/`.

### Estensioni locali

Il repo principale estende il package con override specifici:

| File locale | Estende |
|---|---|
| `App\Models\User` | `Wm\WmPackage\Models\User` |
| `App\Models\EcPoi` | `Wm\WmPackage\Models\EcPoi` |
| `App\Nova\UgcPoi` | `Wm\WmPackage\Nova\UgcPoi` |
| `App\Nova\UgcTrack` | `Wm\WmPackage\Nova\UgcTrack` |
| `App\Nova\Layer` | `Wm\WmPackage\Nova\Layer` |
| `App\Observers\UgcObserver` | `Wm\WmPackage\Observers\UgcObserver` |

Esiste `App\Models\UgcPoi` che estende il modello base del package (aggiunge `read_at`). Le policy vengono registrate in `AppServiceProvider` via `Gate::policy()`.

### Policy e registrazione

Le policy si registrano in `app/Providers/AppServiceProvider.php`. Per sovrascrivere una policy del package senza modificarlo, creare `App\Policies\NomePolicy` e registrarla con `Gate::policy(WmModel::class, AppPolicy::class)`.

### Ruoli e permessi (Spatie)

| Ruolo | Descrizione |
|---|---|
| `Administrator` | Accesso completo al pannello |
| `Validator` | Gestore di un cammino — vede solo le proprie segnalazioni |
| `Guest` | Accesso limitato |

**Il "gestore di cammino" corrisponde al ruolo `Validator`.**
Non usare ruoli del package come `Editor` — non esistono in questo progetto.

Permessi disponibili: `validate source surveys`, `validate pois`, `validate tracks`, `manage roles and permissions`.

### UGC e form

I form UGC sono discriminati da `properties->form->id`:
- `"report"` → segnalazione (visibile ai Validator)
- `"poi"` → punto di interesse (visibile solo agli Administrator)

Il campo `layer_id` viene salvato in `properties` JSON (no FK dedicata): `properties->>'layer_id'`.

La relazione user → layer è `$user->layers()` (`HasMany` via `user_id` su tabella `layers`) — specifica di camminiditalia.

### Observer e notifiche

`App\Observers\UgcObserver` estende quello del package e, al `created`, legge `properties['layer_id']`, trova il Layer e dispatcha `SendUgcReportMailJob` per notificare via email i gestori del layer.

### Routing Nova custom

`NovaServiceProvider` sovrascrive la route `layer-features/{layerId}` del package con `LayerFeatureController` locale, per filtrare le tracce per utente loggato senza toccare il package.

## Feature disponibili

| Feature | Ticket | Moduli toccati | Note |
|---|---|---|---|
| BulkEditAction su EcPoi (global) | oc:8133 | `app/Nova/EcPoi.php` | `BulkEditAction(\App\Nova\EcPoi::class, ['global'])` registrata in `EcPoi::actions()` con canSee+canRun per Administrator; logica bulk in wm-package |
| Home tab layer sorting | oc:7644 | `App\Nova\Layer`, `resources/js/nova/config-home-sorter.js` | Sorting layer nella home tab via Nova |
| UGC email notifications | oc:7641 | `App\Observers\UgcObserver`, `App\Jobs\SendUgcReportMailJob` | Email al gestore del layer alla creazione di un UGC report |
| UGC filtro layer e read/unread | oc:7640 | `App\Nova\UgcPoi`, `App\Models\UgcPoi`, `App\Policies\UgcPoiPolicy`, `App\Nova\Actions\MarkAsRead`, `App\Nova\Actions\MarkAsUnread` | Validator vede solo segnalazioni dei propri layer; badge e action bulk letto/non letto |
| Trasferimento ownership EcTrack al layer owner | oc:8080 | `App\Observers\LayerObserver`, `App\Observers\LayerableObserver`, `config/camminiditalia.php`, `App\Nova\Layer` | Al cambio owner del layer, bulk UPDATE user_id su EcTrack e EcPoi associate; hook su Layerable::created per nuove associazioni |
| EcPoi: sola lettura per Validator | oc:8120 | `App\Policies\EcPoiPolicy`, `App\Providers\AppServiceProvider`, `App\Nova\EcPoi` | ⚠️ Titolo superato da oc:8304/oc:8611: il Validator può ora anche modificare ed eliminare i propri EcPoi (ownership-based). Guest resta bloccato in Nova, invariato. Vedi [docs/knowledge/autorizzazione-ecpoi-validator.md](docs/knowledge/autorizzazione-ecpoi-validator.md) |
| Fix UI layer owner: action e link occhio tracce | oc:8089 | `App\Nova\Layer`, `tests/Feature/LayerActionsVisibilityTest.php`, `wm-package/.../LayerFeatures.php`, `wm-package/.../useGrid.ts` | canSee+canRun su AddLayersToConfigHomeAction (solo Administrator); novaPath via withMeta per link icona occhio corretto |
| Associazione automatica EcPoi al layer della traccia | oc:8139 | `wm-package/.../EcPoiEcTrackObserver.php`, `wm-package/.../Layer.php`, `wm-package/.../EcPoi.php`, `wm-package/.../Nova/Layer.php`, `App\Observers\LayerableObserver`, `App\Observers\LayerObserver`, `App\Console\Commands\SyncLayerEcPois` | EcPoi sincronizzati automaticamente ai layer della traccia; command di migrazione dati storici; panel EcPoi in Nova Layer |
| Fix properties.layers EcPoi corrotto per layer senza taxonomy_where | oc:8140 | `wm-package/src/Services/Models/LayerService.php`, `App\Console\Commands\FixEcPoiLayersProperty`, `tests/Feature/LayerServiceUpdateLayersPropertyGuardTest.php` | Guard in `updateLayersPropertyOnLayeredFeature`: salta add e pulisce stale IDs quando layer non ha manuali né filtri tassonomici; command di riallineamento dati storici |
| Colonna layer linkabile e filtro layer su UgcPoi/UgcTrack | oc:8276 | `app/Nova/Traits/HasLayerFilterAndLink.php`, `app/Nova/UgcPoi.php`, `app/Nova/UgcTrack.php` | Field "layer" (link verso il layer, in nuova scheda) e filtro Select per layer, solo Administrator; trait condiviso tra UgcPoi e UgcTrack |
| Fix drift phpstan-baseline.neon | oc:8312 | `phpstan-baseline.neon`, `app/Nova/Layer.php`, `app/Nova/Traits/HasLayerFilterAndLink.php`, `app/Policies/TaxonomyPoiTypePolicy.php`, `tests/Feature/AppHomeLayerSortButtonTest.php`, `tests/Feature/LayerOwnershipTransferTest.php` | Baseline rigenerato allineato a PHPStan 2.1.38/Larastan 3.9.2; 18 fix reali (docblock orfano, firma closure Nova, return espliciti in policy, asserzioni/chiamate test obsolete), 31 entry baseline per falsi positivi migration + gap tipizzazione wm-package |
| Toggle QR code deep link + well-known registry | oc:8251 | Quasi interamente `wm-package` (vedi `wm-package/CLAUDE.md`); in questo repo solo `.env` (credenziali SFTP) | Toggle per app + QR code/link deep-link mostrato direttamente su Track/Poi (Nova Field); sync automatico file well-known condiviso via SFTP |
| Dashboard statistiche aggregate per Cammini d'Italia | oc:8182 | `app/Nova/Layer.php`, `tests/Feature/LayerGlobalAnalyticsCardVisibilityTest.php`; grosso della logica in `wm-package` (vedi `wm-package/CLAUDE.md`) | `App\Nova\Layer::cards()` registra `LayerAnalyticsCard::global()` su index Layer, solo Administrator, solo se analytics abilitato per l'App; detail view invariata (`parent::cards()`) |
| Box informativi — registrazione EcTrackPolicy | oc:8181 | `app/Providers/AppServiceProvider.php`, `tests/Feature/EcTrackPolicyTest.php`, submodule `wm-package` | Fix bloccante review: `Gate::policy(EcTrack::class, EcTrackPolicy::class)` ownership-based (commit `4fe834e`); grosso builder Nova in wm-package — vedi `wm-package/docs/features/8181-box-informativi-cammino/` |
| Database PostgreSQL separato per i test PHPUnit | oc:8092 | `.env.testing`, `phpunit.xml`, `.github/workflows/run-tests.yml`, `CLAUDE.md` | I test girano su `camminiditalia_testing` (clonato da `template_postgis`), non più sul DB di sviluppo condiviso; `RefreshDatabase` non svuota più i dati locali |
| Modalità auto/manuale layer persistita + blocco auto per owner Administrator | oc:8314 | `app/Http/Controllers/LayerFeatureController.php`, `tests/Feature/LayerFeatureControllerTest.php`, `.env`, `.env.testing` | `track_mode`/`poi_mode` ora persistiti su `sync()`; `auto:true` rifiutato (422) per layer con owner Administrator; default modalità camminiditalia = `manual` (`DEFAULT_LAYER_MODE`) |
| Analytics shard name per query PostHog | oc:8464 | `.env-example`; grosso della logica in `wm-package` (vedi `wm-package/CLAUDE.md`) | Documentazione `.env-example` per `SHARD_NAME`/`ANALYTICS_SHARD_NAME` (righe commentate, nessun valore attivo); il fix applicativo (nuova chiave `analytics_shard_name`, fallback in `AnalyticsService::shardNameClause()`) vive interamente nel submodule |
| Override manuale in Nova | oc:8575 | `app/Nova/Traits/HasLayerOverride.php`, `app/Nova/Traits/HasLayerFilterAndLink.php`, `app/Support/UgcLayerAssignment.php`, `app/Jobs/ResolveUgcLayerJob.php`, `app/Policies/UgcTrackPolicy.php`, `app/Policies/UgcPoiPolicy.php` | Vedi [docs/knowledge/risoluzione-layer-ugc.md](docs/knowledge/risoluzione-layer-ugc.md) |
| RouteShape: esporre Discontinuo come Lineare al frontend, tenere l'alert solo in Nova | oc:8463 | `app/Services/LayerAttributesService.php`, `app/Nova/Layer.php`, `app/Jobs/RecalculateLayerAttributesJob.php`, `config/wm-package.php`, `resources/lang/{it,en}.json`; parte in `wm-package` (vedi `wm-package/CLAUDE.md`) | Vedi [docs/knowledge/route-shape-layer.md](docs/knowledge/route-shape-layer.md) |
| Autorizzazioni "Tipi POI" | oc:8596 | `app/Policies/TaxonomyPoiTypePolicy.php`, `resources/lang/{it,en}.json`, `tests/Feature/TaxonomyPoiTypePolicyTest.php` | Vedi [docs/knowledge/autorizzazione-tipi-poi.md](docs/knowledge/autorizzazione-tipi-poi.md) |
| Fix Validator non vede più EcTrack/EcPoi dopo trasferimento ownership layer | oc:8587 | `app/Policies/EcTrackPolicy.php`, `app/Providers/AppServiceProvider.php`, `app/Nova/EcTrack.php`, `app/Nova/EcPoi.php`, `tests/Feature/{EcTrackPolicy,EcTrackIndexQuery,EcPoiIndexQuery,UgcPoiIndexQuery}Test.php` | Doppia regressione dallo stesso commit oc:8162 (wm-package): Policy Gate per-app invece di per-record, e `indexQuery()` Nova per-app (sempre vuota, camminiditalia ha una sola App); vedi `Decisioni architetturali` |
| Fix associazione EcTrack↔EcPoi in Nova per Validator | oc:8611 | `app/Nova/EcPoi.php`, `app/Nova/EcTrack.php` | Campi di attach "EcTracks"/"EcPois" ripuntati alla risorsa Nova locale (stesso bug di oc:8587, ma sul campo `fields()` invece che sulla index list della risorsa); campo "Layers" su EcTrack nascosto per il Validator. Vedi [docs/knowledge/scoping-validator-risorse-nova.md](docs/knowledge/scoping-validator-risorse-nova.md) |
| Sblocco eliminazione EcPoi per il Validator | oc:8611 | `app/Policies/EcPoiPolicy.php`, `app/Nova/EcPoi.php`, `tests/Feature/EcPoiPolicyTest.php`, `tests/Feature/EcPoiNovaAuthorizationTest.php` | Secondo scope dello stesso ticket, richiesto dal cliente nello stesso thread dopo il fix precedente: `EcPoiPolicy::delete()`/`authorizedToDelete()` diventano ownership-based, come già `update()`. Vedi [docs/knowledge/autorizzazione-ecpoi-validator.md](docs/knowledge/autorizzazione-ecpoi-validator.md) |
| Amministratori possono assegnare Validator/Guest in Nova | oc:8623 | `app/Nova/User.php`, `tests/Feature/UserNovaRoleManagementTest.php` | Override locale del campo Roles ereditato da wm-package (`App\Nova\User::fields()`): un Administrator non super-admin può assegnare solo Validator/Guest, mai Administrator — nessuna modifica al submodule wm-package. Vedi [docs/knowledge/gestione-ruoli-nova-user.md](docs/knowledge/gestione-ruoli-nova-user.md) |
| Località nel dettaglio tappa: solo la regione | oc:8588 | `app/Providers/NovaServiceProvider.php`, `tests/Feature/TaxonomyWhereMenuVisibilityTest.php`; grosso della logica in `wm-package` | Vedi [docs/knowledge/localita-nel-dettaglio-tappa.md](docs/knowledge/localita-nel-dettaglio-tappa.md) |

## Decisioni architetturali

### Analytics shard name per query PostHog (oc:8464)
- Fix applicativo interamente in `wm-package` (vedi `wm-package/CLAUDE.md`) — questo repo riceve solo documentazione `.env-example` (righe commentate `SHARD_NAME`/`ANALYTICS_SHARD_NAME`, nessun valore impostato in nessun ambiente) e il futuro bump del gitlink del submodule
- Fix confinato al branch `RDO_ass_cammini_italia_2026_2` (come oc:8354), nessun backport su `develop`/`main` di wm-package
- Nessuna modifica a `SHARD_NAME`/al suo fallback su `APP_NAME` — scope ridotto deliberatamente in fase di reverse-interaction: solo la nuova variabile dedicata alle query PostHog

### Box informativi + EcTrackPolicy (oc:8181)
- Pattern identico a `EcPoiPolicy` (oc:8120): policy del package registrata in `AppServiceProvider` con `Gate::policy(EcTrack::class, EcTrackPolicy::class)`.
- Validator: `update`/`delete`/`view` solo sulle proprie EcTrack (`user_id`); Layer resta Administrator-only (`LayerPolicy::update()` blocca tutti i Validator).
- Test: `EcTrackPolicyTest.php` (mirror di `EcPoiPolicyTest.php`) + regressione `EcPoiPolicyTest.php`; integrazione Nova in `wm-package/tests/Feature/Nova/ConfigDetailAuthorizationInheritanceTest.php`.
- Frontend `config_detail` (consumo wm-core) fuori scope in questo repo — solo fix autorizzazione consumer emerso in review backend.

### Modalità auto/manuale del layer persistita lato backend (oc:8314)
- persistMode() nel controller locale eredita dal package (protected), nessuna
  duplicazione della logica di persistenza; il ramo auto/manuale locale
  (whitelist modelli, filtro user_id=layerOwnerId) resta specifico di
  camminiditalia e NON delega a parent::sync() (scelta di oc:8311, invariata)
- Layer con owner (risolto: user_id ?? default_owner_id) di ruolo Administrator:
  auto:true viene rifiutato con 422 esplicito nel controller locale (non nel
  package: Wm\WmPackage\Models\Layer non ha un override locale utilizzabile, 22
  punti nel package lo referenziano direttamente). Copertura parziale: blocca
  solo il salvataggio via questo endpoint, non il valore mostrato in UI al
  primo caricamento per layer non ancora toccati
- Default della modalità (quando configuration non ha track_mode/poi_mode)
  cambiato da 'auto' a 'manual' per camminiditalia via nuova chiave config
  wm-package.default_layer_mode (env DEFAULT_LAYER_MODE) — il default 'auto'
  resta invariato per gli altri progetti Webmapp
- Bug scoperto ma non corretto in questo ciclo: 35 layer su 118 hanno tutte le
  tracce associate con user_id diverso dal proprietario del layer (verificato
  su due dump distinti, 28/07 e 23/08, stesso conteggio) — la vista Nova
  (edit/detail) le nasconde sempre (filtro where('user_id', $layerOwnerId)).
  Causa probabile: LayerObserver (oc:8080) trasferisce ownership solo al
  cambio di user_id del layer, non quando vengono aggiunte tracce con owner
  diverso al pivot in un secondo momento. Decisione: nessuna correzione bulk
  sui dati, il cliente verrà informato caso per caso e correggerà lui stesso
  da Nova

### Database PostgreSQL separato per i test PHPUnit (oc:8092)
- `.env.testing` è committato direttamente (non `.example`) ma **non è una copia integrale del `.env` locale**: `APP_KEY`/`JWT_SECRET`/`AWS_DUMPS_ACCESS_KEY_ID`/`AWS_DUMPS_SECRET_ACCESS_KEY` vanno sempre rigenerati/omessi (trovato in review: la prima stesura conteneva questi segreti reali copiati 1:1 dal `.env` personale, incluse credenziali AWS con accesso ai backup di produzione)
- La protezione CI in `run-tests.yml` (env var `DB_HOST`/`DB_DATABASE` esplicite sullo step "Laravel Tests") funziona perché `Illuminate\Support\Env::getRepository()` costruisce il repository con `->immutable()`: le env var reali di un GitHub Actions step non vengono mai cancellate né sovrascritte da `.env.testing`, anche se quest'ultimo viene caricato automaticamente per `APP_ENV=testing`. Verificato empiricamente (non solo per lettura del codice vendor) simulando le condizioni CI in locale — un'ipotesi di regressione basata solo sulla lettura di `Collision\TestCommand::clearEnv()` si è rivelata infondata dopo il test empirico
- **Fix Redis/qemu (non era una limitazione permanente)**: i fallimenti `ConnectionException` verso Redis (40 osservati in questo ciclo, 28 in oc:8312) erano causati da un'immagine `redis:latest` in variante `amd64` fatta girare via emulazione qemu su host `arm64` (Apple Silicon) — non da un problema architetturale del progetto. Fix: `docker pull --platform linux/arm64 redis:latest` + `docker compose up -d --force-recreate redis` (nessun volume persistente su Redis, nessun rischio dati). Risultato: suite `php artisan test` **145 passati, 0 falliti**. La nota equivalente in oc:8312 sotto (che descrive questi fallimenti come "problema di infrastruttura Docker locale pre-esistente" da "rivalutare") è superata da questo fix
- `template_postgis` (template Postgres con PostGIS preinstallato) è uno stato Docker locale non versionato — se il volume Postgres viene ricreato da zero, va rigenerato implicitamente dall'immagine `postgis/postgis` all'avvio, nessuno script del repo lo crea esplicitamente

### Fix drift phpstan-baseline.neon (oc:8312)
- Il baseline (generato 2025-02-12) era disallineato da PHPStan 2.1.38/Larastan 3.9.2 (versioni molto più recenti) — causa non un bump intenzionale ma drift silenzioso: `composer.lock` non è coperto dal check CI, quindi un bump di versione via dipendenze non fa fallire subito nulla, il drift si accumula finché il baseline non intercetta più gli errori nuovi
- 21 errori sulle migration (`ForeignKeyDefinition::onDelete()`, `IndexDefinition::comment()`, `Blueprint::float()` con parametro extra) sono falsi positivi confermati a runtime (metodi magici via `__call()` di `Fluent`, o argomento extra ignorato silenziosamente da PHP) — restano nel baseline, nessuna modifica alle migration
- 10 errori causati da relazioni Eloquent/attributi senza generics dichiarati in `wm-package` (`Layerable::layer()`, `Layer::layerOwner()/ecTracks()/ecPois()`, `EcTrack::ecPois()`, `GeometryModel`) — scope deciso esplicitamente solo su camminiditalia in questo ciclo, restano nel baseline con nota della causa reale; fix alla radice (annotazioni generiche) rimandato a un ticket separato lato wm-package
- `TaxonomyPoiTypePolicy::delete/restore/forceDelete` avevano corpo vuoto (return `null` implicito, incoerente con `bool`) — reso esplicito `return false` coerente col pattern già in `create()/update()`; nessun test dedicato esiste per questa policy (rischio accettato, non aggiunti nuovi test in questo ciclo)
- `AppHomeLayerSortButtonTest`: `assertNotFalse($configHomeIndex, ...)` sostituito con `assertNotNull(...)` — `fieldIndexByAttribute()` ritorna `?int`, mai `false`; l'asserzione originale era un residuo di un pattern basato su `array_search()` e sempre vera per costruzione
- Rigenerazione baseline va fatta **in singolo processo** (`vendor/bin/phpstan analyse --generate-baseline --debug`, no worker paralleli) — osservata race condition sulla cache Nette con i worker paralleli in locale
- Suite `php artisan test` in locale ha 28 test che falliscono con `ConnectionException` verso Redis (`redis-camminiditalia` va in segfault sotto emulazione qemu) — problema di infrastruttura Docker locale pre-esistente, non una regressione dai fix di questo ciclo; da rivalutare se persiste anche in CI

### Visibilità POI/tracce non proprie nel pannello manuale di Layer (oc:8311)
- La regola "ognuno vede/gestisce sempre e solo il proprio contenuto" (`user_id`) si applica a **tutti i ruoli senza eccezioni**, Administrator incluso — l'ownership su un layer riflette sempre il gestore attuale perché il trasferimento al cambio owner è già gestito da `LayerObserver` (oc:8080); l'Administrator può avere più EC solo perché è l'unico che può possedere più layer contemporaneamente (bootstrap iniziale)
- La modalità `auto` di `sync()` (`LayerFeatureController`) non usa più `assignTracksByTaxonomy`/`assignPoisByTaxonomy` del package (logica a tassonomia): per camminiditalia `auto=true` significa "assegna al layer tutti gli EC di cui sono proprietario", senza tassonomia né filtro `app_id` (il progetto ha una sola app)

### Colonna layer linkabile e filtro layer su UgcPoi/UgcTrack (oc:8276)
- Logica di risoluzione layer_id → nome/link e filtro Select estratta in `App\Nova\Traits\HasLayerFilterAndLink`, condiviso da `UgcPoi` e `UgcTrack` — evita la duplicazione del blocco Select introdotta da oc:7640 su UgcPoi soltanto
- `layer_id` da `properties` va sempre validato con `is_numeric()` prima dell'uso: il filtro Select preesistente (oc:7640) usa un cast SQL `::integer` senza validazione, fragile su dati corrotti — rischio noto, non modificato in questo ciclo (fuori scope), ma il nuovo trait adotta validazione PHP-side per non ripeterlo
- Cache statica in-request (`static::$layerNameCache`) per evitare N+1 query quando più righe della stessa pagina index condividono lo stesso layer_id
- Layer cancellato (`layer_id` valido ma `Layer::find()` nullo) mostra "Layer eliminato (ID: {id})", distinto da "Non assegnato" (layer_id assente/non valido) — nessun link generato in entrambi i casi
- Link con `target="_blank" rel="noopener noreferrer"` (mitigazione tabnabbing) e nome layer escapato con `htmlspecialchars()` (mitigazione XSS stored, dato che `Layer::getStringName()` può contenere input utente non sanitizzato)

### Dashboard statistiche aggregate per Cammini d'Italia (oc:8182)
- `App\Nova\Layer::cards()` (prima assente, ereditava `Wm\WmPackage\Nova\Layer::cards()` che ritorna `[]` su index) chiama sempre `parent::cards($request)` come base e ritorna subito se `$request->resourceId` è valorizzato (detail view intatta) — la card globale si aggiunge solo su index, dopo check Administrator + `analytics_app_enabled`/`analytics_webapp_enabled` (stesso gate della card per-layer, letto tramite `getLayerAppProperties()` reso `protected` in wm-package)
- Il grosso della feature (query PostHog, ranking, sezione ricerche) vive interamente in `wm-package` — vedi `wm-package/CLAUDE.md` e `wm-package/docs/features/8182-dashboard-statistiche-aggregate/notes.md` per il dettaglio completo
- **Bug non correlato trovato e fixato in questo branch**: `app/Models/User.php` includeva due volte il trait Nova `Impersonatable` (una volta già risolto ereditando da `Wm\WmPackage\Models\User`, una volta ridichiarato localmente) — fatal error su ogni richiesta a `/nova` (`Impersonatable::canImpersonate()` non tipizzato in conflitto col metodo tipizzato ereditato). Preesistente da oc:8231, non da oc:8182, emerso dopo un ripristino del DB durante questa sessione. Fix: rimossa la ridichiarazione ridondante del trait
- **Da sapere per il prossimo che tocca `phpunit.xml`**: non isola il DB di test da quello di sviluppo (`DB_CONNECTION=sqlite`/`:memory:` commentati) — `php artisan test` con `RefreshDatabase` gira `migrate:fresh` sul DB reale. Causa nota di uno svuotamento accidentale del DB durante questo ciclo. Non modificare questa configurazione senza chiedere esplicitamente conferma al dev (sqlite rompe le migration Postgres/PostGIS-specifiche; un DB Postgres di test dedicato è stato proposto e rifiutato)

### Fix properties.layers EcPoi (oc:8140)
- `updateLayersPropertyOnLayeredFeature` usa un flag `$noValidFilter` (no manual models AND no taxonomy_where AND no taxonomyActivities) invece di un early return — così il path di rimozione gira comunque e pulisce i layer ID storicamente corrotti (`$layerFeaturesIds = []` → `whereNotIn([])` seleziona tutti i POI con quell'ID → vengono rimossi)
- I test per questa logica stanno nel repo principale (`tests/Feature/LayerServiceUpdateLayersPropertyGuardTest.php`) e NON in `wm-package/tests/` — i test del wm-package non possono referenziare `Tests\TestCase` del repo principale, e `Wm\WmPackage\Tests\TestCase` non è in `autoload-dev` di camminiditalia
- Il `layerable_type` nel DB per EcPoi è `'App\Models\EcPoi'` (chiave del morph map), non `Wm\WmPackage\Models\EcPoi::class` — da usare nei test che inseriscono direttamente in `layerables`
- Ordine obbligatorio di esecuzione in produzione: (1) deploy fix wm-package, (2) backup DB, (3) `sync-layer-ec-pois`, (4) `fix-ec-poi-layers-property` — il terzo popola `layerables`, il quarto usa `layerables` come sorgente di verità per aggiornare `properties['layers']`
- `fix-ec-poi-layers-property` ha un self-check pre-deploy e flag `--force` per bypassarlo consapevolmente

### EcPoi: sola lettura per Validator (oc:8120)
- `authorizedToCreate` su una Nova Resource è metodo **statico** — `authorizedToUpdate` e `authorizedToDelete` sono di istanza
- Nova genera il `uriKey` delle action dal metodo `name()` (non dal nome della classe) — es. `ExecuteEcPoiDataChainAction` con `name()` "Execute EcPoi Data Chain" → `execute-ecpoi-data-chain` (non `execute-ec-poi-data-chain-action`)
- `EcPoi::factory()->create()` richiede `'properties' => []` nei test — altrimenti `AbstractObserver` del package fallisce con `TypeError` (tenta di accedere come array una stringa JSON)
- `DownloadEcPoiAction` non riceve `canSee`/`canRun` espliciti: il default Nova filtra già le action in base ad `authorizedToUpdate`, che blocca i Validator
- Policy del package sovrascritta con `Gate::policy(EcPoi::class, EcPoiPolicy::class)` in `AppServiceProvider` — pattern già usato per `UgcPoiPolicy`

### Fix UI layer owner (oc:8089)
- `canSee` senza `canRun` è protezione solo cosmetica: Nova con `canSee=false` già restituisce 404 sull'esecuzione via API (action non trovata in `availableActions()`), ma `canRun` aggiunge protezione esplicita a livello logico
- Nova con `canSee=false` restituisce 404 (non 403) quando si tenta di eseguire l'action via API — comportamento da considerare nei test (asserire `[403, 404]`)
- `uriKey` dell'action `AddLayersToConfigHomeAction` è `aggiungi-alla-home` (generato automaticamente dal nome italiano della classe PHP) — non `add-layers-to-config-home-action`
- `novaPath` iniettato via `withMeta` in `LayerFeatures.php` usando `'/'.trim(Nova::path(), '/')` — il trim previene doppi slash se `Nova::path()` restituisce stringa con slash finale
- Il rebuild del dist del campo Nova si fa con `npm run prod` (non `npm run build`) — configurazione Laravel Mix

### Trasferimento ownership EcTrack al layer owner (oc:8080)
- Due observer locali custom (non in wm-package): `LayerObserver` (cambio owner del layer) e `LayerableObserver` (nuova risorsa associata a layer con owner)
- `LayerObserver::saved()` usa `wasRecentlyCreated || wasChanged('user_id')` — `wasChanged()` ritorna false su record appena creati, quindi la condizione deve coprire entrambi i casi
- Fallback owner configurabile via `CAMMINIDITALIA_DEFAULT_OWNER_ID` in `.env` (default 2) — specifico di camminiditalia, non in wm-package config
- Bulk UPDATE via query builder (`->update()`) — non triggera observer Eloquent sulle singole tracce, comportamento intenzionale
- `layerable_type` in DB è `App\Models\EcTrack` (config `ec_track_model` già impostato) — il confronto nel `LayerableObserver` è corretto per questo progetto
- `EcTrack` e `EcPoi` locali ora hanno `newFactory()` che punta alle factory del package — necessario per i test (trappola `HasPackageFactory`)

### UGC filtro layer e read/unread (oc:7640)
- `App\Nova\UgcPoi` deve dichiarare `public static $model = App\Models\UgcPoi::class` — senza questo override Nova usa il modello del package e le modifiche a campi non in `$fillable` del package vengono silenziosamente ignorate
- Le Nova Action che devono essere usabili dai Validator richiedono `$this->canRun(fn($request, $model) => true)` nel costruttore — Nova 5 chiama `filterByResourceAuthorization` (policy `update`) quando `runCallback` non è impostato
- `UgcPoiPolicy::before()` gestisce esplicitamente tutti i ruoli: Administrator → true, non-Validator → false, Validator → null (passa ai metodi specifici)
- Filtri Nova con ricerca: usare `Select::make()->searchable()->filterable()` nei `fields()` invece di classi Filter custom — è il pattern nativo Nova, produce un select con ricerca senza Vue custom. Nascondere con `->hideFromIndex()->hideFromDetail()->hideWhenCreating()->hideWhenUpdating()`.
- `$layer->name` restituisce stringa vuota per via del cast — usare sempre `$layer->getStringName()` per ottenere il nome leggibile

### UGC email notifications (oc:7641)
- L'observer locale estende quello del package invece di modificarlo — mantiene la compatibilità con gli aggiornamenti di wm-package
- `layer_id` letto da `properties` JSON, non da FK — coerente con la scelta architetturale del progetto

### Associazione automatica EcPoi al layer della traccia (oc:8139)
- `EcPoiEcTrackObserver` (in wm-package) gestisce `created`/`deleted` sul pivot: su `created` chiama `syncWithoutDetaching` per associare il POI ai layer della traccia; su `deleted` rimuove il POI dal layer solo se nessun'altra traccia di quel layer ha ancora quel POI
- `LayerableObserver::deleted` (repo principale) rimuove dal layer i POI orfani quando una traccia viene dissociata dal layer — stessa logica di controllo cross-pivot
- `manualEcPois()` rinominato in `ecPois()` su `Layer` (wm-package); `manualEcPois()` resta come alias `@deprecated` per BC
- `EcPoi::getLayerRelationName()` restituisce `'ecPois'` — necessario per `LayerFeatures` Nova field che è model-agnostic
- `MorphPivot` non ha `withoutObservers()` — per bypassare l'observer nel command di migrazione usare `DB::table('layerables')->insert()` diretto con check di esistenza preventivo (no unique constraint sulla tabella)
- Il command `camminiditalia:sync-layer-ec-pois` è idempotente: calcola `array_diff` tra POI già presenti e nuovi, inserisce solo i mancanti; fa bulk UPDATE `user_id` alla fine per layer
- Panel "Ec Pois" in Nova Layer aggiunto via `LayerFeatures::make()` passando il modello EcPoi — stesso campo usato per le tracce, agnostico al modello
- Ownership last-write-wins per POI condivisi tra layer con owner diversi: comportamento accettato per design, coerente con il pattern già usato per le EcTrack in oc:8080 (`LayerObserver::saved` aggiorna tutti i POI del layer indipendentemente da altre appartenenze)
- La logica "POI ancora linkato al layer tramite altra traccia?" è centralizzata in `EcPoiEcTrack::poiStillLinkedToLayerViaOtherTrack()` — usata da `EcPoiEcTrackObserver` (detach da traccia) e `LayerableObserver::deleted` (detach da layer)

### Fix Validator non vede più EcTrack/EcPoi dopo trasferimento ownership layer (oc:8587)
- `oc:8162` (wm-package, commit `23c4b5e8`) ha introdotto un ruolo `Editor` con scoping multi-app, cambiando **due** meccanismi condivisi da EcTrack/EcPoi: `EcTrackPolicy::view()/update()/delete()` (per-record → `ownsApp()`) e `AbstractEcResource::indexQuery()` (nessuno scoping → filtro per `app_id` posseduto). Nessuno dei due riflette il modello camminiditalia (Validator possiede layer/EC via `user_id`, mai un'App — c'è una sola App, di proprietà dell'Administrator)
- La sola registrazione già esistente da oc:8181 (`Gate::policy(EcTrack::class, EcTrackPolicy::class)` puntata al package) non bastava più dopo oc:8162: serviva un **override locale** della classe stessa, non solo la registrazione — pattern identico a `EcPoiPolicy` (oc:8120). `App\Policies\EcTrackPolicy::before()` ritorna `null` (non `false`) per ogni non-Administrator, a differenza di `EcPoiPolicy` (`before(): !Validator → false`): scelta esplicita per preservare `viewAny()=true` anche per Guest (bloccato solo dalla route Nova), comportamento già testato in precedenza
- Bug distinto ma stessa causa, trovato in fase di challenge (non nella diagnosi iniziale): `AbstractEcResource::indexQuery()` scopa per `app_id`, quindi la lista Nova principale "Ec Tracks"/"Ec Poi" è **sempre vuota** per ogni Validator, indipendentemente dal fix sulla Policy — verificato con un login reale come Validator proprietario di 7 EcTrack (`user_id`), la query Nova restituiva 0 righe. Fix: `indexQuery()` locale su `App\Nova\EcTrack`/`App\Nova\EcPoi` che scopa per `user_id`, senza toccare `AbstractEcResource` (logica generica riusabile da altri consumer del package)
- Questo fix è condizione necessaria perché oc:8120 (EcPoi sola lettura per Validator) sia davvero visibile in Nova: la Policy permetteva già la view, ma la lista che la precede era vuota
- Verificato in reverse-interaction che lo stesso commit oc:8162 **non** ha rotto `UgcPoiPolicy`/`UgcTrackPolicy` per il Validator (`bypassRoles()` include già `'Validator'`, accesso Gate pieno) né `LayerPolicy` (override locale già su `user_id`, non `ownsApp()`); lo scoping reale degli UgcPoi "report" per un Validator resta quello per layer già esistente (`app/Nova/UgcPoi.php::filteredQueryForValidator`), verificato sui dati reali (10 UgcPoi "report" in produzione, nessun mismatch su 13 Validator con layer) e ora protetto da un test di regressione più ampio (`test_validator_with_multiple_layers_sees_reports_from_all_owned_layers_only`)
- `MediaPolicy`/`TaxonomyThemePolicy`/`TaxonomyWherePolicy` (package) sono state toccate dallo stesso commit ma non hanno override locale in camminiditalia; nessuna evidenza di impatto negativo trovata, non verificate a fondo in questo ciclo (rischio residuo basso, accettato)
- 2 UgcPoi "report" su 10 in produzione non hanno alcun `layer_id` (invisibili a qualsiasi Validator, solo l'Administrator li vede) — dato pre-esistente, non introdotto da questo fix, accettato dal dev senza azione
- Rischio cross-progetto (altri consumer di wm-package con la stessa regressione silenziosa su `EcTrackPolicy`/`AbstractEcResource::indexQuery()`) segnalato ma non affrontato — un solo repo per ciclo

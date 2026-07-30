> Ticket: oc:8312

# Notes — Aggiornare phpstan-baseline.neon e risolvere drift PHPStan preesistente

## Deviazioni dal piano

Nessuna deviazione nell'implementazione: tutti i task 1-6 del piano sono stati applicati come descritto (verificato via `git diff --stat` sugli 8 file previsti).

## Bug trovati

Nessuno introdotto da questo ciclo.

## Decisioni

- **Task 7 (verifica finale) — criterio "test passa integralmente" non pienamente verificabile in locale**: `vendor/bin/phpstan analyse` conclude con **0 errori** (criterio soddisfatto). `php artisan test` riporta invece 100 passed / 28 failed, ma tutti i 28 fallimenti sono lo stesso `Predis\Connection\ConnectionException: Connection refused [tcp://redis:6379]`, causato dal container Docker locale `redis-camminiditalia` che va in segfault all'avvio (`qemu: uncaught target signal 11`) — problema di emulazione architettura sull'ambiente locale, riprodotto anche dopo `docker restart redis-camminiditalia`, non risolto da questo ciclo (fuori scope: il ticket riguarda solo il drift PHPStan).
  - I test falliti (incluso `LayerOwnershipTransferTest`, uno dei file toccati dal Task 5) falliscono tutti dentro `EcPoiObserver::saved()` → `BuildAppPoisGeojsonJob::dispatch()` (coda Redis), non nell'asserzione relativa a `ecPois()`/`manualEcPois()` che è oggetto del fix.
  - Le 7 asserzioni non-Redis di `LayerOwnershipTransferTest` passano tutte, incluse quelle che esercitano il fix `ecPois()`.
  - Decisione: procedere al review-gate e ai commit senza bloccare su questo criterio, documentando qui la causa (infra locale, non regressione dai fix del ciclo). Da rivalutare se il problema Redis persiste anche in CI (che userebbe un ambiente diverso, non soggetto a emulazione qemu).

## Follow-up

- Investigare perché `redis-camminiditalia` crasha con segfault sotto qemu in locale (probabile immagine Docker non nativa per l'architettura host) — non bloccante per questo ticket, ma impedisce di verificare in locale la piena assenza di regressioni sui test che dipendono da job in coda.

## Aggiornamento post-review (2026-07-30)

Durante la review formale (`wm-skills:wm-review-ticket`) è emerso che il check CI `phpstan` sulla PR #55 falliva (`FAILURE`), a differenza di quanto riportato inizialmente in questo file (`vendor/bin/phpstan analyse` → 0 errori era vero solo in locale, non in CI):

- **Causa reale (3 errori nuovi, non del bump `composer.lock`)**: verificato rieseguendo `vendor/bin/phpstan analyse` su `main` — gli stessi 3 errori (`app/Http/Controllers/LayerFeatureController.php:62,192` — `method_exists($model, 'getLayerRelationName')` sempre vero; `tests/Feature/LayerFeatureControllerTest.php:241` — `Mockery::twice()` non riconosciuto dagli stub PHPStan-Mockery) esistono **già su `main`**, introdotti da oc:8311/oc:8139, mai catturati dall'analisi originale di questo ticket (probabilmente eseguita prima che oc:8311 fosse mergiato). Non è un bug introdotto da questa PR.
- **`composer.lock` — bump non voluto ma innocuo**: il commit di rigenerazione baseline (`53e6029`) aveva anche cambiato il riferimento path-repository `wm/wm-package` da `dev-oc_561`/`cc79ae3` a `dev-main`/`2e347a8`. Verificato: per un repository Composer di tipo `"path"` questo campo è solo un auto-detect del commit checkato-out localmente al momento di `composer install/update`, non pinna codice realmente eseguito (Composer legge sempre la cartella `wm-package/` così com'è) — non è la causa dei 3 errori CI. Ripristinato comunque al valore originale (identico a quello attualmente su `develop`/`main`) per eliminare rumore non necessario nel diff.
- **Fix applicato**: rigenerato `phpstan-baseline.neon` in singolo processo includendo i 3 errori pre-esistenti sopra descritti (stesso trattamento "falso positivo confermato/gap stub" già usato per le altre 31 entry). Verificato: `vendor/bin/phpstan analyse` → **0 errori**.
- **`php artisan test`**: falliscono 5 test (`EcPoiLayerAssignmentTest`, `EcPoiNovaAuthorizationTest`, `EcPoiPolicyTest`, `LayerFeatureControllerTest` x2) con `ConnectionException` verso host esterni (`osmfeatures.maphub.it`, DEM API) non mockati in questi test — riprodotto identico anche su `main` senza alcuna modifica di questo ciclo: problema di rete del container Docker locale verso servizi esterni, non una regressione. Analogo, per categoria, al problema Redis/qemu già documentato sopra.

## Follow-up (aggiornato)

- Investigare perché `redis-camminiditalia` crasha con segfault sotto qemu in locale (probabile immagine Docker non nativa per l'architettura host) — non bloccante per questo ticket.
- Investigare instabilità di rete del container `laravel-camminiditalia` verso host esterni (`osmfeatures.maphub.it`, DEM API) durante `php artisan test` — pre-esistente su `main`, non bloccante per questo ticket, ma impedisce la verifica locale piena di `EcPoiLayerAssignmentTest`/`EcPoiNovaAuthorizationTest`/`EcPoiPolicyTest`/`LayerFeatureControllerTest`.
- Valutare in un ciclo futuro se rilanciare l'analisi PHPStan periodicamente dopo ogni merge in `develop` (non solo su `composer.lock`), per evitare che nuovi drift come quello di oc:8311/oc:8139 restino invisibili fino alla prossima rigenerazione baseline.

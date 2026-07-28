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

> Ticket: oc:8761

# Notes — date di percorrenza nella richiesta di certificazione (backend)

## Deviazioni dal piano

- **Task 1, passi 1 e 5 (rollback e `migrate` sul DB di sviluppo)**: non eseguiti durante
  l'implementazione, perché cancellano dati; fatti alla fine, su richiesta del dev (vedi
  Decisioni).
- **Test**: oltre ai 16 file convertiti alla factory, andavano allineati alle colonne `NOT NULL`
  anche `test_store_accepts_heif_images` (payload senza date) e un insert grezzo in
  `CertificationRequestServiceTest`.

## Bug trovati

- I test usano `DatabaseTransactions`, non `RefreshDatabase`: dopo aver modificato una migration
  già eseguita, `camminiditalia_testing` resta con lo schema vecchio e la suite va in rosso per la
  colonna mancante. Si ricrea con
  `docker exec laravel-camminiditalia php artisan migrate:fresh --env=testing`.
- Il test sul cast delle date aggiunto al Task 1 aveva due `assertInstanceOf(Carbon::class, …)` su
  proprietà già tipizzate `Carbon`: PHPStan li segnala. Tolti, restano le verifiche su
  `toDateString()`.
- `test_locale_defaults_to_it` creava la richiesta con la factory, che imposta già `locale`: non
  verificava più il default della colonna. Ora crea senza `locale`.

## Decisioni

- La riga del `CLAUDE.md` (sezione oc:8182) che diceva che `phpunit.xml` non isola il DB di test è
  marcata come superata da oc:8092, su scelta del dev: oggi i test girano su
  `camminiditalia_testing`, con la guardia in `tests/TestCase.php`.

- **Migration modificata invece di una nuova** (decisione del dev): il passaporto non è in
  produzione. Sugli ambienti dove la migration era già stata eseguita si fa il rollback prima di
  aggiornare il codice, contando con `migrate:status` le migration da togliere: sul DB locale del
  dev erano 3 (`passport_shares`, `validated_ec_tracks`, `certification_requests`), non 4, perché
  `create_ugc_duplicates_archive_table` non era mai stata eseguita. Persi solo dati di prova.
- **`before_or_equal:tomorrow`**: il server è in UTC e l'app limita le date all'oggi del
  dispositivo, che in Italia fra mezzanotte e le 2 è già il giorno dopo per UTC.

## Follow-up

- Il giorno del rilascio: verificare che `certification_requests` non esista in produzione, e su
  Cammini Dev eseguire la procedura di rollback prima del deploy.
- PHPStan ha 19 errori preesistenti nei file del passaporto non toccati da questo ticket
  (`StageProgressService`, test delle condivisioni).
- `RecalculateLayerAttributesJobTest` è intermittente nella suite completa; da solo passa.

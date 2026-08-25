> Ticket: oc:8312

# Aggiornare phpstan-baseline.neon e risolvere drift PHPStan preesistente

## Cosa cambia

Il check CI PHPStan su camminiditalia torna verde e torna ad avere valore diagnostico. Oggi fallisce sistematicamente perché `phpstan-baseline.neon` (generato il 2025-02-12) è disallineato dallo stato attuale di codice/dipendenze (PHPStan 2.1.38, Larastan v3.9.2 — versioni molto più recenti di quelle usate per generare il baseline). Analisi eseguita (`vendor/bin/phpstan analyse --debug`, single-process per evitare una race condition nei worker paralleli locale-only) su 49 errori attualmente non catturati:

- **21 errori** — falsi positivi confermati sulle migration (verificati a runtime con reflection e chiamate dirette): `ForeignKeyDefinition::onDelete()` e `IndexDefinition::comment()` sono implementati via `__call()` magico di `Fluent` (funzionano, PHPStan non li vede); `Blueprint::float()` con un parametro extra viene silenziosamente ignorato da PHP a runtime. Nessuna modifica al codice — vanno nel baseline rigenerato.
- **10 errori** — causati da relazioni Eloquent e attributi senza tipo generico dichiarato in `wm-package` (`Layerable::layer()`, `Layer::layerOwner()/ecTracks()/ecPois()`, `EcTrack::ecPois()`, `GeometryModel`). **Scope deciso: restare solo su camminiditalia** — non si tocca il submodule in questo ciclo. Vanno anch'essi nel baseline rigenerato, con nota esplicita della causa reale per non perdere il contesto (falso allarme dovuto a un gap di annotazioni in wm-package, non un vero bug).
- **18 errori** — fix reali nel repo principale, elencati in Requisiti.

## Perché

Il check CI PHPStan non essendo aggiornato non distingue più errori nuovi introdotti da una PR da rumore di fondo preesistente (verificato: il check falliva già identicamente su PR precedenti già mergiate, es. PR #51/oc:8276, PR #53/oc:8304 — non è mai stato bloccante ma ha perso significato).

## Requisiti

- [ ] Rigenerare `phpstan-baseline.neon` includendo i 21 falsi positivi sulle migration (nessuna modifica alle migration) + i 10 errori causati da gap di tipizzazione in `wm-package` (nessuna modifica al submodule in questo ciclo — scope limitato a camminiditalia)
- [ ] `app/Nova/Traits/HasLayerFilterAndLink.php:81` — allineare la firma della closure passata a `Select::filterable()` al contratto Nova: `function (NovaRequest $request, $query, mixed $value, string $attribute)`, senza `return` (Nova scarta il valore)
- [ ] `tests/Feature/HasLayerFilterAndLinkTest.php` — la classe anonima usata nel test deve dichiarare `$properties` e `static $model` (anche vuoti/dummy) per riflettere correttamente il contratto che il trait si aspetta da un vero Nova Resource
- [ ] `app/Nova/Layer.php:24` — rimuovere il docblock `@var class-string<\App\Models\Layer>` orfano sopra `indexQuery()`: è posizionato sul metodo sbagliato e riferisce una classe inesistente (il modello Layer vive solo in `Wm\WmPackage\Models\Layer`)
- [ ] `app/Policies/TaxonomyPoiTypePolicy.php` — aggiungere `return false;` esplicito a `delete()`, `restore()`, `forceDelete()`, coerente col pattern già usato da `create()/update()` nello stesso file (nessuno tranne `team@webmapp.it`, gestito da `before()`, può eseguire queste azioni — confermato con l'utente)
- [ ] `tests/Feature/AppHomeLayerSortButtonTest.php` (righe 21, 44) — sostituire `assertNotFalse($configHomeIndex, ...)` con `assertNotNull(...)`: `fieldIndexByAttribute()` ritorna `?int`, mai `false`, l'assertion attuale è sempre vera per costruzione
- [ ] `tests/Feature/LayerOwnershipTransferTest.php` (righe 60, 144) — sostituire le chiamate a `Layer::manualEcPois()` (deprecato, oc:8139) con `ecPois()`
- [ ] Verificare che le 2 voci stale del baseline (`routes/console.php`, `tests/Feature/ExampleTest.php` — pattern che non matchano più nulla nel codice attuale) spariscano automaticamente con la rigenerazione
- [ ] `vendor/bin/phpstan analyse` deve concludere con 0 errori dopo tutti i fix e la rigenerazione del baseline
- [ ] `php artisan test` deve passare integralmente dopo tutti i fix (nessuna regressione comportamentale)

## Rischi

- **Baseline troppo permissivo dopo rigenerazione**: se in futuro si baseline-ignora per pigrizia invece di investigare caso per caso (come fatto qui), il check torna a perdere valore. Mitigazione: questo ciclo documenta esplicitamente la causa di ogni singola entry del baseline (falso positivo confermato a runtime o gap di annotazioni in wm-package, non "non ho voglia di controllare").
- **10 errori wm-package restano nel baseline invece che fixati alla radice**: scelta esplicita per restare nel perimetro camminiditalia-only. Se in un ciclo futuro qualcuno tocca quelle stesse relazioni in wm-package aggiungendo i generics, queste entry del baseline diventeranno anch'esse stale (innocue, ma da ripulire quando il baseline verrà rigenerato di nuovo).
- **`TaxonomyPoiTypePolicy` — cambio di comportamento esplicito**: aggiungere `return false` rende esplicito un deny che oggi è implicito (return null → Gate nega di default). Rischio basso, comportamento runtime non dovrebbe cambiare, ma è comunque un cambio di policy che tocca autorizzazioni. **Non esiste nessun test dedicato a questa policy nel repo** (verificato) — nessuna rete di sicurezza automatizzata oltre alla suite di test generale; rischio accettato, non si aggiungono nuovi test in questo ciclo (fuori scope rispetto al ticket).
- **Check CI PHPStan non copre `composer.lock`**: la deriva che ha causato questo ticket è nata da un bump di versione PHPStan/Larastan via composer, non da una modifica `.php`. Il workflow CI attuale non si attiva su cambi a `composer.lock`, quindi lo stesso pattern di drift silenzioso può ripresentarsi in futuro. Rischio accettato e non mitigato in questo ciclo — fuori scope rispetto al ticket (che chiede solo di rigenerare il baseline e correggere il drift attuale, non di modificare la pipeline CI).
- **Migration `create_users_table.php`: `$table->float('balance', 0, 0)`** — il parametro extra ignorato a runtime è confermato innocuo per PHPStan, ma non è stato indagato se precisione/scala a zero su una colonna monetaria fosse l'intento originale o un bug storico di schema. Va nel baseline as-is, nessuna ulteriore indagine in questo ciclo.

## Out of scope

- **`wm-package` non viene toccato in questo ciclo** — scope deciso esplicitamente: solo camminiditalia. I 10 errori causati da relazioni/attributi non tipizzati nel submodule restano nel baseline; il fix alla radice (annotazioni generiche) è rimandato a un ticket separato lato wm-package
- Non si tocca la configurazione del livello PHPStan (resta livello 5 su camminiditalia) — nessuna richiesta di alzarlo in questo ciclo
- Non si indaga uno stub Larastan alternativo per i pattern `onDelete()/comment()/float()` sulle migration (deciso: non necessario per chiudere questo ticket)
- Non si modificano migration già eseguite in produzione

## Moduli toccati

- `phpstan-baseline.neon` (rigenerato)
- `app/Nova/Traits/HasLayerFilterAndLink.php`
- `tests/Feature/HasLayerFilterAndLinkTest.php`
- `app/Nova/Layer.php`
- `app/Policies/TaxonomyPoiTypePolicy.php`
- `tests/Feature/AppHomeLayerSortButtonTest.php`
- `tests/Feature/LayerOwnershipTransferTest.php`

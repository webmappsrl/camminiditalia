> Ticket: oc:8951

# Notes — Allineamento wm-package a 1.6.1

## Errori miei, corretti dopo che il dev li ha notati

**La procedura post-deploy che avevo scritto era sbagliata in tre punti su quattro.** Avevo messo
`publish-missing-migrations`, `migrate` e il riavvio di Horizon come passi manuali. Leggendo
`scripts/deploy_prod.sh` — cosa che non avevo fatto — il deploy esegue da sé `git submodule
update --init --recursive`, `composer install`, `php artisan migrate --force` e
`horizon:terminate`, con l'app in `down` per tutta la durata. L'unico passo manuale vero è
`scout:import`. Da qui discendono due correzioni: la procedura, e il «rischio finestra fra deploy
e migrate», che non esiste perché l'app è in manutenzione mentre migra.

La radice è una domanda mal posta a inizio sessione («le migration le pubblichiamo sul server o
le committiamo?»), fatta senza aver prima letto lo script di deploy che rispondeva da sé.

## Deviazioni dal piano

**Il piano prevedeva di correggere i test rotti dall'aggiornamento: non ce n'erano.** La suite è
passata al primo colpo, 951 test, 0 falliti. Il task di adeguamento è rimasto vuoto.

**Il confronto prima/dopo degli artefatti è stato rifatto tre volte prima di valere qualcosa.**
Due errori, entrambi scoperti e corretti durante il lavoro:

1. Il primo confronto interrogava `localhost:8000`, che in quel momento era servito da
   `laravel-forestas`: stavo confrontando il `config.json` di «Sardegna Sentieri» con quello di
   Cammini d'Italia in produzione. La causa è in «Bug trovati».
2. Il secondo usava l'endpoint `/api/app/webmapp/{id}/config.json`, che **serve il file già su
   storage** e ricalcola solo se manca (`AppController::config()`): le due chiamate leggevano lo
   stesso file, e «nessuna differenza» non significava niente. Rifatto con
   `AppConfigService::config()`, che costruisce davvero.

Il confronto dei geojson invece non è mai riuscito in modo pulito: spostare il commit del
submodule sotto un server in esecuzione restituisce una pagina di errore. Per quel punto la prova
resta la lettura del codice.

**`taxonomySectionKey()` non si trova dove il piano diceva.** Il piano identificava la sezione
delle tassonomie con `meta()['key']`; `Tab::group()` mette invece la chiave in `attribute`, e
`meta()` è vuoto. I primi due test erano quindi verdi per il motivo sbagliato: corretti e visti
fallire prima di implementare.

**PHPStan va lanciato in processo singolo.** Con i worker paralleli fallisce con una race sulla
cache Nette (`Unable to create file … nette.configurator/Container_*.php`). È lo stesso problema
già documentato nel `CLAUDE.md` per oc:8312.

## Scope aggiunto in sessione

Tutto deciso dal dev mentre il lavoro era in corso, dopo aver visto le pagine di Nova.

**Le tassonomie storiche si dichiarano nel package, lo shard sottrae.** Il package aveva un
meccanismo (`legacy_taxonomies`) nato per dichiarare *ogni* tassonomia storica — lo dice la sua
stessa pagina di conoscenza — ma ne dichiarava una, le Attività. Ora dichiara anche i Tipi POI, e
ogni consumer toglie quelle che non usa. **Questo rende falso il contratto iniziale del ticket**,
per cui in `wm-package` si sarebbe mosso solo il gitlink: l'overview è stata aggiornata.

Su Cammini d'Italia, di conseguenza:

- sul **POI** resta la sola «Tipologia POI», e sparisce la lista di attach «Taxonomy Poi Types»
  del package — erano due controlli sulla stessa relazione nella stessa pagina, il difetto che su
  Forestas ha svuotato un layer al primo salvataggio;
- su **tappa** e **cammino** nessuna tassonomia storica: le Attività qui non si usano (oc:8311);
- `"Poi Types"` tradotta nelle cinque lingue del package;
- la voce di menu «Taxonomies» ora passa da `__()`: la traduzione esisteva già nel package, ma
  `MenuSection::make('Taxonomies', …)` riceveva la stringa grezza e non la cercava mai.

Verificato a mano che il campo scrive davvero: un salvataggio senza modifiche non tocca le
associazioni, togliere un tipo lo toglie anche da `taxonomy_poi_typeables`. Il valore usato per la
prova è stato rimesso com'era.

## Bug trovati

**`/api/v1/app/{id}/pois.geojson` risponde 500, e non per colpa di questo ticket.**
`StorageService::getPoisGeojson(): Argument #1 ($appId) must be of type int, null given`. Succede
in locale, **in produzione e in dev**, dove gira ancora il package vecchio; nessun file sotto
`src/Http/` né `StorageService.php` è toccato dal diff. Preesistente, fuori scope, da aprire a
parte.

**L'ambiente Docker locale era inservibile e nessuno se n'era accorto.** `laravel-camminiditalia`
e `minio-camminiditalia` erano in esecuzione **senza rete**: non riuscivano a prendere le porte
8000 e 9080, occupate da `laravel-forestas` e `minio-osm2cai2`. Conseguenze: `localhost:8000`
rispondeva con un altro progetto, e il ricalcolo del `config.json` falliva su
`UnableToCheckFileExistence: camminiditalia/json/icons.json`. Risolto fermando i container degli
altri progetti e ricreando questi con `docker compose -f local.compose.yml up -d`.

**Il `CLAUDE.md` indica un container che non funziona.** Dice di eseguire i comandi artisan in
`laravel-camminiditalia`; quel container non raggiungeva il database. Tutti i comandi di questo
lavoro sono girati in `php-camminiditalia`. Da decidere se correggere il `CLAUDE.md` o
l'ambiente.

## Decisioni

- **Nessun tag Orchestrator aggiunto al ticket**, su indicazione del dev: la fase che li propone
  è stata saltata di proposito, non per dimenticanza.
- **Stima saltata**, su indicazione del dev: `estimated_hours` non è stato scritto su
  Orchestrator.
- **Le migration sono pubblicate in locale e committate nel ticket** (commit `a00571a`). A metà
  sessione era stato deciso il contrario — pubblicarle sul server — ma è una decisione presa su
  una mia domanda mal posta, e i fatti l'hanno superata: i file vanno nel commit, e sui server le
  esegue il deploy.
- **Criterio di accettazione del diff dato dal dev**: additivo si accetta, modificativo si porta
  a lui. Nessun cambiamento modificativo è emerso nei quattro punti che producono output.
- **Un utente Administrator di prova** (`oc8951@local.test`) è stato creato sul database locale
  per entrare in Nova. Da cancellare quando non serve più.

## Follow-up

- `pois.geojson` in errore 500 in tutti gli ambienti: ticket a sé.
- Il `CLAUDE.md` del repo indica `laravel-camminiditalia` per i comandi artisan, ma quel
  container non è utilizzabile così com'è.
- I **Temi** restano fuori dal modello unico e non hanno relazione sui contenuti EC: se un giorno
  si vorranno gestire come i Tipi POI, serve prima quella relazione nel package.
- La convergenza vera delle tassonomie storiche dentro `taxonomy_terms` (164 Tipi POI, 25
  Attività, 7 Temi) non è stata fatta e non ha un command di travaso: resta da pianificare.

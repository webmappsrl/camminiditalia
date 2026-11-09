> Ticket: oc:8951

# Allineamento wm-package a 1.6.1 su camminiditalia e verifica di non regressione

## Cosa cambia

Il gitlink del submodule `wm-package` passa da `ca41174e` (2026-11-02) a `origin/main`
(release 1.6.1), quattro commit più avanti. Il contenuto è quasi tutto oc:8910, «modello dati
unico classificazioni/accessibilità»: 92 file, ~18.000 righe, tre migration nuove
(`taxonomy_terms`, `taxonomy_termables`, `app_taxonomy_term`), i modelli `TaxonomyTerm` e
`TaxonomyTermable`, il Nova Field `Choice`, e modifiche a `Models\{App,EcTrack,Layer}`,
`Nova\{AbstractEcResource,App,EcPoi,EcTrack,Layer}`, `Nova\Concerns\BuildsTaxonomyFields`,
`Services\{GeoJsonService,Models\LayerService}` e ai Job di reindex.

Durante il lavoro il dev ha allargato lo scope a una seconda cosa, decisa in sessione: le
tassonomie storiche nella sezione Tassonomie dei contenuti. Il package ne dichiarava una sola
(`activities`) pur avendo un meccanismo pensato per dichiararle tutte; ora dichiara anche i Tipi
POI, e ogni shard sottrae quelle che non usa. Su Cammini d'Italia resta il solo «Tipologia POI»
sul POI — nessun dato si sposta, cambia dove lo si sceglie — e sparisce la lista di attach che
duplicava lo stesso controllo. **Quindi in `wm-package` non si muove più solo il gitlink.**

In camminiditalia **non si aggiunge nessuna funzionalità** oltre a questo. Si scrive solo quanto serve perché
dopo l'aggiornamento valga questo: il frontend collegato continua a funzionare, e tutto quello
che oggi si fa in Nova si continua a fare — anche se cambia l'aspetto delle pagine.

Un solo adeguamento è già noto: `App\Nova\Layer::fields()` oggi toglie dalla Resource i Field
`MorphToMany` `taxonomyActivities` e `taxonomyWheres` perché Cammini d'Italia non usa quelle
tassonomie (oc:8311). Nel package nuovo quei Field non esistono più: al loro posto c'è
`makeTaxonomySection(__('Filters'))`, che il filtro locale non intercetta. Senza intervento la
sezione Filters comparirebbe a tutti, Validator compresi. Il presupposto di oc:8311 vale ancora,
quindi va nascosta anche la nuova.

## Perché

Il package è la base di Cammini d'Italia e viene caricato dal submodule: finché il gitlink resta
indietro, il progetto non riceve né le correzioni né le evoluzioni fatte a monte, e la distanza
cresce fino a rendere ogni allineamento successivo più rischioso di questo. oc:8910 è un
cambiamento strutturale sul modello dati, quindi conviene assorbirlo da solo, in un rilascio in
cui l'unica cosa cambiata è il package — se qualcosa si rompe, si sa dove guardare.

## Requisiti

- [ ] Il gitlink di `wm-package` punta a `origin/main` (release 1.6.1) e il submodule è
      allineato nel working tree.
- [ ] Le tre migration del package sono pubblicate con
      `wm-package:publish-missing-migrations` ed eseguite, in locale e in produzione. Non sono
      opzionali: `LayerService` (`:401-404`, `:483-486`), gli Observer di `EcTrack`, `EcPoi` e
      `Layer`, `EcTrack` e `App` interrogano `taxonomy_termables`/`taxonomy_terms` nei percorsi
      normali, quindi senza quelle tabelle salvare un contenuto o ricalcolare un cammino va in
      errore SQL.
- [ ] La sezione Filters introdotta da `makeTaxonomySection(__('Filters'))` **non viene
      costruita** in `App\Nova\Layer`: override di `legacyTaxonomies()` a `[]` e rimozione
      della sezione dai fields, così non parte la query su `taxonomy_wheres` (7.930 righe,
      104 MB). Il filtro per `attribute` di oggi va tolto: è codice morto dopo l'aggiornamento.
- [ ] Ogni punto del diff che produce output per il frontend — `GeoJsonService`, `LayerService`,
      `ReindexAppContentsJob`, `ReindexEcTrackSearchableJob` — è letto e classificato:
      **additivo** (chiave o campo in più) si accetta, **modificativo** (valore esistente che
      cambia o sparisce) si porta al dev prima di proseguire.
- [ ] Confronto prima/dopo degli artefatti pubblici a conferma della lettura del codice:
      `config.json` e `base-config.json` dell'App, geojson di alcuni cammini, presi da
      `https://camminiditalia.maphub.it` (produzione) e `https://camminiditalia.dev.maphub.it`
      (dev) e confrontati con quelli generati dall'ambiente aggiornato.
- [ ] `php artisan test` passa per intero sul DB `camminiditalia_testing`, migration del package
      comprese. I test più esposti sono quelli che leggono i Field della Resource `Layer`
      (`LayerAttributeFieldsTest`, `LayerAttributesWheresTest`, `LayerThemeAttributesTest`,
      `AppHomeLayerSortButtonTest` e gli altri che usano `fieldIndexByAttribute`), quelli su
      `LayerService` e quelli sulle Resource `EcTrack`/`EcPoi`.
- [ ] PHPStan non segnala errori nuovi sui file toccati.
- [ ] Le tassonomie storiche sono dichiarate **nel package** (`legacy_taxonomies` con
      `activities` e `poi_types`) e lo shard sottrae: su Cammini d'Italia resta «Tipologia POI»
      sul POI, niente sul cammino e sulla tappa. La lista di attach dei Tipi POI sparisce dal
      POI: due controlli sulla stessa relazione nella stessa pagina sono il difetto che su un
      altro shard ha svuotato le associazioni al primo salvataggio.
- [ ] `"Poi Types"` tradotta in tutte e cinque le lingue del package, e la voce di menu
      «Taxonomies» passa da `__()` invece di restare in inglese.
- [ ] I controlli di `wm-package/docs/knowledge/8910-tassonomie-cosa-controllare-al-bump-dello-shard.md`
      sono stati eseguiti: `REDIS_CLIENT` valorizzato (senza, i job con `uniqueVia()` su Redis
      muoiono al primo salvataggio), nessuna collisione sul nome `taxonomies()`, trait
      `TaxonomyAbleModel` presente sul modello EC track.
- [ ] Verifica in Nova, come Administrator e come Validator, che le operazioni di oggi si
      possano ancora fare: detail ed edit di `EcTrack` ed `EcPoi` con le loro classificazioni,
      Field `LayerFeatures` sul `Layer` in modalità manuale, attach EcTrack↔EcPoi, Resource del
      passaporto, Resource UGC.
- [ ] Rilascio in produzione con i passi post-deploy eseguiti e riportati sul ticket:
      `wm-package:publish-missing-migrations` e `migrate` come primo passo, uno dietro l'altro,
      poi riavvio di Horizon (il deploy non lo riavvia), poi
      `php artisan scout:import "App\Models\EcTrack"` — **non** il reindex normale: i dynamic
      template dell'indice per `terms` non si applicano a un indice esistente, e un documento
      rifiutato fa fallire il salvataggio in Nova — poi verifica degli artefatti pubblici.

## Rischi

**Costruire la sezione Filters del `Layer` carica 7.930 righe da 104 MB.** È il rischio
principale, verificato riga per riga. `Wm\WmPackage\Nova\Layer::legacyTaxonomies()`
(`src/Nova/Layer.php:143-156`) dichiara, oltre ad Activities, una tassonomia legacy `wheres` ->
`taxonomyWheres`; `BuildsTaxonomyFields::makeLegacyTaxonomyFieldsByTab()` (`:318-321`) ne
costruisce le opzioni con `$model::query()->...->get()`, cioè tutte le righe con tutte le
colonne. Su questo progetto `taxonomy_wheres` ha 7.930 righe per 104 MB di geometrie, e la
costruzione avviene dentro `parent::fields($request)`, che `App\Nova\Layer::fields()` chiama
**prima** del suo `array_filter`: nascondere il campo a valle evita di disegnarlo, non di
caricarlo.
*Mitigazione approvata:* override di `legacyTaxonomies()` in `App\Nova\Layer` che restituisce
`[]`, più rimozione della sezione dai fields - il campo non viene costruito e la query non
parte. È anche l'unica forma che non muore in silenzio: il filtro di oggi cerca
`taxonomyActivities` e `taxonomyWheres`, che dopo l'aggiornamento non esistono più, quindi e'
già codice morto che nessun test segnala.

**Finestra fra il deploy del codice e `migrate`.** Dal momento in cui il codice nuovo è sul
server, `LayerService` (`:401-404`, `:483-486`), gli Observer di `EcTrack`, `EcPoi` e `Layer`,
`EcTrack` e `App` interrogano tabelle che ancora non esistono: chi salva un contenuto in quei
minuti prende un errore SQL. *Decisione del dev:* le migration si pubblicano direttamente sul
server, come negli altri rilasci. *Mitigazione:* `wm-package:publish-missing-migrations` e
`migrate` sono il primo passo della procedura post-deploy, uno dietro l'altro, senza nulla in
mezzo.

**Il confronto degli artefatti pubblici dà una conferma parziale.** Con le tabelle nuove vuote,
`config.json` e i geojson sono identici a meno di una chiave vuota in più: il confronto passerà
verde qualunque cosa succeda in Nova. Serve a escludere i cambiamenti modificativi, non a
provare che Nova funzioni. *Mitigazione:* la verifica in Nova resta un passo a sé e non si
considera coperta dal confronto.

**Le Attività compaiono due volte sulla detail di `EcTrack`.** Il `MorphToMany` "Activities" del
package resta (`src/Nova/EcTrack.php:83`) e si aggiunge `...$this->taxonomySectionFields()`
(`:71`), che le ripropone come `Choice`. Non è una rottura - il `MorphToMany` è listable e non
entra nel form - ma è un doppione visibile. *Mitigazione:* verificarlo a occhio e decidere con
il dev se togliere il doppione o lasciarlo.

**Il reindex paga `terms` per ogni traccia.** `EcTrack::toSearchableArray()` aggiunge
`'terms' => $this->termsForSearch()`, che con le tabelle vuote restituisce un array vuoto al
prezzo di un paio di query per traccia: su 1.315 tracce si sente solo in un reindex completo.
Costo accettato, segnalato perché non si scambi per un problema nuovo.

**Rischi valutati e non confermati** (verificati, restano qui perché non vengano riaperti):
mappa del cammino vuota per il `sync()` tolto dal fallback di lettura - richiede layer in
modalità auto, e in produzione `DEFAULT_LAYER_MODE=manual` con nessun `track_mode` impostato
sui 124 layer; Job incompatibili con i worker in esecuzione - nessun costruttore modificato;
`ReindexAppContentsJob` sconosciuto ai worker - parte solo al cambio delle tassonomie del
modello nuovo, che qui non si adotta; trigger `taxonomy_terms_two_levels` non ripristinato da un
secondo `migrate` - vero come scrittura (sta dentro `if (! Schema::hasTable(...))`), ma richiede
una tabella creata a metà, che `migrate:fresh --env=testing` non produce.

## Out of scope

- Adottare il nuovo modello `taxonomy_terms` per le classificazioni di Cammini d'Italia: le tre
  tabelle nascono vuote, il diff non porta nessun command di travaso dati e le tassonomie
  attuali (Temi, Tipi POI, Attività) restano dove sono, con `activities` dichiarata legacy nel
  default del package. Il passaggio, se si vorrà, è un ticket suo.
- Accendere la sezione Filters dei layer come funzionalità per Cammini d'Italia.
- Qualsiasi modifica dentro `wm-package`: se l'adeguamento giusto risultasse essere nel package,
  diventa un ticket separato su quel repo.
- Riallineare altri shard che usano `wm-package`.

## Moduli toccati

In `camminiditalia` il grosso; in `wm-package` il gitlink **più** la dichiarazione delle
tassonomie storiche (branch `feature/oc-8951-legacy-taxonomies-complete`).

| File | Cosa |
|---|---|
| gitlink `wm-package` | da `ca41174e` a `origin/main` (1.6.1) |
| `database/migrations/` | le tre migration pubblicate dal command del package |
| `app/Nova/Layer.php` | nascondere la sezione Filters al posto dei Field rimossi |
| `tests/Feature/` | adeguamento dei test rotti e regressione sulla sezione Filters nascosta |
| `app/Nova/EcPoi.php` | tiene la sola «Tipologia POI» fra le tassonomie storiche; toglie la lista di attach doppia |
| `app/Nova/EcTrack.php` | nessuna tassonomia storica nella sua sezione |
| `app/Providers/NovaServiceProvider.php` | `__('Taxonomies')` nel menu, che restava in inglese |
| `wm-package/config/wm-package.php` | `legacy_taxonomies` dichiara anche `poi_types` |
| `wm-package/resources/lang/*.json` | `"Poi Types"` nelle cinque lingue |
| `CLAUDE.md`, `docs/knowledge/` | registrazione di quanto emerge |

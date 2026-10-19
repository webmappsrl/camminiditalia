> Ticket: oc:8773

# Associazione delle UgcTrack a più cammini/tappe invece che a un solo layer

## Cosa cambia

Una UgcTrack non è più collegata a un solo cammino (`properties->layer_id`). Quasi tutto il lavoro
sta in wm-package: il package salva quali segmenti delle tappe (EcTrack) la traccia copre, ricava i
cammini attraversati, e li mostra nella risorsa Nova `UgcTrack` con un campo HTML e un filtro per
layer (parte wm-package:
`wm-package/docs/features/8773-associazione-delle-ugctrack-a-piu-cammini-tappe-invece-che-a-un-solo-layer/overview.md`).

In camminiditalia:
- `App\Nova\UgcTrack` riceve campo e filtro dal package tramite `parent::fields()`; li vede solo
  l'Administrator, perché solo lui vede le UgcTrack (invariato);
- spariscono dalle UgcTrack il link "Cammino" basato su `layer_id` e il campo di correzione
  manuale di oc:8575, che restano sugli UgcPoi;
- i parametri sono quelli dell'algoritmo del passaporto: segmenti da 400 m, distanza massima da 50 m. Vivono
  nelle properties della App e si modificano da Nova App, con i campi del package; la soglia di
  sblocco resta di camminiditalia;
- le etichette generiche del package ("layer", "EcTrack") vengono tradotte con il lessico di
  camminiditalia ("cammino", "tappa").

Questo ticket produce il dato **per traccia** su cui oc:8165 (passaporto, badge automatico da GPS)
calcolerà il dato **per utente**: unione dei segmenti delle tracce dell'utente, sblocco della
tappa sopra l'80%, sblocco del cammino.

## Perché

In camminiditalia un layer è un cammino e una EcTrack è una sua tappa. Una UgcTrack attraversa più
tappe e spesso più cammini, quindi collegarla a un solo cammino dà un'informazione incompleta o
sbagliata (oc:8466: la UgcTrack 321, circa 100 km sulla Via degli Dei, assegnata alla Via Mater
Dei). Inoltre oc:8165 deve sapere quali tratti di tappa ha coperto ogni traccia: calcolarlo una
volta per traccia, all'arrivo, evita di rifare l'intersezione geometrica su tutte le tracce di un
utente a ogni lettura del passaporto.

Il `layer_id` delle UgcTrack oggi serve solo in Nova (link, filtro, correzione manuale), e solo
all'Administrator. Per gli UgcPoi invece serve davvero (scoping del Validator, mail al gestore) e
resta.

## Requisiti

- [ ] Bump del submodule wm-package con la parte package di oc:8773, e pubblicazione della nuova
      migration (procedura `wm-package/docs/howto/migration-wm-package.md`).
- [ ] Segmento 400 m e distanza massima 50 m come valori per l'App di camminiditalia (properties della App,
      o default del package se coincidono), modificabili da Nova App con i campi del package.
- [ ] `App\Nova\UgcTrack`: tolti il link "Cammino" su `layer_id` (`HasLayerFilterAndLink`) e il
      campo di correzione manuale (`HasLayerOverride`); su `App\Nova\UgcPoi` restano invariati.
- [ ] `App\Nova\UgcTrack`: campo e filtro del package presenti per l'Administrator; `indexQuery()`,
      menu e `UgcTrackPolicy` invariati.
- [ ] `PopulateUgcLayerIdCommand`: lavora solo sugli UgcPoi.
- [ ] Traduzioni delle etichette del package con il lessico di camminiditalia in tutte le lingue del
      repo (`resources/lang/{it,en,fr,es,de}.json`).
- [ ] Test di integrazione: campo e filtro presenti per l'Administrator, lista UgcTrack vuota per il
      Validator, link e correzione manuale assenti sulle UgcTrack, UgcPoi invariati (link, override,
      scoping del Validator, mail).
- [ ] Dopo il deploy: backup, poi il command del package in `--dry-run`, poi senza.

## Rischi

- **Ordine di rilascio**: il bump del submodule va fatto solo dopo il merge della parte package; la
  migration va pubblicata nello stesso commit.
- **Command lanciato prima della pulizia GPS** sulle tracce esistenti: la corrispondenza verrebbe
  calcolata su geometrie con punti errati. `CleanUgcTrackGeometryJob` accoda il ricalcolo in modo
  esplicito (requisito del package), quindi si recupera, ma l'ordine giusto resta: prima oc:8718,
  poi oc:8719, poi questo command.
- **Stash di oc:8165** (`stash@{0}` sul branch
  `feature/oc-8165-passaporto-camminatore-badge-automatico-da-gps`): contiene già segmentazione,
  corrispondenza, trigger e parametri da Nova App (`PassportSettings`, tab "passport" in
  `App\Nova\App`), calcolati per utente. Quando oc:8165 riprende, deve leggere segmento e distanza massima
  dal package e unire gli indici salvati da oc:8773, usando **solo le righe calcolate con i
  parametri attuali** e applicando i suoi filtri (solo tracce con `properties.locations`, un uuid
  conta una volta), invece di rifare la geometria. Se non si
  allinea, ci saranno due implementazioni della stessa regola con parametri in due posti.
- **Locale non sempre `it` in produzione** (vedi `docs/knowledge/risoluzione-layer-ugc.md`): le
  etichette tradotte del package potrebbero comparire in inglese. Va verificato in Nova dopo il
  deploy.

## Out of scope

- Calcolo per utente, filtri sulle tracce valide, soglie, sblocchi, date e API del passaporto:
  oc:8165 (e il riallineamento del suo stash).
- Visibilità delle UgcTrack per il Validator. Nota: oggi `UgcTrackPolicy::before()` concede al
  Validator `viewAny`/`view`/`delete` su ogni UgcTrack (verificato con Gate sul DB di sviluppo):
  la lista è vuota e la voce non è nel menu, ma il detail via URL diretto è autorizzato.
  Preesistente, da trattare in un ticket separato.
- Varianti dei cammini.
- Qualsiasi cambiamento agli UgcPoi.

## Moduli toccati

**camminiditalia**
- gitlink `wm-package` + migration pubblicata in `database/migrations/`
- `app/Nova/UgcTrack.php`
- `app/Nova/Traits/HasLayerFilterAndLink.php`, `app/Nova/Traits/HasLayerOverride.php` (solo se
  serve separarli per UgcPoi/UgcTrack)
- `app/Console/Commands/PopulateUgcLayerIdCommand.php`
- `resources/lang/{it,en,fr,es,de}.json`
- `tests/Feature/` (nuovi test, più `UgcTrackLayerFieldTest.php` e `HasLayerOverrideTest.php`, che
  oggi testano link e correzione manuale sulle UgcTrack)
- `docs/knowledge/risoluzione-layer-ugc.md`, `CLAUDE.md`

**wm-package**: vedi l'overview nel submodule.

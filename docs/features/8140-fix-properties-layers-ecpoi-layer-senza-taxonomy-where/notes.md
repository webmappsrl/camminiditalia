> Ticket: oc:8140

# Notes — Fix: properties.layers su EcPoi valorizzato erroneamente per layer senza taxonomy_where

## Deviazioni dal piano

- **Test spostati dal wm-package al repo principale**: il plan prevedeva i test in `wm-package/tests/Feature/`. Impossibile: wm-package non può referenziare `Tests\TestCase` del repo principale, e `Wm\WmPackage\Tests\TestCase` non è in `autoload-dev` di camminiditalia. Test spostati in `tests/Feature/LayerServiceUpdateLayersPropertyGuardTest.php` — più corretto perché testano il comportamento nel contesto di camminiditalia (morph map `App\Models\EcPoi`, factory locali, ecc.).

- **Scope esteso a EcTrack**: il ticket menzionava solo EcPoi, ma il bug era identico per EcTrack (stessa call chain). Applicare il guard a entrambi elimina la biforcazione e il rischio di regressione futura su EcTrack. Confermato con l'utente durante la fase di design.

## Bug trovati

- **Morph type nel test**: il `layerable_type` da usare nel DB è `'App\Models\EcPoi'` (chiave del morph map), NON `Wm\WmPackage\Models\EcPoi::class`. Inserire il tipo sbagliato causa un falso negativo nel test (relazione `ecPois` non trova il POI).

- **Guard con early return bloccava anche il path di rimozione**: la prima implementazione usava `return ['added' => [], 'deleted' => []]` per i layer senza filtri. Questo saltava anche il path che rimuove layer ID storicamente corrotti da `properties['layers']`. Verificato su dati reali: EcPoi #422 aveva `properties.layers: [32,24]` ma `layerables: [24]` — Layer 32 (0 manuali, 0 filtri) non veniva mai pulito. Fix: sostituito l'early return con flag `$noValidFilter` che azzera solo `$newLayerFeatures` (nessuna aggiunta) e `$layerFeaturesIds = []` (il path remove pulisce tutti i POI che hanno ancora quel layer ID). Risultato finale: 1350 rimozioni di falsi positivi, media layer/POI scesa da 6,02 a 1,04.

## Decisioni

- **Guard solo in scrittura** (`updateLayersPropertyOnLayeredFeature`), non nelle letture (`getAllVisibleModels`): Nova continua a mostrare i POI geograficamente visibili per un layer anche se non partecipa all'aggiornamento di `properties['layers']`.
- **`taxonomyActivities->isNotEmpty()`** invece di `taxonomyActivities()->exists()`: usa la collection già caricata via eager loading nel command, evitando N+1 query.
- **Command con self-check pre-deploy**: il command verifica che il guard sia in place prima di procedere; `--force` per bypassare consapevolmente.

## Follow-up

- Il bug esiste anche per EcTrack su `properties['layers']`, ma le tracce hanno `assignTracksByTaxonomy` come meccanismo separato — da monitorare se i dati su EcTrack sono anch'essi corrotti dopo il command.
- Fix della root cause profonda (`scopeByWhereProperty` che non aggiunge `WHERE false`) è fuori scope — da valutare in un ticket separato se emergono altri callers problematici.

## Ordine di esecuzione in produzione

`fix-ec-poi-layers-property` e `sync-layer-ec-pois` agiscono su tabelle diverse e devono girare in sequenza:

| Command | Modifica | Non tocca |
|---|---|---|
| `sync-layer-ec-pois` | `layerables` (pivot) | `properties['layers']` |
| `fix-ec-poi-layers-property` | `properties['layers']` | `layerables` |

`fix-ec-poi-layers-property` legge `layerables` come sorgente di verità: se `layerables` è incompleto, anche `properties['layers']` risulterà incompleto. L'ordine corretto è:

1. Deploy fix wm-package (guard)
2. Backup DB
3. `camminiditalia:sync-layer-ec-pois`
4. `camminiditalia:fix-ec-poi-layers-property`

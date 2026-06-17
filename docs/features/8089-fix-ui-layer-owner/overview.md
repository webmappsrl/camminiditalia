> Ticket: oc:8089

# Fix UI layer owner: nascondere action Aggiungi alla home e correggere link occhio tracce

## Cosa cambia

1. L'action `AddLayersToConfigHomeAction` viene nascosta ai non-Administrator nel pannello Nova Layer.
2. Il link sull'icona occhio nella widget tracce del layer punta all'URL corretto con prefisso `/nova`.

## Perché

Il gestore di cammino (Validator) vedeva un'action riservata agli Administrator (`Aggiungi alla home`), causando confusione e potenziale uso improprio. Il link occhio nella lista tracce puntava a `/resources/ec-tracks/<id>` invece di `/nova/resources/ec-tracks/<id>`, rendendo il link non funzionante.

## Requisiti

- [ ] `AddLayersToConfigHomeAction` è visibile solo agli utenti con ruolo `Administrator` (`canSee`)
- [ ] `AddLayersToConfigHomeAction` non è eseguibile da non-Administrator nemmeno via API diretta (`canRun`)
- [ ] Il link icona occhio nella widget LayerFeatures punta a `/nova/resources/<model>/<id>`
- [ ] Il path Nova è letto dinamicamente via `Nova::path()` e passato come prop al componente Vue
- [ ] Un test Feature verifica che un Validator non veda `AddLayersToConfigHomeAction` nella lista actions del Layer e riceva 403 se tenta di eseguirla direttamente via API

## Rischi

- **`useGrid.ts` è un file compilato**: la modifica richiede rebuild del campo Nova (`npm run build` nella cartella `LayerFeatures`). Il rebuild è parte obbligatoria del commit — il dist aggiornato va committato insieme ai sorgenti.
- **`withMeta` già in uso**: aggiungere `novaPath` al meta non crea conflitti ma va verificato che il campo venga ricevuto correttamente nel componente Vue come prop.

## Out of scope

- Altri field Nova che costruiscono link senza prefisso `/nova` (es. `PropertiesPanel.php`, `ImportController.php`) — ticket separati se necessario
- Modifica al package `wm-package` per `AddLayersToConfigHomeAction` — il `canSee` viene applicato nell'override locale `App\Nova\Layer`

## Moduli toccati

**Repo principale (`camminiditalia`):**
- `app/Nova/Layer.php` — aggiungere `AddLayersToConfigHomeAction` al filtro `canSee` esistente
- `tests/Feature/LayerActionsVisibilityTest.php` *(nuovo)* — test che verifica visibilità action per ruolo

**Submodule (`wm-package`):**
- `src/Nova/Fields/LayerFeatures/src/LayerFeatures.php` — aggiungere `novaPath` via `withMeta`
- `src/Nova/Fields/LayerFeatures/resources/js/composables/useGrid.ts` — usare `props.novaPath` nel link
- `src/Nova/Fields/LayerFeatures/dist/` — rebuild del campo compilato

> Ticket: oc:8089

# Notes — Fix UI layer owner: nascondere action Aggiungi alla home e correggere link occhio tracce

## Deviazioni dal piano

- Il `uriKey` dell'action era `aggiungi-alla-home` (italiano), non `add-layers-to-config-home-action`. Aggiornato il test di conseguenza.
- Il test `validator_cannot_run` asserisce `[403, 404]` invece di solo `403`: Nova con `canSee=false` rimuove l'action da `availableActions()` e restituisce 404 (action not found) invece di 403. Entrambi i codici indicano che l'esecuzione è bloccata.
- `Layer::factory()` richiede un `App` esistente nel DB — aggiunto `App::factory()->create()` in `setUp()` seguendo il pattern di `LayerPolicyTest`.
- Il comando di rebuild del dist è `npm run prod` (non `npm run build`) — lo script si chiama `prod` nella configurazione Laravel Mix di questo campo.

## Bug trovati

- `LayerFeatureProps` in `types/interfaces.ts` non dichiarava `novaPath` nel tipo del campo `field` — aggiunto dopo la prima implementazione.

## Decisioni

- `canRun` aggiunto oltre a `canSee` per protezione a livello API (non solo UI) — anche se in pratica `canSee=false` già impedisce l'esecuzione via Nova (404), `canRun` garantisce protezione esplicita nel caso in cui il comportamento di Nova cambi nelle versioni future.
- Fallback `props.novaPath || '/nova'` in `useGrid.ts` per robustezza nel caso in cui `novaPath` non sia passato da consumer del componente che non aggiornano subito.

## Follow-up

Nessuno.

> Ticket: oc:8120

# Notes — EcPoi: sola lettura per Validator

## Deviazioni dal piano

- I test sui `uriKey` delle action Nova erano errati nel piano: Nova genera il `uriKey` dal metodo `name()` dell'action (non dal nome della classe), quindi i valori reali sono `execute-ecpoi-data-chain`, `download-pois`, `translate-descriptions-names` invece di `execute-ec-poi-data-chain-action`, `download-ec-poi-action`, `translate-model-action`.
- `EcPoi::factory()->create()` richiede `'properties' => []` per evitare un `TypeError` nell'`AbstractObserver` del package (che tenta di accedere `$properties['name']` quando `properties` è una stringa JSON).
- La factory del package crea istanze `Wm\WmPackage\Models\EcPoi`, non `App\Models\EcPoi`: il type hint nei test è stato aggiornato di conseguenza.

## Bug trovati

Nessun bug nuovo — le deviazioni sopra erano problemi nei test, non nel codice applicativo.

## Decisioni

- `DownloadEcPoiAction` non riceve `canSee`/`canRun` espliciti perché il default Nova (filtro basato su `authorizedToUpdate`) la rende automaticamente invisibile ai Validator grazie all'override `authorizedToUpdate` sulla risorsa Nova. Lasciare il comportamento implicito è più semplice e coerente.
- `authorizedToCreate` in Nova è un metodo statico — dichiarato `static` nell'override, a differenza di `authorizedToUpdate` e `authorizedToDelete` che sono istanza.

## Follow-up

- `viewAny` mostra tutti gli EcPoi (filtro per `user_id` via `AbstractEcResource::indexQuery`): un Validator vede solo gli EcPoi con il proprio `user_id`. Se in futuro si vuole mostrare ai Validator anche EcPoi di altri utenti del proprio layer, sarà un ticket separato.
- Rollback: se si fa revert di questa feature, eseguire `php artisan optimize:clear` in produzione per svuotare la cache delle policy.

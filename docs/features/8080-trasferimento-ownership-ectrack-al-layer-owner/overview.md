> Ticket: oc:8080

# Trasferimento ownership EcTrack al layer owner all'assegnazione del layer

## Cosa cambia
Quando un amministratore assegna o cambia il gestore (owner) di un layer, tutte le EcTrack e le EcPoi associate a quel layer vengono automaticamente riassegnate al nuovo owner tramite aggiornamento di `user_id`. Se il layer perde il proprio owner (user_id → null), le risorse vengono riassegnate all'utente con id=2 (admin gestore della piattaforma Cammini).

## Perché
Il gestore di un cammino deve poter vedere e gestire tutte le tracce e i POI del proprio layer sia in modalità manuale che automatica nel pannello Nova. Senza il trasferimento automatico, le risorse rimangono associate al gestore precedente e il nuovo gestore non le vede.

## Requisiti
- [ ] Al salvataggio di un Layer, se `user_id` cambia, aggiornare `user_id` su tutte le EcTrack associate tramite `$layer->ecTracks()`
- [ ] Al salvataggio di un Layer, se `user_id` cambia, aggiornare `user_id` su tutte le EcPoi associate tramite `$layer->manualEcPois()`
- [ ] Il trasferimento avviene su TUTTE le EcTrack/EcPoi associate al layer, senza filtrare per owner precedente
- [ ] Quando un admin associa una nuova EcTrack o EcPoi a un layer che ha già un owner, il `user_id` della risorsa viene aggiornato all'owner del layer (hook su `Layerable::created`, gestisce sia `EcTrack` che `EcPoi`)
- [ ] Se il nuovo `user_id` è `null`, le risorse vengono riassegnate all'utente il cui id è definito da `config('camminiditalia.default_owner_id')`, configurabile via `CAMMINIDITALIA_DEFAULT_OWNER_ID` in `.env` con default `2`
- [ ] Il trasferimento è sincrono (bulk UPDATE, nessun Job) — max ~100 risorse per layer, nessun rischio timeout
- [ ] Aggiungere `Log::info(...)` per tracciare ogni trasferimento: layer id, vecchio owner, nuovo owner, ID delle singole tracce e POI trasferiti
- [ ] Aggiungere testo `->help()` sul campo `user_id` nel form edit Nova del Layer: "⚠️ Modificando il gestore, tutte le tracce e i POI associati a questo layer verranno automaticamente trasferiti al nuovo gestore."

## Rischi
- **Bulk update senza controllo individuale:** l'UPDATE bulk è atomico ma non granulare — se una traccia ha un `user_id` particolare per un motivo specifico, verrà comunque sovrascritta. Mitigazione: comportamento documentato e accettato esplicitamente.
- **User id=2 hardcoded:** il fallback a user_id=2 è specifico di camminiditalia. Se in futuro cambia l'utente admin di riferimento, va aggiornato manualmente nel codice. Mitigazione: valore estratto in costante leggibile nel codice.
- **Doppio trigger:** se `saved()` viene chiamato più volte in sequenza (es. pipeline Nova), il trasferimento viene eseguito più volte ma il risultato è idempotente (stesso user_id scritto).

## Out of scope
- Tracciabilità del vecchio owner nelle `properties` del modello (`transferred_from_user_id`)
- Job asincrono per il trasferimento
- Modifiche a `wm-package`

## Moduli toccati
- `app/Observers/LayerObserver.php` — nuovo file, estende `Wm\WmPackage\Observers\LayerObserver`
- `app/Observers/LayerableObserver.php` — nuovo file, secondo observer su `Wm\WmPackage\Models\Layerable` per il caso "traccia aggiunta a layer con owner"
- `app/Providers/AppServiceProvider.php` — registrazione dei nuovi observer locali

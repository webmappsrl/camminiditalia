> Ticket: oc:7640

# UGC segnalazioni: filtro per cammino nel pannello admin e flag letto/non letto

## Cosa cambia

Il pannello Nova per gli UGC viene ristretto per ruolo:
- I **Validator** (gestori di cammino) vedono solo le segnalazioni (`form->id = 'report'`) associate ai layer di loro proprietà. Se non hanno layer, vedono una lista vuota.
- Gli **Administrator** continuano a vedere tutti gli UGC e possono filtrare per layer tramite "Filtro Segnalazioni".

Viene inoltre introdotto un sistema letto/non letto: campo `read_at` timestamp su `ugc_pois`, badge visivo nell'index Nova e action bulk `MarkAsRead` / `MarkAsUnread`.

### Completamento (estensione ticket)

Aggiunto **Nova Filter "Filtro Segnalazioni"** visibile solo agli Administrator: dropdown con i layer che hanno almeno una segnalazione associata (`properties->>'layer_id'`). Filtro non selezionato = tutti gli UGC visibili (comportamento default Nova).

## Perché

I gestori non devono vedere le segnalazioni di altri cammini — problema di usabilità e riservatezza. Un gestore del "Cammino dei Frati" non deve vedere i "problemi aperti" del "Cammino di Dante". Il flag letto/non letto permette di gestire il backlog senza aprire ogni singola voce.

## Requisiti

- [x] `indexQuery()` in `App\Nova\UgcPoi`: se Validator, filtra per `properties->>'layer_id' IN (layer posseduti)` e `properties->>'form'->>'id' = 'report'`. Se Administrator, nessun filtro aggiuntivo. Se Validator senza layer, lista vuota (`whereRaw('1=0')`).
- [x] Migration `read_at` (timestamp nullable) su tabella `ugc_pois` + indice espressione su `(properties->>'layer_id')`
- [x] `App\Models\UgcPoi` (nuovo) estende il modello del package: aggiunge `read_at` a `fillable` e cast `datetime`
- [x] Colonna Nova badge letto/non letto in `App\Nova\UgcPoi`: "Non letto" (badge rosso) se `read_at` NULL, "Letto il X" altrimenti
- [x] Nova Action `App\Nova\Actions\MarkAsRead`: imposta `read_at = now()` sui record selezionati
- [x] Nova Action `App\Nova\Actions\MarkAsUnread`: resetta `read_at = null` sui record selezionati
- [x] `App\Policies\UgcPoiPolicy`: `before()` esplicito — Administrator → `true`, non-Validator → `false`, Validator → `null` (passa ai metodi specifici). `viewAny/view` coerenti con i criteri di `indexQuery()`. Registrata in `AppServiceProvider`.
- [ ] **[COMPLETAMENTO]** `App\Nova\Filters\LayerReportFilter`: Nova Filter visibile solo agli Administrator. Dropdown popolato con i layer che hanno almeno un UgcPoi con `properties->>'layer_id'` valorizzato. `name->it` come label. Filtro non selezionato = nessuna restrizione aggiuntiva. Applicato in `filters()` di `App\Nova\UgcPoi`.

## Rischi

- **`layer_id` non popolato in record storici:** segnalazioni create prima del ticket #7641 non hanno `layer_id` in `properties`. Un Validator non le vedrà. Comportamento accettato.
- **Validator senza layer:** lista vuota con `whereRaw('1=0')` — sicuro e deliberato.
- **`before()` del package bypassa tutto:** la policy locale sovrascrive esplicitamente `before()` con gestione Administrator/Validator/altri — non dipende dall'ordine di registrazione.
- **Logica duplicata Policy / indexQuery:** i criteri devono restare identici — verificato con test multi-ruolo.
- **Rollback `read_at`:** la migration ha `down()` con `dropColumn`. I dati di lettura vengono persi in caso di rollback — accettato consapevolmente (dato operativo non critico).

## Out of scope

- FK `layer_id` sulla tabella `ugc_pois` (si usa `properties` JSON)
- `read_at` su `ugc_tracks` o altre tabelle UGC
- Vista Validator su POI (`form->id = 'poi'`)
- Filtro per singolo layer attivo — il Validator vede tutti i suoi layer
- Feature flag per disabilitare selettivamente parti della feature

## Moduli toccati

**Repo principale `camminiditalia`:**
- `app/Nova/UgcPoi.php` — override `indexQuery()`, aggiunta badge e actions, aggiunta `filters()`
- `app/Models/UgcPoi.php` — nuovo, estende il modello del package con `read_at`
- `app/Policies/UgcPoiPolicy.php` — nuovo, policy custom per questo progetto
- `app/Providers/AppServiceProvider.php` — registrazione della nuova policy
- `app/Nova/Actions/MarkAsRead.php` — nuovo, action bulk
- `app/Nova/Actions/MarkAsUnread.php` — nuovo, action bulk
- `database/migrations/xxxx_add_read_at_to_ugc_pois_table.php` — nuovo (+ indice su `properties->>'layer_id'`)
- `app/Nova/Filters/LayerReportFilter.php` — **nuovo**, filter per layer visibile solo agli Administrator

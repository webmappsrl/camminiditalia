> Ticket: oc:8464

# Config app scritta sullo shard sbagliato: SHARD_NAME fa fallback silenzioso su APP_NAME

## Cosa cambia

`.env-example` documenta, con righe commentate (non attive), le variabili `SHARD_NAME` e la nuova `ANALYTICS_SHARD_NAME` introdotta in `wm-package` — nessun valore viene effettivamente impostato o cambiato in nessun ambiente. Aggiornato il puntatore del submodule `wm-package` al commit con il fix, dopo il merge su `wm-package`.

## Perché

Il fix applicativo vive interamente in `wm-package` (vedi `wm-package/docs/features/8464-shard-name-fallback-app-name/overview.md` per il dettaglio completo). Questo repo riceve solo l'aggiornamento di documentazione in `.env-example`, per rendere visibile ai prossimi dev l'esistenza di entrambe le variabili senza modificare alcun comportamento di default attuale.

## Requisiti

- [ ] `.env-example`: aggiungere righe commentate per `SHARD_NAME` e `ANALYTICS_SHARD_NAME`, ciascuna con un breve commento che ne spiega lo scopo (storage/link vs filtro query PostHog)
- [ ] Aggiornare il gitlink del submodule `wm-package` al commit con il fix, dopo il merge su `wm-package`

## Rischi

Emersi da challenge adversariale. Dettaglio completo (inclusi i rischi lato codice) in `wm-package/docs/features/8464-shard-name-fallback-app-name/overview.md`. Specifico a questo repo:

- **Il fix può essere percepito come "attivo" quando è solo opt-in.** L'unico intervento qui sono righe commentate in `.env-example` — il problema originale del ticket (query PostHog su ambienti non-prod puntano allo shard sbagliato) resta identico in produzione finché nessuno imposta manualmente la nuova variabile. Va reso esplicito nelle note di chiusura ticket che il comportamento di default non cambia in nessun ambiente.
- **Rischio di drift tra `.env-example` e il codice puntato dal gitlink del submodule.** Se il bump del gitlink avviene in un momento diverso dal merge upstream del fix, `.env-example` potrebbe documentare una variabile non ancora supportata dal codice effettivamente puntato (o viceversa). Mitigazione: aggiornare gitlink e `.env-example` nello stesso commit/PR.
- **Titolo del ticket più ampio del fix reale** (vedi overview `wm-package`) — chi legge solo `.env-example` senza consultare il ticket completo potrebbe assumere che anche il problema di scrittura su storage sia stato risolto qui.

## Out of scope

- Valorizzare effettivamente `SHARD_NAME`/`ANALYTICS_SHARD_NAME` in `.env` locale o in `.env.testing` — resta a discrezione di ogni dev/ambiente, non fa parte di questo ticket
- Tutto il resto come da overview `wm-package` (SHARD_NAME esplicito per ambiente, rimozione fallback silenzioso, verifica config di produzione corrotte, backport su develop/main)

## Moduli toccati

- `.env-example`
- `wm-package` (gitlink del submodule, dopo il merge upstream)

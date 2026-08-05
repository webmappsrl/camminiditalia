> Ticket: oc:8169

# Esperienze utente su cammino con foto e descrizione

> Wireframe interattivo (verificato contro l'app reale, loggata): https://claude.ai/code/artifact/24515214-ba97-4f8d-96fd-496b6bee3576
>
> Overview gemella (frontend): `wm-core/docs/features/8169-esperienze-utente-cammino-foto-descrizione/overview.md`

## Cosa cambia

Un nuovo form UGC `feedback` si aggiunge a `poi_acquisition_form` (config app camminiditalia), accanto ai già esistenti `report` e `poi`. I feedback sono `UgcPoi` con `properties.form.id = 'feedback'`, gestiti in Nova con la stessa filosofia delle segnalazioni esistenti: il Validator vede solo i feedback dei propri layer, l'Administrator vede tutto — riusando `UgcPoiPolicy` esistente.

## Perché

Il cliente vuole raccogliere i racconti dei camminatori come patrimonio della community. Il nome generico "Feedback" (form.id) permette ad altri shard di riusarlo con label e form diversi.

## Requisiti

- [ ] Nuovo form `feedback` in `poi_acquisition_form` (config app, camminiditalia): schema con `description` (textarea, `required:false`) — stesso pattern di `report`/`poi`; nessun campo "photos" nello schema (le foto restano fuori-schema, come per gli altri form)
- [ ] `App\Nova\UgcPoi::filteredQueryForValidator()` — estendere la whitelist oggi hardcoded su `'report'` per includere anche `'feedback'` (Validator vede i propri feedback per layer)
- [ ] Verificare che il filtro Select "tipo form" in Nova (pattern `FormSchemaFilter`/`HasLayerFilterAndLink`, wm-package) includa `'feedback'` automaticamente, essendo derivato dinamicamente da `poi_acquisition_form`
- [ ] `App\Policies\UgcPoiPolicy::view()`/`viewAny()` — aggiungere lo scoping per layer (oggi verificano solo `hasRole('Validator')`, senza controllare che il layer appartenga al Validator; lo scoping esiste solo in `filteredQueryForValidator()`, la lista, non nell'autorizzazione di accesso diretto a una singola risorsa). Gap preesistente anche per `report`, ma con `feedback` il contenuto esposto (racconti+foto personali) rende il gap più rischioso — deciso di risolverlo in questo ciclo dato che si tocca comunque `UgcPoi.php`/Policy

## Rischi

- **`filteredQueryForValidator()` ha la whitelist `'report'` cablata direttamente nel metodo** (non parametrica) — estenderla con una seconda stringa hardcoded (`['report', 'feedback']`) funziona ma accumula debito: se in futuro si aggiungono altri `form.id` da mostrare ai Validator, va generalizzato. Non risolto in questo ciclo salvo diversa indicazione del developer.
- **Nessuna modifica a `UgcObserver` richiesta** — verificato nel codice (`App\Observers\UgcObserver::created()`) che l'invio email ai gestori è già filtrato esplicitamente su `$formId !== 'report'` (return anticipato per qualsiasi altro form.id, incluso `feedback`). Comportamento corretto per una feature "community" senza notifica — nessun rischio di regressione qui, segnalato solo per chiarezza in review.
- **Colonna `geometry` di `ugc_pois` è NOT NULL** (verificato in migration, nessun `->nullable()`) — un'Esperienza creata dal frontend senza un pin GPS esplicito deve comunque fornire una geometria valida lato backend. Decisione presa in Fase: challenge (vedi overview wm-core): si riusa la posizione GPS reale dell'utente al momento della pubblicazione, come per report/poi, ma questo dato **non va mai esposto in nessuna vista mappa/pubblica lato backend** (API, export, futura pubblicazione community) — va trattato come metadato tecnico interno, non come posizione intenzionale dell'utente. Nessuna modifica di schema richiesta qui (nessun rilassamento del vincolo NOT NULL), ma qualsiasi endpoint futuro che espone `geometry` di `UgcPoi` deve escludere esplicitamente i record `form.id = 'feedback'` da eventuali vista mappa pubbliche.
- **Debito tecnico noto, non risolto in questo ciclo** (accettato in Fase: challenge): `FormSchemaFilter` (wm-package) deriva le opzioni del filtro Nova dalla *label* del form, non dall'`id` — un Administrator che vede più app rischia, in caso di collisione di label tra app diverse, un filtro che punta al `form.id` sbagliato senza errore. Rischio a bassa probabilità, non introdotto da questo ticket.
- **Debito tecnico noto, non risolto in questo ciclo**: un'Esperienza senza `layer_id` risolvibile (traccia orfana, layer cancellato) resta visibile solo all'Administrator, in un limbo silenzioso — comportamento invariato rispetto a `report` oggi.

## Out of scope

- Pubblicazione/visibilità pubblica dei feedback fuori dal contesto community loggato (ticket futuro)
- Moderazione/approvazione da parte del gestore
- Generalizzazione della whitelist hardcoded in `filteredQueryForValidator()` (debito tecnico noto, non risolto)

## Moduli toccati

**camminiditalia:**
- Configurazione form `feedback` (config app / `poi_acquisition_form`)
- `app/Nova/UgcPoi.php` (`filteredQueryForValidator()`)
- `app/Policies/UgcPoiPolicy.php` (`view()`, `viewAny()`) — scoping per layer

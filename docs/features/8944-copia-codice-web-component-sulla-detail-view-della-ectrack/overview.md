> Ticket: oc:8944

# Copia codice web component sulla detail view della EcTrack

## Cosa cambia

Il bottone «Copia codice web component» compare anche sulla detail view della Resource EcTrack, con una select del layer quando la traccia ne ha più di uno. Il grosso è in wm-package (vedi `wm-package/docs/features/8944-copia-codice-web-component-sulla-detail-view-della-ectrack/overview.md`). In questo repo si restringe la select ai layer che l'utente può vedere in Nova.

Il branch del package parte da `develop`, che contiene oc:8910 (tassonomie, sezione «Filters» sul Layer, sezione «Taxonomies» sulla EcTrack): il gitlink di oc:8944 porta quindi in camminiditalia anche oc:8910, e la sua integrazione si fa qui (deciso col dev il 06/11/2026). Riferimento: `wm-package/docs/knowledge/8910-tassonomie-cosa-controllare-al-bump-dello-shard.md`.

## Perché

Stessa regola della index della Resource Layer locale (`app/Nova/Layer.php::indexQuery()`): un non-Administrator vede solo i layer di cui è owner. Una select che proponesse layer non suoi darebbe al Validator uno snippet di un cammino che non può aprire né controllare. Caso reale sul DB locale: le EcTrack 522, 608 e 611 (utente 1421) stanno nel layer 47 «Cammino dei Tre Villaggi» (owner 1421) e nel layer 63 con lo stesso nome e senza owner.

## Requisiti

- [ ] `App\Nova\EcTrack` sovrascrive il metodo dei layer proponibili del package, partendo dal risultato di `parent::` (che applica già il filtro sul flag dell'App) e marcato `#[\Override]`: Administrator tutti, ogni altro ruolo solo i layer con `user_id` uguale al proprio
- [ ] Validator 1421 sulla EcTrack 522: nessuna select, il bottone copia lo snippet del layer 47; Administrator sulla stessa traccia: select con le option «Cammino dei Tre Villaggi (#47)» e «Cammino dei Tre Villaggi (#63)»
- [ ] Gitlink `wm-package` aggiornato al commit con la modifica
- [ ] Test in `tests/Feature/` sul filtro per ruolo
- [ ] oc:8910: le 3 migration (`zz_2026_11_02_000001_create_taxonomy_terms_table`, `zz_2026_11_02_000002_create_taxonomy_termables`, `zz_2026_11_03_000003_create_app_taxonomy_term_table`) pubblicate con `wm-package:publish-missing-migrations` e versionate in `database/migrations/`
- [ ] oc:8910: la sezione «Filters» (relazioni `taxonomyActivities`, `taxonomyWheres`, `taxonomyTerms`) non compare nella detail view né nel form della Resource Layer, come già attività e zone (oc:8311): su camminiditalia la modalità auto assegna per owner, mentre un filtro salvato lancerebbe `SyncAutoLayerAfterTrackTaxonomyChangeJob` → `assignTracksByTaxonomy()`
- [ ] oc:8910: la sezione «Taxonomies» è nascosta anche sulle Resource EcTrack ed EcPoi, con l'override `taxonomySectionFields(): []` (non viene nemmeno costruita); sulla EcPoi i tipi di POI restano nel loro MorphToMany. EcTrack (decisione del dev dopo averne visto il contenuto: tab Zone in sola lettura e tab Attività; in produzione 5 EcTrack su 1317 hanno un'attività, le zone sono già mostrate da oc:8588)
- [ ] Note di deploy nella PR: `migrate`, `php artisan scout:import "App\Models\EcTrack"` (mapping del campo `terms` cambiato, Elasticsearch), riavvio di Horizon

## Rischi

- Il campo `Layers` della EcTrack è già nascosto al Validator (`app/Nova/EcTrack.php:84`): la select è l'unico punto in cui vede i layer della traccia, e mostra solo i suoi.
- Rilascio vincolato a oc:8937 (vedi overview del package).

- **Tappe dei Validator senza bottone (accettato col dev)**: in produzione (06/11/2026, query read-only) 40 EcTrack di Validator su 520 stanno solo in layer non loro (35 dell'Administrator, utente 2; 5 senza owner): su quelle il Validator non vede il Field. L'Administrator le vede tutte. Scelta mantenuta per coerenza con `Layer::indexQuery()`.
- **oc:8910 portato dal bump**: senza le migration la detail view del Layer va in errore; il mapping Elasticsearch del campo `terms` è cambiato. Mitigazione: migration nello stesso commit del gitlink, passi di deploy nella PR, verifica di Layer ed EcTrack in Nova. Già verificati senza lavoro: nessun modello `App` locale (niente collisione su `taxonomies()`), `App\Models\EcTrack` eredita `TaxonomyAbleModel`, `REDIS_CLIENT=predis` in locale e in produzione.

## Out of scope

- Correzione dei layer duplicati senza owner (es. 63)
- Modifiche al widget (oc:8937)

## Moduli toccati

- `app/Nova/EcTrack.php`
- `tests/Feature/` — test nuovi
- gitlink `wm-package`
- `app/Nova/Layer.php` — sezione «Filters» nascosta
- `app/Nova/EcTrack.php`, `app/Nova/EcPoi.php` — sezione «Taxonomies» nascosta
- `database/migrations/` — 3 migration di oc:8910

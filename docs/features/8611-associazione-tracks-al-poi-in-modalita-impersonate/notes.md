> Ticket: oc:8611

# Notes — Associazione TRACKS al POI in modalità impersonate

## Deviazioni dal piano

Nessuna deviazione sul codice di produzione: `app/Nova/EcPoi.php` e `app/Nova/EcTrack.php` sono stati modificati esattamente come da `plan.md`.

Due correzioni al codice dei **test** durante l'esecuzione del Task 1 (non previste dal piano, causate da assunzioni sbagliate scritte da me nel piano stesso, non da errori nel codice di produzione):

- `makeEcPoi()` aveva il return type `\App\Models\EcPoi`, ma `App\Models\EcPoi::factory()` restituisce in realtà un'istanza di `Wm\WmPackage\Models\EcPoi` (trappola `HasPackageFactory` già documentata in `wm-package/CLAUDE.md` — `App\Models\EcPoi` non sovrascrive `newFactory()`). Corretto l'hint a `Wm\WmPackage\Models\EcPoi`.
- `novaRequestFor()` impostava solo il resolver della `NovaRequest`, ma `Wm\WmPackage\Nova\AbstractEcResource::indexQuery()` (il codice che il fix aggira, non quello che lo sostituisce) legge `Auth::user()` (facade), non `$request->user()`. Senza autenticare anche la sessione reale (`$this->actingAs($user)`), il filtro per `app_id` veniva bypassato e il test non riproduceva correttamente il bug prima del fix. Aggiunta l'autenticazione reale nell'helper.

## Bug trovati

Nessun bug nuovo trovato durante l'implementazione — tutti i bug (compreso quello sul campo "Layers") erano già stati identificati in `overview.md` durante la Fase: challenge, prima di scrivere il piano.

## Decisioni

- Test scritti a livello di `fields()`/`indexQuery()` diretto (come da pattern già in uso in `EcTrackIndexQueryTest`/`EcPoiIndexQueryTest`/`LayerAttributeFieldsTest`), non tramite hit HTTP sull'endpoint Nova `attachable` — più deterministico e coerente con le convenzioni esistenti nel repo, evita di dover ricostruire a mano i parametri interni di `AttachableController` (`component`, `viaRelationship`) che sono un dettaglio implementativo fragile di Nova.
- **Review finale del branch** eseguita da un reviewer isolato (Opus, nessun contesto sulla conversazione), come richiesto da `superpowers:executing-plans`. Verdetto: "Ready to merge: With fixes". Nessun Critical. Due Important: (1) PHPStan falliva su 2 righe del test nuovo (`function.alreadyNarrowedType` su `property_exists()` — sostituito con `instanceof BelongsToMany`, verificato RED→GREEN, suite 10/10 dopo); (2) riga mancante in `CLAUDE.md` — risolta in questa stessa Fase: update-context. Il reviewer ha verificato autonomamente nel codice vendor di Nova l'intera catena causale (nessuna collisione di `uriKey` tra classi locali e del package, nessuna variazione dell'autorizzazione di attach, campi effettivamente top-level nell'array `fields()`), non si è limitato a fidarsi dell'overview.
- **Minori rimandati** (nessuna azione in questo ciclo): i test chiamano `indexQuery()` direttamente invece del percorso completo `buildAttachableQuery()`/HTTP di Nova — rafforzerebbe la copertura ma il ragionamento per evitarlo (fragilità dei parametri interni Nova) resta valido; il rebuild del field (`BelongsToMany::make(...)`) invece di mutare `resourceClass`/`resourceName` perde eventuali futuri modificatori aggiunti dal package — annotato in `docs/knowledge/scoping-validator-risorse-nova.md`; nessun null-guard su `$request->user()` in `indexQuery()` locale — preesistente da oc:8587, non introdotto qui.
- **Verifica manuale del dev**: confermato funzionante sullo scenario reale del ticket, dopo ripristino del backup di produzione (`storage/backups/last_dump.sql.gz`) nel DB di sviluppo per riprodurre le condizioni esatte segnalate dal cliente.

## Follow-up

- **Suite PHPUnit**: `Tests\Feature\RecalculateLayerAttributesJobTest::handle_writes_filters_and_dispatches_config_regeneration` fallisce in modo order-dependent quando eseguito nella suite completa (verificato: passa sempre in isolamento; fallisce anche con le modifiche di questo ticket stashate/rimosse, quindi non è una regressione introdotta da oc:8611). Probabile causa: lock Redis univoco (`UniqueLock`, già menzionato per lo stesso job in `CLAUDE.md` → oc:8182) che sopravvive da un test precedente nell'ordine di esecuzione della suite. Non corretto in questo ciclo (fuori scope, causa esterna a questo fix) — segnalato al dev, nessun ticket separato creato su richiesta esplicita.
- Rimane, come discusso in Fase: challenge, il rischio residuo che altri campi Nova del package (referenziati senza `use` esplicito) abbiano lo stesso bug su altri consumer di `wm-package` — non affrontato in questo ciclo (un repo per ciclo, stesso principio di oc:8587). Documentato in `docs/knowledge/scoping-validator-risorse-nova.md`.
- Campi verso EcTrack/EcPoi sulle risorse Taxonomy (`TaxonomyTheme`, `TaxonomyActivity`) potrebbero avere lo stesso bug — non verificato se il Validator ha effettivamente accesso a quelle risorse in Nova (nessuna policy locale le blocca esplicitamente). Segnalato dal reviewer, non affrontato in questo ciclo.

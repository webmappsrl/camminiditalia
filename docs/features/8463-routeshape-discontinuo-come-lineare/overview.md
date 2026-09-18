> Ticket: oc:8463

# RouteShape: esporre Discontinuo come Lineare al frontend, tenere l'alert solo in Nova

## Cosa cambia
- Il valore pubblico `properties->attributes->shape` calcolato per ogni Layer non conterrà mai più `discontinuous`: quando un cammino risulta discontinuo (tappe con buchi oltre la soglia di 450m), viene persistito come `linear`.
- Un nuovo flag booleano interno `properties->attributes->shape_discontinuous` (persistito solo quando `true`, altrimenti assente — nessuna traduzione multilingua) marca i layer discontinui, letto esclusivamente da Nova: la card "Route shape" resta sempre verde e mostra il valore pubblico reale (Linear/Roundtrip), con un piccolo box di alert interno alla stessa card quando il layer è discontinuo — visibilità invariata (tutti i ruoli che vedono la scheda Layer, Administrator e Validator/gestore — comportamento identico a oggi). Design iterato due volte durante l'esecuzione in base al feedback visivo del dev: vedi notes.md.
- Il flag interno viene escluso esplicitamente sia dal payload di `config.json` sia dall'endpoint `GET /{app}/layer/{layer}` (submodule `wm-package`, entrambi oggi fanno passthrough integrale di `properties` senza whitelist) — vedi `wm-package/docs/features/8463-routeshape-discontinuo-come-lineare/overview.md` per la parte di competenza del submodule.
- Ricalcolo dei 118 layer esistenti dopo il deploy tramite l'azione Nova già presente `RecalculateAppLayerAttributesAction` (nessun nuovo comando Artisan).

## Perché
Emerso in call del 03/09/2026 con Davide Nanna (Cammini d'Italia), formalizzato in oc:8463 (rif. oc:8180): per il cliente "discontinuo" non è una tipologia di cammino, ma un difetto dei dati caricati (tappe con gap oltre soglia, soglia di 450m fissata in call 2026-08-27 da Giuseppe Bonfanti e validata da Alessio Piccioli, resa parametro configurabile). Va quindi mostrato come `linear` all'utente finale, restando visibile come alert solo a chi gestisce i dati in Nova.

## Requisiti
- [ ] `LayerAttributesService::computeCalculatedValues()` non scrive mai `discontinuous` nel valore pubblico `shape`; per un layer con topologia discontinua scrive `shape=linear` (con traduzioni, come oggi) + `shape_discontinuous=true` (booleano semplice).
- [ ] `shape_discontinuous` aggiunto a `CALCULATED_KEYS` per partecipare al meccanismo di pulizia dei valori stale già esistente in `persistCalculatedValues()`.
- [ ] `App\Nova\Layer::renderShapeCard()` mostra sempre il valore pubblico reale (Linear/Roundtrip), card sempre verde/ok; quando il layer è discontinuo aggiunge un box di alert (icona + spiegazione) dentro la stessa card, senza cambiarne lo stato né sostituirne il valore.
- [ ] L'alert resta visibile a tutti i ruoli che possono vedere la scheda Layer in Nova — nessuna restrizione di ruolo aggiuntiva rispetto a oggi.
- [ ] `wm-package`: sia `AppConfigService::config_section_map()` (per `config.json`, blocco `MAP.layers[]`) sia `AppController::layer()` (endpoint `GET /{app}/layer/{layer}`, che fa `$layer->toArray()` senza filtri) escludono le chiavi elencate in `config('wm-package.internal_attribute_keys')` — meccanismo generico, non specifico di `shape_discontinuous`; camminiditalia popola quella lista tramite un proprio `config/wm-package.php` (override versionato via `mergeConfigFrom()`, non un `.env` — scelta finale, vedi notes.md) — dettaglio e design nell'overview del submodule.
- [ ] Test aggiornati in `tests/Feature/LayerAttributesStatePanelTest.php`: un layer discontinuo continua a mostrare il warning in Nova; `properties->attributes->shape` persistito è sempre `linear`/`roundtrip`, mai `discontinuous`.
- [ ] Test di integrazione end-to-end (repo principale) che verifica il `config.json` finale generato per un layer discontinuo: `shape` vale `linear`, `shape_discontinuous` non compare — copre l'accoppiamento cross-repo sulla stringa `shape_discontinuous` duplicata tra i due repo.
- [ ] Ricalcolo dei layer esistenti eseguito via `RecalculateAppLayerAttributesAction` sull'unica App del progetto dopo il deploy (procedura operativa, non codice — da annotare come step post-deploy), seguito da una verifica manuale (query SQL: nessun layer con `properties->attributes->shape = 'discontinuous'`) e da un rilancio manuale di `UpdateAppConfigJob` se la verifica trova residui.

## Rischi
- **Punto di esclusione ambiguo** (critico): il loop di flattening in `config_section_map()` itera sulle chiavi di *primo livello* di `properties` (`attributes` è una di queste, `shape_discontinuous` sta un livello più sotto). Un filtro posizionato ingenuamente a livello del loop non intercetterebbe mai la chiave, lasciando il flag esposto. Mitigato: requisito riscritto esplicitamente come esclusione mirata dentro `attributes`, con test sul nesting reale.
- **Secondo canale di esposizione non coperto**: `AppController::layer()` (wm-package) fa passthrough integrale via `toArray()`, bypassando `config_section_map()`. Mitigato: estesa la stessa esclusione anche lì, centralizzata in un helper condiviso lato wm-package.
- **Accoppiamento cross-repo su stringa duplicata**: `shape_discontinuous` è scritta in camminiditalia e letta/esclusa in wm-package come stringa letterale duplicata, senza costante condivisa. Mitigato: test di integrazione end-to-end nel repo principale sul `config.json` finale.
- **Ordine di deploy obbligato**: il merge/bump di wm-package deve precedere il deploy del repo principale che inizia a scrivere il flag — stesso pattern di un incidente già occorso in team (episodio maphub, `wm-package/CLAUDE.md`). Documentato esplicitamente come step della procedura di rilascio.
- **Race condition nel ricalcolo bulk** (preesistente, non introdotta da questo ticket): `RecalculateAppLayerAttributesAction` dispatcha `UpdateAppConfigJob` senza attendere il completamento di tutti i job di ricalcolo per layer — `config.json` può essere rigenerato con dati parziali. Non si corregge il meccanismo di dispatch (fuori scope); mitigato con una verifica manuale post-deploy (vedi requisito ricalcolo sopra).
- **Rollback e consumer esterni** (rischio accettato, non mitigato in questo ciclo): dati puramente derivati (nessun backup necessario, un rollback si risolve rieseguendo il ricalcolo con codice precedente); nessuna finestra di deprecazione per eventuali consumer esterni che leggessero `"discontinuous"` letterale — decisione di prodotto già presa dal cliente in call (Davide Nanna), non una scelta tecnica di questo ticket.

## Out of scope
- Rename dell'enum/traduzione "continuo→lineare": non necessario, la label italiana "Lineare" è già corretta (verificato in `resources/lang/it.json`) — era un lapsus verbale in call.
- Modifiche alla soglia di 450m per la rilevazione di discontinuità (parametro già esistente e configurabile, non toccato da questo ticket).
- Nessuna modifica ai filtri frontend (oc:8414): l'esposizione passa già attraverso `properties->attributes->shape`, che dopo il fix conterrà sempre e solo `linear`/`roundtrip` — nessun intervento aggiuntivo necessario lato consumo.
- Nessun nuovo comando Artisan di ricalcolo bulk: si riusa l'azione Nova esistente.
- Nessuna modifica al blocco `MAP.filters.layers` (il filtro select "tipologia" in `AppConfigService`, righe 590-621): non espone `shape` oggi e non ne ha bisogno per questo fix.

## Moduli toccati
**Repo principale (camminiditalia):**
- `app/Services/LayerAttributesService.php`
- `app/Nova/Layer.php`
- `tests/Feature/LayerAttributesStatePanelTest.php`
- `tests/Feature/LayerConfigJsonShapeDiscontinuousTest.php` (nuovo, end-to-end)
- `tests/Unit/Services/LayerAttributesServiceShapeDiscontinuousTest.php` (nuovo)
- `config/wm-package.php` (nuovo — override versionato di `internal_attribute_keys`, non un `.env`)
- `app/Jobs/RecalculateLayerAttributesJob.php` (fix non correlato trovato in test manuale: `uniqueVia()` su Redis, vedi notes.md)
- `resources/lang/it.json`, `resources/lang/en.json` (testo dell'alert Nova + una lacuna di traduzione mancante in `en.json` trovata durante il test manuale)

**Submodule wm-package** (vedi overview dedicata):
- `src/Services/Models/App/AppConfigService.php`

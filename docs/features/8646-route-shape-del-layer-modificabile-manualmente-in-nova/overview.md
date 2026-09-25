> Ticket: oc:8646

# Route shape del layer modificabile manualmente in Nova

## Cosa cambia

Nel form di **edit** del Layer in Nova (pannello "Manual attributes") compare un nuovo campo Select
"Route shape" con tre opzioni: **Automatico** (default), **Lineare**, **Ad anello**.

- Scegliendo Lineare o Ad anello, il **solo codice** (`linear` / `roundtrip`) viene salvato in una
  chiave manuale separata `properties->attributes->shape_manual`, che non fa parte delle chiavi
  calcolate (`LayerAttributesService::CALCULATED_KEYS`) e quindi non viene mai toccata dal ricalcolo
  automatico. Il nome della chiave è una costante `LayerAttributesService::SHAPE_MANUAL_KEY`.
- Il valore pubblico `properties->attributes->shape` diventa il **valore finale**: quello manuale
  se presente, altrimenti quello calcolato dalle tappe. Il calcolo in
  `LayerAttributesService::computeCalculatedValues()` tiene conto di `shape_manual`.
- Scegliendo Automatico, `shape_manual` viene rimosso e `shape` torna al valore calcolato.
- Il `shape` finale viene scritto **in modo sincrono** già nella closure `fillUsing` del campo
  (override → valore manuale; Automatico → valore ricalcolato al momento), così la detail view
  mostra subito il valore salvato senza dipendere dalla coda. Il job di ricalcolo resta invariato
  per la rigenerazione asincrona del `config.json`.
- Le etichette tradotte di `shape` sono sempre costruite con `withTranslations()` a partire dal
  codice, anche quando il valore è manuale.
- Al frontend (`config.json` e `GET /api/app/webapp/{app}/layer/{layer}`) arriva **una sola
  informazione**, `shape` finale: `shape_manual` viene escluso aggiungendolo a
  `config('wm-package.internal_attribute_keys')` in `config/wm-package.php`, lo stesso meccanismo
  già usato per `shape_discontinuous` (oc:8463). Nessuna modifica a `wm-package`.
- La card "Route shape" della detail view non cambia nel codice: legge `shape`, quindi mostra già
  il valore finale; l'alert "Discontinuous" resta legato al flag calcolato `shape_discontinuous`.
- Nessuna astrazione generica "chiave calcolata → chiave manuale": il meccanismo è specifico di
  `shape`; si generalizza solo quando arriverà un secondo attributo con override.

## Perché

Il calcolo automatico della tipologia (`determineType()` sulla topologia delle tappe) in alcuni casi
sbaglia: il "Cammino Minerario di Santa Barbara" (layer id 56) è ad anello, ma un tratto staccato
sull'isola lo fa risultare discontinuo e quindi viene esposto come Lineare. Allo scrum del 25/09/2026
Giuseppe Bonfanti ha deciso di non affinare ulteriormente l'algoritmo («un errore ci può essere
sempre») ma di permettere una correzione manuale: «loro identificano che c'è problema, lo cambiano e
via». Il vincolo emerso nella stessa call è che il ricalcolo automatico non deve sovrascrivere il
valore corretto a mano — e oggi il ricalcolo parte a ogni salvataggio del layer (`LayerObserver::saved()`).

## Requisiti

- [ ] Nuovo campo Select "Route shape" nel pannello "Manual attributes" di `App\Nova\Layer`, visibile
      solo in edit (non in index/detail/create), con opzioni Automatico / Lineare / Ad anello;
      "Discontinuo" non è selezionabile
- [ ] In edit il campo mostra il valore manuale corrente (`shape_manual`), o Automatico se assente
- [ ] Lineare / Ad anello → `shape_manual` persistito come solo codice stringa, tramite
      `persistManualValue()`; valore validato con `RouteShape::tryFrom()`, `discontinuous` e valori
      non validi trattati come assenza di override (sia in scrittura dal campo sia in lettura nel calcolo)
- [ ] Nel salvataggio dal campo, anche `shape` finale viene scritto subito (sincrono), con
      etichette da `withTranslations()`
- [ ] Nel salvataggio dal campo, se `shape` finale è cambiato, viene accodato esplicitamente
      `UpdateAppConfigJob` per l'App del layer: la scrittura sincrona via SQL non fa scattare
      `LayerObserver::saved()` del package (che rigenera solo su `wasChanged('properties')`), e il
      job di ricalcolo successivo troverebbe i valori già allineati e salterebbe la rigenerazione
      (`calculatedValuesAreUnchanged()`)
- [ ] Automatico → `shape_manual` rimosso
- [ ] `computeCalculatedValues()` produce `shape` = valore manuale se presente, altrimenti calcolato;
      `shape_discontinuous` resta calcolato dalle tappe, indipendentemente dall'override
- [ ] Un cambio del solo override (senza modifiche alle tappe) viene propagato: `shape` persistito
      aggiornato e `config.json` rigenerato (il job oggi salta tutto se i valori calcolati sono invariati)
- [ ] Un ricalcolo successivo (azione Nova `RecalculateAppLayerAttributesAction`, modifica tappe,
      salvataggio del layer) non altera un override presente
- [ ] `shape_manual` non compare mai in `config.json` né nella risposta di `AppController::layer()`
      (aggiunto a `internal_attribute_keys` in `config/wm-package.php`)
- [ ] Autorizzazione invariata: il campo è modificabile da chi può fare update del Layer
      (`LayerPolicy::update()` → di fatto Administrator; il Validator non può modificare i layer)
- [ ] Etichetta "Automatic" (e le altre nuove stringhe) tradotte in tutte le lingue presenti
      (`resources/lang/{it,en,fr,es,de}.json`); le etichette Lineare/Ad anello riusano `RouteShape::labelIn()`
- [ ] Test Feature:
      1. layer 56-like con override "Ad anello" → `config.json` e API espongono `shape = roundtrip`, senza `shape_manual`
      2. ricalcolo successivo non cambia il valore
      3. ritorno ad Automatico → torna il valore calcolato e `config.json` si aggiorna
      4. test di integrazione end-to-end sul `config.json` (sul modello di `LayerConfigJsonShapeDiscontinuousTest`)
      5. test che `SHAPE_MANUAL_KEY` sia presente in `config('wm-package.internal_attribute_keys')`
      6. test che un `shape_manual` sporco (`discontinuous`, valore sconosciuto) non arrivi al `shape` pubblico
      7. test che dopo il salvataggio dal campo `shape` sia già aggiornato senza eseguire il job
- [ ] Verifica manuale in Nova sul layer 56 nel DB di sviluppo

## Rischi

- **Il job salta la scrittura se i valori calcolati sono invariati** (`calculatedValuesAreUnchanged()`):
  se `shape_manual` non entra nel confronto, cambiare solo l'override non arriva mai al frontend.
  Mitigazione: `shape` finale è calcolato includendo `shape_manual`, quindi il confronto vede il
  cambiamento; coperto dal test 1 e 3.
- **Valore vecchio in detail subito dopo il salvataggio** (challenge): il job è in coda
  (`ShouldQueue`, Redis) e parte dopo la risposta HTTP, quindi la card mostrerebbe ancora il valore
  precedente, e con Horizon fermo non si aggiornerebbe mai. Mitigazione: `shape` finale scritto in
  modo sincrono nella closure `fillUsing`. L'ordine save/job è già coperto da `afterCommit = true`.
  Il `config.json` resta asincrono come oggi.
- **Etichette tradotte congelate nell'override** (challenge): mitigato salvando in `shape_manual`
  solo il codice e ricostruendo le traduzioni a ogni scrittura.
- **Typo tra la chiave scritta e quella dichiarata interna**: mitigato con la costante
  `SHAPE_MANUAL_KEY` (service + campo Nova) e un test che la cerca in `internal_attribute_keys`,
  più il test end-to-end sul `config.json`.
- **Rollback** (challenge): un revert del codice lascia `shape_manual` nel DB e `shape` col valore
  manuale fino al prossimo ricalcolo. Procedura documentata in `notes.md`: pulizia SQL
  `properties #- '{attributes,shape_manual}'`, `RecalculateAppLayerAttributesAction` sull'App,
  verifica del `config.json`.
- **Scelte consapevoli** (non mitigate): su un layer discontinuo con override "Ad anello" la card
  mostra anche l'alert "Discontinuous" (segnala un difetto dei dati delle tappe, non la tipologia);
  l'override resta finché non si rimette Automatico, anche se in seguito le tappe vengono corrette;
  su un layer senza tappe l'override vale comunque.

## Out of scope

- Override manuale per gli altri attributi calcolati (`distance`, `stage_count`, `taxonomy_where`, `themes`)
- Modifiche alla card "Route shape" della detail view (nessun badge "manuale", alert invariato)
- Miglioramenti all'algoritmo `determineType()` / alla soglia `JUNCTION_TOLERANCE_METERS`
- Impostare l'override sui dati esistenti (es. layer 56): lo farà il cliente da Nova
- Modifiche al submodule `wm-package` e al frontend

## Moduli toccati

Tutti nel repo principale (camminiditalia):

- `app/Services/LayerAttributesService.php` — `shape` finale da override manuale + calcolato
- `app/Nova/Layer.php` — nuovo campo Select in "Manual attributes", solo edit
- `app/Jobs/RecalculateLayerAttributesJob.php` — nessuna modifica prevista (la propagazione passa da `computeCalculatedValues()` e dal dispatch esplicito di `UpdateAppConfigJob` nel campo)
- `config/wm-package.php` — `shape_manual` in `internal_attribute_keys`
- `resources/lang/{it,en,fr,es,de}.json` — nuove etichette
- `tests/Feature/…` — nuovi test su override, ricalcolo e `config.json`

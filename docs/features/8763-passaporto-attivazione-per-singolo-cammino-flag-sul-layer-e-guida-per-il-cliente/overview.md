> Ticket: oc:8763

# Passaporto: attivazione per singolo cammino (flag sul layer) e guida per il cliente — backend

Parte backend. App e tipi: `docs/features/8763-…/overview.md` in `wm-core` e `wm-types`
(submodule di `webmapp-app`).

## Cosa cambia

Il passaporto del camminatore non vale più per tutti i cammini insieme: ogni cammino (layer) ha
un interruttore «Passaporto attivo», spento di default, che solo gli `Administrator` accendono da
Nova, anche in blocco. `GET /api/passport` diventa l'elenco dei cammini col passaporto acceso,
anche quelli senza tappe convalidate, con le tappe convalidate dall'utente e la loro data: l'app ne
ricava tab, badge, anelli e segni sulle tappe senza altre chiamate. Gli endpoint del singolo
cammino rispondono 404 sui cammini spenti. I dati già presenti restano e ricompaiono se il cammino
viene riacceso. Il cliente riceve una breve guida su come accendere i cammini.

## Perché

Cammini d'Italia vuole provare il passaporto con pochi partner fidati prima di estenderlo a tutti
(call del 12/10/2026: Giuseppe «ci sarà un attiva passaporto, lo selezioni ed è fatto», «ti
mandiamo comunque una mini guida»; Davide «io poi glielo accendo ai nostri partner più fidati,
così la iniziano a testare e poi la replichiamo per tutti gli altri»). L'interruttore non esiste.
È una delle tre modifiche da fare prima del rilascio in produzione previsto in settimana, insieme
a oc:8761 e oc:8762.

Già che `/api/passport` cambia, la sua risposta diventa sufficiente per tutto ciò che l'app
mostra fuori dal dettaglio: oggi l'app chiama `GET /layer/{id}/progress` per ogni card di cammino
in home e per ogni cammino iniziato nella ricerca.

## Requisiti

- [ ] **Flag sul layer**: booleano `passport_enabled` dentro `properties` del layer; assente o
  `false` = spento. Nessuna colonna e nessuna migration.
- [ ] **Nova, campo**: `Boolean` «Passaporto attivo» su `properties->passport_enabled` in
  `App\Nova\Layer`, modificabile solo dall'`Administrator` **a livello di campo** (in sola lettura
  per tutti gli altri): `LayerPolicy::update` lascia modificare il layer anche a un proprietario
  che non è `Validator`, quindi la policy da sola non basta.
- [ ] **Nova, modifica in blocco**: `BulkEditAction(self::class, ['properties->passport_enabled'])`
  in `App\Nova\Layer::actions()`, con `canSee`/`canRun` solo per `Administrator`, come in
  `App\Nova\EcPoi` (oc:8133).
- [ ] **`GET /api/passport`**: `routes` contiene **un elemento per ogni cammino acceso con almeno
  una tappa associata**, anche con 0 tappe convalidate, e **nessun cammino spento**. Un cammino
  acceso senza tappe non compare. Ogni elemento mantiene i campi di oggi
  (`layer_id`, `name`, `validated`, `total`, `percentage`, `completed`, `km_validated`,
  `km_total`, `last_validated_at`) e aggiunge `validated_tracks`: mappa id della tappa → data di
  convalida ISO 8601, per le tappe del cammino convalidate dall'utente (regola A di `StageProgressService`: una tappa condivisa vale in
  tutti i cammini accesi che la contengono). Una sola query aggregata, senza una query per cammino.
- [ ] **Endpoint del singolo cammino**: un middleware sul gruppo `Route::prefix('layer')` di
  `routes/api.php` risponde **404** se il layer non esiste o ha il passaporto spento. Copre
  certificazione (POST e GET), progress, condivisione della tappa e del cammino, e ogni endpoint
  aggiunto in futuro al gruppo.
- [ ] **Dati esistenti**: spegnere un cammino non cancella né modifica tappe convalidate,
  richieste e condivisioni; le richieste in attesa restano decidibili in Nova e la mail di esito
  parte; le pagine pubbliche delle condivisioni già create restano raggiungibili.
- [ ] **Traduzioni**: «Passaporto attivo» e le etichette nuove nei 5 json di `resources/lang/`.
- [ ] **Guida per il cliente**: `docs/guide/passaporto-attivazione/`, senza dettagli tecnici, con
  screenshot di Nova: come accendere e spegnere il passaporto di un cammino, singolarmente e in
  blocco; avviso che al rilascio è spento su tutti i cammini e che finché nessuno è acceso il tab
  Passaporto dell'app mostra solo un messaggio; avviso di verificare, prima di spegnere un
  cammino, che non abbia richieste di certificazione in attesa (il camminatore riceverebbe l'esito
  via mail senza vederlo nell'app finché il cammino resta spento). Una versione breve va nella risposta al cliente su
  Orchestrator.
- [ ] **Test**: 404 su ciascun endpoint del gruppo con il cammino spento e 2xx con il cammino
  acceso; `routes` con un cammino acceso senza tappe, un cammino spento con tappe convalidate
  (assente), un cammino acceso senza tappe (assente), una tappa condivisa fra un cammino acceso e
  uno spento; `validated_tracks` con id e date corretti; campo e action non modificabili da un
  `Validator` né da un proprietario non `Administrator`; dati intatti dopo lo spegnimento.

## Rischi

- **Tab Passaporto vuoto al rilascio**: con il default spento nessun cammino compare finché il
  cliente non lo accende. Mitigato dal messaggio nel tab (wm-core) e dalla guida; va detto a
  Davide alla consegna.
- **Il flag arriva anche nella config dell'app**: `AppConfigService` copia ogni chiave di
  `properties` nel layer della config. È innocuo perché l'app non lo usa; la modifica in blocco usa
  `saveQuietly()` e non rigenera la config, quindi lì il valore può restare vecchio: non va mai
  usato come fonte.
- **Costo di `/api/passport`**: con tutti i 118 cammini accesi restituisce 118 elementi per ogni
  utente. Resta una query aggregata; va controllato che non diventi una query per cammino.
- **Spegnimento con richieste in attesa** (raro: una volta acceso, un cammino difficilmente si
  spegne): la richiesta resta decidibile in Nova e la mail di esito parte, ma l'app non mostra
  il cammino finché resta spento. Mitigato con l'avviso nella guida, senza blocchi in Nova.
- **Rilascio e rollback legati all'app**: il contratto di `/api/passport` cambia significato
  (cammini accesi invece che iniziati) e forma (`validated_tracks`), e l'app non ha fallback. Un'app
  nuova contro il backend vecchio vede solo i cammini iniziati e nessun chip sulle tappe; un'app
  vecchia contro il backend nuovo mostra i cammini accesi come «in corso» allo 0%. Backend e app
  si rilasciano lo stesso giorno, con le build di test aggiornate. Un rollback del solo backend
  riaccende il passaporto su **tutti** i cammini: va fatto insieme a quello dell'app.
- **Ticket oc:8761 e oc:8762**: toccano lo stesso dominio prima dello stesso rilascio e lavorano
  sul branch `Passaporto`; vanno coordinati nello stesso ciclo di merge.

## Out of scope

- Annullare le richieste in attesa allo spegnimento di un cammino.
- Consentire ai gestori di accendere il proprio cammino.
- Usare il flag dalla config dell'app.
- Un messaggio dedicato nell'app quando un endpoint risponde 404 per un cammino spento: resta il
  messaggio d'errore generico.

## Moduli toccati

**camminiditalia**
- `app/Nova/Layer.php` (campo e `BulkEditAction`)
- `app/Http/Middleware/` (nuovo middleware per il cammino col passaporto acceso) e `bootstrap/app.php` se serve l'alias
- `routes/api.php` (middleware sul gruppo `layer`)
- `app/Services/StageProgressService.php` (`passportFor`, `validated_tracks`)
- `resources/lang/{it,en,de,fr,es}.json`
- `docs/guide/passaporto-attivazione/` (nuova)
- `tests/Feature/` (test nuovi e aggiornati: `StageProgressApiTest`, `StageProgressServiceTest`,
  `PassportRouteShareApiTest`, `PassportStageShareApiTest`, test della certificazione)

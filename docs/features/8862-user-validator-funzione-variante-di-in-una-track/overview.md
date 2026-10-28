> Ticket: oc:8862

# User validator - funzione "variante di" in una track

## Cosa cambia

Il campo «Variante di» della tappa (EcTrack) in Nova, introdotto da oc:8165 e finora visibile
solo all'Administrator, diventa disponibile anche al **Validator** (gestore di cammino) sulle
tappe che può modificare, cioè le sue (`EcTrackPolicy::update()`, `user_id`).

Per il Validator la lista delle possibili tappe principali contiene **solo le sue tappe**
(`user_id` uguale al suo) fra quelle dei cammini in cui sta la tappa, più la principale già
salvata, se c'è. Per l'Administrator la lista resta com'è oggi.

Il valore inviato viene ora controllato anche **lato server**, con errori di validazione di
Nova tradotti al posto dell'errore 500 che produce oggi la `RuntimeException` del model. Fra
questi controlli ci sono anche i tre già esistenti: variante di sé stessa, principale già
variante, tappa già principale di altre.

## Perché

Il cliente, durante la formazione degli enti gestori, ha visto che i Validator non possono
dichiarare le varianti delle loro tappe. Senza varianti un cammino con rami alternativi non
arriva mai al 100% nel passaporto. Oggi l'unico modo è passare dall'Administrator, che non
conosce i cammini quanto il gestore. La limitazione era voluta, in assenza di una regola
chiara sui permessi del Validator: come ha risposto Giuseppe al cliente, le funzioni si
aprono al Validator una alla volta, su richiesta esplicita. Il cliente l'ha chiesta per questa.

## Requisiti

- [ ] Il campo «Variante di» è visibile e modificabile dal Validator sulle sue tappe, con la
      stessa eccezione che vale per l'Administrator: resta nascosto su una tappa che è già
      principale di altre varianti.
- [ ] Per il Validator le opzioni sono le tappe dei cammini della tappa corrente (stessa regola
      di oggi: tolte la tappa stessa e quelle già varianti) **con `user_id` uguale al suo**.
- [ ] Per l'Administrator le opzioni non cambiano: tutte le tappe dei cammini della tappa,
      di qualunque proprietario.
- [ ] Se la tappa è già variante di una principale che il Validator non possiede (collegamento
      creato dall'admin), la principale attuale compare comunque fra le opzioni e il Validator
      può salvare la tappa senza toccare il campo, oppure svuotarlo.
- [ ] **Tutti i controlli nuovi scattano solo se il valore cambia** rispetto a quello salvato:
      un collegamento invariato non viene validato né riscritto. Così un collegamento rimasto
      con una principale tolta nel frattempo dal cammino (nessun observer pulisce
      `ec_track_variants`) non blocca il salvataggio della tappa, né per il Validator né per
      l'Administrator.
- [ ] Lato server il valore inviato, se cambia, viene validato contro le stesse regole delle
      opzioni: tappa di uno dei cammini della tappa corrente e, per il Validator, propria. Un
      id fuori lista mandato a mano viene rifiutato con un errore di validazione.
- [ ] I tre controlli del model (variante di sé stessa, principale già variante, tappa già
      principale di altre) diventano errori di validazione di Nova sul campo, per tutti i
      ruoli. Le tre regole si scrivono **una volta sola**, in un metodo statico di
      `EcTrackVariant` che restituisce il motivo del rifiuto o null: lo usano sia il guard
      `saving()` (che resta come rete di sicurezza) sia la validazione Nova. Il filtro «solo
      tappe proprie» è una regola di permesso e resta nella risorsa Nova.
- [ ] [UX] I messaggi di errore compaiono sotto il campo, il form resta compilato, e sono
      tradotti in tutte le lingue del repo (`resources/lang/{it,en,fr,es,de}.json`, base in
      inglese come le chiavi esistenti, lingua di default `it`).
- [ ] Test: visibilità del campo per Validator, Administrator e Guest; opzioni filtrate per il
      Validator; collegamento esistente con principale altrui conservato al salvataggio;
      rifiuto server di un id non proprio o fuori dai cammini; i tre errori di validazione.

## Rischi

- **Conteggi del passaporto cambiati da un Validator.** Le varianti si leggono solo al
  conteggio del progresso (`StageProgressService::rootSql()`) e nessun job riscrive
  `passport_stages`. Quando un Validator crea o toglie una variante, i totali del suo cammino
  cambiano alla lettura successiva per tutti i camminatori. È l'effetto voluto, ed è limitato
  ai cammini in cui stanno sia la variante sia la principale.
  Sui dati locali le uniche tappe in cammini di proprietari diversi sono 3 (Validator 1421),
  che stanno in due copie dello stesso «Cammino dei Tre Villaggi» (layer 47 e 63, il secondo
  senza owner): nessun altro gestore viene toccato.
- **Nessuna traccia del valore precedente.** Una variante sbagliata cambia i conteggi per tutti
  i camminatori del cammino. Si accetta senza aggiungere colonne né log: `action_events` di
  Nova registra già chi ha salvato la tappa e quando (1429 eventi «Update» su EcTrack in
  locale), e incrociandolo con l'`updated_at` della riga variante si risale all'autore. Il
  valore precedente si perde; la correzione è riscegliere la principale.
- **Lista vuota per il Validator** se le tappe del suo cammino hanno un `user_id` diverso dal
  suo. Sui dati locali non succede: i 453 collegamenti tappa↔cammino con proprietari diversi
  stanno in cammini dell'Administrator (297) o senza owner (156), e i cammini di un Validator
  senza tappe proprie sono 0 su 21. Se capitasse, è coerente con la regola «ognuno gestisce il
  proprio contenuto».

## Out of scope

- I campi **User** (proprietario della tappa) e **Layers** (associazione diretta
  tappa↔cammino) della EcTrack restano riservati all'Administrator: il ticket apre al
  Validator solo «Variante di».
- Nessun cambiamento al comportamento dell'Administrator, a parte i messaggi di validazione
  che sostituiscono l'errore 500.
- Nessun ricalcolo di `passport_stages`: non serve, le varianti si applicano al conteggio.

## Moduli toccati

Tutto nel repo principale (camminiditalia); `wm-package` non viene toccato.

- `app/Nova/EcTrack.php`: `variantField()` (`canSee`, validazione, `fillUsing`) e
  `variantOptions()` (filtro per proprietario per il Validator, principale attuale inclusa)
- eventuale regola di validazione dedicata in `app/Rules/` (decisione in fase di piano)
- `resources/lang/{it,en,fr,es,de}.json`: messaggi di errore
- `tests/Feature/EcTrackVariantFieldTest.php`: casi per Validator e per la validazione

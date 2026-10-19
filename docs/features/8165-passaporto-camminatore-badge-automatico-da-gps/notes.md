> Ticket: oc:8165

# Note — Passaporto camminatore: badge automatico da GPS

## Deviazioni dal piano

- **Il filtro «solo UGC track registrate» serviva in SQL, il piano non lo diceva.** `origin()` è
  PHP, e caricare le tracce per filtrarle in memoria tirerebbe dentro anche i punti grezzi, che
  sono la parte pesante di `properties`. Aggiunto `UgcTrack::scopeRecorded()` in `wm-package`,
  accanto a `origin()`, con un test che verifica che le due forme diano lo stesso esito sugli
  stessi casi. La regola resta in un posto solo, anche se espressa in due linguaggi.
- **Lo scope usa `jsonb_exists()` e non l'operatore `?`.** Quest'ultimo, passando da PDO, va
  raddoppiato per non essere preso per un segnaposto, e il raddoppio si comporta in modo diverso
  a seconda che la query abbia o no altri binding. `WmMatchUgcTracksCommand` nel package aveva
  già preso la stessa strada per la stessa ragione.
- **Il ricalcolo si aggancia a `UgcTrackMatchingRun`, non a `UgcObserver`.** Il piano diceva di
  partire dall'observer della UGC track dopo il dispatch del matching, ma lì il calcolo non è
  ancora stato fatto e non c'è niente da aggregare. `markComputed()` scrive la riga di stato con
  Eloquent, quindi un observer su quel model scatta nel momento giusto, senza toccare il package.
- **La lens Nova contava le tappe invece dei gruppi** (fuori dal piano). Il gestore avrebbe letto
  «7 su 9» dove l'utente vede «7 su 7». Allineata.
- **I sei punti Nova che il piano diceva di filtrare erano già a posto:** `scopeVisibleTo()`
  applica già lo scope `validated`. Aggiunti i test che lo provano, invece di modificare codice
  che andava bene.

## Bug trovati

- **Il database di sviluppo portava già lo schema del vecchio branch.** Due migration di
  `feature/oc-8165-…` (6 ottobre) risultavano eseguite pur non esistendo come file sul branch di
  lavoro: `passport_stages` aveva già `progress` e `duration_seconds`, `validated_at` era già
  nullable ed esisteva `passport_stage_ugc_track`. Il database non sa niente dei branch, e i
  file se n'erano andati con il cambio. Ripulito prima di cominciare (task 0 del piano), con le
  8 righe di prova salvate in CSV.
- **Quattro test preesistenti del matching usavano UGC track di due soli punti a un chilometro di
  distanza**, che con la regola nuova sono tratti lunghi. Non volevano testare quello: geometrie
  infittite a un punto ogni 333 m, stessi estremi e stessi segmenti attesi.
- **Tre test del badge «Origin» codificavano la regola vecchia** (`distanceFilter` come prova di
  registrazione). Aggiornati alla regola nuova.

## Un difetto dell'ambiente di test, non di questo lavoro

**I lock di `ShouldBeUnique` finivano nel Redis vero anche durante i test.** `phpunit.xml`
imposta `CACHE_STORE=array`, ma un job che dichiara `uniqueVia() { return
Cache::store('redis'); }` chiede quello store **per nome** e finisce sul Redis reale. I lock ci
restano fino alla scadenza — un'ora — e sopravvivono alla fine della suite: da lì in poi ogni
dispatch con lo stesso identificativo viene scartato **in silenzio**, e il test che lo attende
fallisce senza un errore che spieghi perché.

Si vedeva: in Redis c'erano decine di lock di `BuildAppPoisGeojsonJob` lasciati da esecuzioni
vecchie, oltre a sette del job di questo ticket. Il sintomo era un test che passava da solo e
falliva nella suite intera — il tipo di cosa che si finisce per marcare come «instabile».

Rimedio, lo stesso che `wm-package` ha adottato in oc:8660: `Tests\TestCase` ora porta lo store
`redis` a `array` per tutta la suite. Un test preesistente
(`RecalculateLayerAttributesJobTest::test_unique_via_uses_redis_not_the_default_database_store`)
controllava la classe concreta dello store: ora verifica l'intento, cioè che quel job non
ripieghi sullo store di default.

**Tentativi sbagliati, prima di arrivarci**: liberare i lock nel `setUp` del test, poi dentro il
test. Nessuno dei due funziona, perché `forgetDriver()` ricrea il client ma non cancella le
chiavi che stanno in Redis. E avevo proposto di togliere l'unicità al job — cioè di cambiare il
comportamento in produzione per far passare un test. Il dev ha fermato quella strada.

## Decisioni

- **Le varianti entrano in questo ciclo**, contro la decisione del 03/09 con il cliente («le
  varianti dobbiamo vederle a parte, non possiamo farlo dentro questo RDO») e contro quella
  interna del 06/10 («non la facciamo entrare in questo flusso di sviluppo»). Motivo: il problema
  è già nei dati e il calcolo nascerebbe sbagliato. Su Via Vandelli — l'unico cammino con il
  passaporto acceso — chi parte da Modena si fermerebbe a 7 tappe su 9 per sempre.
- **Il ramo `distanceFilter` di `origin()` è stato tolto sapendo che costa 20 tracce.** Portate al
  dev con i dati: quelle 20 hanno la versione dell'app nel campo `device` (3.1.3 ×16, 3.1.2 ×3,
  0.0.1 ×1), cioè sono registrazioni vere fatte prima della 3.1.7, quando l'app ha cominciato a
  salvare i punti grezzi. Decisione confermata: senza punti grezzi la traccia è importata.
- **Una validazione non si revoca mai.** Vale per entrambe le cause di declassamento: il gestore
  che modifica il tracciato di una tappa (ogni salvataggio di EcTrack fa ripartire il matching) e
  il riallineamento con il limite sui tratti dritti, che può solo abbassare le coperture.
- **La protezione delle righe manuali e la non-revoca stanno nella clausola dell'upsert**, non in
  un `if` applicativo: così reggono anche se il job e l'azione Nova della certificazione cartacea
  scrivono insieme.
- **Le varianti in una tabella a sé** (`ec_track_variants`) e non in una colonna su `ec_tracks`,
  che è di `wm-package`: una colonna aggiunta da una migration del consumer fa divergere lo
  schema che il package pubblica.
- **Nessun commit durante l'esecuzione**, come da regola del team: i file sono scritti, i commit
  li fa il dev dopo la review.

## Misure fatte, e perché contano

- **Il limite sui tratti dritti non si può applicare lato per lato.** Misurato sulla traccia 297
  (19.994 vertici): confrontare ogni singolo lato con i segmenti costa **305 s contro 16,9 s**, a
  parità di risultato. Dopo la modifica il ricalcolo di quella traccia resta a **3,2 s**, come
  prima.
- **I tratti lunghi sono 214 su 399.290**, e sono quasi tutti sulle UGC track registrate: il
  salto più lungo misura **17,4 km** (traccia 150, utente 3858) e sta su una registrazione, non
  su un file importato. Una traccia registrata su otto ha almeno un salto oltre i 400 m.
- **Il primo lancio validerebbe 75 tappe a 26 persone** (`--dry-run` sui dati di sviluppo). La
  stessa misura fatta a mano con una query indipendente, prima del limite sui tratti dritti,
  dava 77 tappe e 26 persone: le due in meno sono le tracce 297 e 321, ricalcolate durante la
  prova di prestazioni.
- **Quattro UGC track su 267 validano tappe di due cammini diversi**, perché i cammini
  condividono tratti reali (il tratto Colle San Bernardo vale per la Via Francigena e per il
  Cammino di Oropa). Comportamento voluto, non un difetto.

## Deciso in corsa, dopo la review

- **Il calcolo vale solo sui cammini con il passaporto acceso**, e all'accensione il cammino si
  popola da solo. Prima girava su tutti: su questo database aveva prodotto 213 righe, di cui
  solo 8 su un cammino acceso. Le altre 205 sono state cancellate.
- **La tabella si chiama `passport_stages`**, non più `validated_ec_tracks`: dal momento in cui
  contiene anche le tappe a metà, il nome vecchio diceva una cosa falsa, ed è il modo più facile
  per contare come percorse delle tappe che non lo sono. Rename fatto quando in produzione la
  tabella era vuota; il collegamento alle UGC track è diventato `passport_stage_ugc_track`.
- **Il tratto dritto lungo vale solo per i suoi due capi**, non «due segmenti per ogni tappa
  attraversata» come era stato scritto prima: una tappa tagliata di traverso, lontana da dove il
  segnale si è perso e da dove è tornato, non prende nulla.
- **I km di un gruppo sono quelli della tappa principale**, non della più lunga.

## Cose note, lasciate com'è

- **Una percentuale può restare appesa a una UGC track cancellata.** Se una persona cancella la
  traccia che le aveva portato una tappa al 60%, il progresso non si aggiorna da solo in quel
  momento. Si corregge però alla prima UGC track successiva su quella tappa, perché il calcolo
  non somma mai sul valore precedente: riparte da zero dalle tracce che esistono. Resta quindi
  sbagliato solo per chi cancella e non torna mai più lì. Valutato un observer sulla
  cancellazione e scartato: avrebbe accodato un ricalcolo a **ogni** cancellazione di UGC track,
  per tutti, contro un caso di nicchia in cui il dato falso è un avanzamento e non un traguardo.
- **`origin()` e `UgcTrack::recorded()` divergono su un valore `null`**: in PHP `isset()` su
  `properties.import` a `null` è falso, in SQL `jsonb_exists` è vero. Non si verifica, perché
  l'app scrive sempre un oggetto; se un giorno scrivesse `null`, una traccia importata
  conterebbe come registrata.
- **I km di un gruppo sono quelli della tappa principale**: chi percorre il ramo corto si vede
  attribuiti i km del percorso ufficiale. Su Via Vandelli sono 19 in più. Non esiste una
  risposta giusta per tutti finché non si sa quale ramo la persona ha fatto, e la principale è
  almeno una scelta dichiarata dal gestore.
- **L'ultimo segmento di una tappa può essere un avanzo di pochi metri**, e pesa quanto un
  segmento intero perché la copertura si conta a segmenti. Su camminiditalia riguarda 153 tappe
  su 1262, e su una tappa da cinque segmenti vale il 20%: chi la percorre tutta tranne l'ultimo
  metro si ferma all'80%. Correzione provata e poi ritirata su richiesta del dev, perché avrebbe
  richiesto di riallineare una decina di test del package e di ricalcolare tutte le
  corrispondenze. Il limite è scritto nel codice e nella pagina di conoscenza del package.

## Trovato durante il deploy su develop

**`wm:match-ugc-tracks` da solo non riallinea niente dopo questo ticket.** Riaccoda le tracce mai
calcolate, fallite o calcolate con parametri diversi da quelli correnti; il limite sui tratti
dritti lunghi però non cambia nessun parametro, cambia il codice. Per il comando le
corrispondenze esistenti sono già a posto, e restano quelle della regola vecchia.

Serve azzerare prima lo stato dei calcoli (`TRUNCATE ugc_track_matching_runs`), una tabella di
servizio che si ricostruisce da sé. In dev il `--dry-run` è passato da «0 tracce da calcolare» a
269. Il passo è ora in `plan.md` e nel `CLAUDE.md`.

La soluzione migliore sarebbe un'opzione sul comando per forzare il ricalcolo, da tenersi per le
prossime volte: non fatta in questo ciclo su indicazione del dev.

## Follow-up

- **L'informativa privacy non nomina i dati di posizione.** Il testo mostrato alla registrazione
  è il campo `page_privacy` dell'App e per Cammini d'Italia è la privacy policy del sito: 4.633
  caratteri su newsletter, SendinBlue, Facebook, Google Workspace e Google Analytics, dove le
  parole *geolocalizzazione*, *posizione*, *GPS*, *percorso* e *app* non compaiono mai. Da far
  sistemare al cliente prima di accendere il calcolo in produzione; si modifica da Nova.
- **I gruppi di varianti vanno dichiarati dal cliente**, cammino per cammino. Finché non lo sono,
  nessun cammino con rami alternativi arriva al 100%. Partire da Via Vandelli, dove i gruppi sono
  due.
- **Le tappe di un cammino non hanno un ordine**: non esiste una colonna di posizione e le liste
  escono in ordine di creazione. L'elenco «tappe sbloccate e da sbloccare» che l'utente leggerà
  come l'ordine del cammino non lo rispetta. Lavoro a sé.
- **L'attivazione esplicita del passaporto da parte dell'utente** resta fuori scope, ticket a sé.
- **I km di un gruppo sono quelli della principale** (deciso in review): non della più lunga,
  che sarebbe un artificio. Resta il fatto che chi percorre il ramo corto si vede attribuiti i
  km del percorso ufficiale — su Via Vandelli sono 19 in più.
- **Un cambio di tappa non aggiorna da solo il progresso:** `matchEcTrack()` rifà le
  corrispondenze senza toccare lo stato delle UGC track, quindi l'observer non scatta. Si rimedia
  con `camminiditalia:compute-stage-validations`. Se diventasse frequente, varrebbe un aggancio
  dedicato.

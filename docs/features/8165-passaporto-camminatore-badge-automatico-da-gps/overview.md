> Ticket: oc:8165

# Passaporto camminatore: badge automatico da GPS

## Cosa cambia

Il passaporto smette di dipendere solo dalla credenziale cartacea: le tappe si sbloccano da sole
quando le UGC track registrate dall'utente coprono abbastanza la tappa.

Il calcolo non rifà il lavoro geometrico. La corrispondenza fra una UGC track e le tappe che
tocca esiste già (oc:8773, in `wm-package`): ogni tappa è divisa in segmenti di 400 metri e un
segmento risulta toccato se la UGC track gli passa entro 50 metri. Quello che manca è la regola
che trasforma quei segmenti in un verdetto: unendo i segmenti toccati da **tutte** le UGC track
di una persona, se ne risulta coperta almeno l'80% la tappa è percorsa.

Insieme a questo entra il concetto di **variante**, che oggi nei dati non esiste. Una tappa può
essere dichiarata variante di un'altra tappa dello stesso cammino: le due si equivalgono, basta
averne percorsa una, e nel totale del cammino contano per una sola.

Il risultato va a riempire le schermate del passaporto già costruite (oc:8676, oc:8702, oc:8703),
che oggi mostrano solo ciò che un gestore ha validato a mano.

## Perché

È la componente automatica del passaporto, l'ultimo pezzo mancante dell'RDO. Oggi una tappa si
sblocca solo se il camminatore manda la credenziale cartacea e un gestore gliela valida a mano:
un processo che non scala e che lascia inutilizzati i dati GPS già raccolti.

Le varianti entrano adesso, contro quanto deciso in call, perché il problema è già nei dati e non
aspetta: 37 tappe hanno «variante» nel nome e appartengono allo stesso cammino della principale,
quindi concorrono al suo totale. Su Via Fabaria 13 tappe su 23 sono varianti, e il cammino non
potrebbe mai risultare completo. Il caso peggiore è proprio **Via Vandelli**, l'unico cammino con
il passaporto attivo: ha `Tappa 01M`/`01S` e `Tappa 02M`/`02S`, due rami di partenza che
convergono a Pavullo, senza la parola «variante» da nessuna parte. Chi parte da Modena si
fermerebbe a 7 tappe su 9 per sempre.

## Requisiti

### Il verdetto sulla tappa

- [ ] Unire i segmenti toccati da tutte le UGC track di un utente su una tappa, e dichiararla
      percorsa quando la quota di segmenti coperti raggiunge la soglia configurata
- [ ] Soglia configurabile (80%), insieme ai due parametri che già esistono in `wm-package`:
      lunghezza del segmento (400 m) e distanza massima dal percorso (50 m). La configurazione
      serve perché i valori cambiano da shard a shard, non perché questo cliente li cambierà: ha
      accettato i consigliati, e una volta concordati restano fermi
- [ ] Conservare anche l'avanzamento parziale, non solo il superamento della soglia: le schermate
      approvate mostrano «tappa al 60%»
- [ ] Una validazione concessa a mano da un gestore non viene mai toccata dal calcolo, né
      declassata né aggiornata
- [ ] **Una tappa validata non torna mai non validata.** La data di validazione, una volta
      scritta, non si cancella e non si sposta: la validazione resta legata al giorno in cui è
      stata ottenuta e alla tappa com'era allora. Il ricalcolo continua ad aggiornare la
      percentuale, ma non può revocare. Vale per entrambe le cause di declassamento: il gestore
      che modifica il tracciato di una tappa — ogni salvataggio di una EcTrack fa ripartire il
      matching, che cancella e ricalcola le sue corrispondenze — e il riallineamento con il nuovo
      limite sui tratti dritti, che può solo abbassare le coperture
- [ ] **Solo le UGC track registrate concorrono**: una traccia caricata da file (GPX, KML,
      GeoJSON) non certifica nulla, perché non è la registrazione di un percorso ma un disegno.
      Il criterio è `UgcTrack::origin()` di `wm-package`, corretto in questo stesso ciclo. Oggi
      sono 133 su 267, metà dell'archivio
- [ ] Registrare quali UGC track hanno prodotto ciascuna validazione: serve al «numero di uscite
      servite per sbloccarla» previsto dal mockup, e a rifare i conti quando una UGC track viene
      cancellata

### Varianti

- [ ] Una tappa può essere dichiarata variante di un'altra tappa; la relazione è uno a molti (una
      principale, più varianti) e vive su un livello solo (una variante non può avere varianti)
- [ ] Nel conteggio di un cammino le tappe si raggruppano per radice — la principale se è
      presente in quel cammino, altrimenti la tappa stessa — e ogni gruppo vale 1 nel totale,
      soddisfatto da una qualsiasi delle sue tappe
- [ ] La validazione registra la tappa **davvero percorsa**, anche quando è una variante; il
      raggruppamento avviene al momento di contare. Il passaporto e l'immagine di condivisione
      mostrano così il percorso reale
- [ ] Campo in Nova sulla tappa, per dichiarare di quale tappa è variante

### Buchi del segnale GPS

- [ ] Nel matching (`wm-package`), un tratto dritto fra due punti consecutivi più lungo della
      lunghezza di un segmento può validare solo il primo e l'ultimo segmento che incontra, non
      quelli in mezzo

### Dove e quando gira

- [ ] **Si calcola solo sui cammini con il passaporto acceso** (`passport_enabled`, oc:8763): su
      quelli spenti il passaporto non esiste per nessuno. Vale per il servizio, per il job che
      parte dopo una UGC track e per il command, così nessuna delle tre strade può popolare un
      cammino spento
- [ ] **All'accensione, il cammino si popola da solo**: chi ci ha già camminato trova il
      passaporto pieno invece di aspettare la propria uscita successiva. Allo spegnimento non si
      cancella nulla, e riaccendendo le validazioni tornano a vedersi

- [ ] Il progresso dell'utente si aggiorna appena la sua UGC track è stata confrontata con le
      tappe, così l'app lo trova già pronto
- [ ] Command idempotente per il primo popolamento sulle UGC track già in archivio e per i
      ricalcoli dopo un cambio di tappe o di varianti
- [ ] Il command ha un `--dry-run` che non scrive nulla e riporta quante tappe sbloccherebbe e a
      quante persone, come già fa `wm:match-ugc-tracks`. Serve perché il primo lancio è
      irreversibile: sui dati di oggi validerebbe **77 tappe a 26 persone**, e per la regola
      sopra quelle validazioni non si possono più togliere

### Cosa vede l'utente

- [ ] **Una UGC track può validare tappe di più cammini, ed è voluto**: i cammini condividono
      tratti di sentiero, e chi li percorre ha camminato su entrambe le tappe. Succede a 4
      UGC track su 267 — il tratto Colle San Bernardo vale per la Via Francigena e per il Cammino
      di Oropa. Nel passaporto compaiono quindi anche cammini che la persona non ha scelto di
      fare, iniziati per un tratto in comune: nessuna soglia minima per mostrarli

- [ ] Le API del passaporto già esistenti restituiscono anche l'avanzamento parziale e la fonte
      della validazione, senza cambiare la loro forma
- [ ] Il completamento di un cammino resta **calcolato**, non scritto in una tabella di badge, e
      si misura **sui gruppi, non sulle tappe**: il cammino è concluso quando ogni gruppo ha una
      tappa validata. Su Via Vandelli i gruppi sono 7 e non 9, perché `Tappa 01M`/`01S` e
      `Tappa 02M`/`02S` contano una volta sola: chi parte da Modena può arrivare al 100%, cosa
      che contando le tappe non potrebbe fare mai

## Rischi

- **Il cliente deve dichiarare le varianti una per una** (vedi «Da chiedere al cliente»). Il nome
  non è un criterio affidabile: le 37 tappe con «variante» nel nome lo scrivono in sei modi
  diversi, e i rami M/S di Via Vandelli non lo scrivono affatto. Finché i gruppi non sono
  compilati il comportamento è quello di oggi, cioè nessun cammino con rami alternativi arriva al
  100%.
- **Un cambio di parametri a metà strada produrrebbe percentuali senza senso.** Ogni
  corrispondenza porta i parametri con cui è stata calcolata, `total_segments` compreso: finché
  il ricalcolo non ha ripassato tutte le tracce, unire indici riferiti a divisioni diverse della
  stessa tappa dà un numero privo di significato, che può anche superare la soglia e validare per
  sbaglio. Non è in programma che accada — i valori sono concordati e fermi — ma il calcolo
  ignora le corrispondenze con parametri diversi da quelli correnti, che `wm-package` sa già
  individuare con `unmatchedUgcTrackIdsQuery()`.
- **Le tappe di un cammino non hanno un ordine.** Non esiste una colonna di posizione: le liste
  escono in ordine di creazione nel database. L'elenco «tappe sbloccate e da sbloccare» che
  l'utente leggerà come l'ordine del cammino oggi non lo rispetta. Fuori da questo ciclo, ma da
  aprire come lavoro a sé.
- **Chi tiene il telefono spento viene comunque creduto, entro un limite.** Il paracadute sui
  tratti dritti lunghi copre il caso grosso — nei dati esiste un salto da 17 km, e una traccia su
  cinque ha almeno un salto oltre i 400 metri — ma un tratto appena sotto la soglia resta valido
  per intero.
- **L'informativa privacy non nomina i dati di posizione** (vedi «Da chiedere al cliente»). Non
  blocca lo sviluppo, ma va sistemata prima di accendere il calcolo in produzione.

## Da chiedere al cliente

Tre cose che non si decidono qui dentro e che vanno portate a Cammini d'Italia prima di
accendere il calcolo in produzione.

- **La voce che manca nell'informativa privacy.** Il testo mostrato alla registrazione è il campo
  `page_privacy` dell'App, e per Cammini d'Italia è la privacy policy del sito: 4.633 caratteri
  che elencano otto finalità — newsletter, SendinBlue, Facebook, Google Workspace, YouTube,
  Google Analytics — in cui le parole *geolocalizzazione*, *posizione*, *GPS*, *percorso* e *app*
  non compaiono mai. L'obbligo di dichiarare le finalità vale a prescindere dalla base giuridica,
  anche dove un consenso spuntato non serve. Serve che compaia la voce mancante: la posizione
  raccolta registrando una UGC track, usata per certificare le tappe percorse e per mostrare il
  nome sul traguardo condiviso. Lo modifica il cliente da Nova, nessun codice da toccare.
- **I gruppi di varianti.** Quali tappe sono alternative di quali, cammino per cammino. Senza
  questa informazione nessun cammino con rami alternativi arriva al 100%. Da partire da Via
  Vandelli, unico cammino con il passaporto acceso, dove i gruppi sono due: `Tappa 01M`/`01S` e
  `Tappa 02M`/`02S`.

## Out of scope

- **L'attivazione esplicita del passaporto da parte dell'utente.** Diventerà un'azione nel
  frontend: chi attiva il passaporto acconsente, e da lì partono i calcoli sui cammini che lo
  hanno acceso. Ticket a sé. Nel frattempo vale il consenso dato accettando le condizioni al
  momento dell'iscrizione, e il passaporto resta visibile solo a chi è autenticato.
- **Tutto il frontend**: schermate del passaporto, indicatore di avanzamento, pop-up di
  condivisione al salvataggio, tab della bottom bar. Vivono in un repo separato.
- **L'ordine delle tappe dentro un cammino.**
- **Il pannello nel dettaglio dell'utente in Nova**, chiesto dalla prima stesura di questo ticket:
  la risorsa `PassportStage` e la sua lens di riepilogo mostrano già chi ha percorso cosa.
- **Lo sblocco manuale della singola tappa**: chi ha bisogno di essere sbloccato passa dalla
  richiesta di certificazione cartacea, che già esiste e già lo fa.
- **La tabella `user_layer_badges`** prevista dalla prima stesura di questo ticket (nota del
  29/06/2026): il completamento del cammino si calcola dalle tappe validate.

## Moduli toccati

**camminiditalia (repo principale)**

- `database/migrations/` — avanzamento parziale su `passport_stages` (`validated_at`
  nullable, colonna del progresso), collegamento fra una validazione e le UGC track che l'hanno
  prodotta, relazione fra tappa principale e sue varianti
- `app/Models/PassportStage.php` — righe parziali accanto a quelle validate
- `app/Services/` — il servizio che calcola il verdetto da segmenti coperti e soglia
- `app/Jobs/` — aggiornamento del progresso dopo il confronto di una UGC track
- `app/Console/Commands/` — primo popolamento e ricalcolo
- `app/Services/StageProgressService.php` — avanzamento parziale e raggruppamento delle varianti
  nel conteggio del cammino
- `app/Nova/EcTrack.php` — campo per dichiarare di quale tappa è variante
- `app/Nova/PassportStage.php`, `app/Nova/Lenses/PassportStageSummary.php`,
  `app/Nova/Filters/PassportStage*.php`, `app/Nova/CertificationRequest.php` — oggi leggono
  `passport_stages` senza filtro, perché finora conteneva solo validazioni: vanno ristrette
  alle sole righe validate
- `config/camminiditalia.php`, `.env-example` — soglia di copertura

**wm-package (submodule)**

- `src/Services/Models/UgcTrackMatchingService.php` — il paracadute sui tratti dritti lunghi
- `src/Models/UgcTrack.php` — `origin()`: legge `properties.import` (oc:8764), perde il ramo
  `distanceFilter`

Il dettaglio delle due correzioni al package sta nel suo
[overview](../../../wm-package/docs/features/8165-passaporto-camminatore-badge-automatico-da-gps/overview.md).

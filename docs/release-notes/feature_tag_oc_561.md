# Manuale d'uso — Nuove funzionalità

Questo documento descrive le nuove funzionalità rilasciate e le azioni necessarie per configurarle e utilizzarle correttamente, suddivise per pannello di amministrazione e app.

---

## Segnalazioni: selezione del cammino
> oc:7639

### App
Nessuna configurazione richiesta. Quando si crea una nuova segnalazione, compare automaticamente un campo per associarla al cammino di competenza. L'app pre-seleziona il cammino più vicino alla posizione GPS corrente. Se nelle vicinanze ci sono più cammini, l'utente può scegliere quello corretto dall'elenco. Il campo è facoltativo: è possibile inviare la segnalazione anche senza selezionare un cammino.

### Pannello di amministrazione
Nessuna azione richiesta.

---

## Segnalazioni: filtro per cammino e gestione letto/non letto
> oc:7640

### Pannello di amministrazione

**Per i gestori di cammino (ruolo Validator):**
Nessuna configurazione richiesta. Dopo il login, il pannello mostra automaticamente solo le segnalazioni del proprio cammino. Le segnalazioni non ancora aperte sono evidenziate con un badge **"Non letto"**. Per marcare una o più segnalazioni come lette, selezionarle con la casella di spunta e usare l'azione **"Segna come letto"**.

**Per gli amministratori:**
Nella lista segnalazioni è disponibile un filtro **"Filtro Segnalazioni"** per visualizzare le segnalazioni di un cammino specifico.

### App
Nessuna azione richiesta.

---

## Segnalazioni: notifica email automatica al gestore
> oc:7641

### Pannello di amministrazione

Per ricevere le notifiche email è necessario che ogni cammino abbia un gestore assegnato. Aprire il layer dal pannello, verificare che il campo **"Owner"** sia valorizzato con l'utente gestore corretto. Se il campo è vuoto, le notifiche verranno inviate a `info@camminiditalia.org` con un avviso che indica l'assenza del gestore.

Una volta configurato, ogni nuova segnalazione associata al cammino genererà automaticamente una email al gestore con: nome del cammino, contenuto della segnalazione, eventuali foto e un link diretto alla segnalazione nel pannello.

### App
Nessuna azione richiesta.

---

## Widget embed: branding Cammini d'Italia
> oc:7642

### Pannello di amministrazione

Per abilitare la funzionalità di embed su un'app, aprire la risorsa **App** in modalità modifica, andare nella tab **Frontend** e nella sezione **Widget** attivare l'opzione apposita. Una volta abilitata, nella pagina di dettaglio di ogni layer comparirà un pulsante per copiare il codice del widget da incollare sul sito del partner.

Il widget include automaticamente il logo **"Cammini d'Italia"** con link al download dell'app.

### App
Nessuna azione richiesta.

---

## Ricerca per cammino nella home
> oc:7643

### Pannello di amministrazione
Nessuna azione richiesta.

### App
Nessuna configurazione richiesta. Utilizzando il campo di ricerca nella home, oltre ai sentieri compare automaticamente un tab **"Cammini"** con i cammini il cui nome corrisponde al testo cercato. Il tab è visibile solo quando ci sono risultati. Cliccando su un cammino si apre direttamente la sua pagina.

---

## Ordinamento alfabetico dei layer nel pannello
> oc:7644

### Pannello di amministrazione

Aprire la risorsa **App** in modalità modifica, andare nella tab **Home** e cliccare il pulsante **"Ordina A-Z"** per riordinare automaticamente i layer in ordine alfabetico. Cliccare poi **Aggiorna** per salvare il nuovo ordinamento.

### App
Nessuna azione richiesta.

---

## Punti di interesse: scelta tra immagine e icona sulla mappa
> oc:7645

### Pannello di amministrazione

Su ogni punto di interesse (EC POI) è ora disponibile una casella di spunta **"Mostra immagine sulla mappa"**. Se attivata, sulla mappa viene mostrata la foto del punto di interesse al posto dell'icona della categoria. Se disattivata, viene sempre mostrata l'icona, indipendentemente dalla presenza di una foto.

La casella è modificabile solo se il POI ha già un'immagine associata.

### App
Nessuna azione richiesta. La mappa recepisce automaticamente la scelta configurata nel pannello.

---

## Punti di interesse: zoom minimo e filtri per tipo
> oc:7646

### Pannello di amministrazione

**Zoom minimo POI globali:** aprire la risorsa **App** in modalità modifica e configurare il campo **"POI Min Zoom"** con il valore di zoom minimo dal quale i punti di interesse globali diventano visibili sulla mappa. Al di sotto di quel livello di zoom i POI non vengono mostrati, evitando sovraffollamento visivo nelle viste ampie.

**Filtri per tipo POI:** aprire la risorsa **App** in modalità modifica, nella sezione dei filtri attivare la casella **"POI Type Filter"**. Opzionalmente è possibile:
- personalizzare l'etichetta del filtro tramite il campo **"Poi Type Filter Label"**
- escludere determinati tipi di POI dal filtro inserendone gli identificatori separati da virgola nel campo **"Poi Type Exclude Filter"**

### App
Nessuna configurazione richiesta. I filtri per tipo POI, una volta abilitati dal pannello, agiscono in modo coerente sia sui punti di interesse globali che su quelli associati a una traccia specifica.

---

## Statistiche per layer: dashboard Analytics
> oc:7648

### Pannello di amministrazione

Nella pagina di dettaglio di ogni layer è ora presente una sezione **"Analytics Layer — Ultimi 30 giorni"** che mostra automaticamente:
- numero totale di aperture
- utenti unici
- media giornaliera
- grafico delle aperture giornaliere suddivise per piattaforma (Android, iOS e Webapp)

È disponibile un pulsante per esportare il grafico in formato PNG.

Nessuna configurazione richiesta per ogni singolo layer.

### App
Nessuna azione richiesta.

---

## Punti di interesse: visualizzazione immagine o icona sulla mappa (traccia)
> oc:7988

### Pannello di amministrazione
Nessuna azione aggiuntiva richiesta. La scelta configurata sul singolo punto di interesse tramite la casella **"Mostra immagine sulla mappa"** (vedi oc:7645) viene ora recepita correttamente anche quando si visualizzano i punti di interesse associati a una traccia specifica.

### App
Nessuna azione richiesta.

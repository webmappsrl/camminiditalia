> Ticket: oc:7644

# Ordinamento alfabetico automatico dei layer nel backend

## Cosa cambia
Il testo helper del pulsante "Sort Layers A-Z" e il messaggio di successo vengono aggiornati
per comunicare esplicitamente che dopo aver ordinato i layer bisogna cliccare **Update**
("Aggiorna" in italiano) per rendere definitivo il nuovo ordinamento.

## Perché
In fase di testing è emerso che gli utenti non capiscono che il click sul pulsante di
ordinamento non persiste automaticamente le modifiche: Nova richiede un salvataggio esplicito
della risorsa. Aggiungere il reminder nel punto di massima attenzione (descrizione + messaggio
di successo) elimina questa ambiguità.

## Requisiti
- [ ] `$description` aggiornato con "Click **Update** to save the new order." (EN) e
      "Clicca **Aggiorna** per rendere definitivo il nuovo ordinamento." (IT)
- [ ] `$successMessage` aggiornato con lo stesso reminder
- [ ] `resources/lang/en.json` aggiornato con le nuove chiavi
- [ ] `resources/lang/it.json` aggiornato con le nuove chiavi

## Rischi
- Nessun rischio architetturale. Il testo è HTML-escaped tramite `e(__(...))`.
- Fallback Nova: se una chiave manca nel file di lingua, viene restituita la stringa inglese.

## Out of scope
- Modifiche alla logica di ordinamento
- Modifiche ad altri messaggi (error, info)
- Aggiornamenti al submodule wm-package

## Moduli toccati
- `app/Nova/App.php` — metodo `configHomeSortTriggerMarkup()`
- `resources/lang/en.json` — chiavi description e successMessage
- `resources/lang/it.json` — chiavi description e successMessage

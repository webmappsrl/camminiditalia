> Ticket: oc:7644

# Plan — Aggiornamento helper testo pulsante ordinamento layer

## Step 1 — Aggiorna `app/Nova/App.php`

Modifica `configHomeSortTriggerMarkup()`:

**`$description`** — nuova stringa:
```
Sort consecutive Layer boxes alphabetically. Other box types stay in place and you can still reorder everything manually after clicking. Click Update to save the new order.
```

**`$successMessage`** — nuova stringa:
```
Layers sorted alphabetically within each group. Click Update to save the new order.
```

## Step 2 — Aggiorna `resources/lang/en.json`

Rimuovere le vecchie chiavi e aggiungere:
- chiave description (nuova stringa EN → stessa stringa EN)
- chiave successMessage (nuova stringa EN → stessa stringa EN)

## Step 3 — Aggiorna `resources/lang/it.json`

Rimuovere le vecchie chiavi e aggiungere:
- chiave description → "Ordina alfabeticamente solo i box Layer consecutivi. I box di altro tipo restano fermi e puoi comunque riordinare tutto manualmente dopo il click. Clicca Aggiorna per rendere definitivo il nuovo ordinamento."
- chiave successMessage → "Layer ordinati alfabeticamente per ogni gruppo. Clicca Aggiorna per rendere definitivo il nuovo ordinamento."

## Step 4 — Commit

```
feat(oc:7644): update sort helper text to mention Update button
```

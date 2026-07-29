# Task 2: Verifica pre-produzione — `down()` e backup

## Step 1: Test `down()` su ambiente locale Docker

**Status: PASSED**

### Execution Log

#### Initial state verification
```bash
docker exec postgres-camminiditalia psql -U camminiditalia -d camminiditalia -c "\d users" | grep surname
```
Output: `surname | character varying(255)` — colonna presente prima del rollback.

#### Rollback execution
```bash
docker exec laravel-camminiditalia php artisan migrate:rollback --path="database/migrations/2026_07_28_144006_zz_2026_07_27_000001_add_surname_to_users_table.php"
```
Output:
```
   INFO  Rolling back migrations.  
  2026_07_28_144006_zz_2026_07_27_000001_add_surname_to_users_table  20.04ms DONE
```

#### Verification after rollback
```bash
docker exec postgres-camminiditalia psql -U camminiditalia -d camminiditalia -c "\d users" | grep surname
```
Output: (no output — nessun risultato dal grep) — colonna rimossa correttamente.

#### Re-apply migration
```bash
docker exec laravel-camminiditalia php artisan migrate --path="database/migrations/2026_07_28_144006_zz_2026_07_27_000001_add_surname_to_users_table.php"
```
Output:
```
   INFO  Running migrations.  
  2026_07_28_144006_zz_2026_07_27_000001_add_surname_to_users_table  15.26ms DONE
```

#### Final state verification
```bash
docker exec postgres-camminiditalia psql -U camminiditalia -d camminiditalia -c "\d users" | grep surname
```
Output: `surname | character varying(255)` — colonna ripristinata correttamente.

### Outcome
- ✅ `down()` metodo eseguito correttamente — colonna `surname` rimossa senza errori
- ✅ Nessuna violazione FK o dipendenza rilevata durante il rollback
- ✅ `up()` metodo eseguito correttamente — colonna `surname` ricreata senza errori
- ✅ Database lasciato nello stato atteso: colonna `surname` presente su tabella `users`

---

## Step 2: Verifica backup pre-produzione

**Status: MANUAL (non codificabile in questo repo)**

Per autorizzare il deploy in produzione, è **obbligatorio** verificare manualmente l'esistenza di un backup recente del database **immediatamente prima** dell'esecuzione della migration in produzione.

**Checklist manuale (non eseguita in questo task)**:
- [ ] Accedere al dashboard del provider di infrastruttura (es. AWS RDS, Heroku Postgres, managed Postgres del provider)
- [ ] Verificare che esista un backup/snapshot del database **entro le ultime 24 ore** (ideale: ultime poche ore)
- [ ] Annotare l'identificativo del backup e il timestamp esatto
- [ ] Conservare un documento di tracciamento (ticket, Slack message, o log interno) della verifica e della migration eseguita in produzione
- [ ] Eseguire la migration in produzione solo **dopo** questa verifica manuale

Questo step è una verifica **organizzativa**, non tecnica — non è scripturabile in questo repository.

---

## Autorizzazione per deploy in produzione

**✅ Precondizioni soddisfatte:**
- Task 1 (pubblicazione migration con timestamp reale): completato e revisionato
- Task 2 Step 1 (verifica `down()` locale): completato e testato
- Task 2 Step 2 (verifica backup): da eseguire manualmente prima del deploy

**Prossimo passo:**
1. Verificare l'esistenza del backup (Step 2)
2. Eseguire `php artisan migrate` in produzione con questa migration
3. Monitorare i log per errori di esecuzione
4. Verificare che la colonna `surname` sia presente nel DB di produzione tramite query diretta

---

Date: 2026-07-28
Executed by: Claude Code (Task 2)

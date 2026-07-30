> Ticket: oc:8163

# Profilo utente: nome, cognome e avatar (camminiditalia — pubblicazione migration) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pubblicare ed eseguire, sullo shard `camminiditalia`, la migration stub definita in wm-package per la colonna `users.surname`.

**Architecture:** Copia dello stub `.php.stub` (submodule `wm-package`) in `database/migrations/` del repo principale con timestamp reale, seguendo il pattern già in uso (`zz_{stub_timestamp}_...`).

**Tech Stack:** Laravel migrations, PostgreSQL (PostGIS), Docker Compose locale (`laravel-camminiditalia`, `postgres-camminiditalia`).

## Global Constraints

- Nessun commit/branch automatico: i comandi `git commit`/`git push` nei task sono istruzioni testuali per lo sviluppatore.
- Commit convention: `feat(oc:8163): ...`.
- Questo piano dipende dal completamento del piano `wm-package` (Task 1: stub di migration) — non eseguire prima che quello stub esista nel submodule.
- Nessuna migrazione/parsing automatico del `name` esistente in questo ciclo — confermato via query diretta che molti utenti hanno già `name` con nome+cognome concatenato (es. "Gianlorenzo Spaggiari").
- L'esecuzione in produzione richiede backup DB pre-migration e verifica esplicita di `down()` — vedi Task 2.

---

### Task 1: Pubblicare la migration nel repo principale

**Files:**
- Create: `database/migrations/{TIMESTAMP}_zz_2026_07_27_000001_add_surname_to_users_table.php` (dove `{TIMESTAMP}` è il timestamp reale del giorno di pubblicazione, formato `YYYY_MM_DD_HHMMSS`, coerente con `2026_07_13_141750_zz_2026_07_06_000001_add_access_nova_permission.php` già presente)

**Interfaces:**
- Consumes: `wm-package/database/migrations/zz_2026_07_27_000001_add_surname_to_users_table.php.stub` (piano wm-package, Task 1).
- Produces: colonna `users.surname` disponibile sull'ambiente locale/staging di `camminiditalia` — consumato dal Task 2 (verifica pre-produzione) e dal piano `wm-core` (contratto API).

- [ ] **Step 1: Verifica che il submodule `wm-package` sia aggiornato al commit con lo stub**

```bash
cd wm-package && git log --oneline -1 -- database/migrations/zz_2026_07_27_000001_add_surname_to_users_table.php.stub
```

Expected: mostra il commit `feat(oc:8163): add publishable migration stub for users.surname` (dal piano wm-package). Se non lo mostra, aggiorna il submodule prima di procedere:

```bash
cd wm-package && git pull origin <branch-feature-wm-package>
cd .. && git add wm-package && git commit -m "chore: update wm-package submodule to oc:8163 surname migration stub"
```

- [ ] **Step 2: Copia lo stub con timestamp reale**

```bash
NOW=$(date +"%Y_%m_%d_%H%M%S")
cp wm-package/database/migrations/zz_2026_07_27_000001_add_surname_to_users_table.php.stub \
   "database/migrations/${NOW}_zz_2026_07_27_000001_add_surname_to_users_table.php"
```

- [ ] **Step 3: Esegui la migration sull'ambiente Docker locale**

```bash
docker exec -it laravel-camminiditalia php artisan migrate --path="database/migrations/${NOW}_zz_2026_07_27_000001_add_surname_to_users_table.php"
```

Expected: output `Migrated: ...add_surname_to_users_table`.

- [ ] **Step 4: Verifica la colonna sul DB locale**

```bash
docker exec -it postgres-camminiditalia psql -U camminiditalia -d camminiditalia -c "\d users" | grep surname
```

Expected: riga `surname | character varying |` con `nullable` (nessun `not null` nella definizione).

- [ ] **Step 5: Commit**

```bash
git add "database/migrations/${NOW}_zz_2026_07_27_000001_add_surname_to_users_table.php"
git commit -m "feat(oc:8163): publish surname migration for camminiditalia"
```

---

### Task 2: Verifica pre-produzione — `down()` e backup

**Files:**
- Nessun file di codice creato: questo task è una checklist operativa da eseguire prima del deploy in produzione, emersa in Fase: challenge (nessun `down()`/backup era verificato esplicitamente nel piano originale).

**Interfaces:**
- Consumes: migration pubblicata (Task 1).
- Produces: conferma scritta (in `notes.md`, vedi Fase: notes del workflow `wm-plan`) che backup e rollback sono stati verificati prima dell'esecuzione in produzione.

- [ ] **Step 1: Testa `down()` sull'ambiente locale**

```bash
docker exec -it laravel-camminiditalia php artisan migrate:rollback --path="database/migrations/${NOW}_zz_2026_07_27_000001_add_surname_to_users_table.php"
docker exec -it postgres-camminiditalia psql -U camminiditalia -d camminiditalia -c "\d users" | grep surname
```

Expected: nessun output dal secondo comando (colonna `surname` rimossa correttamente). Poi ri-applica la migration per tornare allo stato atteso:

```bash
docker exec -it laravel-camminiditalia php artisan migrate --path="database/migrations/${NOW}_zz_2026_07_27_000001_add_surname_to_users_table.php"
```

- [ ] **Step 2: Verifica esistenza di un backup recente prima dell'esecuzione in produzione**

Non eseguire questo step su ambiente locale — è un controllo da fare manualmente sull'infrastruttura di produzione (dashboard del provider DB, snapshot RDS/managed Postgres, o script di backup interno già in uso dal team) immediatamente prima del deploy. Non c'è comando codificabile in questo repo per questo step: è una verifica organizzativa, non tecnica.

- [ ] **Step 3: Documenta l'esito in `notes.md`**

Aggiungi una riga nella sezione "Decisioni" di `docs/features/8163-profilo-utente-nome-cognome-avatar/notes.md` (questo repo) con esito di Step 1 e conferma di Step 2 prima di autorizzare il deploy in produzione.

---

## Self-Review Checklist (compilata dall'autore del piano)

- **Spec coverage:** pubblicazione migration con timestamp reale (Task 1), verifica `down()`/backup pre-produzione (Task 2) — entrambi i requisiti di `overview.md` sono coperti.
- **Placeholder scan:** Step 2 del Task 2 è intenzionalmente non-codificabile (verifica organizzativa su infrastruttura di produzione, non uno script) — non è un placeholder nel senso di "TODO", è dichiarato esplicitamente come fuori portata di questo repo.
- **Type consistency:** nome del file migration coerente tra Task 1 Step 1/2 e Task 2 Step 1 (stessa variabile `${NOW}` — ricorda che il valore reale va fissato la prima volta che si esegue Step 2 del Task 1 e riusato identico nei passaggi successivi, non rigenerato ogni volta con `date`).

## Execution Handoff

Piano salvato in `docs/features/8163-profilo-utente-nome-cognome-avatar/plan.md`. Due opzioni di esecuzione:

**1. Subagent-Driven (consigliato)** — un subagente fresco per task, review tra un task e l'altro, iterazione rapida.

**2. Inline Execution** — esecuzione in questa sessione con `executing-plans`, batch execution con checkpoint.

Quale preferisci?

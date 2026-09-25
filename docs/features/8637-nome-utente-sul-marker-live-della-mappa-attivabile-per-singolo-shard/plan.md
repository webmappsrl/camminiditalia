> Ticket: oc:8637

# Nome utente sul marker live attivabile per shard — piano di implementazione (camminiditalia)

> **Per chi esegue:** sotto-skill consigliata `superpowers:executing-plans`. **Nessun
> `git add`/`git commit`/`git push`/branch durante l'esecuzione**: i passi "Commit" sono istruzioni
> testuali per il dev, eseguite solo dopo il review-gate.

**Obiettivo:** documentare in `.env-example` la variabile `ANALYTICS_SHOW_LIVE_USER_IDENTITY` introdotta in
`wm-package`, senza attivarla, e allineare il gitlink del submodule.

**Architettura:** nessuna logica locale. La chiave vive in `wm-package/config/wm-package.php` (vedi
il piano dello stesso slug nel submodule); qui solo documentazione e bump.

**Spec:** [overview.md](overview.md); piano del package in
`wm-package/docs/features/8637-nome-utente-sul-marker-live-della-mappa-attivabile-per-singolo-shard/plan.md`.

## Vincoli globali
- Nessun valore impostato in `.env`, `.env.testing` né in `config/wm-package.php` locale (scelta del dev)
- Riga in `.env-example` **commentata**, stesso pattern di `ANALYTICS_SHARD_NAME` (oc:8464)
- Il Task 2 si esegue solo dopo il merge del Task 1 del package su `develop` di wm-package

## Punti da guardare in review
1. La riga di `.env-example` deve restare commentata: una riga attiva verrebbe copiata nei nuovi `.env`
2. Il testo deve descrivere l'esposizione reale per camminiditalia (Validator su layer non propri; Guest escluso dal gate)
3. Il bump del gitlink porta dentro anche gli altri commit del package mergiati nel frattempo: elencarli nella PR

---

### Task 1: documentare `ANALYTICS_SHOW_LIVE_USER_IDENTITY` in `.env-example`

**File:**
- Modifica: `.env-example:14` (subito dopo `#ANALYTICS_SHARD_NAME=camminiditalia`)

- [ ] **Passo 1: aggiungere il blocco commentato**

Dopo la riga `#ANALYTICS_SHARD_NAME=camminiditalia` inserire:

```
# Mostra nome, cognome e link alla scheda utente sul marker live della mappa dei layer in Nova
# (oc:8637, wm-package: config wm-package.analytics_show_live_user_identity). Default false: marker anonimo.
# Con il flag acceso l'identità è visibile a ogni utente che accede a Nova (il Guest è escluso dal
# gate), compresi i Validator anche su layer non propri: l'endpoint della mappa non ha
# autorizzazione per singolo layer. L'identità è quella dichiarata dall'app nell'evento PostHog,
# non verificata dal backend. Dopo il cambio va rigenerata la cache di config (php artisan optimize).
# Cammini d'Italia oggi vuole il marker anonimo: non attivare senza richiesta del cliente.
#ANALYTICS_SHOW_LIVE_USER_IDENTITY=false
```

- [ ] **Passo 2: verificare che la variabile non sia attiva in nessun file**

```bash
grep -n "ANALYTICS_SHOW_LIVE_USER_IDENTITY\|analytics_show_live_user_identity" .env .env.testing config/wm-package.php .env-example
```

Atteso: nessuna occorrenza in `.env`, `.env.testing` e `config/wm-package.php`; in `.env-example`
solo righe che iniziano con `#`.

### Task 2: bump del submodule `wm-package` (dopo il merge del package)

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-2-bump-del-submodule-wm-package-dopo-il-merge-del-package)

**File:**
- Modifica: gitlink `wm-package`

- [ ] **Passo 1: allineare il submodule al `develop` che contiene oc:8637**

```bash
git -C wm-package fetch origin && git -C wm-package checkout develop && git -C wm-package pull
git -C wm-package log --oneline -1 --grep "oc:8637"
```

Atteso: il commit `feat(oc:8637): ...` presente.

- [ ] **Passo 2: elencare i commit del package portati dal bump (per la descrizione della PR)**

```bash
git diff --submodule=log wm-package
```

- [ ] **Passo 3: verificare che il comportamento di camminiditalia non cambi**

⚠️ I container di camminiditalia montano `../wm-package`, non il submodule: un controllo con
`tinker` leggerebbe l'altra copia e darebbe una falsa conferma. Si verifica il submodule sui file:

```bash
grep -n "analytics_show_live_user_identity" wm-package/config/wm-package.php
grep -n "ANALYTICS_SHOW_LIVE_USER_IDENTITY\|analytics_show_live_user_identity" .env .env.testing config/wm-package.php
```

Atteso: la chiave presente nel config del submodule con default `false`; nessuna occorrenza nei
file del consumer. Merge della PR del consumer **insieme** al bump, mai prima.

- [ ] **Passo 4: commit (istruzione per il dev, dopo il review-gate)**

```bash
git add .env-example wm-package docs/features/8637-nome-utente-sul-marker-live-della-mappa-attivabile-per-singolo-shard/ CLAUDE.md
git commit -m "feat(oc:8637): documenta ANALYTICS_SHOW_LIVE_USER_IDENTITY e aggiorna wm-package"
```

PR verso `develop`.

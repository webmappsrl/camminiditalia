> Ticket: oc:8836

# Piano — Messa in sicurezza PHP2Shell sullo shard camminiditalia

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Portare lo shard camminiditalia a PHP 8.4.27 (locale → dev → prod) con hardening permanente del pool FPM, senza perdere le evidenze forensi.

**Architettura:** Due file di configurazione nel repo (`Dockerfile`, `www.conf`) + operazioni Docker manuali sui server, sequenziate per non interferire con i deploy automatici delle GitHub Actions. Nessun codice applicativo.

**Spec:** [overview.md](overview.md) (approvata dal dev)

## Vincoli globali

- Ogni azione che cambia stato su `camminidev` o `cammini` va eseguita **solo dopo conferma esplicita del dev**, una per azione.
- **Nessun commit/branch/push automatico**: i passi git sono istruzioni testuali, eseguite solo al sì esplicito del dev (review-gate di wm-plan).
- Ordine vincolante sui server: evidenze → patch. Mai recreate mentre `deploy_prod.sh`/`deploy_dev.sh` gira.
- La verifica di riuscita è sempre `php -v` **dentro ogni container** che usa l'immagine (`docker ps --filter ancestor=wm-phpfpm:8.4`), mai l'esito della build.
- Rebuild sempre con `--pull`.

## Review Focus

1. Un container che usa `wm-phpfpm:8.4` ma non viene ricreato resta su 8.4.3 mostrando lo stesso tag → verifica per-container (Task 2/5/6).
2. POST multi-foto legittimo fino a ~128M deve passare; singolo file >32M e POST >128M devono fallire pulito → test funzionali (Task 5/6).
3. Recreate lanciato mentre l'Action di deploy gira → corruzione della sequenza; i task sui server impongono l'attesa della fine dell'Action.
4. `php-fpm -tt` deve mostrare i valori nuovi del pool (non basta che il file sia nell'immagine).
5. Horizon su prod è in container a parte e il deploy non lo ricrea → recreate esplicito e verifica che i job ripartano.

---

### Task 1: Pin PHP 8.4.27 e hardening del pool FPM (repo camminiditalia)

**Files:**
- Modify: `docker/configs/phpfpm/Dockerfile:1`
- Modify: `docker/configs/phpfpm/www.conf:542-543` (+ 1 riga nuova)

- [ ] **Step 1: Dockerfile** — riga 1: `FROM php:8.4.3-fpm` → `FROM php:8.4.27-fpm`
- [ ] **Step 2: www.conf** — `php_admin_value[upload_max_filesize]` da `1024M` a `32M`; `php_admin_value[post_max_size]` da `1024M` a `128M`; aggiungere accanto `php_admin_flag[session.upload_progress.enabled] = off`. `php_admin_value[memory_limit] = 1024M` resta invariato
- [ ] **Step 3: verifica statica** — `grep -n "8.4.27\|32M\|128M\|upload_progress" docker/configs/phpfpm/{Dockerfile,www.conf}` mostra i tre valori nuovi e il flag off

### Task 2: Locale — rebuild e verifica

- [ ] **Step 1: build** — `docker compose -f local.compose.yml build --pull php` (ri-tagga `wm-phpfpm:8.4`)
- [ ] **Step 2: recreate** — `docker compose -f local.compose.yml up -d --force-recreate php laravel horizon`
- [ ] **Step 3: verifica per-container** — per ogni nome da `docker ps --filter ancestor=wm-phpfpm:8.4 --format '{{.Names}}'`: `docker exec <nome> php -v` → `PHP 8.4.27`
- [ ] **Step 4: verifica pool** — `docker exec php-camminiditalia php-fpm -tt 2>&1 | grep -E "upload_max_filesize|post_max_size|session.upload_progress"` → 32M / 128M / off
- [ ] **Step 5: fumo applicativo** — `docker exec laravel-camminiditalia php artisan about` esce senza errori

### Task 3: Default condiviso nella procedura wm-package (solo docs)

**Files:**
- Modify: `wm-package/docs/howto/messa-in-sicurezza-php-shard.md` (sezione «Pulizia di configurazione»)

- [ ] **Step 1:** sostituire l'esempio forestas-only con: default di partenza **32M/128M** (il più grande misurato finora: forestas 16,7MB, camminiditalia 19,9MB); la misura sul proprio shard resta obbligatoria — se dà di più, si usa il valore maggiore e si aggiorna questo default
- [ ] **Step 2:** aggiungere nota sulla staleness del pin: il pin esatto va bumpato a ogni CVE del motore; nessun processo automatico lo fa (8.4.3 era pinnato da febbraio 2025)
- [ ] **Step 3:** passare il testo per `wm-context-guard` prima della scrittura definitiva (fase update-context di wm-plan)

### Task 4: Commit e PR (istruzioni testuali — solo dopo review-gate)

- [ ] **Step 1:** branch `feature/oc-8836-messa-in-sicurezza-php2shell-shard-camminiditalia` (repo principale e wm-package, stesso nome)
- [ ] **Step 2:** commit wm-package: `docs(oc:8836): default condiviso 32M/128M e nota staleness pin nella procedura php-shard` → PR wm-package verso il suo branch di integrazione; poi commit camminiditalia: `feat(oc:8836): pin php 8.4.27 e hardening pool FPM (upload_progress off, 32M/128M)` con il bump del gitlink → PR verso `develop`
- [ ] **Step 3:** merge PR su `develop` (il push scatena il deploy automatico su `camminidev` — atteso dal Task 5)

### Task 5: Dev (`camminidev`) — evidenze, patch, verifiche ⚠️ ogni azione su conferma

- [ ] **Step 1: evidenze leggere** (sola lettura, salvate fuori dai repo): log del web server host, `docker logs` + `ps aux` + crontab + file PHP modificati di recente nelle directory scrivibili dei container php/horizon
- [ ] **Step 2: evidenza a costo zero** — `docker commit php-camminiditaliadev evidenze-8836-php-dev`
- [ ] **Step 3:** attendere la **fine del deploy automatico** partito col merge su develop (Task 4 Step 3)
- [ ] **Step 4:** `docker tag wm-phpfpm:8.4 wm-phpfpm:8.4-pre-8836`
- [ ] **Step 5:** build `--pull` dal compose di dev (`develop.compose.yml`)
- [ ] **Step 6:** recreate dei container che usano l'immagine
- [ ] **Step 7:** verifica per-container (`php -v` → 8.4.27 in ciascuno) + `php-fpm -tt` coi valori nuovi + Horizon attivo
- [ ] **Step 8: test funzionali** — POST oversize (>128M) → errore pulito e worker vivi; upload foto UGC <32M ok

### Task 6: Prod (`cammini`) — evidenze, patch, verifiche ⚠️ ogni azione su conferma

- [ ] **Step 1:** verificare che lo snapshot Hetzner (già fatto dal dev) copra la data odierna
- [ ] **Step 2: evidenze** (sola lettura, copiate **fuori dalla macchina**): come Task 5 Step 1 + dump leggero dei log host
- [ ] **Step 3:** merge `develop` → `main` (istruzione testuale, conferma dev)
- [ ] **Step 4:** subito dopo il merge, via SSH: `git pull` manuale + `docker tag wm-phpfpm:8.4 wm-phpfpm:8.4-pre-8836` + build `--pull` — **in parallelo ai test dell'Action**
- [ ] **Step 5:** attendere la fine del deploy automatico (il suo `git pull` è no-op; `horizon:terminate` riavvia i worker sull'immagine vecchia, innocuo)
- [ ] **Step 6:** recreate di `php-camminiditalia` e `horizon-camminiditalia`
- [ ] **Step 7:** verifica per-container + `php-fpm -tt` + Horizon processa job (coda viva)
- [ ] **Step 8: test funzionali** come Task 5 Step 8, su prod

### Task 7: Verifica di compromissione (evidenze prod)

- [ ] **Step 1:** analisi delle evidenze raccolte: log di accesso/errore (dal 20/10), file PHP recenti nelle directory scrivibili, crontab, processi anomali
- [ ] **Step 2:** esito sintetico (senza dati sensibili) proposto al dev per le note del ticket oc:8836

### Task 8: Pulizia post-forense ⚠️ ogni azione su conferma

- [ ] **Step 1:** a verifica di compromissione conclusa: `docker rmi wm-phpfpm:8.4-pre-8836` su dev e prod; su dev anche `docker rmi evidenze-8836-php-dev`
- [ ] **Step 2:** controllo 413/errori upload nei log FPM di dev e prod (nessun upload legittimo respinto)

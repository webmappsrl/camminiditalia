> Ticket: oc:8836

# Messa in sicurezza PHP2Shell (CVE-2026-109618/109619) sullo shard camminiditalia

## Cosa cambia

Lo shard camminiditalia smette di girare su PHP 8.4.3 (vulnerabile a PHP2Shell, RCE non
autenticata nel motore) e passa a PHP 8.4.27 su tutti e tre gli ambienti, a step:
**locale → dev (`camminidev`, 91.99.67.245) → prod (`cammini`, 78.46.250.15)**, entrambi Hetzner.
Insieme al bump, la superficie d'attacco del pool FPM viene ridotta in modo permanente:
`session.upload_progress` spento (oggi non configurato → default PHP acceso: è il vettore di una
delle due CVE) e limiti di upload riportati da 1024M a valori commisurati ai dati reali.

Replica di oc:8833 (forestas, caso d'uso di riferimento) seguendo
`wm-package/docs/howto/messa-in-sicurezza-php-shard.md` (v1.6.0); strategia completa nel tag
Orchestrator `php2shell` (id 706).

## Perché

Il 21/10/2026 sono state pubblicate le CVE PHP2Shell (CVSS 9.2): RCE non autenticata nel motore
PHP, exploit pubblico, attacchi già osservati in rete. La falla è nel motore, non nel codice
applicativo: lo shard è esposto anche se il suo codice è perfetto. Verificato il 22/10: i
container `php-` e `horizon-` di **dev e prod girano PHP 8.4.3** → vulnerabili. In locale i
container risultano già su 8.4.27, ma solo per un effetto collaterale: il tag `wm-phpfpm:8.4` è
stato ricostruito oggi dal lavoro forestas sulla stessa macchina — il Dockerfile di questo repo
pinna ancora `php:8.4.3-fpm`, e un rebuild da qui regredirebbe il tag alla versione vulnerabile.

## Requisiti

- [ ] `docker/configs/phpfpm/Dockerfile`: pin `FROM php:8.4.27-fpm` (oggi `php:8.4.3-fpm`)
- [ ] Pool FPM (`docker/configs/phpfpm/www.conf`): `php_admin_flag[session.upload_progress.enabled] = off`
- [ ] Pool FPM: `php_admin_value[upload_max_filesize] = 32M`, `php_admin_value[post_max_size] = 128M`
      (misura reale: max 19,9MB sul disk `wmfe`, 3.860 media; il POST può portare **più foto in
      una richiesta** — `UgcController.php:158-166` itera `images[]` — quindi `post_max_size`
      copre batch di 4-6 foto grandi). `memory_limit` resta 1024M (finito)
- [ ] **Locale**: rebuild con `--pull`, recreate, verifica `php -v` = 8.4.27 su php/laravel/horizon/scout-init e valori del pool con `php-fpm -tt`
- [ ] **Dev** (`camminidev`): raccolta evidenze leggere (log host/container, file PHP recenti nelle directory scrivibili, crontab) + `docker commit` del container php come evidenza a costo zero, poi merge su develop → **attesa fine del deploy automatico** (porta il Dockerfile sul server) → tag immagine corrente `pre-8836`, build `--pull`, recreate, verifiche; nessuno snapshot (scelta del dev)
- [ ] **Prod** (`cammini`): snapshot Hetzner **già fatto dal dev**; raccolta evidenze (fuori dai repo); merge develop → main, poi subito `git pull` manuale + tag `pre-8836` + build `--pull` **in parallelo ai test dell'Action**; recreate e verifiche **solo dopo la fine del deploy automatico** (mai recreate mentre `deploy_prod.sh` gira)
- [ ] Dopo ogni recreate: verifica **per-container** — `docker ps --filter ancestor=wm-phpfpm:8.4` per enumerare chi usa l'immagine, `php -v` dentro ciascuno (un container non ricreato resta su 8.4.3 mostrando lo stesso tag)
- [ ] A verifica forense conclusa: rimozione dei tag `pre-8836` dai server (immagine vulnerabile pronta all'uso) e controllo nei log di eventuali 413/errori di upload post-patch
- [ ] POST oversize (>64M) → errore pulito (413/400), worker vivi
- [ ] Upload legittimo di una foto UGC (<32M) funzionante dopo il patch
- [ ] Verifica di compromissione sulle evidenze di prod; esito sintetico nelle note del ticket
- [ ] Conoscenza wm-package aggiornata: nella procedura `messa-in-sicurezza-php-shard.md` entra il
      **default di partenza 32M/128M** (il più grande misurato finora: forestas 16,7MB,
      camminiditalia 19,9MB), con la misura sul proprio shard che resta obbligatoria: se dà di
      più, si usa il valore maggiore e si aggiorna il default. Nota anche sulla staleness del pin
      (8.4.3 era pinnato da febbraio 2025 e nessuno l'ha più toccato: alla prossima CVE il bump è
      manuale)
- [ ] Ogni azione che cambia stato su dev e prod è eseguita da Claude via SSH **solo dopo conferma
      esplicita del dev**, una per azione

## Rischi

- **Tag `wm-phpfpm:8.4` condiviso fra più repo locali** (camminiditalia, forestas, due
  boilerplate): sulla macchina del dev l'ultimo rebuild vince. Finché i boilerplate pinnano
  8.4.3, un loro rebuild regredisce il tag anche per camminiditalia. Mitigato qui dal pin nel
  Dockerfile; i boilerplate restano fuori scope (segnalati al dev).
- **Il deploy automatico non ricrea i container**: le GitHub Actions (push su develop → dev, su
  main → prod) fanno solo `git pull` + script + `horizon:terminate`. Il pin arriva sui server
  solo con build `--pull` + recreate manuali, previsti esplicitamente nel piano.
- **Il merge su develop/main fa partire il deploy automatico** (run-tests + SSH): mai recreate
  mentre `deploy_prod.sh` gira. Gestito nella sequenza: build in parallelo ai test, recreate solo
  ad Action conclusa.
- **Breve indisponibilità al recreate** di FPM/Horizon su prod; Horizon va riavviato
  esplicitamente (è in container a parte, il deploy non lo riavvia). Eseguito su conferma del dev.
- **Riduzione limiti upload 1024M → 32M/128M**: un singolo file oltre 32M o un batch oltre 128M
  verrebbe rifiutato. Rischio basso: massimo storico reale 19,9MB, batch coperti fino a ~6 foto
  grandi; verifica post-patch con upload legittimo + controllo 413 nei log.
- **Prod resta vulnerabile finché la sequenza non arriva al suo recreate** (exploit pubblico dal
  21/10). Mitigazione-ponte a caldo proposta e **scartata dal dev**: si va dritti alla patch.

## Out of scope

- Rotazione dei segreti (password DB, SFTP well-known, token): il dev la valuta a parte, dopo che
  **tutti** gli shard saranno in sicurezza.
- Proxy/web server dell'host (fuori dal repo) e aggiornamenti OS host — entrambi i server sono
  Ubuntu 24.04.1 LTS, in supporto: nulla da aggiungere a oc:8835.
- Gli altri shard wm-package (un ticket per shard, come da strategia del tag).
- Snapshot di `camminidev` (scelta esplicita del dev: solo prod).
- Allineamento dei Dockerfile dei boilerplate locali (`boilerplate-test`,
  `laravel-postgis-boilerplate`), anch'essi su 8.4.3.

## Moduli toccati

| File | Repo | Modifica |
|---|---|---|
| `docker/configs/phpfpm/Dockerfile` | camminiditalia | pin `php:8.4.27-fpm` |
| `docker/configs/phpfpm/www.conf` | camminiditalia | `session.upload_progress` off, limiti 32M/128M |
| `docs/features/8836-…/` | camminiditalia | overview, plan, notes |
| `docs/howto/messa-in-sicurezza-php-shard.md` | wm-package (submodule) | default condiviso 32M/128M al posto del solo esempio forestas |

Nessun codice applicativo PHP viene toccato. Sui server: operazioni Docker manuali (tag
`pre-8836`, build `--pull`, recreate) non versionate, descritte nel piano.

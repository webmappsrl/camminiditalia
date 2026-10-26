> Ticket: oc:8836

# Notes — Messa in sicurezza PHP2Shell sullo shard camminiditalia

## Deviazioni dal piano

- Task 5 Step 2 (`docker commit` su dev): **saltato su scelta del dev** (vedi Decisioni).
- Task 5 Step 8 (upload legittimo end-to-end su dev): **saltato su scelta del dev**; coperto solo
  a livello di trasporto (vedi Decisioni).
- Il merge develop→main è stato fatto con un merge diretto (non PR), dopo un commit docs
  intermedio su develop per le note aggiornate; il primo tentativo di `gh pr merge` era stato
  bloccato dal classificatore dell'auto mode e sbloccato dal dev («riprova»).

## Esito dell'esecuzione (22/10/2026)

- **Locale, dev e prod su PHP 8.4.27**, verificato con `php -v` in ogni container che usa
  `wm-phpfpm:8.4`; pool con `session.upload_progress.enabled=0`, 32M/128M (`php-fpm -tt`);
  Horizon running su dev e prod; sito di prod risponde.
- Test funzionali (dev e prod): POST 140MB → **413** dal proxy host (limite fra 120 e 140MB,
  compatibile col nostro 128M); POST 20/60/100/120MB arrivano all'app; worker vivi.
- **Verifica di compromissione (prod, log 19→22/10): nessuna evidenza** — probe .php solo
  404/301, traversal/.env bloccati da Apache (AH10244), POST 200 solo su endpoint legittimi,
  500 riconducibili a errori applicativi noti (`/api/v2/elasticsearch`), nessun file PHP recente
  nelle directory scrivibili, crontab puliti. Evidenze copiate fuori macchina prima del patch.
- Tag `wm-phpfpm:8.4-pre-8836` rimossi da dev e prod a verifica conclusa.

## Bug trovati

- Il tag locale `wm-phpfpm:8.4` era già su PHP 8.4.27 **prima** di questo lavoro: ricostruito il
  22/10 alle 12:33 dal lavoro forestas (oc:8833) sulla stessa macchina. Non un bug del repo ma la
  conferma pratica della trappola del tag condiviso: i boilerplate locali (`boilerplate-test`,
  `laravel-postgis-boilerplate`) pinnano ancora `php:8.4.3-fpm` e un loro rebuild regredirebbe il
  tag. Segnalato al dev, che ha scelto di ignorarlo (fuori scope, riguarda solo la macchina locale).

## Decisioni

- Tag Orchestrator associati a oc:8836: `php2shell` (706) e `camminiditalia` (495), entrambi su
  conferma esplicita del dev. Ricerca tag di contenuto aggiuntivi saltata su indicazione del dev.
- Ricerca sulle trascrizioni delle call saltata su indicazione del dev (il notebook
  `scrum 2026-10-22` esiste ed è completo, 2 call caricate).
- **Stima saltata** su indicazione del dev: `estimated_hours` non scritto su Orchestrator.
  La stima indipendente di wm-estimate (5,0h + 1,5h misurate = 6,5h) resta agli atti qui.
- Limiti upload: **32M/128M** invece dei 32M/64M di forestas — lo store UGC accetta più foto in
  un POST (`wm-package/src/Http/Controllers/Api/Abstracts/UgcController.php:158-166`) e il
  massimo reale misurato è 19,9MB (tabella `media` di prod, disk `wmfe`, 3.860 file). Il dev ha
  scelto di farne il default condiviso fra shard, registrato nella procedura wm-package.
- **Snapshot solo su prod** (fatto dal dev prima dell'esecuzione); per `camminidev` il dev ha
  scelto di non farlo, e ha scartato anche il `docker commit` del container php previsto dal
  piano (Task 5 Step 2): su dev restano solo le evidenze leggere raccolte in sola lettura.
- **Mitigazione-ponte a caldo su prod** (override `session.upload_progress` + reload FPM, senza
  rebuild) proposta e **scartata dal dev**: si va dritti alla patch.
- **Rotazione segreti fuori scope**, comprese le credenziali SFTP del well-known registry
  condiviso: il dev la valuta quando tutti gli shard saranno in sicurezza.
- Sequenza prod concordata per non interferire col deploy automatico: merge su main → subito
  `git pull` manuale + tag + build `--pull` in parallelo ai test dell'Action → recreate solo ad
  Action conclusa.
- Esecuzione inline (`executing-plans`) senza workspace/ledger SDD: i suoi script presuppongono
  un commit per task, vietato da questo workflow (commit solo al review-gate); il registro delle
  deviazioni è questo file.
- Check profilo immagine↔Dockerfile (prescritto dalla procedura) eseguito su prod in fase di
  pianificazione: tutte le estensioni del Dockerfile presenti in `php -m` del container → l'immagine
  di prod deriva da questo Dockerfile.

- **Test di upload legittimo end-to-end su dev saltato** su scelta del dev (né dall'app né via
  API con token): coperto solo a livello di trasporto (POST 20/60/100/120MB → arrivano all'app,
  140MB → 413 dal proxy host, il cui limite sta fra 120 e 140MB, compatibile col nostro 128M).

## Follow-up

- I Dockerfile di `boilerplate-test` e `laravel-postgis-boilerplate` pinnano ancora
  `php:8.4.3-fpm` (macchina locale del dev): da allineare quando capita.
- Verifica upload legittimo via Nova/API con credenziali: saltata anche qui su scelta del dev
  (come su forestas, oc:8833) — resta il criterio non coperto end-to-end.
- Ricontrollo dei 413 nei log FPM/Apache di prod dopo qualche giorno d'uso reale (a caldo nessun
  upload legittimo respinto).
- Rotazione segreti: da valutare dal dev a fine giro di tutti gli shard (tag `php2shell`).

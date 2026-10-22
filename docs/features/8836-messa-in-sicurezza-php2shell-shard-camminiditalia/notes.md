> Ticket: oc:8836

# Notes — Messa in sicurezza PHP2Shell sullo shard camminiditalia

## Deviazioni dal piano

(nessuna finora)

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
- Verifica upload legittimo via Nova/API con credenziali: su forestas (oc:8833) era rimasto
  l'unico criterio aperto; qui è nel piano (Task 5/6 Step 8).

> Ticket: oc:9050

# Modifiche alle tappe salvate in Nova non visibili in app — piano dello shard

> **Per chi esegue:** **nessun commit durante l'esecuzione**. Il Task 2 si fa in produzione, dopo il
> deploy, e ogni comando che scrive o accoda va mostrato al dev e approvato prima di lanciarlo.

**Obiettivo:** portare in camminiditalia la correzione di `wm-package` e rimettere in pari JSON e PBF
delle tappe ferme sull'elevation chart.

**Spec:** [overview.md](overview.md); codice in
[wm-package/docs/features/9050-…/plan.md](../../../wm-package/docs/features/9050-modifiche-alle-tappe-salvate-in-nova-non-visibili-in-app/plan.md).

## Vincoli globali

- Il package si mergia prima, poi qui si aggiorna il gitlink (regola di `wm-package/CLAUDE.md`).
- In produzione: `ssh cammini`, container `php-camminiditalia`, app in `/var/www/html/camminiditalia`.
- I 790 job falliti in `failed_jobs` **non si ritentano** da Horizon: contengono la catena vecchia.

---

### Task 1: gitlink `wm-package`

Requisiti: [R1](overview.md#r1)

- [ ] **Passo 1:** dopo il merge della PR del package, punta il submodule al commit di merge e lancia
  `docker exec laravel-camminiditalia php artisan test`: nessun test nuovo rosso.
- [ ] **Commit (separato da quelli di lavoro, nella stessa PR):** `chore(oc:9050): aggiornamento di wm-package`

### Task 2: rimettere in pari la produzione dopo il deploy

Requisiti: [R2](overview.md#r2), [R3](overview.md#r3)

- [ ] **Passo 1:** verifica che Horizon sia stato riavviato col codice nuovo (`StartedAt` di
  `horizon-camminiditalia` dopo il deploy).
- [ ] **Passo 2:** script tinker in sola lettura: id distinti delle EcTrack ancora esistenti nei
  `failed_jobs` con payload `UpdateEcTrackGenerateElevationChartImage` (regex `s:2:"id";i:(\d+)` sul
  `data.command`). Atteso: circa 539.
- [ ] **Passo 3 (scrive, chiedere al dev):** per ogni id
  `Bus::chain(EcTrackService::make()->publicationJobs($track))->dispatch()`, a blocchi di 100; poi
  `DispatcherAppPbfsDebouncedJob::dispatch(1)`.
- [ ] **Passo 4:** a code vuote, rifai il confronto `updated_at` JSON/DB su tutte le EcTrack. Le
  tappe ancora indietro (attese: le 3 ferme sul DEM il 20/11) si ripubblicano allo stesso modo.
  Esito, data e chi l'ha fatto vanno nella riga `Verifica` di [R3](overview.md#r3).

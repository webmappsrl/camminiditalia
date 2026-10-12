> Ticket: oc:8718

# UgcTrack duplicati con lo stesso uuid: replicare il fix fatto per gli UgcPoi (oc:6951)

## Cosa cambia

Il fix vive in wm-package: vedi [wm-package/docs/features/8718-ugctrack-duplicati-stesso-uuid/overview.md](../../../wm-package/docs/features/8718-ugctrack-duplicati-stesso-uuid/overview.md). In camminiditalia:

- bump del submodule `wm-package`;
- pubblicazione ed esecuzione della migration della tabella `ugc_duplicates_archive`;
- esecuzione una tantum del command di normalizzazione: prima in report, poi con `--execute` dopo la verifica.

## Perché

Su camminiditalia ci sono 15 uuid di UgcTrack duplicati (33 righe su 285). Nessun codice di camminiditalia sovrascrive i controller UGC del package.

## Requisiti

- [ ] Gitlink di `wm-package` aggiornato al commit del fix
- [ ] Migration allineate con la procedura del package (`wm-package:publish-missing-migrations --dry-run`)
- [ ] Command lanciato in report sul DB di sviluppo: i 15 gruppi attesi compaiono, nessuno saltato per geometria
- [ ] Procedura di rilascio documentata: backup DB → report → verifica → `--execute`
- [ ] Suite `php artisan test` verde

## Rischi

- Il command cancella righe (dopo averle archiviate): backup del DB prima di `--execute` in produzione.

## Out of scope

- Qualunque modifica di codice in `app/`: la gestione di `layer_id` e `layer_id_auto_resolved` resta invariata grazie all'unione delle properties nello store del package.

## Moduli toccati

- `wm-package` (gitlink)
- eventuale migration pubblicata in `database/migrations/`

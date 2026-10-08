> Ticket: oc:8718

# Notes — UgcTrack duplicati con lo stesso uuid

Il dettaglio del lavoro è nel cantiere di wm-package:
[wm-package/docs/features/8718-ugctrack-duplicati-stesso-uuid/notes.md](../../../wm-package/docs/features/8718-ugctrack-duplicati-stesso-uuid/notes.md).

## Esito sul DB di sviluppo (07/10/2026)

- Migration `create_ugc_duplicates_archive_table` pubblicata con `wm-package:publish-missing-migrations` e migrata su sviluppo e `camminiditalia_testing`.
- `php artisan wm:fix-duplicated-ugc` (report): **15 gruppi**, tutti "da unire", distanza massima tra copie 0.00 m; nessuno da verificare. `--execute` non lanciato.
- `php artisan test`: 375 passati.

## Rilascio

1. Merge di wm-package e bump del submodule.
2. `php artisan wm-package:publish-missing-migrations --dry-run` → `migrate`.
3. Backup del DB.
4. `php artisan wm:fix-duplicated-ugc` → leggere la tabella stampata a schermo (la stessa informazione finisce in `storage/logs/duplicated-ugc-*.log`).
5. Se il report torna: `php artisan wm:fix-duplicated-ugc --execute`.
6. Controllo: il comando rilanciato riporta `0 gruppi`.
7. Solo dopo: la pulizia GPS di oc:8719 (`wm:clean-ugc-track-geometry --dry-run`, poi senza). Prima i duplicati, perché i job di pulizia aggiornano `updated_at`, che il command dei duplicati usa per ordinare le copie a parità di orario del device, e perché così non si puliscono 18 copie destinate a essere cancellate.

**Attenzione:** se nel frattempo è arrivato su `develop` il passaporto (oc:8165), la tabella `validated_ec_track_ugc_track` ha una FK verso `ugc_tracks` con `ON DELETE CASCADE` e il command non la gestisce: non lanciare `--execute` prima di averlo esteso.

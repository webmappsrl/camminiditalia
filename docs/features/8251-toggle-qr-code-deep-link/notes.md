> Ticket: oc:8251

# Notes — Toggle QR code deep link (camminiditalia)

## Deviazioni dal piano

Nessuna specifica di questo repo. Tutta la logica implementata vive in `wm-package` — vedi `wm-package/docs/features/8251-toggle-qr-code-deep-link/notes.md` per il dettaglio completo delle deviazioni (in particolare: cambio di approccio da Nova Action a Nova Field per la generazione del QR code).

## Bug trovati

- Il container Docker locale non era attivo all'avvio della sessione — riavviato.
- Provisioning locale mancante per il DB `wm_package` usato dai test standalone di `wm-package` (ruolo Postgres + estensione postgis) — creati per questa sessione di sviluppo.

## Decisioni

Nessuna modifica di codice in questo repo per questo ciclo (solo `.env` di configurazione SFTP, da fare in seguito — vedi Follow-up).

## Follow-up

- Valorizzare in `.env` locale/produzione: `WELLKNOWN_SFTP_HOST`, `WELLKNOWN_SFTP_USERNAME`, `WELLKNOWN_SFTP_PRIVATE_KEY_PATH`, `WELLKNOWN_SFTP_ROOT`, `WMPACKAGE_APPLE_TEAM_ID`.

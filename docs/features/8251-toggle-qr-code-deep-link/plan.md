> Ticket: oc:8251

# Piano di implementazione — Toggle QR code deep link (camminiditalia)

Il grosso dell'implementazione vive in `wm-package` — vedi `wm-package/docs/features/8251-toggle-qr-code-deep-link/plan.md` per il piano completo (10 task).

In questo repo l'unico task è di configurazione ambiente.

## 1. Configurazione SFTP locale

- Valorizzare in `.env` (locale/produzione, non versionato): `WELLKNOWN_SFTP_HOST`, `WELLKNOWN_SFTP_USERNAME`, `WELLKNOWN_SFTP_PRIVATE_KEY_PATH`, `WELLKNOWN_SFTP_ROOT`, `WMPACKAGE_APPLE_TEAM_ID`
- Nessun commit necessario (file non versionato) — annotare in `notes.md` quando fatto

Nessun'altra modifica di codice attesa in questo repo per questo ciclo.

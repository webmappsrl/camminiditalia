> Ticket: oc:8251

# Toggle QR code deep link nel backend + generazione automatica file well-known

## Cosa cambia
La quasi totalità della logica di questa feature vive nel submodule `wm-package` (vedi `wm-package/docs/features/8251-toggle-qr-code-deep-link/overview.md` per i dettagli completi: toggle app, azione QR/deep-link su Track/Poi, script well-known).

In questo repo (camminiditalia) cambia solo la **configurazione dell'ambiente**: credenziali SSH/SFTP per lo script che aggiorna il file well-known condiviso sul server della webapp.

## Perché
Vedi overview in `wm-package` per il contesto completo. In sintesi: attivare/disattivare per singola app la possibilità di aprire l'app nativa tramite QR/link diretto (feature nativa già sviluppata in oc:7980 su `webmapp-app`), e permettere ai gestori di generare il materiale promozionale (QR, link) da usare su guide cartacee e cartelli.

## Requisiti
- [ ] `.env` locale/produzione valorizzato con le credenziali SFTP verso il server della webapp (host, utente, path chiave privata, directory remota del file well-known)
- [ ] Nessun'altra modifica di codice attesa in questo repo — la feature è generica e vive interamente in `wm-package`

## Rischi
Vedi `wm-package/docs/features/8251-toggle-qr-code-deep-link/overview.md` — i rischi (race condition sul file condiviso, dipendenza da accesso SSH esterno, fingerprint manuale) sono relativi alla logica implementata nel package, non specifici di questo repo.

## Out of scope
- Qualsiasi override locale in `app/Nova/App.php`, `app/Nova/EcTrack.php`, `app/Nova/EcPoi.php` — a meno che non emerga un bisogno specifico di camminiditalia durante l'implementazione (in tal caso verrà documentato qui)
- Modifiche al repo `webmapp-app` (oc:7980)

## Moduli toccati
- `.env` — credenziali SFTP (non versionate)

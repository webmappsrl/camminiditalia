> Ticket: oc:8163

# Profilo utente: nome, cognome e avatar (camminiditalia — pubblicazione migration)

## Cosa cambia

Lo shard `camminiditalia` pubblica ed esegue la migration stub definita in `wm-package` (colonna `surname` opzionale su `users`), rendendo effettivamente disponibili in produzione i nuovi campi per gli utenti di questo shard. Questo è l'unico cambio nel repo principale: tutta la logica applicativa vive in `wm-package`.

## Perché

Il ticket è taggato `ass_cammini_italia` — questo shard è il target esplicito della feature. Senza la pubblicazione della migration, il codice di `wm-package` esiste ma il campo `surname` non è disponibile a livello di DB per questo shard.

## Requisiti

- [ ] Migration stub di wm-package pubblicata in `database/migrations/` con timestamp reale (pattern esistente: `{publish_timestamp}_zz_{stub_timestamp}_add_surname_to_users_table.php`, coerente con `2026_07_13_141750_zz_2026_07_06_000001_add_access_nova_permission.php` già presente)
- [ ] Migration eseguita sull'ambiente locale/staging di `camminiditalia`; esecuzione in produzione demandata al deploy standard
- [ ] Verifica esplicita, prima dell'esecuzione in produzione, che il metodo `down()` della migration sia corretto (drop della colonna `surname`, nullable, nessuna dipendenza FK) e che esista un backup del DB immediatamente precedente all'esecuzione — emerso in Fase: challenge, non era garantito solo dal precedente (`add_access_nova_permission`) mai stress-testato in rollback

## Rischi

- **Coordinamento deploy** — se la migration viene applicata prima che wm-core/webmapp-app espongano la UI corrispondente, il campo esiste ma resta inutilizzato: non bloccante, solo incompleto fino al rilascio congiunto.
- **Dati esistenti** — confermato via query diretta sul DB locale (`SELECT name FROM users`) che molti utenti esistenti hanno già `name` con nome e cognome concatenati (es. "Gianlorenzo Spaggiari", "massimo gardini"). La migration si limita ad aggiungere la colonna `surname` nullable — nessuna migrazione/parsing automatico del `name` esistente è previsto in questo ciclo (vedi rischio corrispondente in overview wm-core sulla UI).

## Out of scope

- Pubblicazione della stessa migration per altri shard (maphub, osm2cai2, carg, forestas) — restano su decisione autonoma di ciascuno, come da design dello stub

## Moduli toccati

- `database/migrations/` (nuovo file di migration pubblicato dallo stub wm-package)

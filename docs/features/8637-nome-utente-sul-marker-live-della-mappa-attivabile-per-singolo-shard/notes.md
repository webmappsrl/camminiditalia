> Ticket: oc:8637

# Notes — Nome utente sul marker live della mappa attivabile per singolo shard

## Divergenze dal piano, task per task

### Task 2 bump del submodule wm-package dopo il merge del package
Non eseguito in questo ciclo: richiede che la PR del package sia mergiata su `develop` di
`wm-package`. Da fare dopo il merge, seguendo i passi del piano. Nel frattempo il gitlink resta quello
di `develop`, che non contiene la chiave `analytics_show_live_user_identity` (il comportamento resta
comunque anonimo). La verifica del Passo 3 è stata cambiata da `tinker` a un controllo sui file del
submodule: i container montano `../wm-package` e `tinker` darebbe una falsa conferma.

## Bug trovati
- I container di camminiditalia (`php-camminiditalia`, `laravel-camminiditalia`) montano
  `../wm-package` (la copia del package accanto ai progetti), **non** il submodule
  `camminiditalia/wm-package`: modifiche fatte nel submodule non sono viste dall'app in esecuzione.
  Dettaglio e comando usato per i test in `wm-package/docs/features/8637-.../notes.md`.

## Decisioni
- Parametro rinominato in `ANALYTICS_SHOW_LIVE_USER_IDENTITY` dopo l'approvazione del piano, su richiesta
  del dev (famiglia analytics, come `ANALYTICS_SHARD_NAME`).
- Nessun blocco `'analytics_show_live_user_identity' => false` nel `config/wm-package.php` locale, benché il
  file sia la sede preferita per i valori versionati (oc:8463): decisione del dev — chi imposta la
  variabile d'ambiente lo fa di proposito.
- Testo di `.env-example` corretto dopo la challenge: il Guest è escluso dal gate Nova di
  camminiditalia; l'esposizione reale riguarda i Validator su layer non propri.
- Nessun tag aggiunto al ticket (scelta del dev).

## Follow-up
- Bump del gitlink dopo il merge del package (Task 2).

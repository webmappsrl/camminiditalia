> Ticket: oc:8637

# Nome utente sul marker live della mappa attivabile per singolo shard

## Cosa cambia
La visibilità di nome e link sul marker live della mappa diventa configurabile per shard tramite
la variabile d'ambiente `ANALYTICS_SHOW_LIVE_USER_IDENTITY` (default `false`), introdotta in `wm-package`.
In camminiditalia la variabile **non viene attivata**: il marker resta anonimo. Il repo riceve
solo la documentazione della variabile e il bump del submodule.

## Perché
Allo scrum del 23/09/2026 è stato deciso di rendere opzionale l'anonimato introdotto da oc:8586,
con una variabile per shard e non con un'impostazione in Nova. Per Cammini d'Italia: «la vuole
anonima».

## Requisiti
- [ ] `.env-example`: riga commentata `#ANALYTICS_SHOW_LIVE_USER_IDENTITY=false` con spiegazione, stesso
      pattern di `ANALYTICS_SHARD_NAME` (oc:8464): cosa attiva (nome, cognome e link alla scheda
      sul marker live); visibile a ogni utente che accede a Nova (il Guest è escluso dal gate),
      compresi i Validator anche su layer non propri, perché l'endpoint della mappa non ha
      autorizzazione per singolo layer; dopo il cambio va rigenerata la cache di config; il cliente
      oggi vuole il marker anonimo
- [ ] Nessun valore impostato in `.env`, `.env.testing` né in `config/wm-package.php` locale.
      Scelta esplicita del dev: nessun blocco a `false` nel config locale — chi imposta la variabile
      lo fa di proposito
- [ ] Bump del gitlink `wm-package` dopo il merge della parte nel package

## Rischi
- Qualcuno attiva la variabile in produzione su camminiditalia: nomi e posizioni dei pellegrini
  diventano visibili a tutti i Validator, su ogni cammino. Accettato: il flag non viene bloccato nel
  config locale (decisione del dev); il commento in `.env-example` descrive l'esposizione reale.

## Out of scope
- Tutto il resto: logica, test e config vivono in `wm-package` (vedi l'overview dello stesso slug nel submodule)

## Moduli toccati
- `.env-example`
- gitlink del submodule `wm-package`

# Immagine PHP dello shard: pin, pool FPM e aggiornamento

## Come funziona oggi

La procedura generale (ordine evidenze → patch, bump pinnato con `--pull`, verifica `php -v` in
ogni container, default dei limiti di upload) vive in
`wm-package/docs/howto/messa-in-sicurezza-php-shard.md`: qui c'è solo ciò che è di camminiditalia.

- L'immagine `wm-phpfpm:8.4` è prodotta da `docker/configs/phpfpm/Dockerfile` (base pinnata
  `php:8.4.27-fpm`, oc:8836). La buildano i servizi php, laravel, horizon e scout-init;
  `develop.compose.yml` eredita da `compose.yml`.
- Pool FPM (`www.conf`): `session.upload_progress.enabled=off`, `upload_max_filesize=32M`,
  `post_max_size=128M`, `memory_limit=1024M`. `php.ini` porta numeri diversi (50M/2048M), ma
  vincono i `php_admin_*` del pool.
- **Il deploy automatico non ricrea i container** (`prod-deploy.yml`/`dev-deploy.yml`: `git pull`
  + script nel container + `horizon:terminate`): una modifica a `docker/configs/phpfpm/` arriva
  sui server solo con build `--pull` + recreate manuali via SSH (prod `cammini`, dev `camminidev`,
  entrambi Hetzner). Mai recreate mentre il deploy automatico sta girando.
- **Trappola della macchina locale**: il tag `wm-phpfpm:8.4` è condiviso con forestas e i due
  boilerplate (`boilerplate-test`, `laravel-postgis-boilerplate`): l'ultimo che builda vince, e un
  `--force-recreate` dopo un rebuild altrui può regredire i container locali in silenzio.

## Perché così

- **32M/128M** (oc:8836): il massimo reale in storage era 19,9MB (tabella `media`, disk `wmfe`,
  produzione, rilevato il 2026-10-22), ma lo store UGC accetta più foto in un POST
  (`UgcController` del package itera `images[]`), quindi `post_max_size` è largo. È anche il
  default condiviso fra shard registrato nell'howto.
- **Verifiche e hardening secondo l'howto** (oc:8836, nato con oc:8833 su forestas): le
  motivazioni di pin, `upload_progress` off e ordine forense stanno lì, non qui.

## Come ci siamo arrivati

- **`php:8.4.3-fpm` pinnato dal 2025-02-06** (commit `7bd7913`) e mai più toccato: la staleness
  che ha lasciato lo shard esposto a PHP2Shell (CVE-2026-109618/109619) fino a oc:8836.
- **Limiti upload a 1024M/1024M** (pre-oc:8836): un limite di fatto inesistente, superato perché
  amplificava la superficie delle CVE di upload senza bisogno reale.
- **Mitigazione-ponte a caldo su prod** (override del pool + reload FPM senza rebuild, proposta in
  oc:8836): scartata dal dev — si è andati dritti alla patch.
- **Rotazione dei segreti dopo il patch**: rimandata deliberatamente a quando tutti gli shard
  saranno in sicurezza (decisione del dev in oc:8836, come in oc:8833 per forestas).

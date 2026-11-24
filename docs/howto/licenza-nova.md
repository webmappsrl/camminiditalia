# Registrare la licenza Nova su uno shard

Se il pannello Nova mostra in rosso la scritta **«Unregistered»** accanto al logo, il server non ha
una chiave di licenza valida per il suo dominio. Si risolve nell'account Nova e nel `.env` del
server: **non si nasconde con il CSS** (oc:9031).

Fonte ufficiale: https://nova.laravel.com/docs/v5/installation, sezioni «Registering a Nova
License Key and Production URL» e «Using Nova on Development and Staging Domains».

> «Nova requires a license key and a production URL to be used in production environments.»

## Da dove viene la scritta

`LicenseWarning.vue` di Nova la mostra quando `Nova::checkLicenseValidity()` è falso
(`vendor/laravel/nova/src/Nova.php`). Il controllo manda a `nova.laravel.com/api/license-check`
il dominio della richiesta e `config('nova.license_key')`, cioè `NOVA_LICENSE_KEY`, e salva
l'esito in cache per un'ora con la chiave `nova_valid_license_key`.

Le risposte di Laravel dicono cosa manca:

- «There is no license associated with the provided key» → chiave assente o sbagliata;
- «The license key is valid, but we were not able to find a correctly configured site for the
  host: …» → chiave giusta, dominio non registrato.

## Procedura

Serve l'accesso all'account Webmapp su nova.laravel.com.

1. **Licenses** → **«Laravel Nova Unlimited License»** (serie Silver Surfer, Nova 4–5). La licenza
   «WEBMAPP» è la vecchia, per Nova 1–3: non è quella giusta.
2. **Production URLs** → **«Add site»**: il dominio di produzione dello shard, solo l'host (senza
   `https://` né `/`), per esempio `camminiditalia.maphub.it`.
   > «You can generate license keys and register the production URL for your project inside the
   > license's page»
3. **License Keys** → **«Create License Key»**, con il nome dello shard. Una chiave per shard: si
   può rigenerare o revocare senza toccare gli altri progetti, né la chiave che Composer usa in
   `auth.json` per scaricare Nova.
4. Nel `.env` del server: `NOVA_LICENSE_KEY=<chiave>`.
   > «You can register your license key by setting the `NOVA_LICENSE_KEY` variable to `.env` file»
   Il deploy non tocca il `.env`, e mette in cache la config (`php artisan optimize` in
   `scripts/deploy_prod.sh`): dopo la modifica va rieseguito `php artisan config:cache` (o
   `php artisan optimize`), altrimenti la chiave nuova non viene letta.
5. Verifica:
   ```bash
   php artisan nova:check-license
   ```
   > «To verify everything has been configured correctly, you should run the `nova:check-license` command»
   Il comando svuota da sé la cache `nova_valid_license_key` e rifà il controllo usando l'host di
   `APP_URL`, che deve essere il dominio registrato. Se risponde «Your license key is valid and
   correctly configured!», alla prossima pagina la scritta non c'è più.

## Cosa non fare

- **Nascondere la scritta con il CSS** (`a[href*="nova.laravel.com/licenses"] { display: none }`):
  è stato fatto su Forestas e osm2cai, ma lascia il pannello senza licenza.
- **Usare come prova il `validLicense` della pagina di login**: per un utente non loggato Nova
  legge solo la cache (`HandleInertiaRequests.php`), quindi dice solo com'era andato l'ultimo
  controllo fatto da un utente loggato.
- **Contare sull'esclusione dei domini di sviluppo quando non si applica.** La documentazione:
  > «Nova will not check your license key when used on `localhost` or local TLDs like those
  > specified in IETF RFC 2606» (`.test`, `.example`, `.invalid`, `.localhost`, `.local`)
  > «Nova will also not check the current license key when the subdomain is one of these
  > commonly-used staging subdomains:» `staging.`, `stage.`, `test.`, `testing.`, `dev.`, `development.`

  Con `dev.` in mezzo al dominio l'esclusione non è valsa: il 24/11/2026 la verifica di
  `camminiditalia.dev.maphub.it` con una chiave valida ha risposto «not able to find a correctly
  configured site». Anche in locale, su `localhost:8000`, Nova 5.7.6 mostra la scritta se manca
  la chiave. Se si vuole il pannello pulito su questi ambienti, il dominio va registrato come gli
  altri.
- **Scrivere la chiave nel repo** (in `.env-example`, nei documenti, nei ticket): resta solo nel
  `.env` del server.

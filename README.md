## INSTALLAZIONE

Prima di tutto, installa il repository [GEOBOX](https://github.com/webmappsrl/geobox) e configura il [comando ALIASES](https://github.com/webmappsrl/geobox#aliases-and-global-shell-variable).  
Sostituisci `${instance name}` con il nome dell'istanza (APP_NAME nel file .env-example).

### Clona il repository
```sh
git clone git@github.com:webmappsrl/${repository_name}.git ${instance name}
```

### Nota importante: ricordati di eseguire il checkout del branch develop.

```sh
cd ${instance name}
bash docker/init-docker.sh
docker exec -u 0 -it php_${instance name} bash
chown -R 33 storage
```

### Se hai installato XDEBUG, crea il file xdebug.log nel container Docker

```sh
docker exec -u 0 -it php_${instance name} bash
touch /var/log/xdebug.log
chown -R 33 /var/log/
```
### Installa le dipendenze
Avvia una bash all'interno del container php per installare tutte le dipendenze (utilizzare `APP_NAME` al posto di `$nomeApp`):

```sh
docker exec -it php_$nomeApp bash
composer install
php artisan key:generate
php artisan optimize
php artisan migrate
```

#### Nota:

- Per completare l'installazione di Laravel Nova, é necessario fornire le credenziali di accesso.

### Avvia il server
All'interno del container php, lancia il comando `composer run dev` per avviare il server.
A questo punto l'applicativo è in ascolto su <http://127.0.0.1:8000> (la porta è quella definita in `DOCKER_SERVE_PORT`)

### Differenze ambiente produzione locale

Questo sistema di container docker è utilizzabile sia per lo sviluppo locale sia per un sistema in produzione.

Di fatto il comando init-docker per utilizzare l'ambiente prod usa:

```sh
docker compose up -d
```

Per l'ambiente dev/locale invece:

```sh
docker compose -f develop.compose.yml up -d
```

In locale abbiamo queste caratteristiche:

-   la possibilità di lanciare il processo `composer run dev` all'interno del container phpfpm, quindi la configurazione della porta `DOCKER_SERVE_PORT` (default: `8000`) e `DOCKER_VITE_PORT` (default:`5173`) necessaria al progetto. Se servono più istanze laravel con processo artisan serve contemporaneamente in locale, valutare di dedicare una porta tcp dedicata ad ognuno di essi. Per fare questo basta solo aggiornare `DOCKER_SERVE_PORT` e `DOCKER_VITE_PORT`.
-   la presenza di xdebug, definito in fase di build dell'immagine durante l'esecuzione del comando
-   `APP_ENV=local`, `APP_DEBUG=true` e `LOG_LEVEL=debug` che istruiscono laravel su una serie di comportamenti per il debug e l'esecuzione locale dell'applicativo
-   Una password del db con complessità minore. **In produzione usare [password complesse](https://www.avast.com/random-password-generator#pc)**

### Configurazione xdebug vscode (solo in locale)

Assicurarsi di aver installato l'estensione [PHP Debug](https://marketplace.visualstudio.com/items?itemName=xdebug.php-debug).

Una volta avviato il container con xdebug configurare il file `.vscode/launch.json`, in particolare il `pathMappings` tenendo presente che **sulla sinistra abbiamo la path dove risiede il progetto all'interno del container**, `${workspaceRoot}` invece rappresenta la pah sul sistema host. Eg:

```json
{
    "version": "0.2.0",
    "configurations": [
        {
            "name": "Listen for Xdebug",
            "type": "php",
            "request": "launch",
            "port": 9200,
            "pathMappings": {
                "/var/www/html/${APP_NAME}": "${workspaceRoot}"
            }
        }
    ]
}
```

Per utilizzare xdebug **su browser** utilizzare uno di questi 2 metodi:

-   Installare estensione xdebug per browser [Xdebug helper](https://chrome.google.com/webstore/detail/xdebug-helper/eadndfjplgieldjbigjakmdgkmoaaaoc)
-   Utilizzare il query param `XDEBUG_SESSION_START=1` nella url che si vuole debuggare
-   Altro, [vedi documentazione xdebug](https://xdebug.org/docs/step_debug#web-application)

Invece **su cli** digitare questo prima di invocare il comando php da debuggare:

```bash
export XDEBUG_SESSION=1
```

### Problemi noti

Durante l'esecuzione degli script potrebbero verificarsi problemi di scrittura su certe cartelle, questo perchè di default l'utente dentro il container è `www-data (id:33)` quando invece nel sistema host l'utente ha id `1000`:

-   Chown/chmod della cartella dove si intende scrivere, eg:

    NOTA: per eseguire il comando chown potrebbe essere necessario avere i privilegi di root. In questo caso si deve effettuare l'accesso al cointainer del docker utilizzando lo specifico utente root (-u 0). Questo è valido anche sbloccare la possibilità di scrivere nella cartella /var/log per il funzionamento di Xdedug

    Utilizzare il parametro `-u` per il comando `docker exec` così da specificare l'id utente, eg come utente root (utilizzare `APP_NAME` al posto di `$nomeApp`):

    ```bash
    docker exec -u 0 -it php_$nomeApp bash
    chown -R 33 storage
    ```

Xdebug potrebbe non trovare il file di log configurato nel .ini, quindi generare vari warnings

-   creare un file in `/var/log/xdebug.log` all'interno del container phpfpm. Eseguire un `chown www-data /var/log/xdebug.log`. Creare questo file solo se si ha esigenze di debug errori xdebug (impossibile analizzare il codice tramite breakpoint) visto che potrebbe crescere esponenzialmente nel tempo

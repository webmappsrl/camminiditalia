# Script di Aggiornamento Database

Questo script permette di aggiornare il database locale con i dati più recenti dal server remoto.

## Prerequisiti

1. **Container Docker attivi**: Assicurati che i container Docker siano in esecuzione
   ```bash
   docker-compose up -d
   ```

2. **File .env configurato**: Il file `.env` deve contenere le variabili del database:
   - `DB_DATABASE`
   - `DB_USERNAME` 
   - `DB_PASSWORD`

3. **Accesso SSH al server remoto**: Devi avere accesso SSH al server `78.46.250.15`

## Script Disponibile

### `update_db_from_remote.sh`

Scarica il backup più recente dal server remoto e sostituisce completamente il database locale.

**Uso:**
```bash
./scripts/update_db_from_remote.sh <username>
```

**Esempio:**
```bash
./scripts/update_db_from_remote.sh root
```

**Cosa fa:**
- Si connette via SSH al server `78.46.250.15`
- Scarica `camminiditalia/storage/backups/last_dump.sql.gz`
- Decomprime il file
- **Pulisce completamente il database locale esistente**
- Importa il backup nel database locale
- **Esegue le migrazioni Laravel** per assicurarsi che la struttura sia aggiornata
- Pulisce i file temporanei

**⚠️ Attenzione**: Questo script **sostituisce completamente** i dati locali con quelli del server remoto. Tutti i dati locali esistenti verranno persi.

## Struttura Directory

```
storage/
└── db-dumps/
    └── last_dump.sql.gz               # Backup remoto scaricato (temporaneo)
```

## Troubleshooting

### Errore: "Container PostgreSQL non è in esecuzione"
```bash
# Avvia i container
docker-compose up -d

# Verifica che siano attivi
docker ps
```

### Errore: "Variabili del database non definite"
Controlla che il file `.env` contenga:
```env
DB_DATABASE=nome_database
DB_USERNAME=username
DB_PASSWORD=password
```

### Errore di connessione SSH
- Verifica di avere accesso SSH al server remoto
- Controlla che l'username sia corretto
- Assicurati di avere le chiavi SSH configurate

### Errore durante l'importazione
- Verifica che il file di backup non sia corrotto
- Controlla i log del container PostgreSQL:
  ```bash
  docker logs postgres-${APP_NAME:-camminiditalia}
  ```

## Note di Sicurezza

- I backup contengono dati sensibili, mantieni la directory `storage/db-dumps/` sicura
- Non committare mai file di backup nel repository Git
- Considera di aggiungere `storage/db-dumps/*.sql.gz` al `.gitignore`

## Automazione

Per automatizzare l'aggiornamento del database, puoi aggiungere lo script al crontab:

```bash
# Aggiornamento settimanale dal remoto (domenica alle 3:00)
0 3 * * 0 cd /path/to/camminiditalia && ./scripts/update_db_from_remote.sh root
```

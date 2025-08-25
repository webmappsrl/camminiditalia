#!/bin/bash

# Script per scaricare il backup del database dal server remoto e aggiornare il database locale
# Uso: ./scripts/update_db_from_remote.sh [username]

set -e  # Esci se un comando fallisce

# Configurazione
REMOTE_HOST="78.46.250.15"
REMOTE_PATH="camminiditalia/storage/backups/last_dump.sql.gz"
LOCAL_BACKUP_DIR="storage/db-dumps"
LOCAL_BACKUP_FILE="last_dump.sql.gz"
LOCAL_SQL_FILE="last_dump.sql"

# Colori per output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Funzione per log colorato
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Controlla se è stato fornito un username
if [ $# -eq 0 ]; then
    log_error "Devi specificare un username per la connessione SSH"
    echo "Uso: $0 <username>"
    echo "Esempio: $0 root"
    exit 1
fi

REMOTE_USER="$1"

log_info "Iniziando aggiornamento database da server remoto..."

# Crea la directory locale se non esiste
if [ ! -d "$LOCAL_BACKUP_DIR" ]; then
    log_info "Creando directory $LOCAL_BACKUP_DIR..."
    mkdir -p "$LOCAL_BACKUP_DIR"
fi

# Controlla se il container Docker è in esecuzione
if ! docker ps | grep -q "postgres_camminiditalia"; then
    log_error "Il container PostgreSQL non è in esecuzione!"
    log_info "Avvia i container con: docker-compose up -d"
    exit 1
fi

# Pulisci il database esistente prima dell'importazione
log_info "Pulendo il database esistente..."
if docker exec "postgres_camminiditalia" psql -U "$DB_USERNAME" -d "$DB_DATABASE" -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public;" > /dev/null 2>&1; then
    log_info "Database pulito con successo"
else
    log_warn "Impossibile pulire il database, continuando con l'importazione..."
fi

# Scarica il backup dal server remoto
log_info "Scaricando backup da $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH..."
if scp "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH" "$LOCAL_BACKUP_DIR/$LOCAL_BACKUP_FILE"; then
    log_info "Backup scaricato con successo in $LOCAL_BACKUP_DIR/$LOCAL_BACKUP_FILE"
else
    log_error "Errore durante il download del backup"
    exit 1
fi

# Decomprimi il file
log_info "Decomprimendo il backup..."
if gunzip -f "$LOCAL_BACKUP_DIR/$LOCAL_BACKUP_FILE"; then
    log_info "Backup decompresso in $LOCAL_BACKUP_DIR/$LOCAL_SQL_FILE"
else
    log_error "Errore durante la decompressione"
    exit 1
fi

# Controlla se il file SQL esiste
if [ ! -f "$LOCAL_BACKUP_DIR/$LOCAL_SQL_FILE" ]; then
    log_error "File SQL non trovato: $LOCAL_BACKUP_DIR/$LOCAL_SQL_FILE"
    exit 1
fi

# Ottieni le credenziali del database dal file .env
if [ ! -f ".env" ]; then
    log_error "File .env non trovato!"
    exit 1
fi

# Carica le variabili d'ambiente
export $(grep -v '^#' .env | xargs)

# Controlla che le variabili del database siano definite
if [ -z "$DB_DATABASE" ] || [ -z "$DB_USERNAME" ] || [ -z "$DB_PASSWORD" ]; then
    log_error "Variabili del database non definite nel file .env"
    log_info "Assicurati che DB_DATABASE, DB_USERNAME e DB_PASSWORD siano definiti"
    exit 1
fi

log_info "Importando backup nel database $DB_DATABASE..."

# Importa il backup nel database
if docker exec -i "postgres_camminiditalia" psql -U "$DB_USERNAME" -d "$DB_DATABASE" < "$LOCAL_BACKUP_DIR/$LOCAL_SQL_FILE"; then
    log_info "Database aggiornato con successo!"
else
    log_error "Errore durante l'importazione del database"
    exit 1
fi

# Esegui le migrazioni per assicurarsi che la struttura sia aggiornata
log_info "Eseguendo le migrazioni..."
if docker exec "php_camminiditalia" php artisan migrate --force; then
    log_info "Migrazioni completate con successo!"
else
    log_warn "Errore durante l'esecuzione delle migrazioni, ma il database è stato importato"
fi

# Pulisci i file temporanei
log_info "Pulendo file temporanei..."
rm -f "$LOCAL_BACKUP_DIR/$LOCAL_SQL_FILE"

log_info "Aggiornamento completato con successo!"
log_info "Il database è stato aggiornato con il backup più recente dal server remoto"

> Ticket: oc:8092

# Piano implementativo — DB PostgreSQL separato per i test PHPUnit

**⚠️ Nessun commit automatico.** Ogni step indica il commit di riferimento come istruzione testuale per lo sviluppatore — i commit vengono eseguiti solo dopo approvazione esplicita in `review-gate: dialog`.

## Step 1 — Creare `.env.testing`

Copiare `.env` (root del repo principale), cambiando solo:
- `APP_ENV=testing` (esplicito nel file, ridondante con `phpunit.xml` ma coerente con la convenzione Laravel)
- `DB_DATABASE=camminiditalia_testing`

Tutti gli altri parametri (Redis, MinIO, Elasticsearch, mail, ecc.) restano identici al `.env` locale — non hanno effetto pratico nei test perché `phpunit.xml` già forza `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `SCOUT_DRIVER=collection`.

File: `.env.testing` (nuovo, root repo principale, **committato direttamente**, nessuna variante `.example`).

Commit: `fix(oc:8092): aggiungi .env.testing per DB PostgreSQL dedicato ai test`

## Step 2 — Creare il database `camminiditalia_testing`

Eseguire nel container Docker (comando DDL via tinker, non `psql` diretto — evita prompt password interattivo):

```bash
docker exec laravel-camminiditalia php artisan tinker --execute="\DB::statement('CREATE DATABASE camminiditalia_testing TEMPLATE template_postgis');"
```

Nessun file di codice coinvolto — modifica di stato dell'ambiente Docker locale, eseguita direttamente in questa sessione (autorizzazione già ottenuta dall'utente).

## Step 3 — Aggiornare `phpunit.xml`

Nel blocco `<php>`:
- Rimuovere le due righe SQLite commentate (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`)
- Aggiungere `<env name="DB_DATABASE" value="camminiditalia_testing"/>`

File: `phpunit.xml` (modifica).

Commit: `fix(oc:8092): configura phpunit.xml per DB di test dedicato`

## Step 4 — Migrare il DB di test

```bash
docker exec laravel-camminiditalia php artisan migrate --env=testing
```

Nessun file coinvolto — popola lo schema sul DB creato allo Step 2.

## Step 5 — Proteggere la CI (`run-tests.yml`)

**Emerso da Fase: challenge, non nel ticket originale.** Senza questo step, il nuovo `.env.testing` (con `DB_HOST=db`, valido solo su rete Docker locale) verrebbe caricato automaticamente anche in CI quando `APP_ENV=testing` — rompendo tutti i test DB-touching su ogni PR, perché il runner GitHub non ha un host `db` risolvibile e non crea mai `camminiditalia_testing`.

Nello step "Laravel Tests" di `.github/workflows/run-tests.yml`, aggiungere env var esplicite (hanno precedenza su qualsiasi valore caricato da `.env.testing`, stesso meccanismo di `phpunit.xml`):

```yaml
      - name: Laravel Tests
        run: php artisan test --log-events-verbose-text storage/logs/test.log
        env:
          DB_HOST: localhost
          DB_DATABASE: camminiditalia
```

Questi valori puntano al servizio Postgres effimero già esistente nel workflow (`POSTGRES_DB: camminiditalia`, raggiungibile su `localhost` via port mapping) — nessuna modifica al servizio stesso, nessun nuovo DB da creare in CI.

File: `.github/workflows/run-tests.yml` (modifica).

Commit: `fix(oc:8092): proteggi CI da auto-load di .env.testing`

## Step 6 — Verificare la suite esistente

```bash
docker exec laravel-camminiditalia php artisan test
```

Criteri di verifica (non un semplice "tutto verde"):
- I test che usano `RefreshDatabase` devono girare sul nuovo DB `camminiditalia_testing`, non più su `camminiditalia` (verificabile controllando che il DB di sviluppo non venga più svuotato dopo la run)
- I 28 fallimenti Redis/qemu pre-esistenti (oc:8312) sono una baseline nota — non devono aumentare, ma non è richiesto risolverli in questo ciclo
- Eventuali nuovi fallimenti non riconducibili a Redis vanno diagnosticati singolarmente (possibile causa: dipendenza implicita da dati organici del DB dev, vedi Rischi in `overview.md`) e documentati in `notes.md`, non ignorati

## Step 7 — Documentare in `CLAUDE.md`

Aggiungere in `## Comandi comuni`:

```markdown
### Setup DB di test (una tantum, dopo primo clone o reset container)
\`\`\`bash
docker exec laravel-camminiditalia php artisan tinker --execute="\DB::statement('CREATE DATABASE camminiditalia_testing TEMPLATE template_postgis');"
docker exec laravel-camminiditalia php artisan migrate --env=testing
\`\`\`

### Reset del DB di test (se corrotto durante lo sviluppo)
\`\`\`bash
docker exec laravel-camminiditalia php artisan migrate:fresh --env=testing
\`\`\`
```

File: `CLAUDE.md` (modifica — sezione `## Comandi comuni`).

Commit: `fix(oc:8092): documenta setup e reset DB di test in CLAUDE.md`

Questo aggiornamento è distinto da quello di `Fase: update-context` (che copre `## Feature disponibili` e `## Decisioni architetturali`) — qui si tratta solo della sezione `## Comandi comuni`, già prevista come requisito esplicito del ticket.

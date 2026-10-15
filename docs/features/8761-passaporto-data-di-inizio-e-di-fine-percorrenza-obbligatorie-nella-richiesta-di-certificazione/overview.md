> Ticket: oc:8761

# Passaporto: data di inizio e di fine percorrenza obbligatorie nella richiesta di certificazione

Questa è la parte server. Il form sta in wm-core e il tipo in wm-types (submodule di
webmapp-app), ciascuno con la propria overview e lo stesso slug.

## Cosa cambia

La richiesta di certificazione del passaporto riceve due campi obbligatori, `start_date` ed
`end_date` (`AAAA-MM-GG` nel multipart): le date in cui il camminatore ha percorso il cammino. Il
server le valida, le salva sulla richiesta e le mostra al gestore nella pagina Nova della
richiesta e nella mail «nuova richiesta».

## Perché

Cammini d'Italia vuole le date per le statistiche sui passaggi e considera la richiesta un atto
formale, quindi le vuole obbligatorie e complete. Call del 12/10/2026, Davide Nanna: «data inizio
e data fine», «essendo formale», «data completa con l'anno». È una delle modifiche da fare prima
del rilascio in produzione del passaporto, previsto in settimana. Il passaporto non è in
produzione, quindi la tabella si può cambiare senza migration di aggiornamento.

## Requisiti

- [ ] Due colonne `start_date` ed `end_date`, tipo `date`, `NOT NULL`, aggiunte **modificando la
      migration esistente** `2026_09_28_150000_create_certification_requests_table.php`, dopo
      `serial_number`. Nessuna migration nuova: decisione del dev.
- [ ] Modello `CertificationRequest`: i due campi in `$fillable`, in `$casts` come `'date'` e nel
      docblock come `@property \Illuminate\Support\Carbon $start_date` / `$end_date`.
- [ ] `StoreCertificationRequestRequest`:
      `start_date` → `required`, `date_format:Y-m-d`, `before_or_equal:tomorrow`;
      `end_date` → `required`, `date_format:Y-m-d`, `after_or_equal:start_date`,
      `before_or_equal:tomorrow`.
      Il limite è domani e non oggi perché il server è in UTC: fra mezzanotte e le 2 italiane il
      camminatore è già al giorno dopo.
- [ ] `CertificationRequestController` passa le due date a `CertificationRequestService::submit`,
      che le salva nel `create` dentro la transazione.
- [ ] Risorsa Nova `CertificationRequest`: due campi `Date` in sola lettura, «Start date» ed «End
      date», accanto a «Serial number»; etichette tradotte nei 5 json di `resources/lang/`
      (`it`, `en`, `de`, `fr`, `es`).
- [ ] Mail «nuova richiesta» (`new-certification-request.blade.php`): una riga «Percorso» con
      «dal 01/10/2026 al 10/10/2026» (`d/m/Y`), accanto a «Inviata il» e «Numero seriale».
- [ ] Test: una `CertificationRequestFactory` con le date valorizzate (e `HasFactory` sul
      modello), usata nei 16 file di test che oggi creano richieste con
      `CertificationRequest::create`; `validPayload()` del test API con le date; casi nuovi per
      date mancanti, formato errato, fine prima di inizio, data oltre domani, salvataggio nel
      service, riga nella mail, campi in Nova se c'è già un test della risorsa.
- [ ] PHPStan senza errori nuovi sui file toccati.

## Rischi

- **Migration modificata su ambienti dove è già stata eseguita**: su Cammini Dev e sui database
  locali `php artisan migrate` non la riesegue, le colonne mancano e ogni invio va in errore 500.
  Lì, **prima di aggiornare il codice**, si fa il rollback; poi pull e `php artisan migrate`.
  Il rollback deve togliere anche le migration successive: `validated_ec_tracks` ha una foreign
  key verso `certification_requests` (`2026_09_30_120000_create_validated_ec_tracks_table.php:17`)
  e Postgres non elimina una tabella referenziata. Procedura:
  1. `php artisan migrate:status` e conta le migration eseguite da
     `2026_09_28_150000_create_certification_requests_table` compresa fino all'ultima. Sui database
     allineati a oggi sono 4: `certification_requests`, `validated_ec_tracks`, `passport_shares`,
     `ugc_duplicates_archive`.
  2. `php artisan migrate:rollback --step=<N>` (oggi `--step=4`): i loro `down()` fanno solo
     `dropIfExists`.
  3. Pull del codice aggiornato, poi `php artisan migrate`, che le ricrea tutte con le colonne
     nuove.
  Si perdono i dati di prova di quelle tabelle, compreso l'archivio dei duplicati UGC (oc:8718).
  Il giorno del rilascio va verificato che `certification_requests` non esista in produzione. La
  procedura va scritta nella PR, perché nessun controllo automatico se ne accorge.
- **Ordine dei deploy**: un'app senza i campi riceve 422. Backend e app vanno rilasciati insieme,
  e le build di prova già installate vanno aggiornate.
- **Cammino fatto in più viaggi**: il form chiede un solo intervallo; chi fa le tappe in viaggi
  separati indicherà un intervallo che li comprende tutti.

## Out of scope

- Mostrare le date al camminatore (mail di esito, risposta dell'API sullo stato): l'API verso
  l'app resta invariata.
- Usare le date come data di completamento delle tappe o nella condivisione del cammino
  completato: resta possibile in futuro, perché le date restano salvate.
- Messaggi di validazione personalizzati: l'app mostra un proprio messaggio tradotto per un 422
  sulle date.
- Modifiche al numero seriale (resta facoltativo) e al consenso (già obbligatorio).

## Moduli toccati

- `database/migrations/2026_09_28_150000_create_certification_requests_table.php`
- `database/factories/CertificationRequestFactory.php` (nuovo)
- `app/Models/CertificationRequest.php`
- `app/Http/Requests/StoreCertificationRequestRequest.php`
- `app/Http/Controllers/Api/CertificationRequestController.php`
- `app/Services/CertificationRequestService.php`
- `app/Nova/CertificationRequest.php`
- `resources/views/emails/new-certification-request.blade.php`
- `resources/lang/{it,en,de,fr,es}.json`
- i 16 file in `tests/` che creano richieste di certificazione, più i casi nuovi

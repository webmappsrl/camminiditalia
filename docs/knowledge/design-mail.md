# Design delle mail

## Come funziona oggi

Tutte le mail del backend (segnalazione UGC al gestore, nuova richiesta di certificazione al gestore, esito al camminatore) usano lo stesso scheletro: `resources/views/emails/layouts/cammini.blade.php` più i componenti `resources/views/components/mail/` (`route`, `field`, `note`, `photos`). Icon dell'App e logo del cammino arrivano da `App\Support\MailBranding` (media `icon` dell'App, media `logo` del Layer); se mancano, la mail mostra solo il testo.

Vincoli che il codice da solo non spiega:
- **colori dal sito camminiditalia.org** (tema `cammini-v2`): quasi nero `#1d282b`, arancione `#f07821`, crema `#fdedd7`, fondo `#f4f6f8`, grigio testo secondario `#4c585b`; etichette dei campi in `#bb4613` (`cm2-primary-700`, 5,3:1): il primary `#f07821` su bianco arriva solo a 2,8:1. Il `primary_color` dell'App nel DB (`#de1b0d`) **non** è il colore del brand;
- **WCAG 2.2 AA**: contrasto almeno 4,5:1 per il testo, testo a 16px, etichette in tondo (niente maiuscolo piccolo), stato scritto in parole e non solo colore o emoji, `alt` descrittivi, `lang` del documento nella lingua della mail;
- **unica eccezione accettata**: il pulsante ha testo bianco a 16px su `#e15d15` (3,6:1). Il bianco sull'arancione del sito (`#f07821`) arriva solo a 2,8:1;
- **tabelle `role="presentation"` e stili in linea**, non `flex` né `<style>`: Outlook e diverse webmail li ignorano;
- **immagini da URL assoluti dello storage** (`wmfe`): in locale puntano a `localhost:9000` e non si vedono fuori dalla propria macchina.

## Perché così

- **Un solo design** (oc:8671): le mail erano tre stili diversi (UGC nera e arancione con l'icon, mail del passaporto senza logo). Il dev ha chiesto che ogni mail futura riusi lo stesso scheletro.
- **Logo del cammino nel corpo e non in testata** (oc:8671): la testata resta del brand Cammini d'Italia; il logo del cammino accompagna il nome del cammino di cui parla la mail.
- **Pulsante bianco su arancione scuro** (oc:8671): il testo scuro sull'arancione era accessibile (5,3:1) ma al dev non piaceva; il bianco a 16px è un'eccezione consapevole.
- **Etichetta sopra il valore** (oc:8671): la versione a tabella (etichetta a sinistra) andava a capo male sul telefono con valori lunghi come un'email.
- **Anteprime delle foto anche nella mail della richiesta di certificazione** (oc:8671, rischio accettato dal dev): vedi la pagina [passaporto-validazione-credenziale-cartacea.md](passaporto-validazione-credenziale-cartacea.md).

## Come ci siamo arrivati

- **Tre template autonomi con CSS in `<style>`** (fino a oc:8671, superati): contrasti sotto AA (bianco su `#e8621a` 3,4:1, piè di pagina `#aaa` 2,2:1), testo piccolo maiuscolo, `alt="Logo"`.
- **Rosso `#de1b0d` come colore del brand** (prima proposta di oc:8671, scartata): preso dal `primary_color` dell'App, non corrisponde al sito.

> Ticket: oc:9031

# Piano — [cammini] logo sul backend

Repo: solo `camminiditalia` (nessuna modifica a `wm-package`). Branch:
`feature/oc-9031-cammini-logo-sul-backend`, da `develop`.

Commit: `feat(oc:9031): ...`, `docs(oc:9031): ...`, `test(oc:9031): ...`. I commit si fanno solo
dopo l'approvazione esplicita del dev, alla fine (review-gate).

## Task 1 — Branch

- `git checkout -b feature/oc-9031-cammini-logo-sul-backend` da `develop` (i file di
  `docs/features/9031-…` non ancora tracciati passano sul branch).

## Task 2 — Test che fallisce

`tests/Feature/NovaBrandLogoTest.php` (`Tests\TestCase`, nessun accesso al database):

- `config('nova.brand.logo')` punta a un file esistente sotto `resources/img/`;
- `Nova::logo()` restituisce una stringa che inizia con `<svg` e si legge con `DOMDocument`
  (XML ben formato, radice `svg`, con `viewBox`);
- nessun elemento ha l'attributo `id`;
- c'è al massimo un `<style>`, e ogni selettore che contiene comincia con `.cdi-logo-name` o
  `.dark .cdi-logo-name` (niente regole che tocchino il resto della pagina);
- almeno un elemento ha la classe `cdi-logo-name` (il nome è presente);
- l'`<svg>` ha uno `style` con `height` e non ha gli attributi `width`/`height`/`class`.

Esecuzione: `docker exec laravel-camminiditalia php artisan test --filter=NovaBrandLogoTest`
→ deve fallire (oggi `brand.logo` è commentato).

## Task 3 — Script di generazione dell'SVG

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-3--script-di-generazione-dellsvg)

`docs/features/9031-cammini-logo-sul-backend/build-logo.py`, eseguito fuori dal progetto con
`uv run --with fonttools python -I build-logo.py <disco.svg> <InstrumentSans-Bold.ttf> <uscita.svg>`
(nessuna dipendenza aggiunta al repo):

1. legge i `path` del disco dall'SVG ufficiale (viewBox `0 0 104 105`), senza toccarne `d` e `fill`;
2. converte «Cammini d'Italia» in tracciati con `fontTools.pens.svgPathPen.SVGPathPen` e
   `TTFont` (Instrument Sans Bold, apostrofo tipografico `’`), applicando il kerning della tabella
   `GPOS` se presente, altrimenti le sole larghezze `hmtx`;
3. dimensiona il testo con l'altezza delle maiuscole circa il 40% del disco, centrato in
   verticale, a destra del disco con uno spazio di 1/5 del diametro (l'area di rispetto del design
   system);
4. scrive un unico `<svg viewBox="…" role="img" style="height:…px">` con:
   `<style>.cdi-logo-name{fill:#BB4613}.dark .cdi-logo-name{fill:#F07821}</style>`, i `path` del
   disco e un `<path class="cdi-logo-name" d="…">` per il nome; nessun `id`, nessun `width`/`height`.

Sorgenti, salvati nella cartella della feature perché lo script sia rieseguibile:
- disco: `logo.svg` del design system (= SVG dell'header di camminiditalia.org, già verificato
  identico);
- carattere: `InstrumentSans-Bold.ttf` dal design system (licenza SIL OFL: va verificata nel file
  prima di salvarlo; se non è OFL, il carattere non entra nel repo e lo script lo prende da un
  percorso passato a mano).

## Task 4 — Logo e config

- Eseguire lo script → `resources/img/camminiditalia-logo.svg`.
- `config/nova.php`: decommentare e impostare
  `'logo' => resource_path('img/camminiditalia-logo.svg'),`; `colors` invariato.
- Rilanciare il test del Task 2 → deve passare.

## Task 5 — Altezza e prova visiva

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-5--altezza-e-prova-visiva)

Con `composer run dev` / container attivo, aprire `http://localhost:8000/nova`:

- header in tema chiaro e scuro (menu del tema di Nova), a 1280 px, a 900 px (fascia 768–1023, il
  contenitore non ha larghezza fissa) e il menu mobile sotto 768 px;
- pagina di login;
- il logo non supera 200 px di larghezza nell'header, non sposta la ricerca, il disco resta un
  cerchio, il nome è leggibile.

Regolare in `build-logo.py` l'altezza (`style`) e le proporzioni del testo, poi rigenerare.
Screenshot dei due temi da mostrare al dev.

## Task 6 — `.env-example`

Accanto alle altre variabili di Nova, la riga vuota con un commento:

```
# Chiave di licenza Nova dello shard (una per shard, dalla licenza Unlimited): vedi docs/howto/licenza-nova.md
NOVA_LICENSE_KEY=
```

## Task 7 — `docs/howto/licenza-nova.md`

Procedura per registrare la licenza Nova su uno shard, con le citazioni della documentazione
ufficiale (https://nova.laravel.com/docs/v5/installation):

1. account su nova.laravel.com → Licenses → «Laravel Nova Unlimited License»;
2. «Production URLs» → «Add site» con il dominio di produzione (solo host);
3. «License Keys» → «Create License Key» con il nome dello shard;
4. nel `.env` del server `NOVA_LICENSE_KEY=<chiave>`; poi `php artisan config:cache` (il deploy
   usa `php artisan optimize`);
5. verifica: `php artisan nova:check-license` (svuota da sé la cache `nova_valid_license_key` e
   rifà il controllo con il dominio di `APP_URL`);
6. cosa non fare: nascondere «Unregistered» con il CSS; contare sull'esclusione `dev.` quando
   `dev.` non è il primo sottodominio (`camminiditalia.dev.maphub.it` risponde 403); leggere il
   `validLicense` della pagina di login come prova (viene solo dalla cache).

Niente chiavi nel documento.

## Task 8 — Verifiche finali

- `docker exec laravel-camminiditalia php artisan test --filter=NovaBrandLogoTest`
- `docker exec laravel-camminiditalia composer format`
- PHPStan (review-gate).

## Task 9 — Notes e CLAUDE.md

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-9--notes-e-claudemd)

- `notes.md` con deviazioni e decisioni (deroga al design system, colori per tema).
- Riga in `## Feature disponibili` del `CLAUDE.md`, con il rimando a `docs/howto/licenza-nova.md`.

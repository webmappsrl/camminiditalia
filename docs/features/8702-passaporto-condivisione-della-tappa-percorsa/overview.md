> Ticket: oc:8702

# Passaporto: condivisione della tappa percorsa — parte backend

Documento d'insieme, con la parte app: `webmapp-app/docs/features/8702-passaporto-condivisione-della-tappa-percorsa/overview.md`.
Riferimento visivo: mockup del cliente `mockup-app.png` (cartella Drive `1OHscIxWg0OTrsO6opJ-15WChwEEC-d_i`).

## Cosa cambia

La risposta di `/api/layer/{layer}/progress` aggiunge per ogni tappa i dati tecnici della traccia e
il flag `shareable`. Un endpoint nuovo genera l'immagine di condivisione di una tappa validata
dall'utente, in formato storia, sul mockup del cliente, e una pagina pubblica dedicata mostra la
tappa condivisa.

## Perché

L'app deve mostrare quei dati nella pagina della tappa e far condividere sui social la tappa
percorsa. `share-story-image` di `wm-package` accetta solo tracce UGC e non conosce il passaporto;
se una tappa è percorsa da un utente lo sa solo questo repo. E una tappa è una traccia del catalogo,
senza un record dell'utente a cui agganciare immagine e snapshot: serve una tabella propria.

## Requisiti

- [ ] `/api/layer/{layer}/progress` aggiunge per ogni tappa, quando presenti: `ref`, `from`, `to`,
      `ascent`, `descent`, la miniatura della `feature_image`, e `shareable: true` sulle tappe
      validate per l'utente. I campi esistenti non cambiano.
- [ ] Tabella `passport_stage_shares`: `uuid` generato alla creazione, `user_id`, `layer_id`,
      `ec_track_id`, `snapshot` JSON, unica per (utente, tappa). Media collection `share_image`
      `singleFile`: ricondividere aggiorna la stessa riga e la stessa immagine.
- [ ] Endpoint autenticato, es. `POST /api/layer/{layer}/stage/{track}/share-image`: risponde solo
      se la tappa appartiene al layer ed è validata per l'utente, altrimenti 403/404. Lingua da
      `Accept-Language`, italiano come ripiego. Restituisce `image_url` e `share_url`.
- [ ] Pagina pubblica `GET /share/passport-stage/{uuid}` con meta Open Graph (`og:image` è
      l'immagine generata). Mostra solo lo snapshot — cammino, tappa, partenza, arrivo, lunghezza,
      dislivello, data — e un link all'app. Nessun nome dell'utente, nessun id interno.
- [ ] Immagine 1080×1920, generata sempre, dall'alto in basso:
      - sfondo beige con curve di livello, come nel mockup;
      - logo del cammino in un cerchio bianco, omesso se manca;
      - nome del cammino;
      - mappa con cornice arancione: inquadratura sulla tappa con margine di circa il 30%; tappa
        gialla spessa con bordo bianco; resto del cammino rosso sottile al 70%, sotto la tappa;
        marker di partenza e arrivo; nessuna etichetta sulle altre tappe;
      - «Tappa {ref}», o il nome della tappa se `ref` manca;
      - partenza, arrivo, lunghezza; dislivello positivo solo se presente. **Niente tempo**;
      - logo di Cammini d'Italia in fondo.
- [ ] Un dato mancante toglie la sua voce, senza etichette vuote.
- [ ] Testi lunghi: riquadro fisso per campo, a capo fino a due righe, poi corpo ridotto fino a un
      minimo, poi «…». Un test compone l'immagine con i testi più lunghi del database e le etichette
      in tedesco.
- [ ] La mappa si disegna con il metodo generico nuovo di `MapRenderService` (`wm-package`, stesso
      slug): tutto il cammino come primo layer, la tappa sopra, marker di partenza e arrivo,
      inquadratura sulla tappa. Il layout dell'immagine si compone qui con Intervention Image.

## Rischi

- **Dipende da oc:8676, non ancora mergiato.** Il branch di oc:8702 parte da
  `feature/oc-8676-…` e va ribasato su `develop` dopo il merge di oc:8676.
- **Dipende dal metodo nuovo di `wm-package`:** il bump del submodule va fatto prima.
- **Ordine di rilascio:** questo backend va in produzione prima del rilascio dell'app; `shareable`
  evita che un'app uscita prima mostri un pulsante rotto.
- **CORS del disco media `wmfe`**: l'app web scarica l'immagine come `Blob`, quindi il disco deve
  permetterlo per l'origine della webapp camminiditalia. Da verificare e, se serve, configurare.
- **`ref` non compilato.** L'immagine mostra il nome della tappa: è il ripiego previsto.
- **Migrazione nuova.** Il rollback richiede di togliere la tabella; i link pubblici già condivisi
  smetterebbero di funzionare.

## Out of scope

- Validazione delle tappe da GPS e da credenziale cartacea (oc:8166, oc:8165).
- Il tempo impiegato sulla tappa.

## Moduli toccati

- `app/Services/StageProgressService.php`
- `database/migrations/…` — tabella `passport_stage_shares`
- `app/Models/…` — model della condivisione
- `routes/api.php`, `routes/web.php`
- `app/Http/Controllers/…` — controller dell'immagine e della pagina pubblica
- `app/Services/…` — composizione dell'immagine della tappa
- `resources/views/…` — pagina pubblica
- asset: sfondo e logo Cammini d'Italia

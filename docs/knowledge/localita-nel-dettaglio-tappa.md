# Località nel dettaglio tappa

Cosa vale in camminiditalia per le località (regione, comuni) mostrate nel dettaglio di tappe e POI. Il meccanismo è in wm-package: [wm-package/docs/knowledge/localita-mostrate-taxonomy-where.md](../../wm-package/docs/knowledge/localita-mostrate-taxonomy-where.md).

## Come funziona oggi

- L'opzione **"Località mostrate"** dell'App 1 va impostata a **Regione** al rilascio (passo 5 della procedura sotto): da quel momento app e siti WordPress mostrano solo la regione, mentre nel DB restano anche i comuni. Finché è vuota si vede tutto, come prima di oc:8588.
- La voce **"Taxonomy Where"** è nel menu "Taxonomies" di Nova, visibile **solo all'Administrator** (`app/Providers/NovaServiceProvider.php`). La policy del package resta: lettura per tutti, modifica solo Administrator.
- Le where si importano da osmfeatures con l'azione "Import Taxonomy Where" (Regione L4, poi Comune L8).
- "Track Search In" dell'App 1 contiene solo `name`: la ricerca per testo non trova le tappe per comune, indipendentemente dall'opzione.

## Perché così

- **Solo la regione** (oc:8588): il "comune" nel dettaglio tappa risultava spesso impreciso; deciso in call di collaudo (release 13.1.17) e confermato allo scrum del 2026-09-23.
- **Voce di menu solo per l'Administrator** (oc:8588): le where sono dati di piattaforma, al gestore di cammino non servono.

## Come ci siamo arrivati

- **TaxonomyWhere fuori dal menu** (oc:8311, superata da oc:8588): in camminiditalia non si usavano; con oc:8588 diventano un dato mostrato al cliente.

## Procedura di rilascio

1. `pg_dump` di `ec_tracks`, `ec_pois`, `ugc_pois`, `ugc_tracks`.
2. Deploy (wm-package prima, poi bump del submodule), riavvio di Horizon.
3. Import delle where (Regione, poi Comune) e attesa che finiscano i job di dettaglio: il `--dry-run` deve mostrare 0 where senza geometria.
4. `php artisan wm:resync-taxonomy-where --app=1 --dry-run`, poi senza `--dry-run` (senza `--only-legacy`: il ricalcolo automatico a fine import lascia le tracce con la sola regione).
5. In Nova, App 1 → "Località mostrate" = Region.

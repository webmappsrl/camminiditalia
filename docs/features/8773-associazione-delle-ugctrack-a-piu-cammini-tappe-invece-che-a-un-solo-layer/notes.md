> Ticket: oc:8773

# Notes — UGC track associate a più cammini e tappe (camminiditalia)

La parte principale del lavoro è in wm-package: vedi
`wm-package/docs/features/8773-associazione-delle-ugctrack-a-piu-cammini-tappe-invece-che-a-un-solo-layer/notes.md`.

## Divergenze dal piano, task per task

### Task 1 bump del submodule e migration

Il bump al commit di merge del package non è ancora fatto: va eseguito dopo il merge della
PR di wm-package. In questo branch il submodule punta al branch della feature.

### Task 5 verifica nel locale documentazione procedura di deploy

Eseguita sul DB di sviluppo con l'ok del dev: 267 UGC track con corrispondenza calcolata,
0 fallite, `layer_id` tolto dalle 76 che lo avevano. La prova ha fatto emergere la lentezza
della UGC track 297 (95,7 s), poi corretta nel package (~3 s), e il problema della
precedenza delle traduzioni (vedi Bug trovati).

## Bug trovati

- L'observer locale `App\Observers\UgcObserver` (registrato anche su UgcTrack) poteva ancora
  scrivere `layer_id` su una UGC track con `form.id = report`. Ora esce subito per le UGC
  track; `ResolveUgcLayerJobTest` spostato sugli UgcPoi, con un test negativo sulle UGC track.
- Le traduzioni cammino/tappa di `resources/lang/*.json` non comparivano: il JSON del package
  vinceva su quello dello shard da oc:8247. Corretto nel package.
- `RecalculateLayerAttributesJobTest` fallisce in alcune esecuzioni combinate per un lock
  unique su Redis (`UpdateAppConfigJob`) lasciato da 6 test preesistenti: non causato da
  oc:8773 (il nuovo test è isolato con `Bus::fake`), da trattare a parte.

## Decisioni

- Le UGC track restano visibili solo all'Administrator. Il Validator ha comunque `view` /
  `delete` sul dettaglio via URL diretto (`UgcTrackPolicy::before()`), problema preesistente,
  fuori scope: ticket separato.
- Segmento 400 m e distanza 50 m coincidono con i default del package: nessun valore da
  impostare sulla App di camminiditalia.

## Follow-up

- Bump del gitlink al commit di merge di wm-package, insieme alla migration pubblicata.
- Deploy: `migrate`; `horizon:terminate` (nuovo supervisor `supervisor-ugc-track-matching`);
  backup; se non già fatto `wm:fix-duplicated-ugc` (oc:8718) e `wm:clean-ugc-track-geometry`
  (oc:8719); `wm:match-ugc-tracks --dry-run`, poi senza `--dry-run`; controllo che non ci
  siano run `failed` in `ugc_track_matching_runs`. Verificare PostGIS ≥ 3.4 in produzione.
- EcTrack 961 ("Cammino di Dante - Tappa 11") non è associata a nessun layer: da segnalare al
  cliente se deve far parte del Cammino di Dante (in sviluppo le EC track senza layer sono 33).
- Ticket separato per l'accesso del Validator al dettaglio delle UGC track via URL.
- Riordino del `CLAUDE.md` (piano di `wm-context-doctor` del 13/10/2026: schema atteso con
  `## Conoscenza`, smontaggio di `## Decisioni architetturali`, `.claude/rules/`, voci false su
  `phpunit.xml` di oc:8182 e superate su Redis di oc:8312): da fare dopo il merge di oc:8773, con un
  ticket a parte (decisione del dev).

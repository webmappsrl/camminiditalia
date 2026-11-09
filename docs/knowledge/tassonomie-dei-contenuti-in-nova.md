# Dove si scelgono le classificazioni di un contenuto, e perché lì

## Come funziona oggi

Un contenuto EC ha due famiglie di classificazioni, che in Nova stanno in posti diversi.

**Le tassonomie storiche** — quelle con una tabella propria — si dichiarano in
`wm-package.legacy_taxonomies`, una voce per tassonomia con relazione, modello, etichetta, tab e
se è a scelta singola. Oggi il package dichiara le **Attività** (`taxonomy_activities`, 25 righe)
e i **Tipi POI** (`taxonomy_poi_types`, 164), e aggiunge le **Zone** solo sul `Layer`. Il criterio
è che le dichiari tutte lui, e che ogni shard sottragga quelle che non usa sovrascrivendo
`legacyTaxonomies()` nella propria Resource Nova.

I **Temi** (`taxonomy_themes`, 7 righe) restano fuori da questo meccanismo, e non per scelta di
presentazione: non esiste una relazione `taxonomyThemes` sui contenuti EC. Vivono sul cammino,
dove `App\Nova\Layer` li gestisce con un `Multiselect` proprio.

Su Cammini d'Italia:

| Resource | Cosa resta |
|---|---|
| `App\Nova\EcPoi` | la sola «Tipologia POI» |
| `App\Nova\EcTrack` | niente |
| `App\Nova\Layer` | niente — e si toglie anche la sezione che le conterrebbe, perché il package la costruisce comunque, vuota |

Niente di tutto questo sposta dei dati: la dichiarazione punta alla relazione che il modello ha
già, e il package ne costruisce il campo di scelta. Le Zone (`taxonomy_where`) non sono una voce
dichiarabile: su una tappa e su un POI compaiono come campo calcolato dalla geometria, in sola
lettura.

**Il modello unico** (`taxonomy_terms`, `taxonomy_termables`, `app_taxonomy_term`, arrivato col
package 1.6.1) esiste come schema ma su Cammini d'Italia è **vuoto**: nessuna tassonomia è stata
convertita, e non esiste un command di travaso. Le tre tabelle restano comunque obbligatorie:
`LayerService`, gli Observer di `EcTrack`, `EcPoi` e `Layer`, `EcTrack` e `App` le interrogano nei
percorsi normali, quindi senza di esse salvare un contenuto finisce in errore SQL.

**Una relazione si mostra in un posto solo.** Quando una tassonomia è dichiarata fra le storiche,
l'eventuale lista di attach del package per la stessa relazione va tolta nella Resource dello
shard. Due controlli sulla stessa relazione nella stessa pagina si sovrascrivono a vicenda: quello
che non mostra tutte le righe stacca le altre al primo salvataggio.

**Sul cammino le tassonomie non si dichiarano, e non è una questione di aspetto.** Le opzioni di
una tassonomia storica si costruiscono caricando l'intera tabella, e sul `Layer` il package
include le Zone: 7.930 righe con dentro le geometrie, 104 MB. Misurato sullo stesso database:
145 ms a costruire i Field con l'override, 806 ms senza. Il dettaglio di perché un filtro a valle
non basti sta nel docblock di `App\Nova\Layer::legacyTaxonomies()`.

## Perché così

- **Il package dichiara, lo shard sottrae** (oc:8951): l'elenco completo delle tassonomie è
  conoscenza del modello dati, che sta nel package; quali servano è una scelta del progetto. Col
  criterio opposto — il package dichiara il minimo, lo shard aggiunge — ogni consumer dovrebbe
  riscrivere relazione e modello di una tassonomia che il package già conosce, e un consumer che
  non lo fa non vede una classificazione che esiste.
- **Attività escluse su Cammini d'Italia** (oc:8311, confermata in oc:8951): non vengono usate, e
  la modalità automatica del cammino assegna i contenuti per proprietario, non per tassonomia.
- **Il modello unico non è stato adottato** (oc:8951): convertire 164 Tipi POI senza un command di
  travaso, mentre quei dati alimentano i filtri della mappa e `pois.geojson`, è un lavoro a sé.
  Il ticket che ha portato il package 1.6.1 aveva come criterio che l'unica cosa cambiata fosse la
  versione del package.

## Come ci siamo arrivati

- **I Field `MorphToMany` `taxonomyActivities` e `taxonomyWheres` filtrati dai fields del cammino**
  (oc:8311, superata da oc:8951): funzionava finché quei Field esistevano. Col package 1.6.1 non
  esistono più — al loro posto c'è una sezione di filtri — e il filtro è diventato codice morto che
  nessun test segnalava, mentre il costo vero restava pagato per intero.
- **I Tipi POI scelti dalla lista di attach del package** (superata da oc:8951): sostituita dal
  campo nella sezione Tassonomie, che mostra le icone e si comporta come le altre
  classificazioni. La lista è stata tolta, non lasciata accanto.

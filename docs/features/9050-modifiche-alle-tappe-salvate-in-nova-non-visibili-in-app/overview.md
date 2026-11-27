> Ticket: oc:9050
> Stato: approvata il 2026-11-27

# Modifiche alle tappe salvate in Nova non visibili in app

## Cosa cambia

Le modifiche alle tappe salvate in Nova tornano a comparire in app, e le tappe rimaste indietro dal
20/10 vengono rimesse in pari. La correzione delle catene di job sta in `wm-package` (vedi
[wm-package/docs/features/9050-…/overview.md](../../../wm-package/docs/features/9050-modifiche-alle-tappe-salvate-in-nova-non-visibili-in-app/overview.md));
qui arriva il puntatore del submodule, e dopo il deploy i dati di produzione si rimettono in pari a
mano, via ssh, senza codice nuovo.

## Perché

Il cliente segnala che dal 20/11/2026 alcune modifiche alle tappe salvate in Nova non compaiono in app
(esempio: EcTrack 434, Cammino Minerario di Santa Barbara – Tappa 01). In produzione
`UpdateEcTrackGenerateElevationChartImage` fallisce sempre (modulo `canvas` compilato per Node 18,
container su Node 20 dal 22/10) e ferma la catena prima di `GenerateEcTrackPBFBatch` e della
pubblicazione del JSON.

Il 20/11 fra le 17:18 e le 18:01 l'utente `Cammini` (id 2) ha fatto 760 «Update» di EcTrack con la
geometria cambiata: 395 catene si sono fermate in quell'ora. Il 25/11 lo stesso utente ha fatto 1.283
«Update» delle sole `properties`, che hanno ripubblicato il JSON senza passare dall'elevation chart:
la tappa 434 oggi è aggiornata. Restano indietro:

- il JSON di 19 tappe (166, 167, 378, 564, 936, 1025, 1205–1208, 1210–1218), 17 modificate il 26–27/11;
- con ogni probabilità i PBF delle tappe la cui catena si è fermata, perché `GenerateEcTrackPBFBatch`
  viene dopo l'elevation chart: dedotto dal codice e dai payload dei job falliti, non verificato sui tile.

## Punto di partenza

- Il gitlink `wm-package` punta a `1f52d75d`, lo stesso commit deployato in produzione il 27/11.
- `failed_jobs` di produzione: 790 fallimenti di `UpdateEcTrackGenerateElevationChartImage` dal 20/10,
  su 540 tappe diverse (539 esistono ancora); il payload di ognuno contiene i job della catena rimasti
  in attesa.
- Le action Nova esistenti non bastano: «Update Tracks on AWS» accoda solo `UpdateEcTrackAwsJob`,
  «Process Track Data» rilancia `createDataChain()` con DEM, 3D e pendenze.
- Il package ha `DispatcherAppPbfsDebouncedJob`, unico per App, che rigenera una volta tutti i tile
  dell'App (`generateWholeAppPbfsOptimized()`).

## Requisiti

- <a id="r1"></a>**R1** — Il gitlink `wm-package` punta a un commit che soddisfa i requisiti dell'overview del package (elevation chart fuori dalle catene, pubblicazione anche quando un job fallisce).
- <a id="r2"></a>**R2** — Dopo il deploy, a mano via ssh: per ogni EcTrack ancora esistente con un fallimento di `UpdateEcTrackGenerateElevationChartImage` in `failed_jobs` (id deduplicati, senza filtro di data) si accodano i job di pubblicazione, poi un solo `DispatcherAppPbfsDebouncedJob` per l'App 1.
- <a id="r3"></a>**R3** — Finiti i job di [R2](#r2), in produzione nessuna EcTrack ha il JSON con `updated_at` precedente a quello del DB.

## Rischi

- **Carico della coda.** [R2](#r2) accoda circa 540 catene di tre job sulle code `default` e `aws`, e
  una sola rigenerazione dei PBF dell'App: nessuna chiamata al servizio DEM e nessun tile duplicato.
  Il batch PBF per tappa è stato scartato in challenge: decine di migliaia di `GeneratePBFJob` non unici
  sulla coda `pbf`, e nessuna rigenerazione dei tile che coprivano solo il tracciato vecchio.
- **Tappe ferme per altri motivi.** Le 3 tappe ferme su `UpdateEcTrackDemJob` il 20/11 non sono fra
  quelle di [R2](#r2): le trova il controllo di [R3](#r3), e si ripubblicano allo stesso modo.
- **Retry da Horizon dei vecchi fallimenti.** I 790 job falliti contengono la catena con l'elevation
  chart: un «Retry» da Horizon li farebbe fallire di nuovo. Non vanno ritentati.

## Out of scope

- Rendere visibili all'utente in Nova i fallimenti delle catene.
- Ripulire i `failed_jobs` di produzione.
- I file di log di `storage/logs/wm-package` con i nomi che si allungano a ogni rotazione.

## Moduli toccati

- gitlink `wm-package`

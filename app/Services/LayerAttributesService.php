<?php

namespace App\Services;

use App\Enums\RouteShape;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Models\Layer;

/**
 * Calcola i valori di filtro di un cammino (Layer) aggregando le sue tappe
 * (EcTrack associate via pivot layerables).
 *
 * Specifico di camminiditalia: qui un Layer È un cammino, cioè un'unica
 * lunga traccia suddivisa in tappe. Le primitive generiche riusabili
 * (enum, verifica di chiusura geometrica) vivono in wm-package.
 */
class LayerAttributesService
{
    /**
     * Tolleranza con cui due estremi si considerano coincidenti, in METRI,
     * misurata come distanza geodetica reale (Haversine), non per-asse in
     * gradi (vedi versione precedente di questa costante e di
     * coordinatesMatch(), oc:8180): un grado di longitudine è più corto di
     * un grado di latitudine e la differenza dipende dalla latitudine (alle
     * latitudini italiane, 42-43°N, 0.001° valgono ~111 m in latitudine ma
     * solo ~82 m in longitudine) — un confronto per-asse rendeva la
     * classificazione dipendente dalla direzione in cui è digitalizzata la
     * traccia.
     *
     * 400 m riconosce come anello l'"Anello di Teodelapio" (Layer 130), la
     * cui chiusura a Spoleto misura 362 m fra la fine della Tappa 05 (id
     * 1340) e l'inizio della Tappa 01 (id 1336); le altre sue quattro
     * giunzioni stanno fra 35 e 119 m. Valore deliberatamente rivedibile:
     * essendo in metri (non in gradi) è leggibile e modificabile senza
     * conversioni.
     *
     * Non condivisa con la tolleranza interna di
     * GeometryComputationService::isRoundtrip() nel wm-package: quel
     * metodo non viene usato per la tipologia dei cammini (vincolo di
     * questo ticket, non va modificato).
     */
    public const JUNCTION_TOLERANCE_METERS = 400;

    /**
     * Livello amministrativo OSM delle regioni (8 = comune, escluso).
     */
    public const ADMIN_LEVEL_REGION = 4;

    /**
     * Raggio medio della Terra in metri, usato dalla formula di Haversine
     * in coordinatesMatch().
     */
    private const EARTH_RADIUS_METERS = 6371000;

    /**
     * Compone un valore di vocabolario nella forma { value, name } usata anche
     * da taxonomy_where: l'identificatore stabile piu' la mappa delle
     * traduzioni per tutte le lingue del progetto.
     *
     * Serve perche' il consumer (app e frontend) non deve mantenere una
     * propria tabella di traduzione degli enum: le etichette le possiede il
     * backend, e duplicarle porterebbe le due copie a divergere. Nota: le
     * traduzioni sono PERSISTITE, quindi una modifica alle etichette richiede
     * un ricalcolo per propagarsi.
     *
     * @param  callable(string): string  $label
     * @return array{value: string, name: array<string, string>}
     */
    public function withTranslations(string $value, callable $label): array
    {
        $names = [];
        foreach (config('wm-tab-translatable.locales', ['it', 'en']) as $locale) {
            $names[$locale] = $label($locale);
        }

        return ['value' => $value, 'name' => $names];
    }

    /**
     * Chiavi del sotto-oggetto properties->attributes (le caratteristiche
     * del cammino: distanza, durata, tipologia, regioni attraversate, ecc.
     * NON gli attributi Eloquent del modello) gestite dal calcolo
     * automatico. Le altre
     * (walking_network, season) sono manuali e non vanno mai toccate.
     */
    public const CALCULATED_KEYS = ['distance', 'stage_count', 'shape', 'taxonomy_where', 'themes'];

    /**
     * Somma delle distanze delle tappe, in km.
     *
     * Nessun filtro su user_id: la lunghezza è una proprietà oggettiva del
     * cammino, indipendente da chi possiede le singole tracce (35 layer su
     * 118 hanno tracce con owner diverso — bug noto oc:8314).
     *
     * Solo le tappe con un valore di distanza effettivamente calcolato
     * (MANUAL, OSM o DEM) contribuiscono alla somma. Se nessuna tappa ha un
     * valore, il totale non è determinabile e va restituito null (non 0.0):
     * un cammino senza distanza nota non deve entrare nel filtro "0-10 km".
     * Il controllo è su `null`, non sulla verità del valore, perché una
     * tappa con distanza legittima 0 deve comunque contribuire alla somma.
     */
    public function totalDistance(Layer $layer): ?float
    {
        $tracks = $layer->ecTracks()->get();

        if ($tracks->isEmpty()) {
            return null;
        }

        $total = 0.0;
        $hasValue = false;
        foreach ($tracks as $track) {
            /** @var \Wm\WmPackage\Models\EcTrack $track */
            $value = $track->classifyField($track, 'distance')['currentValue'] ?? null;

            if ($value === null) {
                continue;
            }

            $hasValue = true;
            $total += (float) $value;
        }

        if (! $hasValue) {
            return null;
        }

        return round($total, 2);
    }

    /**
     * Numero di tappe del cammino.
     *
     * La "Durata" del filtro è il numero di tappe (una tappa = una
     * giornata di cammino), non il tempo di percorrenza: i valori
     * duration_forward/backward delle EcTrack stanno su un pivot
     * per-attività come stringhe e sono distinti per senso di marcia,
     * quindi non aggregabili in modo affidabile.
     */
    public function stageCount(Layer $layer): ?int
    {
        $count = $layer->ecTracks()->count();

        return $count > 0 ? $count : null;
    }

    /**
     * Estremi (primo e ultimo punto) di ogni tappa del cammino.
     *
     * Usa ST_GeometryN invece di ST_LineMerge: il merge di tappe non
     * contigue restituirebbe ancora una MultiLineString, rendendo
     * ST_StartPoint nullo. Le geometrie sono forzate a 2D (le EcTrack
     * sono multiLineStringz).
     *
     * @return array<int, array{track_id: int, start: array{0: float, 1: float}, end: array{0: float, 1: float}}>
     */
    public function trackEndpoints(Layer $layer): array
    {
        $trackType = config('wm-package.ec_track_model', 'App\Models\EcTrack');

        $rows = DB::select(
            'SELECT id,
                    ST_X(ST_StartPoint(ST_GeometryN(g, 1))) AS start_x,
                    ST_Y(ST_StartPoint(ST_GeometryN(g, 1))) AS start_y,
                    ST_X(ST_EndPoint(ST_GeometryN(g, ST_NumGeometries(g)))) AS end_x,
                    ST_Y(ST_EndPoint(ST_GeometryN(g, ST_NumGeometries(g)))) AS end_y
             FROM (
                SELECT t.id, ST_Force2D(t.geometry::geometry) AS g
                FROM ec_tracks t
                JOIN layerables l
                  ON l.layerable_id = t.id
                 AND l.layerable_type = ?
                WHERE l.layer_id = ?
                  AND t.geometry IS NOT NULL
             ) AS tracks',
            [$trackType, $layer->id]
        );

        $endpoints = [];
        foreach ($rows as $row) {
            if ($row->start_x === null || $row->start_y === null || $row->end_x === null || $row->end_y === null) {
                continue;
            }

            $endpoints[] = [
                'track_id' => (int) $row->id,
                'start' => [(float) $row->start_x, (float) $row->start_y],
                'end' => [(float) $row->end_x, (float) $row->end_y],
            ];
        }

        return $endpoints;
    }

    /**
     * Tipologia del cammino: anello, lineare o discontinuo.
     *
     * Criterio basato sulla connessione del grafo in cui i nodi sono le
     * TAPPE (non gli estremi): esiste un arco fra due tappe se un estremo
     * dell'una coincide con un estremo dell'altra (tolleranza
     * JUNCTION_TOLERANCE_METERS, distanza geodetica reale). Questo modella correttamente varianti e
     * bretelle (diramazioni legittime, non modellate esplicitamente nei
     * dati): una variante aggiunge rami al grafo ma non lo rende
     * "anomalo".
     *
     * - grafo connesso, 0 estremi liberi → anello
     * - grafo connesso, ≥1 estremi liberi → lineare (le varianti sono rami
     *   attaccati alla catena principale, non un'ambiguità)
     * - grafo non connesso (due o più componenti) → discontinuo: i tratti
     *   non si toccano, nessun percorso unico ricostruibile
     *
     * Un "estremo libero" è un estremo di tappa che non coincide con
     * nessun estremo di un'ALTRA tappa (gli estremi della stessa tappa non
     * sono mai considerati reciprocamente congiunti in questo conteggio,
     * altrimenti una singola tappa risulterebbe sempre "anello").
     *
     * Caso limite di una sola tappa: il grafo è banalmente connesso; è
     * anello se i suoi due estremi coincidono fra loro (entro tolleranza),
     * altrimenti lineare.
     *
     * Nessuna tappa (o nessuna con geometria) → null: non è una
     * constatazione sul tracciato, è mancanza di dati.
     *
     * @param  array<int, array{track_id: int, start: array{0: float, 1: float}, end: array{0: float, 1: float}}>  $endpoints
     */
    public function determineType(array $endpoints): ?RouteShape
    {
        if ($endpoints === []) {
            return null;
        }

        $trackCount = count($endpoints);

        if ($trackCount === 1) {
            return $this->coordinatesMatch($endpoints[0]['start'], $endpoints[0]['end'])
                ? RouteShape::ROUNDTRIP
                : RouteShape::LINEAR;
        }

        // Union-Find sulle tappe: unisce due tappe se un loro estremo coincide.
        $parent = range(0, $trackCount - 1);
        $find = function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) {
                $x = $parent[$x];
            }

            return $x;
        };
        $union = function (int $a, int $b) use (&$parent, $find): void {
            $rootA = $find($a);
            $rootB = $find($b);
            if ($rootA !== $rootB) {
                $parent[$rootA] = $rootB;
            }
        };

        $freeEnds = 0;

        foreach ($endpoints as $i => $endpointA) {
            $startMatched = false;
            $endMatched = false;

            foreach ($endpoints as $j => $endpointB) {
                if ($i === $j) {
                    continue;
                }

                if ($this->coordinatesMatch($endpointA['start'], $endpointB['start'])
                    || $this->coordinatesMatch($endpointA['start'], $endpointB['end'])) {
                    $startMatched = true;
                    $union($i, $j);
                }

                if ($this->coordinatesMatch($endpointA['end'], $endpointB['start'])
                    || $this->coordinatesMatch($endpointA['end'], $endpointB['end'])) {
                    $endMatched = true;
                    $union($i, $j);
                }
            }

            if (! $startMatched) {
                $freeEnds++;
            }
            if (! $endMatched) {
                $freeEnds++;
            }
        }

        $roots = [];
        for ($i = 0; $i < $trackCount; $i++) {
            $roots[$find($i)] = true;
        }

        if (count($roots) > 1) {
            return RouteShape::DISCONTINUOUS;
        }

        return $freeEnds === 0 ? RouteShape::ROUNDTRIP : RouteShape::LINEAR;
    }

    /**
     * Feature GeoJSON con la geometria aggregata di tutte le tappe.
     *
     * ST_Union produce una MultiLineString unica; ST_Force2D perché le
     * EcTrack sono 3D e la quota è irrilevante per l'intersezione con le
     * aree amministrative. Le properties sono vuote per scelta:
     * getWheresByGeojson() le azzera comunque per ridurre il payload.
     */
    public function aggregatedGeojsonFeature(Layer $layer): ?array
    {
        $trackType = config('wm-package.ec_track_model', 'App\Models\EcTrack');

        $row = DB::selectOne(
            'SELECT ST_AsGeoJSON(ST_Union(ST_Force2D(t.geometry::geometry))) AS geojson
             FROM ec_tracks t
             JOIN layerables l
               ON l.layerable_id = t.id
              AND l.layerable_type = ?
             WHERE l.layer_id = ?
               AND t.geometry IS NOT NULL',
            [$trackType, $layer->id]
        );

        if ($row === null || $row->geojson === null) {
            return null;
        }

        $geometry = json_decode($row->geojson, true);

        if (! is_array($geometry) || ! isset($geometry['type'])) {
            return null;
        }

        return [
            'type' => 'Feature',
            'properties' => [],
            'geometry' => $geometry,
        ];
    }

    /**
     * Regioni attraversate dal cammino, come `[{value, name}]`.
     *
     * Chiama direttamente il client osmfeatures sulla geometria aggregata
     * delle tappe: la geometria del Layer NON è utilizzabile perché è il
     * suo bounding box (nullable), che intersecherebbe regioni non
     * attraversate dal percorso.
     *
     * getWheresByGeojson() restituisce un oggetto indicizzato per id OSM con
     * una chiave interna `_admin_level`, non esponibile così com'è: la
     * normalizzazione tiene solo le regioni (admin_level 4, i comuni sono
     * rumore per un filtro "Regione"), scarta `_admin_level` dalla mappa dei
     * nomi e produce la stessa forma `{value, name}` degli altri attributi,
     * con `value` derivato dal nome inglese in forma slug. `admin_level` non
     * viene esposto: dopo il filtro vale sempre 4.
     *
     * @return array<int, array{value: string, name: array<string, string>}>|null
     */
    public function wheres(Layer $layer): ?array
    {
        $feature = $this->aggregatedGeojsonFeature($layer);

        if ($feature === null) {
            return null;
        }

        // La chiamata HTTP a osmfeatures e' la parte piu' costosa del
        // ricalcolo, ed e' una funzione pura della geometria aggregata: si
        // memorizza sulla firma della geometria, cosi' i ricalcoli ripetuti
        // dello stesso cammino (auto-riparazione a ogni save, dispatch
        // multipli durante un import) non la ripetono. Se le tappe cambiano,
        // cambia la firma e la chiamata viene rifatta.
        $signature = md5((string) json_encode($feature['geometry'] ?? null));

        $wheres = Cache::remember(
            "layer-attributes:wheres:{$signature}",
            now()->addDay(),
            fn () => app(OsmfeaturesClient::class)->getWheresByGeojson($feature)
        );

        return $this->normalizeWheres($wheres);
    }

    /**
     * Temi associati al cammino, nella forma `[{value, name}]` usata da tutti
     * gli altri attributi.
     *
     * A differenza degli enum (shape, season, walking_network) i temi sono
     * creati dal cliente da Nova, quindi il valore inglese puo' mancare: il
     * `value` viene derivato dalla prima traduzione disponibile nell'ordine
     * en, it, resto — e in ultima istanza dall'id della tassonomia, cosi' che
     * un tema senza nome utilizzabile resti comunque filtrabile con una chiave
     * stabile.
     *
     * @return array<int, array{value: string, name: array<string, string>}>|null
     */
    public function themes(Layer $layer): ?array
    {
        $themes = [];

        foreach ($layer->taxonomyThemes as $theme) {
            /** @var \Wm\WmPackage\Models\TaxonomyTheme $theme */
            /** @var array<string, string> $names */
            $names = array_filter(
                $theme->getTranslations('name'),
                fn ($name) => is_string($name) && $name !== ''
            );

            // Un tema senza nessun nome utilizzabile viene SCARTATO, non
            // esposto con un nome vuoto: `name` deve essere sempre un oggetto
            // JSON, e una mappa PHP vuota verrebbe serializzata come lista
            // `[]` (stesso cambio di tipo che writeAttributes() evita per la
            // chiave `attributes`), rompendo un consumer tipizzato. Un tema
            // senza nome non e' comunque presentabile come voce di filtro.
            if ($names === []) {
                Log::warning('LayerAttributesService::themes: tema senza nome utilizzabile, escluso dagli attributi', [
                    'layer_id' => $layer->id,
                    'taxonomy_theme_id' => $theme->id,
                ]);

                continue;
            }

            $source = $names['en'] ?? $names['it'] ?? reset($names);
            $value = $this->slugifyValue((string) $source);

            if ($value === '') {
                $value = 'theme-'.$theme->id;
            }

            $themes[$value] = ['value' => $value, 'name' => $names];
        }

        if ($themes === []) {
            return null;
        }

        // Chiave del map usata per deduplicare due temi con lo stesso slug;
        // ordinamento per value per rendere deterministica la scrittura.
        ksort($themes);

        return array_values($themes);
    }

    /**
     * Riduce un nome a una chiave stabile in stile enum: minuscolo, ASCII,
     * spazi e separatori resi trattini. "Aosta Valley" -> "aosta-valley".
     */
    private function slugifyValue(string $name): string
    {
        $ascii = Str::ascii($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($ascii)) ?? '';

        return trim($slug, '-');
    }

    /**
     * Normalizza l'oggetto indicizzato per id OSM restituito dal client in
     * una lista `[{value, name}]`.
     *
     * Tiene solo le regioni (admin_level 4), scarta le aree senza nome,
     * dedupica per value e ordina per value — così ricalcoli identici
     * producono scritture identiche.
     *
     * @param  array<string, array<string, mixed>>  $wheres
     * @return array<int, array{value: string, name: array<string, string>}>|null
     */
    private function normalizeWheres(array $wheres): ?array
    {
        $normalized = [];
        // Deduplica sullo slug: due aree distinte con lo stesso nome inglese
        // produrrebbero due voci identiche, quindi due opzioni "Regione"
        // duplicate nello stesso filtro (themes() dedupica gia' allo stesso
        // modo, con la mappa chiavata sullo slug).
        $seen = [];

        foreach ($wheres as $identifier => $data) {
            $adminLevel = isset($data['_admin_level']) ? (int) $data['_admin_level'] : null;
            unset($data['_admin_level']);

            // Solo le regioni. Il client osmfeatures interroga due livelli
            // amministrativi e ne unisce i risultati: 4 = regione, 8 = comune.
            // Il filtro esposto all'utente e' "Regione", quindi i comuni sono
            // rumore: su 121 cammini producevano 2780 voci complessive (un
            // cammino da solo ne aveva 271) e 767 KB di config.
            if ($adminLevel !== self::ADMIN_LEVEL_REGION) {
                continue;
            }

            // Stessa forma { value, name } degli altri attributi, con value
            // derivato dal nome inglese come per gli enum (season, shape).
            //
            // Non vengono esposti ne' admin_level (dopo il filtro qui sopra
            // vale sempre 4: campo costante, inutile al consumer) ne' il codice
            // OSM: la chiave del filtro e' il valore inglese, coerente con
            // tutti gli altri attributi.
            // Come in themes(): un'area senza nessun tag name non viene
            // esposta, per non produrre `name: []` (lista) al posto di un
            // oggetto. Non e' una perdita: una regione senza nome non e'
            // presentabile come voce del filtro.
            if ($data === []) {
                Log::warning('LayerAttributesService::normalizeWheres: area senza nome, esclusa dagli attributi', [
                    'osmfeatures_id' => $identifier,
                ]);

                continue;
            }

            $value = $this->slugifyValue((string) ($data['en'] ?? $data['it'] ?? reset($data)));

            if ($value === '') {
                $value = $this->slugifyValue((string) $identifier);
            }

            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;

            $normalized[] = [
                'value' => $value,
                'name' => $data,
            ];
        }

        if ($normalized === []) {
            return null;
        }

        // Ordinamento per codice OSM: rende la scrittura deterministica a
        // ricalcoli identici.
        usort($normalized, fn (array $a, array $b): int => strcmp($a['value'], $b['value']));

        return $normalized;
    }

    /**
     * True se i valori appena calcolati coincidono con quelli gia' persistiti.
     *
     * Serve a non riscrivere e soprattutto a non rigenerare il config quando
     * non e' cambiato nulla: il ricalcolo viene accodato anche da eventi che
     * non toccano gli attributi (auto-riparazione a ogni save del cammino),
     * e ogni rigenerazione e' un rebuild completo del config con upload.
     *
     * Il confronto e' canonico: jsonb normalizza l'ordine delle chiavi, i
     * valori calcolati no, quindi entrambi i lati vengono ordinati
     * ricorsivamente prima di essere serializzati.
     *
     * @param  array<string, mixed>  $values
     */
    public function calculatedValuesAreUnchanged(Layer $layer, array $values): bool
    {
        $stored = DB::selectOne(
            "SELECT COALESCE(properties->'attributes', '{}'::jsonb) AS attributes FROM layers WHERE id = ?",
            [$layer->id]
        );

        if ($stored === null) {
            return false;
        }

        $decoded = json_decode((string) $stored->attributes, true);
        $decoded = is_array($decoded) ? $decoded : [];

        $storedCalculated = array_intersect_key($decoded, array_flip(self::CALCULATED_KEYS));

        return $this->canonicalize($storedCalculated) === $this->canonicalize($values);
    }

    /**
     * Serializzazione stabile e indipendente dall'ordine delle chiavi.
     * Le LISTE mantengono il loro ordine (in taxonomy_where e themes l'ordine
     * e' significativo e deterministico); solo le mappe vengono ordinate.
     */
    private function canonicalize(mixed $value): string
    {
        return (string) json_encode($this->sortKeysRecursively($value));
    }

    private function sortKeysRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($item) => $this->sortKeysRecursively($item), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Chiavi calcolate del sotto-oggetto properties->attributes (le
     * caratteristiche del cammino — non gli attributi Eloquent del modello).
     *
     * Le chiavi non calcolabili sono OMESSE, non impostate a 0/null: un
     * layer senza tappe non deve comparire in un filtro "0-10 km"
     * (stesso criterio del guard di oc:8140).
     */
    public function computeCalculatedValues(Layer $layer): array
    {
        $values = [];

        $distance = $this->totalDistance($layer);
        if ($distance !== null) {
            $values['distance'] = $distance;
        }

        $stageCount = $this->stageCount($layer);
        if ($stageCount !== null) {
            $values['stage_count'] = $stageCount;
        }

        $shape = $this->determineType($this->trackEndpoints($layer));
        if ($shape !== null) {
            $values['shape'] = $this->withTranslations($shape->value, fn (string $locale) => $shape->labelIn($locale));
        }

        $wheres = $this->wheres($layer);
        if ($wheres !== null) {
            $values['taxonomy_where'] = $wheres;
        }

        $themes = $this->themes($layer);
        if ($themes !== null) {
            $values['themes'] = $themes;
        }

        return $values;
    }

    /**
     * Scrive le chiavi calcolate nel sotto-oggetto properties->attributes
     * (le caratteristiche del cammino, non gli attributi Eloquent del
     * modello) via jsonb_set.
     *
     * Non si usa il salvataggio Eloquent: su properties scrivono già
     * setNameAttribute, l'override di Layer::save(), le traduzioni Spatie
     * e job del package, tutti in read-modify-write dell'intero blob —
     * un save() concorrente durante un ricalcolo massivo cancellerebbe
     * traduzioni redazionali non ricalcolabili.
     *
     * Le chiavi manuali di properties->attributes (walking_network, season) sono preservate; le
     * chiavi calcolate non più calcolabili vengono rimosse per non
     * lasciare valori stale.
     */
    public function persistCalculatedValues(Layer $layer, array $values): void
    {
        $payload = $values === []
            ? '{}'
            : json_encode($values, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        // Le chiavi calcolate da rimuovere passano come array bindato
        // (operatore jsonb `- text[]`), non interpolate nella query: stesso
        // stile di persistManualValue(), un solo modo di scrivere in questa
        // classe.
        $this->writeAttributes(
            $layer,
            "(COALESCE(properties->'attributes', '{}'::jsonb) - ?::text[]) || ?::jsonb",
            ['{'.implode(',', self::CALCULATED_KEYS).'}', $payload]
        );
    }

    /**
     * Scrive properties->attributes valutando l'espressione SQL passata, e
     * RIMUOVE del tutto la chiave se il risultato e' un oggetto vuoto.
     *
     * Il motivo per cui la chiave non viene lasciata a `{}`: un oggetto JSON
     * vuoto letto da Postgres torna in PHP come array vuoto e viene
     * riserializzato nel config come `[]`, cioe' un tipo diverso da quello di
     * tutti gli altri cammini (oggetto). Un consumer tipizzato (Dart/Swift,
     * dove attributes e' una mappa) si rompe su quel cambio di tipo. Chiave
     * assente e' invece una condizione sola da verificare, ed e' la stessa
     * regola che applichiamo alle singole chiavi non calcolabili.
     *
     * L'espressione compare due volte nella query (nel test e nel ramo di
     * scrittura), quindi i binding vengono passati due volte.
     *
     * @param  array<int, mixed>  $bindings
     */
    private function writeAttributes(Layer $layer, string $expression, array $bindings): void
    {
        DB::update(
            "UPDATE layers
             SET properties = CASE
                 WHEN ({$expression}) = '{}'::jsonb
                     THEN COALESCE(properties, '{}'::jsonb) - 'attributes'
                 ELSE jsonb_set(
                     COALESCE(properties, '{}'::jsonb),
                     '{attributes}',
                     {$expression},
                     true
                 )
             END
             WHERE id = ?",
            [...$bindings, ...$bindings, $layer->id]
        );
    }

    /**
     * Scrive (o rimuove) un singolo valore manuale del sotto-oggetto
     * properties->attributes (le caratteristiche del cammino, non gli
     * attributi Eloquent del modello)
     * via jsonb_set, senza mai leggere properties in PHP.
     *
     * Stessa forma di persistCalculatedValues(): una sola istruzione SQL,
     * parametri bindati, nessun save()/saveQuietly() Eloquent. A differenza
     * di quel metodo, qui si scrive UNA chiave sola (senza toccare le
     * altre), quindi non c'è finestra di concorrenza con un ricalcolo
     * automatico che scrive nel frattempo le CALCULATED_KEYS.
     *
     * $value === null, oppure una lista vuota ([]), rimuove la chiave
     * invece di scrivere un valore nullo: il requisito è "chiave assente",
     * non "chiave a null" (properties finisce nel config.json pubblico).
     *
     * Rifiuta silenziosamente (log warning, nessuna scrittura) una chiave
     * appartenente a CALCULATED_KEYS: questo metodo è per i valori manuali
     * (walking_network, season), mai per quelli calcolati.
     */
    public function persistManualValue(Layer $layer, string $key, mixed $value): void
    {
        if (in_array($key, self::CALCULATED_KEYS, true)) {
            Log::warning(
                "LayerAttributesService::persistManualValue: tentativo di scrivere la chiave calcolata '{$key}' ignorato.",
                ['layer_id' => $layer->id]
            );

            return;
        }

        $isEmpty = $value === null || $value === [];

        // jsonb_set con create_missing=true crea SOLO l'ultimo elemento del
        // path: se un livello intermedio è assente (qui, 'attributes' quando
        // properties è ancora {}), l'intera chiamata è un no-op silenzioso
        // (nessun errore, nessuna riga cambiata nel contenuto). Per questo,
        // come persistCalculatedValues(), si scrive sempre con un path a un
        // solo livello ('{attributes}'), passando come nuovo valore l'intero
        // oggetto attributes ricomposto in SQL (merge/rimozione della singola
        // chiave), non un path multi-livello.
        if ($isEmpty) {
            $this->writeAttributes(
                $layer,
                "COALESCE(properties->'attributes', '{}'::jsonb) - ?::text",
                [$key]
            );

            return;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        $this->writeAttributes(
            $layer,
            "(COALESCE(properties->'attributes', '{}'::jsonb) - ?::text) || jsonb_build_object(?::text, ?::jsonb)",
            [$key, $key, $encoded]
        );
    }

    /**
     * Vero se due punti [lon, lat] distano meno di JUNCTION_TOLERANCE_METERS
     * (distanza geodetica reale, formula di Haversine).
     */
    private function coordinatesMatch(array $first, array $second): bool
    {
        return $this->haversineDistanceMeters($first, $second) < self::JUNCTION_TOLERANCE_METERS;
    }

    /**
     * Distanza geodetica in metri fra due punti [lon, lat] (formula di
     * Haversine). Calcolo in PHP, non SQL: qui i punti sono già caricati in
     * memoria (trackEndpoints() ha già fatto la query) e i confronti sono
     * O(n²) sul numero di tappe — una query per coppia sarebbe migliaia di
     * round-trip su un cammino con ~100 tappe.
     */
    private function haversineDistanceMeters(array $first, array $second): float
    {
        [$lon1, $lat1] = $first;
        [$lon2, $lat2] = $second;

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }
}

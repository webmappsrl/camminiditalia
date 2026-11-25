<?php

namespace App\Observers;

use App\Jobs\RecalculateLayerAttributesJob;
use App\Services\LayerAttributesService;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;

/**
 * Quando cambia il nome di una regione, ricalcola gli attributi dei layer che la attraversano; il
 * config della loro App si rigenera dal job stesso (oc:8958).
 *
 * Gli attributi `taxonomy_where` dei layer prendono il nome dalla TaxonomyWhere del DB
 * (LayerAttributesService::normalizeWheres()), ma nessun altro evento li ricalcola quando quel
 * nome cambia: senza questo observer una correzione fatta in Nova resterebbe fuori dal filtro
 * dell'app fino al ricalcolo successivo, lanciato a mano.
 *
 * Tracce e POI non sono coperti qui: si riallineano con `wm:resync-taxonomy-where`.
 */
class TaxonomyWhereNameObserver
{
    /**
     * Mette in coda il ricalcolo di ogni layer che attraversa la regione.
     *
     * Job indipendenti e non una chain: RecalculateLayerAttributesJob è
     * ShouldBeUniqueUntilProcessing, e in una chain il job successivo passa da PendingDispatch,
     * che lo scarta se il lock è già preso portandosi dietro tutto il resto della catena (lo
     * stesso motivo per cui RecalculateAppLayerAttributesAction non usa chain). Ogni job, se gli
     * attributi cambiano, accoda UpdateAppConfigJob, che è unico: le rigenerazioni dello stesso
     * config si deduplicano.
     */
    public function updated(TaxonomyWhere $taxonomyWhere): void
    {
        if (! $taxonomyWhere->wasChanged('name')) {
            return;
        }

        if ((int) ($taxonomyWhere->properties['admin_level'] ?? 0) !== LayerAttributesService::ADMIN_LEVEL_REGION) {
            return;
        }

        foreach ($this->crossingLayerIds($taxonomyWhere->id) as $layerId) {
            RecalculateLayerAttributesJob::dispatch($layerId, regenerateConfig: true);
        }
    }

    /**
     * Layer con almeno una tappa che interseca la geometria della where. Stesso criterio di
     * Wm\WmPackage\Services\Models\LayerService (ST_Intersects fra tracce e taxonomy_wheres).
     * Una where senza geometria non produce nessun layer.
     *
     * @return array<int, int> id dei layer
     */
    private function crossingLayerIds(int $taxonomyWhereId): array
    {
        $trackMorphTypes = array_values(array_unique([
            config('wm-package.ec_track_model', EcTrack::class),
            EcTrack::class,
        ]));
        $placeholders = implode(',', array_fill(0, count($trackMorphTypes), '?'));

        $rows = DB::select(
            "SELECT DISTINCT l.id
               FROM layers l
               JOIN layerables lb ON lb.layer_id = l.id AND lb.layerable_type IN ({$placeholders})
               JOIN ec_tracks t ON t.id = lb.layerable_id
               JOIN taxonomy_wheres w ON w.id = ? AND w.geometry IS NOT NULL
              WHERE ST_Intersects(t.geometry::geometry, w.geometry::geometry)
              ORDER BY l.id",
            [...$trackMorphTypes, $taxonomyWhereId]
        );

        return array_map(fn ($row) => (int) $row->id, $rows);
    }
}

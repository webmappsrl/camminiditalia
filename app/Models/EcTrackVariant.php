<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Wm\WmPackage\Models\EcTrack;

/**
 * Una tappa dichiarata variante di un'altra (oc:8165).
 *
 * Le due si equivalgono: basta averne percorsa una, e nel totale del cammino contano per una
 * sola. Il raggruppamento avviene al momento di contare, non quando si registra una
 * validazione, così il passaporto certifica sempre la tappa davvero percorsa.
 *
 * La relazione vive su **un solo livello**: una variante non può avere varianti sue. È il
 * guard di `booted()`, perché la chiave primaria copre solo «una tappa ha al più una
 * principale».
 *
 * @property int $ec_track_id
 * @property int $main_ec_track_id
 */
class EcTrackVariant extends Model
{
    protected $table = 'ec_track_variants';

    protected $primaryKey = 'ec_track_id';

    public $incrementing = false;

    protected $fillable = [
        'ec_track_id',
        'main_ec_track_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $variant) {
            if ($key = static::violation((int) $variant->ec_track_id, (int) $variant->main_ec_track_id)) {
                throw new RuntimeException(__($key));
            }
        });
    }

    /**
     * Il motivo per cui la tappa non può essere variante della principale indicata, come chiave
     * di traduzione, o null se il collegamento è ammesso. Lo usano il guard del model e la
     * validazione del campo Nova (oc:8862), così le regole stanno in un punto solo.
     */
    public static function violation(int $ecTrackId, int $mainEcTrackId): ?string
    {
        if ($ecTrackId === $mainEcTrackId) {
            return 'A stage cannot be a variant of itself.';
        }

        if (static::whereKey($mainEcTrackId)->exists()) {
            return 'The main stage is already a variant: variants live on a single level.';
        }

        if (static::where('main_ec_track_id', $ecTrackId)->exists()) {
            return 'This stage is already the main stage of other variants: it cannot become a variant itself.';
        }

        return null;
    }

    /**
     * Per le tappe indicate, la principale di ciascuna. Le tappe che non sono varianti non
     * compaiono nel risultato.
     *
     * @param  array<int>  $ecTrackIds
     * @return array<int, int> id variante => id principale
     */
    public static function mainIdsFor(array $ecTrackIds): array
    {
        if ($ecTrackIds === []) {
            return [];
        }

        return static::whereIn('ec_track_id', $ecTrackIds)
            ->pluck('main_ec_track_id', 'ec_track_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * La radice del gruppo di ogni tappa indicata: la principale se la tappa è una variante,
     * altrimenti la tappa stessa. È il criterio con cui si contano i gruppi di un cammino.
     *
     * @param  array<int>  $ecTrackIds
     * @return array<int, int> id tappa => id radice
     */
    public static function rootIdsFor(array $ecTrackIds): array
    {
        $mains = static::mainIdsFor($ecTrackIds);

        $roots = [];
        foreach ($ecTrackIds as $id) {
            $roots[$id] = $mains[$id] ?? $id;
        }

        return $roots;
    }

    /** @return BelongsTo<EcTrack, $this> */
    public function ecTrack(): BelongsTo
    {
        return $this->belongsTo(EcTrack::class, 'ec_track_id');
    }

    /** @return BelongsTo<EcTrack, $this> */
    public function mainEcTrack(): BelongsTo
    {
        return $this->belongsTo(EcTrack::class, 'main_ec_track_id');
    }
}

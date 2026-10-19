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
            if ($variant->ec_track_id === $variant->main_ec_track_id) {
                throw new RuntimeException('Una tappa non può essere variante di sé stessa.');
            }

            if (static::whereKey($variant->main_ec_track_id)->exists()) {
                throw new RuntimeException(
                    'La tappa principale è già una variante: le varianti vivono su un solo livello.'
                );
            }

            if (static::where('main_ec_track_id', $variant->ec_track_id)->exists()) {
                throw new RuntimeException(
                    'Questa tappa è già principale di altre varianti: non può diventare a sua volta una variante.'
                );
            }
        });
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

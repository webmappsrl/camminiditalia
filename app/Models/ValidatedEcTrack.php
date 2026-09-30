<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

/**
 * Tappa (EcTrack) validata per un camminatore (oc:8671). Una sola riga per
 * coppia utente-tappa (vincolo unico). `certification_request_id` è valorizzato
 * solo per le validazioni manuali; `layer_id` è il cammino in cui la tappa è
 * stata validata per prima, non un'appartenenza.
 *
 * @property int $id
 * @property int $user_id
 * @property int $ec_track_id
 * @property int $layer_id
 * @property ?int $certification_request_id
 * @property string $source
 * @property \Illuminate\Support\Carbon $validated_at
 * @property-read ?\Illuminate\Database\Eloquent\Model $ecTrack
 */
class ValidatedEcTrack extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_GPS = 'gps';

    protected $fillable = [
        'user_id',
        'ec_track_id',
        'layer_id',
        'certification_request_id',
        'source',
        'validated_at',
    ];

    protected $casts = [
        'validated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Modello da config('wm-package.ec_track_model'), come Layer::ecTracks().
     *
     * @return BelongsTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function ecTrack(): BelongsTo
    {
        return $this->belongsTo(config('wm-package.ec_track_model', EcTrack::class), 'ec_track_id');
    }

    /**
     * @return BelongsTo<Layer, $this>
     */
    public function layer(): BelongsTo
    {
        return $this->belongsTo(Layer::class);
    }

    /**
     * @return BelongsTo<CertificationRequest, $this>
     */
    public function certificationRequest(): BelongsTo
    {
        return $this->belongsTo(CertificationRequest::class);
    }
}

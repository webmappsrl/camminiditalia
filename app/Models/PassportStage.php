<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
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
 * @property ?Carbon $validated_at nullo finché la tappa non è validata (oc:8165)
 * @property int $progress quota di segmenti percorsi, 0-100 (oc:8165)
 * @property-read ?Model $ecTrack
 */
class PassportStage extends Model
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
        'progress',
    ];

    protected $casts = [
        'validated_at' => 'datetime',
        'progress' => 'integer',
    ];

    /**
     * Solo le tappe validate. Dal oc:8165 la tabella ospita anche righe di avanzamento
     * parziale, con `validated_at` nullo: è il punto unico per escluderle. Per le query raw
     * vedi validatedSql().
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeValidated(Builder $query): Builder
    {
        return $query->whereNotNull($query->qualifyColumn('validated_at'));
    }

    /**
     * Stessa condizione di scopeValidated() per le query raw/JoinClause su
     * `passport_stages` con alias (es. `v`). Nelle LEFT JOIN va messa
     * nella clausola ON, non nel WHERE.
     */
    public static function validatedSql(string $alias): string
    {
        return "{$alias}.validated_at IS NOT NULL";
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Le UGC track che hanno prodotto questa riga (oc:8165): è il «numero di uscite» mostrato
     * sulla tappa sbloccata, e serve a rifare i conti quando una UGC track viene cancellata.
     * Vuota sulle validazioni concesse a mano.
     *
     * @return BelongsToMany<UgcTrack, $this>
     */
    public function ugcTracks(): BelongsToMany
    {
        return $this->belongsToMany(UgcTrack::class, 'passport_stage_ugc_track');
    }

    /**
     * Modello da config('wm-package.ec_track_model'), come Layer::ecTracks().
     *
     * @return BelongsTo<Model, $this>
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

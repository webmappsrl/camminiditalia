<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Models\User as WmUser;

/**
 * Le foto della credenziale stanno nella media library (collection `default`),
 * come per gli UGC. InteractsWithMedia rimuove media e file alla cancellazione.
 *
 * @property int $id
 * @property int $user_id
 * @property int $layer_id
 * @property ?int $app_id
 * @property string $status
 * @property ?string $serial_number
 * @property \Illuminate\Support\Carbon $disclaimer_accepted_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class CertificationRequest extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** Valore di risposta API quando non esiste alcuna richiesta: NON è uno stato persistito. */
    public const STATUS_NONE = 'none';

    public const MEDIA_COLLECTION = 'default';

    protected $fillable = [
        'user_id',
        'layer_id',
        'app_id',
        'status',
        'serial_number',
        'disclaimer_accepted_at',
    ];

    protected $casts = [
        'disclaimer_accepted_at' => 'datetime',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::MEDIA_COLLECTION);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Layer, $this>
     */
    public function layer(): BelongsTo
    {
        return $this->belongsTo(Layer::class);
    }

    /**
     * @param  Builder<CertificationRequest>  $query
     * @return Builder<CertificationRequest>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * @param  Builder<CertificationRequest>  $query
     * @return Builder<CertificationRequest>
     */
    public function scopePendingFor(Builder $query, WmUser $user, Layer $layer): Builder
    {
        return $query->pending()
            ->where('user_id', $user->id)
            ->where('layer_id', $layer->id);
    }

    /**
     * Regola di visibilità unica (Nova + policy): Administrator tutto,
     * Validator solo i layer di cui è proprietario, gli altri nulla.
     *
     * @param  Builder<CertificationRequest>  $query
     * @return Builder<CertificationRequest>
     */
    public function scopeVisibleTo(Builder $query, WmUser $user): Builder
    {
        if ($user->hasRole('Administrator')) {
            return $query;
        }

        if ($user->hasRole('Validator')) {
            return $query->whereHas('layer', fn ($q) => $q->where('user_id', $user->id));
        }

        return $query->whereRaw('1 = 0');
    }

    public function isVisibleTo(WmUser $user): bool
    {
        if ($user->hasRole('Administrator')) {
            return true;
        }

        return $user->hasRole('Validator')
            && $this->layer !== null
            && (int) $this->layer->user_id === (int) $user->id;
    }
}

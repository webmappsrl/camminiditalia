<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string $locale
 * @property ?string $decision_note
 * @property ?\Illuminate\Support\Carbon $decided_at
 * @property ?int $decided_by
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

    /** Lingue in cui può partire la mail di esito al camminatore (oc:8671). */
    public const SUPPORTED_LOCALES = ['it', 'en', 'fr', 'es', 'de'];

    public const DEFAULT_LOCALE = 'it';

    /** Lunghezza massima della nota del gestore, uguale in Nova e nella conferma. */
    public const DECISION_NOTE_MAX_LENGTH = 5000;

    protected $fillable = [
        'user_id',
        'layer_id',
        'app_id',
        'status',
        'serial_number',
        'disclaimer_accepted_at',
        'locale',
        'decision_note',
        'decided_at',
        'decided_by',
    ];

    protected $casts = [
        'disclaimer_accepted_at' => 'datetime',
        'decided_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return HasMany<ValidatedEcTrack, $this>
     */
    public function validatedTracks(): HasMany
    {
        return $this->hasMany(ValidatedEcTrack::class)->validated();
    }

    /**
     * Lingua della richiesta ricavata dall'header Accept-Language dell'app:
     * la prima lingua supportata in ordine di preferenza (q), confrontando solo
     * il codice principale (`de-DE` e `de_DE` → `de`); altrimenti DEFAULT_LOCALE.
     */
    public static function localeFromAcceptLanguage(?string $header): string
    {
        $candidates = [];

        foreach (explode(',', (string) $header) as $position => $part) {
            $pieces = explode(';', trim($part));
            $language = strtolower(preg_split('/[-_]/', trim($pieces[0]))[0]);
            $quality = 1.0;

            foreach (array_slice($pieces, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $parameter, $matches)) {
                    $quality = (float) $matches[1];
                }
            }

            if ($language !== '' && $quality > 0) {
                $candidates[] = [$language, $quality, $position];
            }
        }

        usort($candidates, fn ($a, $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        foreach ($candidates as [$language]) {
            if (in_array($language, self::SUPPORTED_LOCALES, true)) {
                return $language;
            }
        }

        return self::DEFAULT_LOCALE;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
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

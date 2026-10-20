<?php

namespace App\Models;

use App\Support\EcTrackLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

/**
 * Esperienza di un camminatore su un cammino (oc:8169): descrizione, foto,
 * tappa facoltativa e registrazioni collegate. Le foto stanno nella media
 * library (collection `photos`); InteractsWithMedia rimuove media e file
 * alla cancellazione.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $layer_id
 * @property ?int $ec_track_id
 * @property ?string $description
 * @property bool $is_public
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Experience extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    public const MEDIA_COLLECTION = 'photos';

    /** Numero massimo di foto di un'esperienza. */
    public const MAX_PHOTOS = 6;

    protected $fillable = [
        'uuid',
        'user_id',
        'layer_id',
        'ec_track_id',
        'description',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::MEDIA_COLLECTION);
    }

    /**
     * L'app dell'esperienza è quella del cammino: la legge MediaObserver per
     * assegnare `app_id` ai media (altrimenti ripiegherebbe su 1).
     */
    public function getAppIdAttribute(): ?int
    {
        return $this->layer?->app_id;
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
     * @return BelongsTo<EcTrack, $this>
     */
    public function ecTrack(): BelongsTo
    {
        return $this->belongsTo(config('wm-package.ec_track_model', EcTrack::class), 'ec_track_id');
    }

    /**
     * Registrazioni (UgcTrack) collegate all'esperienza.
     *
     * @return BelongsToMany<UgcTrack, $this>
     */
    public function ugcTracks(): BelongsToMany
    {
        return $this->belongsToMany(UgcTrack::class, 'experience_ugc_track');
    }

    /**
     * Regola di visibilità unica: Administrator tutto, Validator solo le
     * esperienze pubbliche dei layer di cui è proprietario, gli altri nulla.
     *
     * @param  Builder<Experience>  $query
     * @return Builder<Experience>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('Administrator')) {
            return $query;
        }

        if ($user->hasRole('Validator')) {
            return $query
                ->where('is_public', true)
                ->whereHas('layer', fn ($q) => $q->where('user_id', $user->id));
        }

        return $query->whereRaw('1 = 0');
    }

    public function isVisibleTo(User $user): bool
    {
        if ($user->hasRole('Administrator')) {
            return true;
        }

        return $user->hasRole('Validator')
            && $this->is_public
            && $this->layer !== null
            && (int) $this->layer->user_id === (int) $user->id;
    }

    /**
     * Rappresentazione per l'API dell'app.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing(['layer', 'ecTrack', 'ugcTracks:id']);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'layer' => [
                'id' => $this->layer->id,
                'name' => $this->layerName(),
            ],
            'ec_track' => $this->ecTrack === null ? null : [
                'id' => $this->ecTrack->id,
                'name' => EcTrackLabel::for($this->ecTrack),
            ],
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // Nessuna conversione registrata: la miniatura è l'immagine originale.
            'photos' => $this->getMedia(self::MEDIA_COLLECTION)
                ->map(fn ($media) => [
                    'id' => $media->id,
                    'url' => $media->getUrl(),
                    'thumbnail' => $media->getUrl(),
                ])
                ->values()
                ->all(),
            'ugc_track_ids' => $this->ugcTracks->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    /**
     * Nome del cammino nella lingua corrente, con ripiego sulla prima traduzione non vuota.
     */
    private function layerName(): string
    {
        $layer = $this->layer;
        $name = $layer->name;

        if (is_array($name)) {
            $name = $layer->getTranslation('name', app()->getLocale(), true)
                ?: collect($layer->getTranslations('name'))->first(fn ($v) => trim((string) $v) !== '');
        }

        return trim((string) $name) !== '' ? (string) $name : '#'.$layer->id;
    }
}

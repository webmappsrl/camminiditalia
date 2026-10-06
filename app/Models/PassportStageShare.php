<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

/**
 * Condivisione di una tappa percorsa del passaporto (oc:8702). Una sola riga
 * per coppia utente-tappa: l'`uuid` è stabile e finisce nel link pubblico,
 * l'immagine di condivisione è una sola (collection `share_image`, singleFile).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $layer_id
 * @property int $ec_track_id
 * @property ?array $snapshot
 * @property-read ?int $app_id
 */
class PassportStageShare extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const MEDIA_COLLECTION = 'share_image';

    protected $fillable = [
        'uuid',
        'user_id',
        'layer_id',
        'ec_track_id',
        'snapshot',
    ];

    protected $casts = [
        'snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $share) {
            if (empty($share->uuid)) {
                $share->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * `app_id` derivato dal layer: la tabella non ha la colonna, ma
     * MediaObserver lo legge dal model proprietario per valorizzare
     * `media.app_id` (altrimenti ripiega su un default non valido).
     */
    protected function appId(): Attribute
    {
        return Attribute::get(fn () => $this->layer?->app_id);
    }

    /**
     * Una sola immagine per condivisione: una nuova sostituisce la precedente.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::MEDIA_COLLECTION)->singleFile();
    }

    /**
     * Riga di condivisione della coppia utente-tappa, creata al primo uso.
     */
    public static function forUserAndTrack(User $user, Layer $layer, EcTrack $track): self
    {
        $keys = ['user_id' => $user->id, 'ec_track_id' => $track->id];

        try {
            return static::firstOrCreate($keys, ['layer_id' => $layer->id]);
        } catch (UniqueConstraintViolationException $e) {
            // firstOrCreate non è atomico: con due richieste concorrenti sulla
            // stessa coppia la seconda INSERT viola l'indice unico. La riga
            // esiste ormai, la si rilegge.
            return static::where($keys)->firstOrFail();
        }
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
        return $this->belongsTo(EcTrack::class, 'ec_track_id');
    }
}

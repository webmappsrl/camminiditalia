<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\User;

/**
 * Condivisione del passaporto: una tappa percorsa (oc:8702) o un cammino
 * completato (oc:8703), con la relazione polimorfica `shareable` (EcTrack o
 * Layer). Una sola riga per utente e cosa condivisa: l'`uuid` è stabile e
 * finisce nel link pubblico, l'immagine di condivisione è una sola
 * (collection `share_image`, singleFile).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $layer_id
 * @property string $shareable_type
 * @property int $shareable_id
 * @property ?array $snapshot
 * @property-read ?int $app_id
 */
class PassportShare extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const MEDIA_COLLECTION = 'share_image';

    protected $fillable = [
        'uuid',
        'user_id',
        'layer_id',
        'shareable_type',
        'shareable_id',
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
     * Riga di condivisione dell'utente per `$shareable`, creata al primo uso:
     * una tappa del cammino `$layer`, o il cammino stesso (`$shareable` è
     * `$layer`).
     */
    public static function forUser(User $user, Layer $layer, Model $shareable): self
    {
        $keys = [
            'user_id' => $user->id,
            'shareable_type' => $shareable->getMorphClass(),
            'shareable_id' => $shareable->getKey(),
        ];

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
     * Vero se la condivisione è di un cammino completato, non di una tappa.
     */
    public function isRoute(): bool
    {
        return $this->shareable_type === (new Layer)->getMorphClass();
    }

    /**
     * La tappa (EcTrack) o il cammino (Layer) condivisi.
     *
     * @return MorphTo<Model, $this>
     */
    public function shareable(): MorphTo
    {
        return $this->morphTo();
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
}

<?php

namespace App\Http\Requests;

use App\Models\Experience;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Modifica di un'esperienza (oc:8169). `keep_media_ids` è obbligatorio: stringa
 * JSON di interi (`"[]"` per «tieni nessuna foto»), così arriva anche vuoto in multipart.
 */
class UpdateExperienceRequest extends FormRequest
{
    public const MAX_PHOTOS = Experience::MAX_PHOTOS;

    public function authorize(): bool
    {
        // Esperienza altrui: 404 prima della validazione, come se non esistesse.
        abort_unless($this->route('experience')->user_id === $this->user()->id, 404);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:5000'],
            'keep_media_ids' => ['required', 'string'],
            'images' => ['nullable', 'array', 'max:'.self::MAX_PHOTOS],
            'images.*' => ['file', 'mimes:jpeg,jpg,png,webp', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
        ];
    }

    /**
     * Id delle foto da tenere, già ripuliti da quelli non appartenenti all'esperienza.
     * Da usare dopo la validazione.
     *
     * @return int[]
     */
    public function keepMediaIds(): array
    {
        /** @var Experience $experience */
        $experience = $this->route('experience');
        $decoded = json_decode((string) $this->input('keep_media_ids'), true);
        $requested = is_array($decoded) ? array_map('intval', $decoded) : [];
        $own = $experience->getMedia(Experience::MEDIA_COLLECTION)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_intersect($requested, $own));
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $decoded = json_decode((string) $this->input('keep_media_ids'), true);
                $valid = is_array($decoded) && array_is_list($decoded)
                    && count(array_filter($decoded, fn ($v) => ! is_int($v))) === 0;
                if (! $valid) {
                    $validator->errors()->add('keep_media_ids', __('keep_media_ids must be a JSON array of integers.'));
                }
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Experience $experience */
                $experience = $this->route('experience');
                $kept = count($this->keepMediaIds());
                $new = count((array) $this->file('images', []));

                if ($kept + $new > self::MAX_PHOTOS) {
                    $validator->errors()->add('images', __('An experience can have at most :max photos.', ['max' => self::MAX_PHOTOS]));
                }

                // Senza `description` nella richiesta il testo resta quello attuale.
                $text = $this->exists('description') ? $this->input('description') : $experience->description;
                if (trim((string) $text) === '' && $kept + $new === 0) {
                    $validator->errors()->add('description', __('Write a text or add at least one photo.'));
                }
            },
        ];
    }
}

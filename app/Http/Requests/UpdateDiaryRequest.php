<?php

namespace App\Http\Requests;

use App\Models\Diary;
use App\Services\DiaryTextService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Modifica di un'esperienza (oc:8169). `keep_media_ids` è obbligatorio: stringa
 * JSON di interi (`"[]"` per «tieni nessuna foto»), così arriva anche vuoto in multipart.
 */
class UpdateDiaryRequest extends FormRequest
{
    public const MAX_PHOTOS = Diary::MAX_PHOTOS;

    public function authorize(): bool
    {
        // Esperienza altrui: 404 prima della validazione, come se non esistesse.
        abort_unless($this->route('diary')->user_id === $this->user()->id, 404);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Il racconto si ripulisce prima della validazione (oc:8169, ciclo 3): i limiti valgono
     * sull'HTML che verrà salvato, e un racconto di soli paragrafi vuoti diventa `null`.
     */
    protected function prepareForValidation(): void
    {
        $description = $this->input('description');
        // un valore non testuale o enorme non si ripulisce: lo rifiutano le regole `string` e `max`
        if (is_string($description) && strlen($description) <= DiaryTextService::MAX_HTML) {
            $this->merge(['description' => app(DiaryTextService::class)->clean($description)]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['description.max' => __('The story is too long.')];
    }

    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:'.DiaryTextService::MAX_HTML],
            'publication_consent' => ['sometimes', 'boolean'],
            'rating_route' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'rating_hospitality' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'rating_difficulty' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            // date di percorrenza (oc:8169, ciclo 4): «oggi» nel fuso di Roma, non del server
            'walk_start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Rome')->format('Y-m-d')],
            'walk_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:walk_start_date', 'before_or_equal:'.now('Europe/Rome')->format('Y-m-d')],
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
        /** @var Diary $diary */
        $diary = $this->route('diary');
        $decoded = json_decode((string) $this->input('keep_media_ids'), true);
        $requested = is_array($decoded) ? array_map('intval', $decoded) : [];
        $own = $diary->getMedia(Diary::MEDIA_COLLECTION)->pluck('id')->map(fn ($id) => (int) $id)->all();

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

                /** @var Diary $diary */
                $diary = $this->route('diary');
                $kept = count($this->keepMediaIds());
                $new = count((array) $this->file('images', []));

                if ($kept + $new > self::MAX_PHOTOS) {
                    $validator->errors()->add('images', __('A diary can have at most :max photos.', ['max' => self::MAX_PHOTOS]));
                }

                $description = $this->input('description');
                if ($description !== null && app(DiaryTextService::class)->visibleLength($description) > DiaryTextService::MAX_VISIBLE) {
                    $validator->errors()->add('description', __('The story can be at most 20,000 characters long.'));

                    return;
                }

                // Senza `description` nella richiesta il testo resta quello attuale.
                $text = $this->exists('description') ? $description : $diary->description;
                if (trim((string) $text) === '' && $kept + $new === 0) {
                    $validator->errors()->add('description', __('Write a text or add at least one photo.'));
                }
            },
        ];
    }
}

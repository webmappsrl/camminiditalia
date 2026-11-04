<?php

namespace App\Http\Requests;

use App\Models\Diary;
use App\Services\DiaryTextService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Wm\WmPackage\Models\Layer;

/**
 * Creazione di un'esperienza (oc:8169). Oltre alle regole sui campi, dopo la
 * validazione si controlla che l'esperienza abbia un contenuto (testo o foto)
 * e che la tappa appartenga al cammino indicato.
 */
class StoreDiaryRequest extends FormRequest
{
    public function authorize(): bool
    {
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
            'uuid' => ['required', 'uuid'],
            'layer_id' => ['nullable', 'integer', 'exists:layers,id'],
            'ec_track_id' => ['nullable', 'required_without:layer_id', 'integer', 'exists:ec_tracks,id'],
            'description' => ['nullable', 'string', 'max:'.DiaryTextService::MAX_HTML],
            'publication_consent' => ['sometimes', 'boolean'],
            'rating_route' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'rating_hospitality' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'rating_difficulty' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            // date di percorrenza (oc:8169, ciclo 4): «oggi» nel fuso di Roma, non del server
            'walk_start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Rome')->format('Y-m-d')],
            'walk_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:walk_start_date', 'before_or_equal:'.now('Europe/Rome')->format('Y-m-d')],
            'images' => ['nullable', 'array', 'max:'.Diary::MAX_PHOTOS],
            'images.*' => ['file', 'mimes:jpeg,jpg,png,webp', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $description = $this->input('description');
                if ($description !== null && app(DiaryTextService::class)->visibleLength($description) > DiaryTextService::MAX_VISIBLE) {
                    $validator->errors()->add('description', __('The story can be at most 20,000 characters long.'));

                    return;
                }

                $hasText = $description !== null;
                if (! $hasText && ! $this->hasFile('images')) {
                    $validator->errors()->add('description', __('Write a text or add at least one photo.'));
                }

                $layerId = $this->input('layer_id');
                if ($layerId === null && $this->input('ec_track_id') !== null) {
                    $hasLayer = Layer::query()
                        ->whereHas('ecTracks', fn ($q) => $q->where('ec_tracks.id', $this->input('ec_track_id')))
                        ->exists();
                    if (! $hasLayer) {
                        $validator->errors()->add('ec_track_id', __('The stage does not belong to any route.'));
                    }
                }

                $ecTrackId = $this->input('ec_track_id');
                if ($layerId !== null && $ecTrackId !== null) {
                    $belongs = Layer::query()->whereKey($layerId)
                        ->whereHas('ecTracks', fn ($q) => $q->where('ec_tracks.id', $ecTrackId))
                        ->exists();
                    if (! $belongs) {
                        $validator->errors()->add('ec_track_id', __('The stage does not belong to this route.'));
                    }
                }
            },
        ];
    }
}

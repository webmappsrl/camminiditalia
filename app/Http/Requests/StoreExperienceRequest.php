<?php

namespace App\Http\Requests;

use App\Models\Experience;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Wm\WmPackage\Models\Layer;

/**
 * Creazione di un'esperienza (oc:8169). Oltre alle regole sui campi, dopo la
 * validazione si controlla che l'esperienza abbia un contenuto (testo o foto)
 * e che la tappa appartenga al cammino indicato.
 */
class StoreExperienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'layer_id' => ['nullable', 'integer', 'exists:layers,id'],
            'ec_track_id' => ['nullable', 'required_without:layer_id', 'integer', 'exists:ec_tracks,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'images' => ['nullable', 'array', 'max:'.Experience::MAX_PHOTOS],
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

                $hasText = trim((string) $this->input('description')) !== '';
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

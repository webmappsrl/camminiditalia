<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCertificationRequestRequest extends FormRequest
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
            'images' => ['required', 'array', 'min:1', 'max:6'],
            'images.*' => ['required', 'file', 'mimes:jpeg,png,webp,heic,heif', 'max:8192'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            // Limite `tomorrow` e non `today`: il server è in UTC, mentre l'app limita le date all'oggi locale del dispositivo (in Italia fra mezzanotte e le 2 è già il giorno dopo per UTC).
            'start_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:tomorrow'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:tomorrow'],
            'disclaimer_accepted' => ['accepted'],
        ];
    }
}

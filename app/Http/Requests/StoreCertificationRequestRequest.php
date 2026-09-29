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
            'disclaimer_accepted' => ['accepted'],
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Diary;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * `user_id` e `layer_id` non hanno un default: li passa sempre il chiamante.
 *
 * @extends Factory<Diary>
 */
class DiaryFactory extends Factory
{
    protected $model = Diary::class;

    /**
     * Stato di default: esperienza privata con uuid casuale.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'description' => fake()->sentence(),
            'is_public' => false,
        ];
    }
}

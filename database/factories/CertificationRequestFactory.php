<?php

namespace Database\Factories;

use App\Models\CertificationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * `user_id` e `layer_id` non hanno un default: li passa sempre il chiamante.
 *
 * @extends Factory<CertificationRequest>
 */
class CertificationRequestFactory extends Factory
{
    protected $model = CertificationRequest::class;

    /**
     * Stato di default: richiesta in attesa, con date di percorrenza valide.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => CertificationRequest::STATUS_PENDING,
            'disclaimer_accepted_at' => now(),
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-05',
            'locale' => CertificationRequest::DEFAULT_LOCALE,
        ];
    }
}

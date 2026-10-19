<?php

namespace App\Jobs;

use App\Mail\CertificationDecisionMail;
use App\Models\CertificationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCertificationDecisionMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public CertificationRequest $request,
    ) {}

    public function handle(): void
    {
        $email = trim((string) $this->request->user?->email);

        if ($email === '') {
            Log::warning('SendCertificationDecisionMailJob: il camminatore della richiesta '.$this->request->id.' non ha un indirizzo email.');

            return;
        }

        Mail::to($email)->send(new CertificationDecisionMail($this->request));
    }
}

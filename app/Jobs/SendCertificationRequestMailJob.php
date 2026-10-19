<?php

namespace App\Jobs;

use App\Mail\NewCertificationRequestMail;
use App\Models\CertificationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCertificationRequestMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public CertificationRequest $request,
    ) {}

    public function handle(): void
    {
        $layer = $this->request->layer;
        $owner = $layer?->layerOwner;

        if (! $owner) {
            $fallback = (string) config('camminiditalia.fallback_notification_email');
            Log::info('SendCertificationRequestMailJob: layer '.$layer?->id.' has no owner, sending fallback to '.$fallback.'.');
            Mail::to($fallback)->send(new NewCertificationRequestMail($this->request, noOwner: true));

            return;
        }

        /** @var \Wm\WmPackage\Models\User $owner */
        Mail::to($owner->email)->send(new NewCertificationRequestMail($this->request));
    }
}

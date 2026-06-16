<?php

namespace App\Jobs;

use App\Mail\NewUgcReportMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\Layer;

class SendUgcReportMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        protected GeometryModel $ugc,
        protected Layer $layer,
    ) {}

    public function handle(): void
    {
        $owner = $this->layer->layerOwner;

        if (! $owner) {
            Log::info('SendUgcReportMailJob: layer '.$this->layer->id.' has no owner, sending fallback to info@camminiditalia.org.');
            Mail::to('info@camminiditalia.org')->send(new NewUgcReportMail($this->ugc, $this->layer, noOwner: true));

            return;
        }

        Mail::to($owner->email)->send(new NewUgcReportMail($this->ugc, $this->layer));
    }
}

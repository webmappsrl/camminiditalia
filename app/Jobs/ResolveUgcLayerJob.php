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
use Wm\WmPackage\Services\UgcService;

class ResolveUgcLayerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly GeometryModel $ugc,
        public readonly bool $notify = true,
    ) {}

    public function handle(UgcService $ugcService): void
    {
        $this->ugc->refresh();

        // Solo le segnalazioni (form.id = report) devono ricevere notifica
        $formId = $this->ugc->properties['form']['id'] ?? null;
        if ($formId !== 'report') {
            return;
        }

        // Idempotenza: se layer_id è già stato salvato da un retry precedente, invia email e termina
        if (! empty($this->ugc->properties['layer_id'])) {
            if ($this->notify) {
                $layer = Layer::find($this->ugc->properties['layer_id']);
                if ($layer) {
                    SendUgcReportMailJob::dispatch($this->ugc, $layer);
                }
            }

            return;
        }

        // Guard: geometry assente o non valida
        if (! $this->ugc->geometry) {
            Log::warning('ResolveUgcLayerJob: UgcPoi #'.$this->ugc->id.' ha geometry null, skip.');

            return;
        }

        $layer = $ugcService->resolveLayerByProximity($this->ugc);

        if (! $layer) {
            Log::info('ResolveUgcLayerJob: nessun layer trovato per UgcPoi #'.$this->ugc->id.'.');
            if ($this->notify) {
                Mail::to('info@camminiditalia.org')->send(new NewUgcReportMail($this->ugc, null, noOwner: true));
            }

            return;
        }

        // Salva layer_id nelle properties con saveQuietly() per non retriggare l'observer
        $properties = $this->ugc->properties ?? [];
        $properties['layer_id'] = $layer->id;
        $properties['layer_id_auto_resolved'] = true;
        if (isset($properties['form']) && is_array($properties['form'])) {
            $properties['form']['layer_id'] = $layer->id;
        }
        $this->ugc->properties = $properties;
        $this->ugc->saveQuietly();

        if ($this->notify) {
            SendUgcReportMailJob::dispatch($this->ugc, $layer);
        }
    }
}

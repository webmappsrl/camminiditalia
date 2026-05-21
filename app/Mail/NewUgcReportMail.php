<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Models\Abstracts\GeometryModel;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\GeometryComputationService;

class NewUgcReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $novaUrl;
    public ?string $coordinates;

    public function __construct(
        public readonly GeometryModel $ugcPoi,
        public readonly Layer $layer,
    ) {
        $resourceName = $ugcPoi instanceof \Wm\WmPackage\Models\UgcPoi ? 'ugc-pois' : 'ugc-tracks';
        $this->novaUrl = rtrim(config('app.url'), '/').'/nova/resources/'.$resourceName.'/'.$ugcPoi->id;
        $this->coordinates = $this->extractCoordinates($ugcPoi);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nuova segnalazione su '.$this->layer->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-ugc-report',
        );
    }

    private function extractCoordinates(GeometryModel $ugc): ?string
    {
        try {
            $coords = GeometryComputationService::make()->getGeometryModelCoordinates($ugc);

            return number_format($coords->y, 6).', '.number_format($coords->x, 6);
        } catch (\Throwable) {
            return null;
        }
    }
}

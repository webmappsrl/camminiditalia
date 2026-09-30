<?php

namespace App\Mail;

use App\Models\CertificationRequest;
use App\Support\EcTrackLabel;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Wm\WmPackage\Models\EcTrack;

/**
 * Esito della richiesta di certificazione, al camminatore (oc:8671). La lingua
 * è quella salvata sulla richiesta al POST dell'app: impostata sul Mailable,
 * così subject e vista vengono tradotti insieme.
 */
class CertificationDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly CertificationRequest $request,
    ) {
        $this->locale($request->locale ?: CertificationRequest::DEFAULT_LOCALE);
    }

    public function envelope(): Envelope
    {
        $key = $this->isApproved()
            ? 'Your certification request for :route has been approved'
            : 'Your certification request for :route has been rejected';

        return new Envelope(
            subject: __($key, ['route' => $this->routeName()], $this->locale),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.certification-decision',
            with: [
                'approved' => $this->isApproved(),
                'route' => $this->routeName(),
                'walkerName' => trim((string) $this->request->user?->name),
                'stages' => EcTrackLabel::sortNatural(
                    $this->request->validatedTracks()->with('ecTrack')->get()
                        ->map(fn ($validated) => $validated->ecTrack)
                        ->filter(fn ($track) => $track instanceof EcTrack)
                )->map(fn (EcTrack $track) => EcTrackLabel::for($track)),
                'note' => $this->request->decision_note,
            ],
        );
    }

    private function isApproved(): bool
    {
        return $this->request->status === CertificationRequest::STATUS_APPROVED;
    }

    private function routeName(): string
    {
        return $this->request->layer?->getStringName() ?? '—';
    }
}

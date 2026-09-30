<?php

namespace App\Mail;

use App\Models\CertificationRequest;
use App\Nova\CertificationRequest as NovaCertificationRequest;
use App\Support\UserDisplay;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Laravel\Nova\Nova;

class NewCertificationRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $novaUrl;

    public string $walkerDisplay;

    public function __construct(
        public readonly CertificationRequest $request,
        public readonly bool $noOwner = false,
    ) {
        $this->novaUrl = rtrim(config('app.url'), '/').'/'.trim(Nova::path(), '/').'/resources/'
            .NovaCertificationRequest::uriKey().'/'.$request->id;
        $this->walkerDisplay = $this->resolveWalkerDisplay($request);
        $this->locale('it');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nuova richiesta di certificazione su '.($this->request->layer?->getStringName() ?? 'cammino non determinato'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-certification-request',
        );
    }

    private function resolveWalkerDisplay(CertificationRequest $request): string
    {
        return UserDisplay::withEmail($request->user);
    }
}

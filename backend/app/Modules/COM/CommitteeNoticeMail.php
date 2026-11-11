<?php

namespace App\Modules\COM;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso a los integrantes del Comité SST (convocatoria a reunión). El texto lo arma CommitteeController. */
class CommitteeNoticeMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $companyName,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title.' · '.$this->companyName);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.committee-notice', text: 'mail.committee-notice-text');
    }
}

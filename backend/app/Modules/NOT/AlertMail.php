<?php

namespace App\Modules\NOT;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso inmediato por correo (alertas críticas y canales de correo documentados). Sin datos médicos: enlaza a la app. */
class AlertMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $company,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly string $priority,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->priority === 'critical' ? 'URGENTE: ' : '').$this->title.' · '.$this->company);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.alert', text: 'mail.alert-text');
    }
}

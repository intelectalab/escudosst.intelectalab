<?php

namespace App\Modules\SUS\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso de la suscripción al cliente: por vencer, vencida (gracia), bloqueada o pago registrado.
 * El texto lo arma SubscriptionNotifier; aquí solo se da formato.
 */
class SubscriptionNoticeMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $body,
        public readonly string $tone,   // info | warning | danger | success
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title.' · EscudoSST');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.subscription-notice', text: 'mail.subscription-notice-text');
    }
}

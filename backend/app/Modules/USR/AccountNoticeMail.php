<?php

namespace App\Modules\USR;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso de seguridad de la cuenta (M-USR §10): bloqueo por intentos fallidos o inicio de sesión desde un equipo nuevo. */
class AccountNoticeMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title.' · EscudoSST');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.account-notice', text: 'mail.account-notice-text');
    }
}

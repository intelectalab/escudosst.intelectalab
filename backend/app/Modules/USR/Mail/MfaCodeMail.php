<?php

namespace App\Modules\USR\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Código de verificación de 6 dígitos para iniciar sesión.
 * Se envía al momento (no por cola) porque la persona está esperando en la pantalla.
 * Nunca lleva datos médicos ni enlaces para iniciar sesión.
 */
class MfaCodeMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $code,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->code.' es tu código de verificación de EscudoSST');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.mfa-code',
            text: 'mail.mfa-code-text',
        );
    }
}

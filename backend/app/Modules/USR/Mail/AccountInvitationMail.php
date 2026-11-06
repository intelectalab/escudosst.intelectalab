<?php

namespace App\Modules\USR\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Invitación al administrador de cuenta. Con $url: crea su contraseña. Sin $url: ya tenía usuario
 * y solo se le avisa que tiene acceso a la nueva empresa.
 */
class AccountInvitationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $companyName,
        public readonly ?string $url,
        public readonly int $hours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->url
            ? 'Te invitaron a administrar '.$this->companyName.' en EscudoSST'
            : 'Ya tienes acceso a '.$this->companyName.' en EscudoSST');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.account-invitation', text: 'mail.account-invitation-text');
    }
}

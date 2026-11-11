<?php

namespace App\Modules\USR\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Invitación a una empresa. Con $url: crea su contraseña. Sin $url: ya tenía usuario y solo se le avisa
 * que tiene acceso a la nueva empresa. Sin $roleName es la invitación al administrador de cuenta
 * (panel de la plataforma o «Prueba gratis»); con $roleName, la de un usuario de la empresa con ese rol.
 */
class AccountInvitationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $companyName,
        public readonly ?string $url,
        public readonly int $hours,
        public readonly ?string $roleName = null,
    ) {}

    public function envelope(): Envelope
    {
        if ($this->roleName) {
            return new Envelope(subject: ($this->url ? 'Tu invitación a EscudoSST: ' : 'Ya tienes acceso en EscudoSST: ')
                .$this->roleName.' en '.$this->companyName);
        }

        return new Envelope(subject: $this->url
            ? 'Te invitaron a administrar '.$this->companyName.' en EscudoSST'
            : 'Ya tienes acceso a '.$this->companyName.' en EscudoSST');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.account-invitation', text: 'mail.account-invitation-text');
    }
}

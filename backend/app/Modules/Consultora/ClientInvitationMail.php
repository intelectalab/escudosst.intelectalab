<?php

namespace App\Modules\Consultora;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Invitación de una consultora a una empresa cliente (M-CON, SCR-CON-03). */
class ClientInvitationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $consultancy,
        public readonly string $companyName,
        public readonly string $ruc,
        public readonly ?string $planName,
        public readonly bool $companyExists,
        public readonly string $url,
        public readonly int $days,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->consultancy.' te invita a gestionar la SST de '.$this->companyName.' en EscudoSST');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.consultancy-invitation', text: 'mail.consultancy-invitation-text');
    }
}

<?php

namespace App\Modules\AUL;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso por correo a quien no tiene cuenta en EscudoSST (participantes externos y compradores de VTA): pedido pagado,
 * cupos disponibles, pedido por vencer, matrícula, curso por vencer o completado. Sin datos médicos ni de pago.
 */
class LearnerMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $company,
        public readonly string $title,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title.' · '.$this->company);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.learner-notice', text: 'mail.learner-notice-text');
    }
}

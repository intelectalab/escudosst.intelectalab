<?php

namespace App\Modules\WEB\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correos de los formularios del sitio: respuesta automática a quien pide demo, cotización o consulta,
 * y aviso al equipo de ventas. $rows son líneas «concepto → valor» (p. ej. precios de la cotización).
 */
class SiteRequestMail extends Mailable
{
    use Queueable;

    /** @param  array<int, array{0: string, 1: string}>  $rows */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $intro,
        public readonly array $rows = [],
        public readonly ?string $note = null,
        public readonly ?string $buttonText = null,
        public readonly ?string $buttonUrl = null,
        /** Archivo de calendario (.ics) opcional: [contenido, nombre]. */
        public readonly ?array $ics = null,
        /** Archivo PDF opcional (p. ej. la cotización): [contenido, nombre]. */
        public readonly ?array $attachment = null,
    ) {}

    public function attachments(): array
    {
        $files = [];
        if ($this->ics) {
            $files[] = \Illuminate\Mail\Mailables\Attachment::fromData(fn () => $this->ics[0], $this->ics[1])->withMime('text/calendar; charset=UTF-8; method=PUBLISH');
        }
        if ($this->attachment) {
            $files[] = \Illuminate\Mail\Mailables\Attachment::fromData(fn () => $this->attachment[0], $this->attachment[1])->withMime('application/pdf');
        }

        return $files;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title.' · EscudoSST');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.site-request', text: 'mail.site-request-text');
    }
}

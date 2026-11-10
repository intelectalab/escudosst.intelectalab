<?php

namespace App\Modules\NOT;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Resumen diario de alertas SST de una persona en una empresa (un solo correo con todos sus avisos nuevos). */
class SstDigestMail extends Mailable
{
    use Queueable;

    /** @param list<array{title: string, body: string, url: string, priority: string}> $items */
    public function __construct(
        public readonly string $name,
        public readonly string $company,
        public readonly array $items,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: count($this->items).' alerta(s) SST para hoy · '.$this->company);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.sst-digest', text: 'mail.sst-digest-text');
    }
}

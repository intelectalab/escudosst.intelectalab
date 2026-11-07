<?php

namespace App\Modules\WEB\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Una edición del boletín normativo para un suscriptor. Lleva su enlace de baja de un clic y el encabezado
 * List-Unsubscribe (RN-WEB-50). El cuerpo es texto simple (## subtítulo, - lista) convertido a HTML seguro.
 */
class NewsletterIssueMail extends Mailable
{
    /** @param  array<int, array{t: string, text?: string, items?: string[]}>  $blocks */
    public function __construct(
        public readonly string $subjectLine,
        public readonly ?string $preheader,
        public readonly array $blocks,
        public readonly string $webUrl,
        public readonly string $unsubscribeUrl,
        public readonly string $unsubscribeApiUrl,
        public readonly bool $isTest = false,
        public readonly string $kicker = 'BOLETÍN NORMATIVO SST',
        public readonly string $reason = 'Recibes este boletín porque te suscribiste y confirmaste tu correo en el sitio de EscudoSST.',
        public readonly bool $legalNote = true,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->isTest ? '[Prueba] ' : '').$this->subjectLine);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeApiUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.newsletter-issue', text: 'mail.newsletter-issue-text');
    }

    /** Texto simple → bloques: «## » subtítulo, «- » punto de lista, línea vacía separa párrafos. */
    public static function blocks(string $body): array
    {
        $out = [];
        foreach (preg_split('/\n\s*\n/', str_replace("\r\n", "\n", $body)) as $chunk) {
            $para = [];
            $list = [];
            $flush = function () use (&$out, &$para, &$list) {
                if ($para) {
                    $out[] = ['t' => 'p', 'text' => implode(' ', $para)];
                }
                if ($list) {
                    $out[] = ['t' => 'ul', 'items' => $list];
                }
                $para = $list = [];
            };
            foreach (array_filter(array_map('trim', explode("\n", $chunk)), 'strlen') as $l) {
                if (preg_match('/^#{1,3}\s+(.*)$/', $l, $m)) {
                    $flush();
                    $out[] = ['t' => 'h', 'text' => $m[1]];
                } elseif (preg_match('/^[-•*]\s+(.*)$/', $l, $m)) {
                    if ($para) {
                        $flush();
                    }
                    $list[] = $m[1];
                } else {
                    if ($list) {
                        $flush();
                    }
                    $para[] = $l;
                }
            }
            $flush();
        }

        return $out;
    }
}

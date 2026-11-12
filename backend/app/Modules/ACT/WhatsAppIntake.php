<?php

namespace App\Modules\ACT;

use App\Core\Support\Providers;
use App\Core\Tenancy\DbContext;
use App\Modules\NOT\WhatsAppCloud;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reporte de actos y condiciones por WhatsApp (docs/modulos/M-ACT.md, SCR-ACT-03): conversación guiada con la API oficial
 * de WhatsApp Business. La persona escanea el QR del punto, pulsa «Reportar por WhatsApp» y envía el mensaje ya escrito
 * con el código del punto; el sistema pregunta el tipo, la descripción y una foto (opcional), si quiere poner su nombre,
 * y responde con el código de seguimiento.
 *
 *  - El código del punto es el mismo del QR (empresa + token HMAC): sin él no se registra nada.
 *  - RN-ACT-03: si el reporte es anónimo no se guarda nombre ni teléfono; del número solo queda su huella (para evitar abuso).
 *  - El estado de la conversación vive en la caché por la huella del número y vence a los 30 minutos sin respuesta.
 */
final class WhatsAppIntake
{
    private const TTL_MINUTES = 30;

    private const TYPES = ['1' => 'unsafe_act', '2' => 'unsafe_condition', '3' => 'near_miss', '4' => 'suggestion'];

    public function __construct(private readonly WhatsAppCloud $wa) {}

    /** Mensaje ya escrito del enlace «Reportar por WhatsApp» del punto. */
    public static function startText(string $companyId, string $token): string
    {
        return 'Hola, quiero hacer un reporte de seguridad. Código del punto: '.str_replace('-', '', $companyId).'-'.strtoupper($token);
    }

    /** Enlace wa.me con el mensaje ya escrito, si WhatsApp está en vivo y tiene número. */
    public static function link(string $companyId, string $token): ?string
    {
        $p = Providers::get('whatsapp');
        $phone = preg_replace('/\D/', '', (string) ($p['settings']['phone'] ?? ''));
        if (! $p['live'] || $phone === '') {
            return null;
        }

        return 'https://wa.me/'.$phone.'?text='.rawurlencode(self::startText($companyId, $token));
    }

    public static function phoneHash(string $phone): string
    {
        return hash_hmac('sha256', 'wa|'.preg_replace('/\D/', '', $phone), (string) config('app.key'));
    }

    /** Atiende un mensaje entrante. $msg es el objeto «message» del webhook de Meta. */
    public function handle(array $msg): void
    {
        $from = (string) ($msg['from'] ?? '');
        if ($from === '') {
            return;
        }
        $key = 'wa:intake:'.self::phoneHash($from);
        $s = Cache::get($key);
        $text = trim((string) ($msg['text']['body'] ?? $msg['interactive']['button_reply']['id'] ?? $msg['interactive']['list_reply']['id'] ?? ''));

        // Inicio: el mensaje trae el código del punto (aunque haya una conversación a medias, empieza de nuevo).
        if (preg_match('/([a-f0-9]{32})-([A-F0-9]{32})/i', $text, $m)) {
            $company = substr($m[1], 0, 8).'-'.substr($m[1], 8, 4).'-'.substr($m[1], 12, 4).'-'.substr($m[1], 16, 4).'-'.substr($m[1], 20);
            $point = $this->point(strtolower($company), strtoupper($m[2]));
            if (! $point) {
                $this->reply($from, 'Este código de punto no es válido o el punto ya no está activo. Avisa al área de Seguridad y Salud en el Trabajo.');

                return;
            }
            Cache::put($key, ['step' => 'type', 'company' => $point->company_id, 'point' => $point->id, 'token' => strtoupper($m[2]),
                'anon_ok' => (bool) $point->allow_anonymous], now()->addMinutes(self::TTL_MINUTES));
            $this->reply($from, "Reporte para {$point->company_name}".($point->name ? " · {$point->name}" : '').".\n\n¿Qué quieres reportar? Responde con el número:\n"
                ."1. Acto inseguro\n2. Condición insegura\n3. Casi accidente\n4. Sugerencia");

            return;
        }
        if (! $s) {
            $this->reply($from, 'Para reportar, escanea el código QR del punto de reporte y pulsa «Reportar por WhatsApp». Así sabremos de qué lugar se trata.');

            return;
        }
        if (in_array(Str::lower($text), ['cancelar', 'salir'], true)) {
            Cache::forget($key);
            $this->reply($from, 'Listo, cancelamos el reporte. Puedes empezar de nuevo cuando quieras desde el QR.');

            return;
        }

        switch ($s['step']) {
            case 'type':
                if (! isset(self::TYPES[$text])) {
                    $this->reply($from, 'Responde con un número del 1 al 4: 1 acto inseguro, 2 condición insegura, 3 casi accidente o 4 sugerencia.');

                    return;
                }
                $s = ['step' => 'description', 'type' => self::TYPES[$text]] + $s;
                $this->reply($from, 'Cuéntanos qué viste y dónde (al menos 10 letras).');
                break;
            case 'description':
                if (mb_strlen($text) < 10) {
                    $this->reply($from, 'Cuéntanos un poco más, por favor (al menos 10 letras).');

                    return;
                }
                $s = ['step' => 'photo', 'description' => mb_substr($text, 0, 2000)] + $s;
                $this->reply($from, 'Si puedes, envía una foto. Si no tienes, escribe NO.');
                break;
            case 'photo':
                if (($msg['type'] ?? '') === 'image' && ! empty($msg['image']['id'])) {
                    $s['media_id'] = (string) $msg['image']['id'];
                } elseif (Str::lower($text) !== 'no') {
                    $this->reply($from, 'Envía una foto o escribe NO para continuar sin foto.');

                    return;
                }
                if ($s['anon_ok']) {
                    $s['step'] = 'anonymous';
                    $this->reply($from, "¿Quieres poner tu nombre en el reporte?\n1. Sí\n2. No, reportar sin mi nombre");
                } else {
                    $s['step'] = 'name';
                    $this->reply($from, 'Escribe tu nombre (en este punto los reportes llevan nombre).');
                }
                break;
            case 'anonymous':
                if ($text === '2') {
                    Cache::forget($key);
                    $this->finish($from, $s + ['anonymous' => true]);

                    return;
                }
                if ($text !== '1') {
                    $this->reply($from, 'Responde 1 para poner tu nombre o 2 para reportar sin tu nombre.');

                    return;
                }
                $s['step'] = 'name';
                $this->reply($from, 'Escribe tu nombre.');
                break;
            case 'name':
                if (mb_strlen($text) < 3) {
                    $this->reply($from, 'Escribe tu nombre, por favor.');

                    return;
                }
                Cache::forget($key);
                $this->finish($from, $s + ['anonymous' => false, 'name' => mb_substr($text, 0, 120)]);

                return;
        }
        Cache::put($key, $s, now()->addMinutes(self::TTL_MINUTES));
    }

    private function finish(string $from, array $s): void
    {
        $code = DB::transaction(function () use ($from, $s) {
            DbContext::setCompany($s['company']);
            $p = DB::selectOne('select * from report_points where id = ? and is_active and revoked_at is null and deleted_at is null', [$s['point']]);
            if (! $p) {
                return null;
            }
            $n = (int) DB::selectOne('select count(*) + 1 as n from substandard_reports where extract(year from created_at) = extract(year from now())')->n;
            do {
                $code = sprintf('REP-%s-%04d', date('Y'), $n++);
            } while (DB::selectOne('select 1 as ok from substandard_reports where code = ? and deleted_at is null', [$code]));
            $photo = ! empty($s['media_id']) ? $this->photo($s['company'], (string) $s['media_id']) : null;
            DB::insert(
                "insert into substandard_reports (company_id, code, report_type, origin, report_point_id, site_id, area_id, description, location_text, photo_file_id,
                    reported_at, is_anonymous, reporter_contact, whatsapp_conversation_id, status)
                 values (?, ?, ?, 'whatsapp', ?, ?, ?, ?, ?, ?, now(), ?, ?, ?, 'received')",
                [$s['company'], $code, $s['type'], $p->id, $p->site_id, $p->area_id, $s['description'], $p->location_description, $photo,
                    $s['anonymous'] ? 'true' : 'false', $s['anonymous'] ? null : $s['name'].' · +'.preg_replace('/\D/', '', $from), self::phoneHash($from)]
            );
            DB::update('update report_points set report_count = report_count + 1, last_used_at = now() where id = ?', [$p->id]);

            return $code;
        });
        $this->reply($from, $code
            ? "¡Gracias! Recibimos tu reporte. Tu código de seguimiento es {$code}. El área de Seguridad y Salud en el Trabajo lo revisará."
            : 'El punto de reporte ya no está activo. Avisa al área de Seguridad y Salud en el Trabajo.');
    }

    /** Descarga la foto de Meta y la guarda como archivo de la empresa (módulo ACT). */
    private function photo(string $companyId, string $mediaId): ?string
    {
        try {
            $token = (string) (Providers::get('whatsapp')['secrets']['token'] ?? '');
            $meta = Http::timeout(20)->withToken($token)->get(WhatsAppCloud::GRAPH.'/'.rawurlencode($mediaId))->json();
            $mime = (string) ($meta['mime_type'] ?? '');
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
            if (! $ext || empty($meta['url']) || (int) ($meta['file_size'] ?? 0) > 15 * 1024 * 1024) {
                return null;
            }
            $bytes = Http::timeout(30)->withToken($token)->get($meta['url'])->body();
            $id = (string) Str::uuid7();
            $disk = config('escudo.files_disk', 'local');
            $path = "company/{$companyId}/".date('Y/m')."/{$id}.{$ext}";
            Storage::disk($disk)->put($path, $bytes);
            DB::insert("insert into files (id, company_id, bucket, object_key, original_name, mime_type, size_bytes, sha256, module_code, entity_type, status, exif_stripped)
                        values (?, ?, ?, ?, ?, ?, ?, decode(?, 'hex'), 'ACT', 'substandard_reports', 'available', false)",
                [$id, $companyId, $disk, $path, "whatsapp.{$ext}", $mime, strlen($bytes), hash('sha256', $bytes)]);

            return $id;
        } catch (Throwable) {
            return null;   // sin foto, el reporte igual se registra
        }
    }

    private function point(string $company, string $token): ?object
    {
        if (! Str::isUuid($company)) {
            return null;
        }

        return DB::transaction(function () use ($company, $token) {
            DbContext::setCompany($company);
            $p = DB::selectOne("select p.*, (select coalesce(trade_name, legal_name) from companies where id = p.company_id) as company_name
                                  from report_points p where p.token_hash = decode(?, 'hex') and p.deleted_at is null", [hash('sha256', $token)]);
            if (! $p || ! hash_equals(ReportPointController::token($company, $p->id), $token) || ! $p->is_active || $p->revoked_at) {
                return null;
            }

            return $p;
        });
    }

    private function reply(string $to, string $text): void
    {
        $this->wa->sendText($to, $text);
    }
}

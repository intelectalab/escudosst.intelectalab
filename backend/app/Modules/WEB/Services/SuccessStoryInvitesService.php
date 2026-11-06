<?php

namespace App\Modules\WEB\Services;

use App\Core\Http\ApiProblem;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Http\Controllers\SuccessStoriesController;
use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Casos de éxito por invitación, sin pasar archivos por correo:
 *  1. El equipo invita al cliente (nombre, contacto y correo). Le llega un enlace personal que vence en 30 días.
 *  2. El cliente cuenta su historia en un formulario y firma la autorización (nombre, cargo, documento y casilla).
 *  3. Se guarda la evidencia (texto exacto + sha256, fecha, IP en hash) y una constancia imprimible. El caso queda
 *     en borrador para que el equipo lo revise, apruebe y publique. Al cliente le llega una copia con un enlace
 *     para retirar la autorización; al equipo, un aviso.
 *  4. Si el cliente usa ese enlace, el caso se oculta al momento y se registra el retiro.
 */
class SuccessStoryInvitesService
{
    public const VALID_DAYS = 30;

    /** Versión del texto de autorización. Texto modelo: debe validarlo un abogado (docs/10, punto 22). */
    public const TEXT_VERSION = 'v1-modelo';

    public function __construct(private AccountsService $accounts) {}

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    // ------------------------------------------------------------ Panel

    public function list(): array
    {
        return $this->db()->select(
            "select i.id, i.client_name, i.sector, i.contact_name, i.contact_email, i.status, i.expires_at, i.sent_count,
                    i.last_sent_at, i.submitted_at, i.story_id, i.created_at, (i.status = 'sent' and i.expires_at < now()) as expired
               from success_story_invites i
              where i.status <> 'cancelled'
              order by (i.status = 'sent') desc, i.created_at desc limit 200"
        );
    }

    public function invite(array $d, string $actor): string
    {
        $token = Str::random(48);
        $id = $this->db()->table('success_story_invites')->insertGetId([
            'client_name' => trim($d['client_name']),
            'sector' => $d['sector'] ?? null,
            'contact_name' => trim($d['contact_name']),
            'contact_email' => mb_strtolower(trim($d['contact_email'])),
            'message' => isset($d['message']) && trim($d['message']) !== '' ? trim($d['message']) : null,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::VALID_DAYS),
            'created_by' => $actor,
        ]);
        $this->accounts->audit($actor, 'success_story_invited', 'success_story_invites', $id, ['client' => $d['client_name']]);
        $this->sendInvite($id, $token);

        return $id;
    }

    public function resend(string $id, string $actor): void
    {
        $i = $this->invite_($id);
        if ($i->status !== 'sent') {
            throw $this->problem('Esta invitación ya fue respondida o cancelada.');
        }
        $token = Str::random(48);   // el enlace anterior deja de servir
        $this->db()->update(
            'update success_story_invites set token_hash = ?, expires_at = ?, sent_count = sent_count + 1, last_sent_at = now(), updated_at = now() where id = ?',
            [hash('sha256', $token), now()->addDays(self::VALID_DAYS), $id]
        );
        $this->accounts->audit($actor, 'success_story_invite_resent', 'success_story_invites', $id, []);
        $this->sendInvite($id, $token);
    }

    public function cancel(string $id, string $actor): void
    {
        $i = $this->invite_($id);
        if ($i->status !== 'sent') {
            throw $this->problem('Esta invitación ya fue respondida.');
        }
        $this->db()->update("update success_story_invites set status = 'cancelled', updated_at = now() where id = ?", [$id]);
        $this->accounts->audit($actor, 'success_story_invite_cancelled', 'success_story_invites', $id, []);
    }

    /** Autorizaciones de un caso (para el panel): documento enmascarado. */
    public function consents(string $storyId): array
    {
        $rows = $this->db()->select(
            'select id, invite_id, company_name, company_ruc, signer_name, signer_role, signer_doc_type, signer_doc_number, signer_email,
                    authorization_version, text_sha256, file_id, accepted_at, withdrawn_at, withdraw_reason
               from success_story_consents where story_id = ? order by accepted_at desc',
            [$storyId]
        );

        return array_map(function ($r) {
            $doc = $this->decrypt($r->signer_doc_number);
            $r->signer_doc_masked = $doc ? str_repeat('•', max(0, mb_strlen($doc) - 3)).mb_substr($doc, -3) : null;
            unset($r->signer_doc_number);

            return $r;
        }, $rows);
    }

    // ------------------------------------------------------------ Público: formulario del cliente

    public function publicInfo(string $token): array
    {
        $i = $this->validInvite($token);
        $s = $this->settings();

        return [
            'client_name' => $i->client_name,
            'sector' => $i->sector,
            'contact_name' => $i->contact_name,
            'contact_email' => $i->contact_email,
            'message' => $i->message,
            'expires_at' => $i->expires_at,
            'authorization_template' => $this->authorizationText([
                'signer_name' => '[tu nombre]', 'signer_doc_type' => 'DNI', 'signer_doc_number' => '[tu documento]',
                'signer_role' => '[tu cargo]', 'company_name' => $i->client_name, 'company_ruc' => null,
            ], $s),
        ];
    }

    /** El cliente envía su historia y firma: crea el caso en borrador con la evidencia de la autorización. */
    public function submit(string $token, array $d, ?UploadedFile $logo, string $ipHash, ?string $userAgent): array
    {
        $i = $this->validInvite($token);
        $s = $this->settings();
        $db = $this->db();

        $known = array_map(fn ($r) => trim($r->code), DB::select('select code from modules where deleted_at is null'));
        $modules = array_values(array_intersect(array_unique(array_map('trim', $d['modules'] ?? [])), $known));
        $results = array_values(array_filter(array_map(
            fn ($r) => ['value' => trim((string) ($r['value'] ?? '')), 'label' => trim((string) ($r['label'] ?? ''))],
            $d['results'] ?? []
        ), fn ($r) => $r['value'] !== '' && $r['label'] !== ''));

        $signer = [
            'signer_name' => trim($d['signer_name']), 'signer_role' => trim($d['signer_role']),
            'signer_doc_type' => $d['signer_doc_type'], 'signer_doc_number' => trim($d['signer_doc_number']),
            'company_name' => trim($d['company_name']), 'company_ruc' => ($d['company_ruc'] ?? null) ?: null,
        ];
        $text = $this->authorizationText($signer, $s);
        $withdrawToken = Str::random(48);

        $out = $db->transaction(function () use ($db, $i, $d, $s, $signer, $text, $modules, $results, $logo, $ipHash, $userAgent, $withdrawToken) {
            $storyId = $db->table('success_stories')->insertGetId([
                'slug' => $this->uniqueSlug($signer['company_name']),
                'title' => trim($d['title'] ?? '') ?: "{$signer['company_name']} ordenó su SST con EscudoSST",
                'client_name' => $signer['company_name'],
                'sector' => $d['sector'],
                'project_year' => $d['project_year'] ?? null,
                'challenge' => trim($d['challenge']),
                'solution' => trim($d['solution']),
                'results_json' => json_encode($results, JSON_UNESCAPED_UNICODE),
                'quote' => trim($d['quote'] ?? '') ?: null,
                'modules_json' => json_encode($modules),
                'is_example' => false,
            ]);

            if ($logo) {
                $logoId = $this->storeAsset('story_logo', $logo->getMimeType(), file_get_contents($logo->getRealPath()), $logo->getClientOriginalName(), $logo->getSize());
                $db->update('update success_stories set logo_file_id = ? where id = ?', [$logoId, $storyId]);
            }

            $consentId = (string) Str::uuid7();
            $acceptedAt = now();
            $html = $this->constancia($signer + ['signer_email' => $d['signer_email']], $text, $acceptedAt->toIso8601String(), $consentId, $ipHash, $s);
            $fileId = $this->storeAsset('story_consent', 'text/html', $html, "constancia-autorizacion-{$consentId}.html", strlen($html));

            $db->table('success_story_consents')->insert([
                'id' => $consentId,
                'story_id' => $storyId,
                'invite_id' => $i->id,
                'company_name' => $signer['company_name'],
                'company_ruc' => $signer['company_ruc'],
                'signer_name' => $signer['signer_name'],
                'signer_role' => $signer['signer_role'],
                'signer_doc_type' => $signer['signer_doc_type'],
                'signer_doc_number' => Crypt::encryptString($signer['signer_doc_number']),
                'signer_email' => mb_strtolower(trim($d['signer_email'])),
                'authorization_version' => self::TEXT_VERSION,
                'authorization_text' => $text,
                'text_sha256' => hash('sha256', $text),
                'file_id' => $fileId,
                'accepted_at' => $acceptedAt,
                'ip_hash' => $ipHash,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 300) : null,
                'withdraw_token_hash' => hash('sha256', $withdrawToken),
            ]);
            $db->update('update success_stories set consent_file_id = ? where id = ?', [$fileId, $storyId]);
            $db->update("update success_story_invites set status = 'submitted', submitted_at = now(), story_id = ?, updated_at = now() where id = ?", [$storyId, $i->id]);

            return ['story_id' => $storyId, 'consent_id' => $consentId];
        });

        $this->accounts->audit(null, 'success_story_submitted', 'success_stories', $out['story_id'], ['invite_id' => $i->id, 'consent_id' => $out['consent_id'], 'text_version' => self::TEXT_VERSION]);
        $this->mailSigner($d['signer_email'], $signer['signer_name'], $text, $withdrawToken);
        $this->mailTeam("Caso de éxito recibido: {$signer['company_name']}", 'El cliente envió su historia y firmó la autorización. Revísala, apruébala y publícala.', [
            ['Empresa', $signer['company_name']], ['Firmó', "{$signer['signer_name']} ({$signer['signer_role']})"],
        ], '/plataforma/sitio/casos/'.$out['story_id']);

        return $out;
    }

    // ------------------------------------------------------------ Público: retiro

    public function withdrawInfo(string $token): array
    {
        $c = $this->consentByToken($token);

        return ['company_name' => $c->company_name, 'title' => $c->title, 'accepted_at' => $c->accepted_at, 'withdrawn_at' => $c->withdrawn_at];
    }

    public function withdraw(string $token, ?string $reason): void
    {
        $c = $this->consentByToken($token);
        if ($c->withdrawn_at) {
            return;   // ya estaba retirada: no hace falta nada más
        }
        $db = $this->db();
        $db->transaction(function () use ($db, $c, $reason) {
            $db->update('update success_story_consents set withdrawn_at = now(), withdraw_reason = ? where id = ?', [$reason ? mb_substr(trim($reason), 0, 300) : null, $c->id]);
            $s = $db->selectOne('select status, consent_file_id, is_example from success_stories where id = ?', [$c->story_id]);
            if ($s && $s->consent_file_id === $c->file_id) {
                $hide = in_array($s->status, ['approved', 'published'], true) && ! $s->is_example;
                $db->update(
                    'update success_stories set consent_file_id = null'.($hide ? ", status = 'hidden', approved_by = null, approved_at = null" : '').' where id = ?',
                    [$c->story_id]
                );
            }
        });
        $this->accounts->audit(null, 'success_story_consent_withdrawn', 'success_stories', $c->story_id, ['consent_id' => $c->id, 'by' => 'client_link']);
        $this->mailWithdrawn($c);
    }

    // ------------------------------------------------------------ Apoyo

    /** Texto de la autorización con los datos del firmante. Lo que se guarda y se firma es este texto exacto. */
    public function authorizationText(array $p, array $s): string
    {
        $ruc = $p['company_ruc'] ? " (RUC {$p['company_ruc']})" : '';
        $owner = ($s['legal_name'] ?? 'GRUPO DE SOLUCIONES CREATIVAS SAC').(($s['ruc'] ?? null) ? " (RUC {$s['ruc']})" : '');
        $brand = $s['trade_name'] ?? 'EscudoSST';

        return <<<TXT
            AUTORIZACIÓN PARA PUBLICAR UN CASO DE ÉXITO

            Yo, {$p['signer_name']}, identificado(a) con {$p['signer_doc_type']} N.° {$p['signer_doc_number']}, en mi calidad de {$p['signer_role']} de {$p['company_name']}{$ruc}, declaro que tengo facultades para autorizar en nombre de la empresa y autorizo a {$owner}, titular de {$brand}, a publicar en su sitio web, redes sociales y materiales comerciales el caso de éxito que envío en este formulario: el nombre y el logo de la empresa, el reto, la solución, los resultados y cifras indicados, y la cita.

            Declaro que la información es veraz y que las cifras están respaldadas por la empresa. El caso no incluye fotos de personas ni datos personales de trabajadores.

            Esta autorización es gratuita y no tiene plazo. Puedo retirarla en cualquier momento con el enlace que recibiré por correo; al retirarla, el caso deja de mostrarse de inmediato.
            TXT;
    }

    private function constancia(array $p, string $text, string $acceptedAt, string $consentId, string $ipHash, array $s): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $hash = hash('sha256', $text);
        $body = nl2br($e($text));
        $date = $e(now()->parse($acceptedAt)->timezone('America/Lima')->format('d/m/Y H:i'));

        return <<<HTML
            <!DOCTYPE html>
            <html lang="es"><head><meta charset="utf-8"><title>Constancia de autorización · {$e($p['company_name'])}</title>
            <style>body{font-family:Arial,sans-serif;color:#1b2a3a;max-width:760px;margin:40px auto;padding:0 24px;line-height:1.55}
            h1{font-size:20px;color:#0D3B6E}table{border-collapse:collapse;width:100%;margin:18px 0}td{border:1px solid #d6dde6;padding:8px 10px;font-size:14px;vertical-align:top}
            td:first-child{background:#f3f6fa;width:34%;font-weight:bold}.box{border:1px solid #d6dde6;border-radius:8px;padding:16px 18px;background:#fbfcfe}
            .muted{color:#5b6b7c;font-size:12px}@media print{body{margin:0}}</style></head><body>
            <h1>Constancia de autorización electrónica</h1>
            <p class="muted">Emitida por {$e($s['trade_name'] ?? 'EscudoSST')} · {$e($s['legal_name'] ?? '')}</p>
            <table>
            <tr><td>Empresa</td><td>{$e($p['company_name'])}{$e($p['company_ruc'] ? ' · RUC '.$p['company_ruc'] : '')}</td></tr>
            <tr><td>Firmante</td><td>{$e($p['signer_name'])} · {$e($p['signer_role'])}</td></tr>
            <tr><td>Documento</td><td>{$e($p['signer_doc_type'])} {$e($p['signer_doc_number'])}</td></tr>
            <tr><td>Correo</td><td>{$e($p['signer_email'])}</td></tr>
            <tr><td>Fecha y hora (Lima)</td><td>{$date}</td></tr>
            <tr><td>Forma de aceptación</td><td>Formulario web con enlace personal enviado al correo del firmante; casilla de aceptación marcada.</td></tr>
            <tr><td>Versión del texto</td><td>{$e(self::TEXT_VERSION)}</td></tr>
            <tr><td>Huella del texto (SHA-256)</td><td style="font-family:monospace;font-size:12px;word-break:break-all">{$hash}</td></tr>
            <tr><td>Origen (IP, en hash)</td><td style="font-family:monospace;font-size:12px;word-break:break-all">{$e($ipHash)}</td></tr>
            <tr><td>N.° de constancia</td><td style="font-family:monospace">{$e($consentId)}</td></tr>
            </table>
            <h2 style="font-size:16px">Texto aceptado</h2>
            <div class="box">{$body}</div>
            <p class="muted">La huella SHA-256 permite comprobar que el texto no cambió: es la misma que quedó registrada en la base de datos al momento de la aceptación.</p>
            </body></html>
            HTML;
    }

    private function storeAsset(string $kind, string $mime, string $content, string $name, int $size): string
    {
        $disk = config('escudo.site_assets_disk', 'local');
        $id = (string) Str::uuid7();
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/avif' => 'avif', 'text/html' => 'html'][$mime] ?? 'bin';
        $path = ($kind === 'story_consent' ? 'private/' : 'site/')."{$kind}-{$id}.{$ext}";
        Storage::disk($disk)->put($path, $content);
        $this->db()->insert(
            'insert into site_assets (id, kind, disk, path, mime_type, size_bytes, original_name) values (?, ?, ?, ?, ?, ?, ?)',
            [$id, $kind, $disk, $path, $mime, $size, mb_substr($name, 0, 200)]
        );

        return $id;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'caso';
        $slug = $base;
        for ($n = 2; $this->db()->selectOne('select 1 from success_stories where slug = ? and deleted_at is null', [$slug]); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function validInvite(string $token): object
    {
        $i = $this->db()->selectOne('select * from success_story_invites where token_hash = ?', [hash('sha256', $token)]);
        if (! $i || $i->status === 'cancelled') {
            throw ApiProblem::notFound('Este enlace no es válido. Pide uno nuevo al equipo de EscudoSST.');
        }
        if ($i->status === 'submitted') {
            throw new ApiProblem(410, 'already_submitted', 'Ya enviaste tu historia', 'Ya recibimos tu historia con este enlace. ¡Gracias!');
        }
        if (strtotime($i->expires_at) < time()) {
            throw new ApiProblem(410, 'expired', 'El enlace venció', 'Este enlace venció. Pide uno nuevo al equipo de EscudoSST.');
        }

        return $i;
    }

    private function consentByToken(string $token): object
    {
        return $this->db()->selectOne(
            'select c.*, s.title from success_story_consents c join success_stories s on s.id = c.story_id where c.withdraw_token_hash = ?',
            [hash('sha256', $token)]
        ) ?? throw ApiProblem::notFound('Este enlace no es válido.');
    }

    private function invite_(string $id): object
    {
        return $this->db()->selectOne('select * from success_story_invites where id = ?', [$id]) ?? throw ApiProblem::notFound('La invitación no existe.');
    }

    private function settings(): array
    {
        return (array) $this->db()->selectOne('select legal_name, ruc, trade_name, sales_email, contact_email from site_settings where singleton');
    }

    private function url(string $path): string
    {
        return rtrim((string) config('escudo.frontend_url'), '/').$path;
    }

    private function sendInvite(string $id, string $token): void
    {
        $i = $this->invite_($id);
        $intro = "Queremos contar en nuestro sitio cómo {$i->client_name} ordenó su seguridad y salud en el trabajo con EscudoSST. "
            .'En el enlace puedes escribir tu historia en pocos minutos y autorizar su publicación. Nada se publica sin que el equipo lo revise.';
        try {
            Mail::to($i->contact_email)->send(new SiteRequestMail(
                $i->contact_name, "Cuéntanos tu historia con EscudoSST", $intro,
                [['Empresa', $i->client_name], ['El enlace vence', now()->parse($i->expires_at)->timezone('America/Lima')->format('d/m/Y')]],
                $i->message, 'Contar mi historia', $this->url("/casos/compartir/{$token}")
            ));
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar la invitación del caso de éxito', ['error' => $e->getMessage()]);
            throw new ApiProblem(502, 'mail_failed', 'No se pudo enviar el correo', 'La invitación quedó guardada, pero el correo no salió. Prueba «Reenviar».');
        }
    }

    private function mailSigner(string $email, string $name, string $text, string $withdrawToken): void
    {
        try {
            Mail::to($email)->send(new SiteRequestMail(
                $name, 'Recibimos tu historia y tu autorización',
                'Gracias. El equipo revisará tu caso antes de publicarlo. Abajo está la copia exacta de lo que autorizaste. '
                .'Guarda este correo: si algún día quieres que dejemos de mostrar el caso, usa el botón y se ocultará de inmediato.',
                [], $text, 'Retirar mi autorización', $this->url("/casos/retirar/{$withdrawToken}")
            ));
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar la copia de la autorización', ['error' => $e->getMessage()]);
        }
    }

    private function mailWithdrawn(object $c): void
    {
        try {
            Mail::to($c->signer_email)->send(new SiteRequestMail(
                $c->signer_name, 'Retiramos tu autorización',
                "Listo: el caso de {$c->company_name} ya no se muestra en nuestro sitio. Si fue un error, escríbenos.", []
            ));
        } catch (\Throwable $e) {
            Log::warning('No se pudo confirmar el retiro de la autorización', ['error' => $e->getMessage()]);
        }
        $this->mailTeam("Autorización retirada: {$c->company_name}", 'El cliente retiró su autorización con su enlace. El caso se ocultó del sitio automáticamente.', [
            ['Empresa', $c->company_name], ['Firmante', $c->signer_name],
        ], '/plataforma/sitio/casos/'.$c->story_id);
    }

    private function mailTeam(string $title, string $intro, array $rows, string $path): void
    {
        $s = $this->settings();
        $to = ($s['sales_email'] ?? null) ?: ($s['contact_email'] ?? null);
        if (! $to) {
            return;   // sin correo del equipo: queda el aviso en la campana del panel
        }
        try {
            Mail::to($to)->send(new SiteRequestMail('equipo', $title, $intro, $rows, null, 'Ver en el panel', $this->url($path)));
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar al equipo', ['error' => $e->getMessage()]);
        }
    }

    private function decrypt(?string $v): ?string
    {
        try {
            return $v ? Crypt::decryptString($v) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function problem(string $msg): ApiProblem
    {
        return new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => ['action' => [$msg]]]);
    }

    /** Sectores válidos (los mismos de success_stories). */
    public static function sectors(): array
    {
        return SuccessStoriesController::SECTORS;
    }
}

<?php

namespace App\Modules\CAP;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\Rules\PeopleRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Impresión y verificación pública de certificados (CAP) y fotochecks (FOT):
 *  GET /cap/certificates/{id}/print       datos para imprimir el certificado con su QR (con sesión)
 *  GET /fot/credentials/{id}/print        datos para imprimir el fotocheck con su QR (con sesión)
 *  GET /public/verify/certificate/{code}  verificación pública (sin sesión; responde igual a inexistentes y no públicos)
 *  GET /public/verify/credential/{token}  verificación pública del fotocheck en la garita (límite credential_verify_rate_limit, RN-FOT-06)
 *  GET /public/verify/company/{company}   página pública de verificación de la empresa (SCR-CAP-12/13): nombre, texto propio y si está activa
 * Cada verificación pública queda registrada (funciones public_verify_* de la base).
 */
class PrintController extends Controller
{
    public function certificate(string $id): JsonResponse
    {
        $this->can('cap.training_certificates.read');
        $c = Str::isUuid($id) ? DB::selectOne('select * from training_certificates where id = ? and deleted_at is null', [$id]) : null;
        if (! $c) {
            throw ApiProblem::notFound('El certificado no existe o es de otra empresa.');
        }
        $this->ownOnly($c->worker_id, 'El certificado no existe o es de otra empresa.');
        // RN-CAP-08: un certificado por validar no se descarga hasta que el Jefe SST lo valide.
        if ($c->status === 'pending_validation') {
            throw new ApiProblem(409, 'conflict', 'Certificado por validar', 'El certificado aún no está validado por el Jefe SST: se podrá descargar cuando lo valide.');
        }
        $t = $c->training_id ? DB::selectOne('select title, instructor_name, provider_name from trainings where id = ?', [$c->training_id]) : null;

        return response()->json(['data' => [
            'certificate_code' => $c->certificate_code, 'verification_code' => $c->verification_code, 'holder_name' => $c->holder_name,
            'holder_document_masked' => $c->holder_document_masked, 'course_title' => $c->course_title, 'hours' => $c->hours === null ? null : (float) $c->hours,
            'score' => $c->score === null ? null : (float) $c->score, 'issued_on' => $c->issued_on, 'expires_on' => $c->expires_on, 'status' => $c->status,
            'public_verification_enabled' => (bool) $c->public_verification_enabled, 'instructor' => $t?->instructor_name ?: $t?->provider_name,
            'company' => $this->company(),
            // Firma de quien validó el certificado (si guardó su firma en «Mi firma»): se pega sola en el certificado.
            'signature' => \App\Modules\FIR\SignatureImages::userDataUrl($c->validated_by),
            'signer_name' => $c->validated_by ? DB::selectOne('select full_name from users where id = ?', [$c->validated_by])?->full_name : null,
            // SCR-CAP-12: el QR lleva a la verificación desde la página de la empresa (su texto y su límite de consultas).
            'verify_url' => rtrim((string) config('escudo.frontend_url'), '/').'/verificar/certificado/'.$c->verification_code.'?empresa='.$c->company_id,
            // SCR-CAP-11: diseño de la plantilla del certificado (textos, firmantes, logo, QR, horas y nota).
            'template' => self::template($c->certificate_template_id),
        ]]);
    }

    public function credential(string $id): JsonResponse
    {
        $this->can('fot.credentials.read');
        $c = Str::isUuid($id) ? DB::selectOne('select * from credentials where id = ? and deleted_at is null', [$id]) : null;
        if (! $c) {
            throw ApiProblem::notFound('El fotocheck no existe o es de otra empresa.');
        }
        $this->ownOnly($c->worker_id, 'El fotocheck no existe o es de otra empresa.');
        $p = $c->worker_id
            ? DB::selectOne('select w.full_name, w.document_last4, w.photo_file_id, p.name as position, s.name as site from workers w
                               left join positions p on p.id = w.current_position_id left join sites s on s.id = w.current_site_id where w.id = ?', [$c->worker_id])
            : DB::selectOne("select first_name || ' ' || paternal_last_name as full_name, document_last4, photo_file_id, position_text as position, null as site
                               from contractor_workers where id = ?", [$c->contractor_worker_id]);
        $type = DB::selectOne('select name from credential_types where id = ?', [$c->credential_type_id]);
        $token = PeopleRules::credentialToken($c->company_id, $c->credential_number);
        // Al imprimir, el QR vale con este token (los fotochecks emitidos antes de este cambio quedan al día al reimprimirlos).
        DB::update("update credentials set printed_at = now(), token_hash = decode(?, 'hex'), token_last4 = ? where id = ?", [hash('sha256', $token), substr($token, -4), $id]);

        return response()->json(['data' => [
            'credential_number' => $c->credential_number, 'type' => $type?->name, 'status' => $c->status, 'valid_from' => $c->valid_from, 'expires_on' => $c->expires_on,
            'holder_name' => $p?->full_name, 'document_masked' => $p?->document_last4 ? '****'.$p->document_last4 : null, 'position' => $p?->position, 'site' => $p?->site,
            'photo_file_id' => $p?->photo_file_id, 'company' => $this->company(),
            'verify_url' => rtrim((string) config('escudo.frontend_url'), '/').'/verificar/fotocheck/'.$token,
        ]]);
    }

    public function verifyCertificate(Request $request, string $code): JsonResponse
    {
        $ip = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        $ua = mb_substr((string) $request->userAgent(), 0, 300);
        $data = json_decode(DB::selectOne("select public_verify_certificate(?, ?, decode(?, 'hex'), ?) as r", [$code, 'public_web', $ip, $ua])->r, true);
        // RN-CAP-14: la misma respuesta para un código inexistente y uno no verificable (no revela que el código existe)…
        if (in_array($data['result'] ?? null, ['invalid', 'not_public'], true)) {
            // …y la consulta del código inexistente también queda registrada (la del no verificable ya la registró la función).
            if (($data['result'] ?? null) === 'invalid') {
                DB::insert("insert into certificate_verifications (company_id, certificate_id, queried_code, channel, result, response_snapshot, ip_hash, user_agent)
                            values (null, null, ?, 'public_web', 'invalid', '{\"result\":\"invalid\"}'::jsonb, decode(?, 'hex'), ?)",
                    [mb_substr(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)), 0, 64), $ip, $ua]);
            }
            $data = ['result' => 'invalid'];
        }

        return response()->json(['data' => $data]);
    }

    /** SCR-CAP-12/13: datos de la página pública de verificación de una empresa (sin datos de personas). */
    public function verifyPage(string $company): JsonResponse
    {
        $c = DB::transaction(function () use ($company) {
            \App\Core\Tenancy\DbContext::setCompany($company);

            return DB::selectOne("select coalesce(trade_name, legal_name) as name from companies where id = ? and deleted_at is null and status = 'active'", [$company]);
        });
        if (! $c) {
            return response()->json(['data' => ['result' => 'invalid']]);
        }
        $on = filter_var(\App\Core\Support\LegalParams::forCompany($company, 'certificate_public_verification'), FILTER_VALIDATE_BOOLEAN);

        return response()->json(['data' => ['result' => 'ok', 'company' => $c->name, 'enabled' => $on,
            'text' => $on ? trim((string) \App\Core\Support\LegalParams::forCompany($company, 'certificate_verification_page_text')) : '']]);
    }

    /** Campos de diseño de la plantilla del certificado; null si el certificado no tiene plantilla (diseño estándar). */
    public static function template(?string $id): ?array
    {
        $t = $id ? DB::selectOne('select orientation, paper_size, body_template, logo_file_id, background_file_id, signatory_name, signatory_title, signature_file_id,
                                         second_signatory_name, second_signatory_title, second_signature_file_id, shows_qr, shows_hours, shows_score, layout
                                    from certificate_templates where id = ?', [$id]) : null;
        if (! $t) {
            return null;
        }
        $a = (array) $t;
        $a['layout'] = json_decode((string) $t->layout, true) ?: [];
        foreach (['shows_qr', 'shows_hours', 'shows_score'] as $k) {
            $a[$k] = (bool) $t->{$k};
        }

        return $a;
    }

    public function verifyCredential(Request $request, string $token): JsonResponse
    {
        $r = DB::selectOne("select public_verify_credential(?, ?, decode(?, 'hex'), ?) as r",
            [strtoupper($token), 'public_qr', hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), mb_substr((string) $request->userAgent(), 0, 300)]);
        $data = json_decode($r->r, true);
        // Los motivos de bloqueo (p. ej. «aptitud médica») no se muestran a cualquiera que escanee el QR.
        unset($data['blocking_reasons']);

        return response()->json(['data' => $data]);
    }

    /** Rol trabajador: solo imprime su propio certificado o fotocheck (docs/05 §4.3). */
    private function ownOnly(?string $workerId, string $msg): void
    {
        $ctx = app(CompanyContext::class);
        if ($ctx->roleCode === 'worker' && (! $workerId || DB::selectOne('select worker_id from memberships where id = ?', [$ctx->membershipId])?->worker_id !== $workerId)) {
            throw ApiProblem::notFound($msg);
        }
    }

    private function company(): array
    {
        $c = DB::selectOne('select legal_name, trade_name, ruc, logo_file_id from companies where id = ?', [app(CompanyContext::class)->companyId]);

        return ['name' => $c->trade_name ?: $c->legal_name, 'legal_name' => $c->legal_name, 'ruc' => trim($c->ruc), 'logo_file_id' => $c->logo_file_id];
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden();
        }
    }
}

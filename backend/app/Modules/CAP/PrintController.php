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
 *  GET /public/verify/credential/{token}  verificación pública del fotocheck en la garita
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
            'verify_url' => rtrim((string) config('escudo.frontend_url'), '/').'/verificar/certificado/'.$c->verification_code,
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
        $r = DB::selectOne("select public_verify_certificate(?, ?, decode(?, 'hex'), ?) as r",
            [$code, 'public_web', hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')), mb_substr((string) $request->userAgent(), 0, 300)]);

        return response()->json(['data' => json_decode($r->r, true)]);
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

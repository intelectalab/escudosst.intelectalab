<?php

namespace App\Modules\FIR;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Modules\REC\Registry;
use App\Modules\REC\Rules\WorkerRules;
use App\Modules\TRB\WorkerAppAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Firma asistida en persona (M-FIR RN-FIR-05) para el trabajo en campo: charlas (M-CH5 SCR-CH5-02, «firma asistida uno tras
 * otro») y entregas de EPP (M-EPP SCR-EPP-03). El trabajador se identifica él mismo con su DNI y PIN, o firma con su trazo en la
 * pantalla; quien asiste declara que está presente y queda como testigo. Deja la solicitud firmada y la firma con su evidencia.
 */
final class AssistedSignature
{
    public static function sign(Request $request, string $table, string $subjectId, string $workerId, string $role): string
    {
        if (! $request->boolean('assisted')) {
            self::fail('assisted', 'Confirma que el trabajador está presente y está de acuerdo con firmar.');
        }
        $w = DB::selectOne('select id, full_name, document_last4, document_number_hash, pin_hash from workers where id = ? and deleted_at is null', [$workerId]);
        if (! $w) {
            throw ApiProblem::notFound('El trabajador no existe.');
        }
        $image = $request->input('image') ?: null;
        if ($request->filled('pin')) {
            $dni = preg_replace('/\D/', '', (string) $request->input('dni'));
            if (! $dni || ! $w->document_number_hash || ! hash_equals(bin2hex(WorkerAppAccess::bytes($w->document_number_hash)), WorkerRules::documentHash('DNI', $dni))) {
                self::fail('dni', 'El DNI no corresponde al trabajador que firma.');
            }
            WorkerAppAccess::assertNotLocked($w->id);
            if (! WorkerAppAccess::checkPin($w->id, $w->pin_hash, (string) $request->input('pin'))) {
                self::fail('pin', 'El PIN no es correcto.');
            }
            $identity = 'dni_pin';
        } elseif ($image) {
            $identity = 'stroke';
        } else {
            self::fail('pin', 'El trabajador debe identificarse con su DNI y PIN o firmar con su trazo en la pantalla.');
        }

        $company = app(CompanyContext::class)->companyId;
        $uid = (string) auth()->id();
        $row = DB::selectOne("select * from \"{$table}\" where id = ?", [$subjectId]);
        $hash = SignatureService::hash($table, $row);
        $order = (int) DB::selectOne('select coalesce(max(sequence_order), 0) + 1 as n from signature_requests where subject_type = ? and subject_id = ? and deleted_at is null', [$table, $subjectId])->n;
        $reqId = DB::selectOne(
            "insert into signature_requests (company_id, subject_type, subject_id, subject_module, document_hash, signer_worker_id, role_in_document,
                sequence_order, status, requested_by, signed_at, created_by, updated_by)
             values (?, ?, ?, ?, decode(?, 'hex'), ?, ?, ?, 'signed', ?, now(), ?, ?) returning id",
            [$company, $table, $subjectId, Registry::data()['resources'][$table]['module'] ?? null, $hash, $w->id, $role, $order, $uid, $uid, $uid])->id;
        $evidence = ['request_id' => $reqId, 'method' => 'assisted_in_person', 'identity' => $identity, 'witness_user_id' => $uid, 'signed_by_user_id' => $uid,
            'signer_worker_id' => $w->id, 'signer' => $w->full_name, 'subject' => "{$table}/{$subjectId}", 'document_hash' => $hash,
            'hash_scope' => SignatureService::scope($table), 'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300), 'at' => now()->toIso8601String()];
        [$lat, $lng, $device] = SignatureService::place($request, $uid);
        $evidence += ($lat !== null ? ['geo' => [$lat, $lng]] : []) + ($device ? ['device_id' => $device] : []);
        $sigId = DB::selectOne(
            "insert into signatures (company_id, signature_request_id, subject_type, subject_id, signer_worker_id, signer_name, signer_document_masked, method,
                document_hash, evidence_hash, evidence, ip, user_agent, signed_at, geo_lat, geo_lng, device_id, created_by)
             values (?, ?, ?, ?, ?, ?, ?, 'assisted_in_person', decode(?, 'hex'), decode(?, 'hex'), ?::jsonb, ?::inet, ?, now(), ?, ?, ?, ?) returning id",
            [$company, $reqId, $table, $subjectId, $w->id, $w->full_name, $w->document_last4 ? '****'.$w->document_last4 : null, $hash,
                hash('sha256', json_encode($evidence)), json_encode($evidence, JSON_UNESCAPED_UNICODE), $request->ip(), mb_substr((string) $request->userAgent(), 0, 300),
                $lat, $lng, $device, $uid])->id;
        if ($image) {
            SignatureImages::stamp($sigId, $company, null, $image);
        }
        DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, ip, user_agent)
                    values (?, ?, ?, 'user', 'signature_signed', ?, ?, ?, ?::jsonb, ?::inet, ?)",
            [$company, $uid, app(CompanyContext::class)->roleCode, Registry::data()['resources'][$table]['module'] ?? 'FIR', $table, $subjectId,
                json_encode(['signature' => $sigId, 'method' => 'assisted_in_person', 'identity' => $identity, 'worker_id' => $w->id]), $request->ip(), mb_substr((string) $request->userAgent(), 0, 300)]);

        return $sigId;
    }

    private static function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}

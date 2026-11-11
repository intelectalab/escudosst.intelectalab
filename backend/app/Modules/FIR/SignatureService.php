<?php

namespace App\Modules\FIR;

use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de firma reutilizable por otros módulos (M-FIR).
 *
 * Huella del registro (RN-FIR-02): por defecto todas sus columnas (SignatureController::hashRow). Para los registros cuyo
 * estado cambia después de firmados sin alterar lo firmado (p. ej. una versión documental aprobada que luego queda
 * obsoleta), la huella cubre solo su contenido inmutable: así la firma sigue «Íntegra» cuando cambia el estado.
 */
class SignatureService
{
    /** Tabla => columnas de contenido inmutable que cubre la huella. */
    public const CONTENT_COLUMNS = [
        'document_versions' => ['id', 'company_id', 'document_id', 'version_number', 'version_label', 'file_id', 'content_html', 'change_summary'],
        // Informe de investigación: lo que firma el equipo (no su estado, que avanza a aprobada o cerrada).
        'investigations' => ['id', 'company_id', 'event_id', 'code', 'method', 'started_on', 'summary', 'immediate_causes', 'basic_causes', 'conclusions', 'lessons_learned'],
    ];

    /** Alcance de la huella para una tabla: 'content' (solo contenido inmutable) o 'row' (todo el registro). */
    public static function scope(string $table): string
    {
        return isset(self::CONTENT_COLUMNS[$table]) ? 'content' : 'row';
    }

    /** Huella SHA-256 (hex) del registro según el alcance indicado. */
    public static function hash(string $table, object $row, ?string $scope = null): string
    {
        $scope ??= self::scope($table);
        if ($scope !== 'content' || ! isset(self::CONTENT_COLUMNS[$table])) {
            return SignatureController::hashRow($row);
        }
        $data = array_intersect_key((array) $row, array_flip(self::CONTENT_COLUMNS[$table]));
        ksort($data);

        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Firma en el acto, del usuario autenticado, ya identificado (p. ej. confirmó su contraseña para aprobar):
     * deja la solicitud cumplida y la evidencia en «signatures». Devuelve [request_id, signature_id].
     *
     * @return array{0: string, 1: string}
     */
    public static function signNow(string $table, string $id, string $module, string $role, string $userId, string $method = 'pin'): array
    {
        $company = app(CompanyContext::class)->companyId;
        $row = DB::selectOne("select * from \"{$table}\" where id = ?", [$id]);
        $scope = self::scope($table);
        $hash = self::hash($table, $row, $scope);
        $order = (int) DB::selectOne('select coalesce(max(sequence_order), 0) + 1 as n from signature_requests where subject_type = ? and subject_id = ? and deleted_at is null', [$table, $id])->n;
        $reqId = DB::selectOne(
            "insert into signature_requests (company_id, subject_type, subject_id, subject_module, document_hash, signer_user_id, role_in_document, sequence_order,
                status, requested_by, signed_at, created_by, updated_by)
             values (?, ?, ?, ?, decode(?, 'hex'), ?, ?, ?, 'signed', ?, now(), ?, ?) returning id",
            [$company, $table, $id, $module, $hash, $userId, $role, $order, $userId, $userId, $userId]
        )->id;
        $name = DB::selectOne('select full_name from users where id = ?', [$userId])->full_name;
        $req = request();
        $evidence = ['request_id' => $reqId, 'method' => $method, 'signed_by_user_id' => $userId, 'signer' => $name, 'subject' => "{$table}/{$id}",
            'document_hash' => $hash, 'hash_scope' => $scope, 'role' => $role, 'ip' => $req->ip(), 'user_agent' => mb_substr((string) $req->userAgent(), 0, 300),
            'at' => now()->toIso8601String()];
        $sigId = DB::selectOne(
            "insert into signatures (company_id, signature_request_id, subject_type, subject_id, signer_user_id, signer_name, method, document_hash, evidence_hash,
                evidence, ip, user_agent, signed_at, created_by)
             values (?, ?, ?, ?, ?, ?, ?, decode(?, 'hex'), decode(?, 'hex'), ?::jsonb, ?::inet, ?, now(), ?) returning id",
            [$company, $reqId, $table, $id, $userId, $name, $method, $hash, hash('sha256', json_encode($evidence)), json_encode($evidence, JSON_UNESCAPED_UNICODE),
                $req->ip(), mb_substr((string) $req->userAgent(), 0, 300), $userId]
        )->id;
        SignatureImages::stamp($sigId, $company, $userId, null);

        return [$reqId, $sigId];
    }
}

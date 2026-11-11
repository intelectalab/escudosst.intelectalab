<?php

namespace App\Modules\FIR;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\Registry;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seguimiento y verificación de firmas (M-FIR SCR-FIR-03 y SCR-FIR-04).
 *  GET  /fir/pending                     solicitudes de firma pendientes de la empresa (documento, firmante, recordatorios)
 *  POST /fir/requests/{id}/remind        reenviar el recordatorio al firmante (aviso M-NOT); suma reminder_count; como máximo
 *                                        uno cada «signature_reminder_hours» horas por solicitud
 *  GET  /fir/verify?code=XXXX-XXXX-XXXX  verificar una firma por su código de verificación (o el id de la firma)
 *  GET  /fir/verify/{table}/{id}         verificar todas las firmas de un registro
 *
 * Verificar = volver a calcular la huella SHA-256 del registro (misma representación canónica con que se firmó, RN-FIR-02)
 * y compararla con la guardada en la firma: «Íntegro» si coincide, «Alterado desde la firma» si no. Se muestran el firmante,
 * la fecha, el método y la evidencia técnica (IP abreviada, navegador). El código de verificación son los 12 primeros
 * caracteres de la huella de la evidencia.
 */
class SignatureToolsController extends Controller
{
    public function pending(): JsonResponse
    {
        $this->can('fir.signature_requests.read');
        $rows = DB::select(
            "select r.id, r.subject_type, r.subject_id, r.subject_module, r.role_in_document, r.status, r.expires_at, r.reminder_count, r.created_at,
                    r.requested_by, coalesce(u.full_name, w.full_name) as signer_name, q.full_name as requested_by_name,
                    (select max(n.created_at) from notifications n where n.subject_id = r.id and n.event_code = 'fir.signature_reminder' and n.deleted_at is null) as last_reminder_at
               from signature_requests r left join users u on u.id = r.signer_user_id left join workers w on w.id = r.signer_worker_id
               left join users q on q.id = r.requested_by
              where r.status = 'pending' and r.deleted_at is null and (r.expires_at is null or r.expires_at > now())
              order by r.created_at limit 100"
        );
        $canRemind = in_array('fir.signature_requests.create', Permissions::effective(), true);

        return response()->json(['data' => array_map(fn ($r) => [
            ...(array) $r,
            'subject_label' => $this->subjectLabel($r->subject_type, $r->subject_id),
            'subject_tab' => Registry::data()['resources'][$r->subject_type]['tab'] ?? $r->subject_type,
            'can_remind' => $canRemind || (string) $r->requested_by === (string) auth()->id(),
        ], $rows)]);
    }

    public function remind(string $id, AccountsService $accounts): JsonResponse
    {
        $r = Str::isUuid($id) ? DB::selectOne('select * from signature_requests where id = ? and deleted_at is null', [$id]) : null;
        if (! $r) {
            throw ApiProblem::notFound('La solicitud de firma no existe o es de otra empresa.');
        }
        if (! in_array('fir.signature_requests.create', Permissions::effective(), true) && (string) $r->requested_by !== (string) auth()->id()) {
            throw ApiProblem::forbidden('Solo quien pidió la firma o el área SST reenvía el recordatorio.');
        }
        if ($r->status !== 'pending' || ($r->expires_at && strtotime($r->expires_at) < time())) {
            throw new ApiProblem(409, 'conflict', 'Firma cerrada', 'La solicitud ya no está pendiente (firmada, rechazada o vencida): no se envía recordatorio.');
        }
        $hours = max(1, (int) LegalParams::number('signature_reminder_hours', 24));
        $last = DB::selectOne("select max(created_at) as at from notifications where subject_id = ? and event_code = 'fir.signature_reminder' and deleted_at is null", [$r->id])->at;
        if ($last && strtotime($last) > time() - $hours * 3600) {
            throw new ApiProblem(429, 'too_many_requests', 'Recordatorio reciente',
                'Ya se envió un recordatorio el '.date('d/m/Y H:i', strtotime($last))." (como máximo uno cada {$hours} horas).");
        }
        $user = $r->signer_user_id ?: DB::selectOne(
            "select coalesce(w.user_id, (select user_id from memberships where worker_id = w.id and status = 'active' and deleted_at is null limit 1)) as u
               from workers w where w.id = ?", [$r->signer_worker_id])?->u;
        $label = $this->subjectLabel($r->subject_type, $r->subject_id);
        $tab = Registry::data()['resources'][$r->subject_type]['tab'] ?? $r->subject_type;
        $module = strtolower((string) ($r->subject_module ?: (Registry::data()['resources'][$r->subject_type]['module'] ?? 'fir')));
        $count = (int) $r->reminder_count + 1;
        DB::insert(
            "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, recipient_worker_id, title, body, link, priority, dedupe_key)
             values (?, 'fir.signature_reminder', 'signature_requests', ?, ?, ?, 'Recordatorio: tienes una firma pendiente', ?, ?, 'high', ?)",
            [app(CompanyContext::class)->companyId, $r->id, $user, $r->signer_worker_id, mb_substr("Falta tu firma en {$tab}: {$label}.", 0, 500),
                "/m/{$module}?t={$r->subject_type}&open={$r->subject_id}", "fir_rem:{$r->id}:{$count}"]
        );
        DB::update('update signature_requests set reminder_count = ?, updated_by = ? where id = ?', [$count, (string) auth()->id(), $r->id]);
        $accounts->audit((string) auth()->id(), 'signature_reminded', $r->subject_type, $r->subject_id, ['request' => $r->id, 'reminder' => $count]);

        return response()->json(['data' => ['reminder_count' => $count, 'notified' => (bool) ($user || $r->signer_worker_id)]]);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $this->can('fir.signatures.read');
        $code = strtolower(preg_replace('/[^0-9a-fA-F-]/', '', (string) $request->query('code')));
        if (Str::isUuid($code)) {
            $sig = DB::selectOne('select * from signatures where id = ?', [$code]);
        } else {
            $hex = str_replace('-', '', $code);
            if (strlen($hex) < 12) {
                $this->fail('code', 'Escribe el código de verificación de 12 caracteres (p. ej. A1B2-C3D4-E5F6).');
            }
            $sig = DB::selectOne("select * from signatures where encode(evidence_hash, 'hex') like ? order by signed_at desc limit 1", [substr($hex, 0, 64).'%']);
        }
        if (! $sig) {
            throw ApiProblem::notFound('No hay una firma con ese código en esta empresa.');
        }

        return response()->json(['data' => $this->check($sig)]);
    }

    public function verifySubject(string $table, string $id): JsonResponse
    {
        $this->can('fir.signatures.read');
        $this->subject($table, $id);

        return response()->json(['data' => array_map(fn ($s) => $this->check($s),
            DB::select('select * from signatures where subject_type = ? and subject_id = ? order by signed_at', [$table, $id]))]);
    }

    /** Compara la huella guardada en la firma con la del registro tal como está hoy. */
    private function check(object $sig): array
    {
        $row = $this->subject($sig->subject_type, $sig->subject_id, missingOk: true);
        $signed = bin2hex(self::bytes($sig->document_hash));
        $evidence = json_decode((string) $sig->evidence, true) ?: [];
        // Las firmas antiguas cubren todo el registro; las nuevas dicen su alcance (contenido inmutable o registro completo).
        $current = $row ? SignatureService::hash($sig->subject_type, $row, $evidence['hash_scope'] ?? 'row') : null;
        $role = $sig->signature_request_id ? DB::selectOne('select role_in_document from signature_requests where id = ?', [$sig->signature_request_id])?->role_in_document : null;
        $code = strtoupper(implode('-', str_split(substr(bin2hex(self::bytes($sig->evidence_hash)), 0, 12), 4)));

        return [
            'signature_id' => $sig->id,
            'code' => $code,
            'result' => $current === null ? 'missing' : (hash_equals($signed, $current) ? 'intact' : 'altered'),
            'signer_name' => $sig->signer_name,
            'signer_document_masked' => $sig->signer_document_masked,
            'role_in_document' => $role,
            'method' => $sig->method,
            'signed_at' => $sig->signed_at,
            'subject_type' => $sig->subject_type,
            'subject_id' => $sig->subject_id,
            'subject_tab' => Registry::data()['resources'][$sig->subject_type]['tab'] ?? $sig->subject_type,
            'subject_module' => Registry::data()['resources'][$sig->subject_type]['module'] ?? null,
            'subject_label' => $row ? $this->subjectLabel($sig->subject_type, $sig->subject_id) : null,
            'signed_hash' => $signed,
            'current_hash' => $current,
            'evidence' => [
                'ip' => self::maskIp($sig->ip),
                'user_agent' => $sig->user_agent ? mb_substr($sig->user_agent, 0, 160) : null,
                'method' => $evidence['method'] ?? $sig->method,
                'at' => $evidence['at'] ?? $sig->signed_at,
                'geo' => $sig->geo_lat !== null ? round((float) $sig->geo_lat, 3).', '.round((float) $sig->geo_lng, 3) : null,
            ],
        ];
    }

    /** Fila del registro firmado (exige poder verlo). */
    private function subject(string $table, string $id, bool $missingOk = false): ?object
    {
        $res = Registry::data()['resources'][$table] ?? null;
        if (! $res || ! Str::isUuid($id)) {
            throw ApiProblem::notFound('Ese registro no admite firmas.');
        }
        if (! in_array(strtolower($res['module']).".{$table}.read", Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede ver el registro firmado.');
        }
        $row = DB::selectOne("select * from \"{$table}\" where id = ?".(Registry::softDelete($table) ? ' and deleted_at is null' : ''), [$id]);
        if (! $row && ! $missingOk) {
            throw ApiProblem::notFound('El registro no existe o es de otra empresa.');
        }

        return $row;
    }

    private function subjectLabel(string $table, string $id): string
    {
        $col = Registry::labelColumn($table);
        if (! $col || ! preg_match('/^[a-z_0-9]+$/', $col) || ! Registry::has($table)) {
            return 'Registro';
        }
        $v = DB::selectOne("select \"{$col}\"::text as l from \"{$table}\" where id = ?", [$id])?->l;

        return $v !== null && $v !== '' ? mb_substr($v, 0, 120) : 'Registro';
    }

    private static function maskIp(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }
        if (str_contains($ip, '.')) {
            $p = explode('.', $ip);

            return "{$p[0]}.{$p[1]}.xx.xx";
        }

        return implode(':', array_slice(explode(':', $ip), 0, 3)).':…';
    }

    private static function bytes(mixed $v): string
    {
        if (is_resource($v)) {
            $v = stream_get_contents($v);
        }
        $v = (string) $v;

        return str_starts_with($v, '\\x') ? (string) hex2bin(substr($v, 2)) : $v;
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto con las firmas.');
        }
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}

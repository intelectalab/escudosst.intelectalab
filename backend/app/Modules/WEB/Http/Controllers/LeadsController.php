<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Interesados (leads) del sitio (SCR-WEB-39 y 40): una ficha por correo, alimentada por todos los formularios
 * (hoy: demo, cotización y consulta; luego calculadora, autodiagnóstico, recursos, boletín…).
 *  GET   /internal/site/leads          bandeja con filtros en el cliente
 *  GET   /internal/site/leads/{id}     ficha con consentimientos y línea de tiempo (sus solicitudes)
 *  PATCH /internal/site/leads/{id}     estado, nota y motivo de descarte
 *  GET   /internal/site/leads/export   CSV con quienes aceptaron recibir novedades (para el boletín)
 */
class LeadsController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = DB::connection('platform')->select(
            "select l.id, l.email, l.full_name, l.company_name, trim(l.ruc) as ruc, l.phone, l.sector, l.source, l.last_source,
                    l.last_activity_at, l.marketing_consent, l.status, l.created_at,
                    (select count(*) from demo_requests d where lower(d.email) = l.email and d.deleted_at is null) as requests
               from marketing_leads l where l.deleted_at is null
              order by (l.status = 'new') desc, l.last_activity_at desc limit 500"
        );

        return response()->json(['data' => $rows]);
    }

    public function show(string $id): JsonResponse
    {
        $db = DB::connection('platform');
        $l = $db->selectOne(
            "select l.*, v.version_label as privacy_version from marketing_leads l
               left join legal_document_versions v on v.id = l.privacy_version_id
              where l.id = ? and l.deleted_at is null",
            [$id]
        ) ?? throw ApiProblem::notFound('El interesado no existe.');
        $row = (array) $l;
        unset($row['ip_hash']);
        $row['timeline'] = $db->select(
            "select 'request' as kind, d.request_type as detail, d.status, d.interest_plan_code, d.message, d.created_at as at
               from demo_requests d where lower(d.email) = ? and d.deleted_at is null
              order by d.created_at desc",
            [$l->email]
        );

        return response()->json(['data' => $row]);
    }

    public function update(Request $request, string $id, AccountsService $accounts): Response
    {
        $data = $request->validate([
            'status' => ['required', 'in:new,contacted,qualified,converted,discarded'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'discard_reason' => ['nullable', 'required_if:status,discarded', 'string', 'max:300'],
            'withdraw_marketing' => ['boolean'],
        ], ['discard_reason.required_if' => 'Escribe por qué se descarta.']);
        $db = DB::connection('platform');
        $l = $db->selectOne('select id, status, marketing_consent from marketing_leads where id = ? and deleted_at is null', [$id])
            ?? throw ApiProblem::notFound('El interesado no existe.');

        $row = [
            'status' => $data['status'],
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'discard_reason' => $data['status'] === 'discarded' ? trim($data['discard_reason']) : null,
            'updated_by' => $request->user()->id,
        ];
        // La persona pidió no recibir más correos: se registra el retiro del consentimiento (no se puede volver a marcar desde aquí).
        if (! empty($data['withdraw_marketing']) && $l->marketing_consent) {
            $row += ['marketing_consent' => false, 'marketing_consent_withdrawn_at' => now()];
        }
        $db->table('marketing_leads')->where('id', $id)->update($row);
        $accounts->audit($request->user()->id, 'lead_updated', 'marketing_leads', $id, ['status' => [$l->status, $data['status']], 'withdraw_marketing' => isset($row['marketing_consent'])]);

        return response()->noContent();
    }

    /** Solo quienes aceptaron recibir novedades y no lo retiraron (Ley 29733). */
    public function export(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rows = DB::connection('platform')->select(
            "select email, full_name, company_name, to_char(marketing_consent_at at time zone 'America/Lima', 'DD/MM/YYYY') as since
               from marketing_leads where marketing_consent and marketing_consent_withdrawn_at is null and deleted_at is null order by email"
        );

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Correo', 'Nombre', 'Empresa', 'Aceptó novedades desde'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [$r->email, $r->full_name, $r->company_name, $r->since], ';');
            }
            fclose($out);
        }, 'interesados-con-novedades-'.now('America/Lima')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

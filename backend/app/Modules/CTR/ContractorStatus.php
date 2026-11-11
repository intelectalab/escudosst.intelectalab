<?php

namespace App\Modules\CTR;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Estado de los requisitos de un contratista (M-CTR, SCR-CTR-05/06/07): requisitos activos que le aplican según su
 * nivel de riesgo, el último documento cargado para cada uno (de la empresa o de cada trabajador) y su semáforo.
 * Lo usan el portal del contratista y la bandeja de revisión.
 */
final class ContractorStatus
{
    public const SOON_DAYS = 30;

    /** Contratista al que está acotada la membresía activa (membership_scopes.scope_type = 'contractor'). */
    public static function scopedContractorId(): ?string
    {
        $ctx = app(CompanyContext::class);

        return DB::selectOne(
            "select s.contractor_id from membership_scopes s join contractors c on c.id = s.contractor_id and c.deleted_at is null
              where s.membership_id = ? and s.scope_type = 'contractor' and s.deleted_at is null order by s.created_at limit 1",
            [$ctx->membershipId]
        )?->contractor_id;
    }

    /** Contratista del portal o 403: solo el administrador de contratista asociado a uno entra aquí. */
    public static function requirePortalContractor(): object
    {
        $id = self::scopedContractorId();
        $c = $id ? DB::selectOne(
            'select id, ruc, legal_name, trade_name, contact_name, contact_email, contact_phone, services_description, risk_level, status
               from contractors where id = ? and deleted_at is null', [$id]) : null;

        return $c ?? throw ApiProblem::forbidden('Tu acceso no está asociado a una empresa contratista. Pide a la empresa principal que te invite al portal.');
    }

    /** @return list<object> requisitos activos que aplican al contratista (por nivel de riesgo). */
    public static function requirements(object $contractor, string $appliesTo): array
    {
        return DB::select(
            'select id, requirement_code, name, description, document_kind, is_mandatory, validity_months
               from contractor_requirements
              where deleted_at is null and is_active and applies_to = ?
                and (risk_levels is null or cardinality(risk_levels) = 0 or ?::text is null or ?::text = any(risk_levels))
              order by is_mandatory desc, name',
            [$appliesTo, $contractor->risk_level, $contractor->risk_level]
        );
    }

    /**
     * Último documento por requisito (y trabajador) del contratista.
     * @return array<string, object> clave "requirement_id|worker_id"
     */
    public static function latestDocuments(string $contractorId): array
    {
        $rows = DB::select(
            "select distinct on (d.requirement_id, d.contractor_worker_id)
                    d.id, d.requirement_id, d.contractor_worker_id, d.status, d.issued_on, d.expires_on, d.review_comments, d.reviewed_at, d.created_at,
                    d.file_id, f.original_name, f.mime_type
               from contractor_documents d left join files f on f.id = d.file_id
              where d.contractor_id = ? and d.deleted_at is null and d.requirement_id is not null
              order by d.requirement_id, d.contractor_worker_id, d.created_at desc, d.id desc",
            [$contractorId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r->requirement_id.'|'.($r->contractor_worker_id ?? '')] = $r;
        }

        return $out;
    }

    /** pending (sin documento), submitted, observed, rejected, approved, expiring, expired. */
    public static function state(?object $doc): string
    {
        if (! $doc) {
            return 'pending';
        }
        $today = date('Y-m-d');
        if ($doc->status === 'expired' || (in_array($doc->status, ['approved', 'submitted'], true) && $doc->expires_on && $doc->expires_on < $today)) {
            return 'expired';
        }
        if ($doc->status === 'approved' && $doc->expires_on && $doc->expires_on <= date('Y-m-d', strtotime('+'.self::SOON_DAYS.' days'))) {
            return 'expiring';
        }

        return $doc->status === 'pending' ? 'pending' : $doc->status;
    }

    /** @return list<array<string, mixed>> */
    public static function checklist(array $requirements, array $docs, ?string $workerId = null): array
    {
        return array_map(function ($r) use ($docs, $workerId) {
            $d = $docs[$r->id.'|'.($workerId ?? '')] ?? null;

            return [
                'id' => $r->id,
                'code' => $r->requirement_code,
                'name' => $r->name,
                'description' => $r->description,
                'is_mandatory' => (bool) $r->is_mandatory,
                'validity_months' => $r->validity_months === null ? null : (int) $r->validity_months,
                'state' => self::state($d),
                'document' => $d ? [
                    'id' => $d->id, 'status' => $d->status, 'issued_on' => $d->issued_on, 'expires_on' => $d->expires_on,
                    'review_comments' => $d->review_comments, 'created_at' => $d->created_at,
                    'file_id' => $d->file_id, 'file_name' => $d->original_name, 'mime_type' => $d->mime_type,
                ] : null,
            ];
        }, $requirements);
    }

    /** Cumple si todos los obligatorios están aprobados y vigentes. */
    public static function complete(array $checklist): bool
    {
        foreach ($checklist as $i) {
            if ($i['is_mandatory'] && ! in_array($i['state'], ['approved', 'expiring'], true)) {
                return false;
            }
        }

        return true;
    }

    /** Usuarios administradores del contratista (para avisarles). @return list<string> */
    public static function adminUsers(string $contractorId): array
    {
        return array_column(DB::select(
            "select distinct m.user_id from membership_scopes s join memberships m on m.id = s.membership_id
              where s.contractor_id = ? and s.scope_type = 'contractor' and s.deleted_at is null and m.deleted_at is null and m.status = 'active'",
            [$contractorId]
        ), 'user_id');
    }

    /** Aviso en la app (M-NOT) a los usuarios indicados. */
    public static function notify(array $users, string $event, string $subjectId, string $title, string $body, string $link, string $priority = 'normal'): void
    {
        $company = app(CompanyContext::class)->companyId;
        foreach (array_unique($users) as $u) {
            DB::insert(
                'insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$company, $event, 'CTR', $subjectId, $u, $title, mb_substr($body, 0, 500), $link, $priority, $event.':'.$subjectId.':'.time()]
            );
        }
    }
}

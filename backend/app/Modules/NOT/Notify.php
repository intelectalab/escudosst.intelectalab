<?php

namespace App\Modules\NOT;

use App\Core\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Avisos inmediatos (§10 de las fichas) en la bandeja de la app; el envío por correo o WhatsApp lo hace AlertDelivery
 * según las preferencias de cada persona. Nunca interrumpe la operación que los origina: si algo falla, no se avisa.
 */
final class Notify
{
    /** @param list<?string> $users */
    public static function users(array $users, string $event, string $module, string $subjectId, string $title, string $body, string $link, string $priority = 'normal', ?string $dedupe = null, ?string $company = null): int
    {
        $company ??= app()->bound(CompanyContext::class) ? app(CompanyContext::class)->companyId : null;
        if (! $company) {
            return 0;
        }
        $me = (string) auth()->id();
        $n = 0;
        foreach (array_unique(array_filter($users)) as $u) {
            if ($u === $me) {
                continue;   // quien hace la acción no necesita aviso de su propia acción
            }
            $n += DB::affectingStatement(
                'insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) on conflict do nothing',
                [$company, $event, $module, $subjectId, $u, $title, mb_substr($body, 0, 500), $link, $priority, ($dedupe ?? $event.':'.$subjectId.':'.date('Y-m-d H:i')).':'.$u]
            );
        }

        return $n;
    }

    /** Avisos a trabajadores (con o sin cuenta: los ven en su app y les llegan por WhatsApp si lo aceptaron). @param list<string> $workers */
    public static function workers(array $workers, string $event, string $module, string $subjectId, string $title, string $body, string $link, string $priority = 'normal', ?string $dedupe = null): int
    {
        $company = app()->bound(CompanyContext::class) ? app(CompanyContext::class)->companyId : null;
        $workers = array_values(array_unique(array_filter($workers)));
        if (! $company || $workers === []) {
            return 0;
        }
        $n = 0;
        foreach (DB::select('select id, user_id from workers where id = any(?::uuid[])', ['{'.implode(',', $workers).'}']) as $w) {
            $n += DB::affectingStatement(
                'insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, recipient_worker_id, title, body, link, priority, dedupe_key)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) on conflict do nothing',
                [$company, $event, $module, $subjectId, $w->user_id, $w->id, $title, mb_substr($body, 0, 500), $link, $priority, ($dedupe ?? $event.':'.$subjectId).':'.$w->id]
            );
        }

        return $n;
    }

    /** Usuarios activos de la empresa con alguno de esos roles (por su rol base). @return list<string> */
    public static function roles(array $roles): array
    {
        return array_column(DB::select(
            "select distinct m.user_id from memberships m join roles r on r.id = m.role_id
              where m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = any(?::text[])",
            ['{'.implode(',', $roles).'}']
        ), 'user_id');
    }

    /**
     * Usuarios con esos roles que ven la sede: sin alcance de sede (ven todo) o con la sede entre sus alcances.
     * @return list<string>
     */
    public static function siteTeam(array $roles, ?string $siteId): array
    {
        return array_column(DB::select(
            "select distinct m.user_id from memberships m join roles r on r.id = m.role_id
              where m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = any(?::text[])
                and (not exists (select 1 from membership_scopes s where s.membership_id = m.id and s.scope_type = 'site' and s.deleted_at is null)
                     or exists (select 1 from membership_scopes s where s.membership_id = m.id and s.scope_type = 'site' and s.site_id = ? and s.deleted_at is null))",
            ['{'.implode(',', $roles).'}', $siteId]
        ), 'user_id');
    }

    /** M-ACT §10: «nuevo reporte en mi sede» al supervisor y al Jefe SST que ven esa sede (desde la app, el QR o WhatsApp). */
    public static function newReport(string $companyId, string $code): void
    {
        try {
            $r = DB::selectOne('select id, site_id, description from substandard_reports where code = ? and deleted_at is null', [$code]);
            if ($r) {
                self::users(self::siteTeam(['sst_supervisor', 'sst_manager'], $r->site_id), 'act.new_report', 'ACT', $r->id, 'Nuevo reporte en tu sede',
                    "{$code}: ".mb_substr((string) $r->description, 0, 160), '/m/act?t=substandard_reports&open='.$r->id, 'normal', 'act.new_report:'.$r->id, $companyId);
            }
        } catch (\Throwable) {
            // El aviso nunca impide registrar el reporte.
        }
    }
}

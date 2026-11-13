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
    public static function users(array $users, string $event, string $module, string $subjectId, string $title, string $body, string $link, string $priority = 'normal', ?string $dedupe = null): int
    {
        $company = app()->bound(CompanyContext::class) ? app(CompanyContext::class)->companyId : null;
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

    /** Usuarios activos de la empresa con alguno de esos roles (por su rol base). @return list<string> */
    public static function roles(array $roles): array
    {
        return array_column(DB::select(
            "select distinct m.user_id from memberships m join roles r on r.id = m.role_id
              where m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = any(?::text[])",
            ['{'.implode(',', $roles).'}']
        ), 'user_id');
    }
}

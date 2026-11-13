<?php

namespace App\Modules\ACR;

use App\Core\Support\LegalParams;
use Illuminate\Support\Facades\DB;

/**
 * Acción correctiva que el sistema crea sola cuando una regla lo exige (requisito legal incumplido, medición sobre el límite,
 * condición de un cambio aprobado, evaluación ergonómica de riesgo alto). Queda con su origen (RN-ACR-01) y abierta para su responsable.
 */
final class AutoAction
{
    public static function create(string $company, string $sourceType, string $sourceId, string $title, ?string $description, ?string $responsible,
        ?string $due, string $priority = 'medium', ?string $site = null, ?string $area = null): string
    {
        $n = (int) DB::selectOne('select count(*) + 1 as n from corrective_actions where extract(year from created_at) = extract(year from now())')->n;
        do {
            $code = sprintf('AC-%s-%04d', date('Y'), $n++);
        } while (DB::selectOne('select 1 as ok from corrective_actions where code = ? and deleted_at is null', [$code]));
        $due ??= date('Y-m-d', strtotime('+'.(int) LegalParams::number('legal_gap_action_days', 30).' days'));

        $id = DB::selectOne(
            "insert into corrective_actions (company_id, code, source_type, source_id, action_type, title, description, site_id, area_id, responsible_user_id, due_on, priority, status, created_by, updated_by)
             values (?, ?, ?, ?, 'corrective', ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?) returning id",
            [$company, $code, $sourceType, $sourceId, mb_substr($title, 0, 500), $description, $site, $area, $responsible ?? (string) auth()->id(), $due, $priority,
                (string) auth()->id(), (string) auth()->id()]
        )->id;
        \App\Modules\REC\Rules\AcrRules::notifyAssigned($id);

        return $id;
    }
}

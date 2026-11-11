<?php

namespace App\Modules\MRL;

use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Novedades normativas (M-MRL §7 flujo 1-2, RN-MRL-05, criterio 2, SCR-MRL-05).
 *
 * Cuando EscudoSST cambia una norma del catálogo global (pasa a «modificada» o «derogada», o cambia su texto, vigencia o
 * sus requisitos), los requisitos de cada empresa vinculados a esa norma quedan «requiere revisión» (no cuentan como
 * cumplidos hasta revisarse) y se avisa al Jefe SST de la empresa.
 */
final class NormChanges
{
    /** Campos de la norma cuyo cambio exige revisar los requisitos vinculados. */
    public const WATCHED = ['status', 'number', 'title', 'summary', 'effective_on', 'repealed_on', 'official_url'];

    public function __construct(private AccountsService $accounts) {}

    /** Motivo del cambio entre la norma antes y después, o null si nada relevante cambió. */
    public static function reason(object|array $before, array $after): ?string
    {
        $before = (array) $before;
        $changed = array_filter(self::WATCHED, fn ($k) => array_key_exists($k, $after) && (string) ($before[$k] ?? '') !== (string) ($after[$k] ?? ''));
        if ($changed === []) {
            return null;
        }
        if (in_array('status', $changed, true) && in_array($after['status'], ['amended', 'repealed'], true)) {
            return $after['status'] === 'repealed' ? 'La norma fue derogada.' : 'La norma fue modificada.';
        }

        return 'La norma del catálogo se actualizó ('.implode(', ', array_map(fn ($k) => [
            'status' => 'estado', 'number' => 'número', 'title' => 'título', 'summary' => 'resumen', 'effective_on' => 'vigencia',
            'repealed_on' => 'derogación', 'official_url' => 'texto oficial',
        ][$k], $changed)).').';
    }

    /**
     * Marca «requiere revisión» los requisitos vinculados a la norma en todas las empresas y avisa a su Jefe SST.
     *
     * @return array{companies: int, requirements: int, notified: int}
     */
    public function flag(string $normId, string $reason): array
    {
        $norm = DB::connection('platform')->selectOne('select id, norm_type, number, title, company_id from legal_norms where id = ?', [$normId]);
        if (! $norm) {
            return ['companies' => 0, 'requirements' => 0, 'notified' => 0];
        }
        $companies = $norm->company_id ? [(object) ['id' => $norm->company_id]]
            : DB::connection('platform')->select("select id from companies where deleted_at is null and status = 'active'");
        $stamp = now()->format('YmdHis');
        $out = ['companies' => 0, 'requirements' => 0, 'notified' => 0];
        foreach ($companies as $c) {
            [$reqs, $sent] = $this->accounts->withCompany($c->id, fn (ConnectionInterface $db) => $this->forCompany($db, $c->id, $norm, $reason, $stamp));
            if ($reqs > 0) {
                $out['companies']++;
                $out['requirements'] += $reqs;
                $out['notified'] += $sent;
            }
        }

        return $out;
    }

    /** @return array{0: int, 1: int} requisitos marcados y avisos creados */
    private function forCompany(ConnectionInterface $db, string $company, object $norm, string $reason, string $stamp): array
    {
        $ids = $db->select(
            "update legal_requirements set needs_review = true, review_reason = ?, review_flagged_at = now(), reviewed_at = null, reviewed_by = null
              where legal_norm_id = ? and deleted_at is null and status = 'active' returning id",
            [mb_substr($reason, 0, 500), $norm->id]
        );
        if ($ids === []) {
            return [0, 0];
        }
        $label = trim(self::TYPE[$norm->norm_type] ?? '').' '.$norm->number;
        $team = array_column($db->select(
            "select distinct m.user_id from memberships m join roles r on r.id = m.role_id
              where m.company_id = ? and m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = 'sst_manager'", [$company]), 'user_id');
        $sent = 0;
        foreach ($team as $user) {
            $sent += $db->affectingStatement(
                "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                 values (?, 'mrl.norm_changed', 'legal_norms', ?, ?, ?, ?, '/m/mrl', 'high', ?) on conflict do nothing",
                [$company, $norm->id, $user, "Novedad normativa: {$label}",
                    mb_substr("{$reason} Revisa ".count($ids)." requisito(s) de tu matriz vinculados a «{$norm->title}».", 0, 500),
                    "mrl_norm:{$norm->id}:{$stamp}:{$user}"]
            );
        }

        return [count($ids), $sent];
    }

    private const TYPE = ['law' => 'Ley', 'supreme_decree' => 'D.S.', 'ministerial_resolution' => 'R.M.', 'technical_standard' => 'Norma técnica',
        'ordinance' => 'Ordenanza', 'directive' => 'Directiva', 'other' => ''];
}

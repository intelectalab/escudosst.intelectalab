<?php

namespace App\Modules\CTR;

use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Vencimiento de documentos de contratistas (M-CTR §10 «Documento por vencer», RN-CTR-03; M-SCT RN-SCT-05).
 * Corre todos los días: los documentos aprobados que vencieron pasan a «vencido» y su trabajador queda no habilitado;
 * los que vencen dentro de `contractor_doc_notice_days` (parámetro legal, 15 por defecto) y las pólizas SCTR del
 * contratista que vencen dentro de `sctr_policy_notice_days` se avisan en la campana al Jefe SST de la empresa
 * principal y a los administradores del contratista. Cada aviso se deja una sola vez por documento y motivo.
 */
final class ExpiryAlerts
{
    public function __construct(private AccountsService $accounts) {}

    /** @return int avisos creados */
    public function run(): int
    {
        $companies = DB::connection('platform')->select(
            "select c.id from companies c join subscriptions s on s.company_id = c.id
              where c.deleted_at is null and c.status = 'active' and s.deleted_at is null and s.status in ('trialing','active','past_due','grace')"
        );
        $total = 0;
        foreach ($companies as $c) {
            $total += $this->accounts->withCompany($c->id, fn (ConnectionInterface $db) => $this->forCompany($db, $c->id));
        }

        return $total;
    }

    public function forCompany(ConnectionInterface $db, string $company): int
    {
        if (! $db->selectOne("select 1 as ok from feature_flags where module_code = 'CTR' and mode = 'enabled' and deleted_at is null")) {
            return 0;
        }
        $docDays = $this->param($db, $company, 'contractor_doc_notice_days', 15);
        $sctrDays = $this->param($db, $company, 'sctr_policy_notice_days', 15);

        // RN-CTR-03: el aprobado que venció pasa a «vencido»; el trabajador con un documento obligatorio vencido queda no habilitado.
        $expired = $db->select(
            "update contractor_documents set status = 'expired', updated_at = now()
              where deleted_at is null and status = 'approved' and expires_on < current_date
              returning id, contractor_id, contractor_worker_id, requirement_id, expires_on"
        );
        foreach ($expired as $d) {
            if ($d->contractor_worker_id && $db->selectOne('select 1 as ok from contractor_requirements where id = ? and is_mandatory', [$d->requirement_id])) {
                $db->update('update contractor_workers set access_enabled = false, updated_at = now() where id = ?', [$d->contractor_worker_id]);
            }
        }
        $db->update(
            "update sctr_policies set status = case when ends_on < current_date then 'expired' else 'expiring' end, updated_at = now()
              where deleted_at is null and status in ('active', 'expiring') and contractor_id is not null
                and (ends_on < current_date or (status = 'active' and ends_on <= current_date + ?::int))", [$sctrDays]);

        $docs = $db->select(
            "select d.id, d.contractor_id, d.expires_on, d.status, coalesce(r.name, d.document_kind, 'Documento') as name, c.legal_name,
                    nullif(trim(coalesce(w.first_name, '') || ' ' || coalesce(w.paternal_last_name, '')), '') as worker
               from contractor_documents d
               join contractors c on c.id = d.contractor_id and c.deleted_at is null
               left join contractor_requirements r on r.id = d.requirement_id
               left join contractor_workers w on w.id = d.contractor_worker_id
              where d.deleted_at is null and d.expires_on is not null
                and ((d.status = 'approved' and d.expires_on between current_date and current_date + ?::int)
                     or (d.status = 'expired' and d.expires_on >= current_date - 7))
                and not exists (select 1 from contractor_documents n where n.contractor_id = d.contractor_id and n.requirement_id = d.requirement_id
                                 and n.contractor_worker_id is not distinct from d.contractor_worker_id and n.deleted_at is null and n.created_at > d.created_at)",
            [$docDays]
        );
        $policies = $db->select(
            "select p.id, p.contractor_id, p.ends_on as expires_on, p.status, p.policy_number, p.coverage_kind, c.legal_name
               from sctr_policies p join contractors c on c.id = p.contractor_id and c.deleted_at is null
              where p.deleted_at is null and p.contractor_id is not null and p.status in ('expiring', 'expired') and p.ends_on >= current_date - 7"
        );

        $team = array_column($db->select(
            "select m.user_id from memberships m join roles r on r.id = m.role_id
              where m.company_id = ? and m.status = 'active' and m.deleted_at is null and r.code = 'sst_manager'", [$company]), 'user_id');
        $created = 0;
        foreach ($docs as $d) {
            $what = $d->worker ? "«{$d->name}» de {$d->worker}" : "«{$d->name}»";
            $expiredNow = $d->status === 'expired';
            $created += $this->notify($db, $company, $d->contractor_id, $team, $expiredNow ? 'ctr.document_expired' : 'ctr.document_expiring', $d->id,
                $expiredNow ? 'Documento de contratista vencido' : 'Documento de contratista por vencer',
                "{$d->legal_name}: {$what} ".($expiredNow ? 'venció el ' : 'vence el ').date('d/m/Y', strtotime($d->expires_on)).'.',
                $expiredNow ? 'high' : 'normal', $d->expires_on);
        }
        foreach ($policies as $p) {
            $expiredNow = $p->status === 'expired';
            $kind = $p->coverage_kind === 'health' ? 'salud' : 'pensión';
            $created += $this->notify($db, $company, $p->contractor_id, $team, $expiredNow ? 'ctr.sctr_expired' : 'ctr.sctr_expiring', $p->id,
                $expiredNow ? 'SCTR de contratista vencido' : 'SCTR de contratista por vencer',
                "{$p->legal_name}: la póliza SCTR de {$kind} N.° {$p->policy_number} ".($expiredNow ? 'venció el ' : 'vence el ').date('d/m/Y', strtotime($p->expires_on)).'.',
                $expiredNow ? 'high' : 'normal', $p->expires_on);
        }

        return $created;
    }

    /** Aviso al Jefe SST de la principal y a los administradores del contratista, sin repetir. */
    private function notify(ConnectionInterface $db, string $company, string $contractorId, array $team, string $event, string $subject, string $title, string $body, string $priority, string $due): int
    {
        $admins = array_column($db->select(
            "select distinct m.user_id from membership_scopes s join memberships m on m.id = s.membership_id
              where s.contractor_id = ? and s.scope_type = 'contractor' and s.deleted_at is null and m.deleted_at is null and m.status = 'active'", [$contractorId]), 'user_id');
        $n = 0;
        foreach (array_unique([...$team, ...$admins]) as $user) {
            $dedupe = $event.':'.$subject.':'.$user;   // único por empresa (uq_notifications_1): incluye a quien lo recibe
            if ($db->selectOne('select 1 as ok from notifications where company_id = ? and recipient_user_id = ? and dedupe_key = ? and deleted_at is null', [$company, $user, $dedupe])) {
                continue;
            }
            $db->insert(
                'insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, due_at, dedupe_key)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$company, $event, 'CTR', $subject, $user, $title, mb_substr($body, 0, 500), '/m/ctr', $priority, $due, $dedupe]
            );
            $n++;
        }

        return $n;
    }

    private function param(ConnectionInterface $db, string $company, string $key, int $default): int
    {
        $v = $db->selectOne(
            'select value from legal_parameters where key = ? and (company_id is null or company_id = ?) and valid_from <= current_date
                and (valid_to is null or valid_to >= current_date) and deleted_at is null order by company_id nulls last, valid_from desc limit 1',
            [$key, $company]
        );

        return is_numeric($v->value ?? null) ? max(1, (int) $v->value) : $default;
    }
}

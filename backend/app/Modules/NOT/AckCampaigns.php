<?php

namespace App\Modules\NOT;

use App\Core\Support\LegalParams;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Cargos de recepción que nacen solos al publicar (§10 de las fichas):
 *  - M-DOC «Documento pendiente de cargo»: al quedar vigente una versión que exige cargo, a los trabajadores del alcance
 *    del documento; recordatorio cada ack_reminder_days y aviso al Jefe SST si la campaña va baja (ack_campaign_check_days, ack_min_pct).
 *  - M-POL RN-POL-04: la política vigente se publica como documento (categoría «Política SST») y su campaña va a todos los
 *    trabajadores activos y a los nuevos al ingresar.
 *  - M-RIS RN-RIS-04: el RISST vigente deja una entrega pendiente para cada trabajador activo (y para los nuevos);
 *    recordatorio cada ack_reminder_days; RN-RIS-06 aviso al Jefe SST y RR.HH. de quienes pasan risst_delivery_grace_days sin cargo.
 * Los trabajadores firman desde su app (pendientes). Nada de esto impide publicar: si falla, no se avisa.
 */
final class AckCampaigns
{
    /** Versión de documento que quedó vigente: si exige cargo, pide la lectura al alcance del documento. */
    public static function documentPublished(string $versionId, ?string $title = null): int
    {
        try {
            $v = DB::selectOne(
                'select v.id, v.version_number, v.version_label, coalesce(v.requires_ack, c.requires_ack, false) as needs, d.code, d.title, d.scope_type, d.site_id, d.area_id, d.position_id
                   from document_versions v join documents d on d.id = v.document_id left join document_categories c on c.id = d.category_id where v.id = ?', [$versionId]);
            if (! $v || ! $v->needs) {
                return 0;
            }
            [$where, $bind] = self::scope($v->scope_type, $v->site_id, $v->area_id, $v->position_id);
            $workers = array_column(DB::select("select w.id from workers w where {$where}", $bind), 'id');
            $label = "{$v->code} · {$v->title}".($v->version_label ? " ({$v->version_label})" : " (v{$v->version_number})");

            return self::askWorkers($versionId, $workers, $title ?? 'Documento pendiente de cargo', "Lee y firma el cargo de recepción de: {$label}.");
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * M-POL RN-POL-04: la política vigente se publica como versión vigente del documento «Política SST» (para que los
     * trabajadores firmen su cargo desde la app) y se pide el cargo a todos los trabajadores activos.
     */
    public static function policyPublished(string $policyId): int
    {
        try {
            $p = DB::selectOne('select id, company_id, version_number, title, content_html, file_id, approved_by, approved_at from sst_policies where id = ?', [$policyId]);
            $cat = DB::selectOne("select id from document_categories where code = 'POLICY' and deleted_at is null order by company_id nulls last limit 1")?->id;
            if (! $p || ! $cat) {
                return 0;
            }
            $doc = DB::selectOne("select id from documents where code = 'POL-SST' and deleted_at is null")
                ?? DB::selectOne("insert into documents (company_id, category_id, code, title, scope_type, status, is_public_to_workers) values (?, ?, 'POL-SST', 'Política de Seguridad y Salud en el Trabajo', 'company', 'active', true) returning id",
                    [$p->company_id, $cat]);
            DB::update("update document_versions set status = 'obsolete', obsoleted_at = now() where document_id = ? and status = 'current' and deleted_at is null", [$doc->id]);
            $n = (int) DB::selectOne('select coalesce(max(version_number), 0) + 1 as n from document_versions where document_id = ?', [$doc->id])->n;
            $v = DB::selectOne(
                "insert into document_versions (company_id, document_id, version_number, version_label, file_id, content_html, status, approved_by, approved_at, effective_on, requires_ack)
                 values (?, ?, ?, ?, ?, ?, 'current', ?, coalesce(?::timestamptz, now()), (now() at time zone 'America/Lima')::date, true) returning id",
                [$p->company_id, $doc->id, $n, 'Versión '.$p->version_number, $p->file_id, $p->content_html, $p->approved_by, $p->approved_at]
            );
            DB::update("update documents set current_version_id = ?, status = 'active' where id = ?", [$v->id, $doc->id]);
            DB::update('update sst_policies set document_version_id = ? where id = ?', [$v->id, $p->id]);
            [$where, $bind] = self::scope('company', null, null, null);
            $workers = array_column(DB::select("select w.id from workers w where {$where}", $bind), 'id');

            return self::askWorkers($v->id, $workers, 'Política SST pendiente de cargo', "Lee y firma el cargo de la política de seguridad y salud en el trabajo: «{$p->title}».");
        } catch (\Throwable) {
            return 0;
        }
    }

    /** M-RIS RN-RIS-04: RISST vigente → entrega pendiente y aviso para cada trabajador activo. */
    public static function risstPublished(string $versionId): int
    {
        try {
            $r = DB::selectOne('select id, company_id, title, version_number from risst_versions where id = ?', [$versionId]);
            [$where, $bind] = self::scope('company', null, null, null);
            $workers = array_column(DB::select("select w.id from workers w where {$where}", $bind), 'id');

            return self::risstFor($r, $workers);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Trabajador nuevo: cargo de la política vigente y entrega pendiente del RISST vigente (RN-POL-04, RN-RIS-04/06). */
    public static function newWorker(string $workerId): void
    {
        try {
            $pol = DB::selectOne("select p.title, p.document_version_id from sst_policies p where p.status = 'current' and p.deleted_at is null and p.document_version_id is not null limit 1");
            if ($pol) {
                self::askWorkers($pol->document_version_id, [$workerId], 'Política SST pendiente de cargo', "Lee y firma el cargo de la política de seguridad y salud en el trabajo: «{$pol->title}».");
            }
            $r = DB::selectOne("select id, company_id, title, version_number from risst_versions where status = 'current' and deleted_at is null limit 1");
            if ($r) {
                self::risstFor($r, [$workerId]);
            }
        } catch (\Throwable) {
            // El aviso nunca impide registrar al trabajador.
        }
    }

    /** Revisión periódica (SstAlerts): recordatorios y avance de las campañas. */
    public static function daily(ConnectionInterface $db, string $company, array $enabled): int
    {
        $n = 0;
        $every = max(1, (int) LegalParams::number('ack_reminder_days', 3));
        $period = (string) intdiv((int) (time() / 86400), $every);
        if (in_array('DOC', $enabled, true) || in_array('POL', $enabled, true)) {
            // Recordatorio a cada pendiente cuyo pedido tiene al menos ack_reminder_days (uno por periodo).
            $n += $db->affectingStatement(
                "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, recipient_worker_id, title, body, link, priority, dedupe_key)
                 select distinct on (n.subject_id, n.recipient_worker_id) n.company_id, 'doc.ack_reminder', 'document_versions', n.subject_id, n.recipient_user_id, n.recipient_worker_id,
                        'Recordatorio: documento pendiente de cargo', 'Aún falta tu cargo de lectura de '||d.code||' · '||d.title||'.', '/m/doc', 'normal',
                        'doc_ack_rem:'||n.subject_id||':'||n.recipient_worker_id||':p'||?
                   from notifications n join document_versions v on v.id = n.subject_id and v.status = 'current' and v.deleted_at is null join documents d on d.id = v.document_id
                  where n.company_id = ? and n.event_code = 'doc.ack_requested' and n.deleted_at is null and n.recipient_worker_id is not null
                    and n.created_at < now() - make_interval(days => ?::int)
                    and not exists (select 1 from document_acknowledgements a where a.document_version_id = n.subject_id and a.worker_id = n.recipient_worker_id)
                    and not exists (select 1 from notifications r where r.event_code = 'doc.ack_reminder' and r.subject_id = n.subject_id and r.recipient_worker_id = n.recipient_worker_id
                                     and r.created_at > now() - make_interval(days => ?::int) and r.deleted_at is null)
                 on conflict do nothing",
                [$period, $company, $every, $every]
            );
            // M-DOC §10: campaña con avance bajo (al llegar a ack_campaign_check_days con menos de ack_min_pct) → Jefe SST.
            $check = (int) LegalParams::number('ack_campaign_check_days', 7);
            $min = (int) LegalParams::number('ack_min_pct', 80);
            $low = $db->select(
                "select v.id, d.code, d.title, count(distinct n.recipient_worker_id) as expected, count(distinct a.worker_id) as received
                   from document_versions v join documents d on d.id = v.document_id
                   join notifications n on n.subject_id = v.id and n.event_code = 'doc.ack_requested' and n.deleted_at is null and n.recipient_worker_id is not null
                   left join document_acknowledgements a on a.document_version_id = v.id and a.worker_id = n.recipient_worker_id
                  where v.status = 'current' and v.deleted_at is null
                  group by v.id, d.code, d.title
                 having min(n.created_at) < now() - make_interval(days => ?::int) and count(distinct a.worker_id) * 100 < ? * count(distinct n.recipient_worker_id)",
                [$check, $min]
            );
            foreach ($low as $l) {
                $pct = (int) round($l->received * 100 / max(1, $l->expected));
                $n += Notify::users(self::team($db, ['sst_manager']), 'doc.ack_campaign_low', 'DOC', $l->id, 'Campaña de cargos con avance bajo',
                    "{$l->code} · {$l->title}: van {$l->received} de {$l->expected} cargos ({$pct} %).", '/m/doc', 'normal', 'doc.ack_campaign_low:'.$l->id, $company);
            }
        }
        if (in_array('RIS', $enabled, true)) {
            $n += $db->affectingStatement(
                "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, recipient_worker_id, title, body, link, priority, dedupe_key)
                 select r.company_id, 'ris.reminder', 'RIS', r.id, w.user_id, w.id, 'Recordatorio: RISST pendiente de recepción',
                        'Aún falta tu cargo de recepción del Reglamento Interno de SST.', '/mi', 'normal', 'ris.reminder:'||r.id||':p'||?
                   from risst_deliveries r join workers w on w.id = r.worker_id and w.status = 'active'
                  where r.company_id = ? and r.status = 'pending' and r.deleted_at is null and r.created_at < now() - make_interval(days => ?::int)
                 on conflict do nothing",
                [$period, $company, $every]
            );
            // RN-RIS-06: trabajadores que pasan risst_delivery_grace_days desde su ingreso sin cargo del RISST vigente.
            $grace = (int) LegalParams::number('risst_delivery_grace_days', 30);
            $late = $db->selectOne(
                "select v.id, count(w.id) as n from risst_versions v
                   join workers w on w.status = 'active' and w.deleted_at is null and coalesce(w.hire_on, w.created_at::date) <= current_date - ?::int
                  where v.status = 'current' and v.deleted_at is null
                    and not exists (select 1 from risst_deliveries d where d.worker_id = w.id and d.risst_version_id = v.id and d.status in ('delivered', 'signed') and d.deleted_at is null)
                  group by v.id",
                [$grace]
            );
            if ($late && (int) $late->n > 0) {
                $n += Notify::users(self::team($db, ['sst_manager', 'hr']), 'ris.grace_exceeded', 'RIS', $late->id, 'Trabajadores sin cargo del RISST',
                    "{$late->n} trabajador(es) pasaron {$grace} días desde su ingreso sin el cargo del RISST vigente.", '/m/ris?t=risst_deliveries', 'normal',
                    'ris.grace_exceeded:'.$late->id.':'.$late->n, $company);
            }
        }

        return $n;
    }

    private static function risstFor(object $r, array $workers): int
    {
        $n = 0;
        foreach ($workers as $w) {
            $n += DB::affectingStatement(
                "insert into risst_deliveries (company_id, risst_version_id, worker_id, status) select ?, ?, ?, 'pending'
                  where not exists (select 1 from risst_deliveries where risst_version_id = ? and worker_id = ? and deleted_at is null)",
                [$r->company_id, $r->id, $w, $r->id, $w]
            );
        }
        Notify::workers($workers, 'ris.pending', 'RIS', $r->id, 'RISST pendiente de recepción',
            "Firma el cargo de recepción del Reglamento Interno de SST (versión {$r->version_number}).", '/mi', 'normal', 'ris.pending:'.$r->id);

        return $n;
    }

    /** Pide el cargo a esos trabajadores (aviso «doc.ack_requested», que la app del trabajador muestra en sus pendientes). */
    private static function askWorkers(string $versionId, array $workers, string $title, string $body): int
    {
        $workers = array_values(array_unique(array_filter($workers)));
        if ($workers === []) {
            return 0;
        }
        $company = app(\App\Core\Tenancy\CompanyContext::class)->companyId;
        $n = 0;
        foreach (DB::select(
            "select w.id, coalesce(w.user_id, (select m.user_id from memberships m where m.worker_id = w.id and m.status = 'active' and m.deleted_at is null limit 1)) as user_id
               from workers w where w.id = any(?::uuid[])
                and not exists (select 1 from document_acknowledgements a where a.document_version_id = ? and a.worker_id = w.id)",
            ['{'.implode(',', $workers).'}', $versionId]) as $w) {
            $n += DB::affectingStatement(
                "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, recipient_worker_id, title, body, link, priority, dedupe_key)
                 values (?, 'doc.ack_requested', 'document_versions', ?, ?, ?, ?, ?, '/m/doc', 'high', ?) on conflict do nothing",
                [$company, $versionId, $w->user_id, $w->id, $title, mb_substr($body, 0, 500), "doc_ack:{$versionId}:{$w->id}"]
            );
        }

        return $n;
    }

    /** Trabajadores activos (o de licencia) del alcance. */
    private static function scope(?string $type, ?string $site, ?string $area, ?string $position): array
    {
        $where = "w.deleted_at is null and w.status in ('active', 'on_leave')";
        return match ($type) {
            'site' => [$where.' and w.current_site_id = ?', [$site]],
            'area' => [$where.' and w.current_area_id = ?', [$area]],
            'position' => [$where.' and w.current_position_id = ?', [$position]],
            default => [$where, []],
        };
    }

    private static function team(ConnectionInterface $db, array $roles): array
    {
        return array_column($db->select(
            "select distinct m.user_id from memberships m join roles r on r.id = m.role_id where m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = any(?::text[])",
            ['{'.implode(',', $roles).'}']
        ), 'user_id');
    }
}

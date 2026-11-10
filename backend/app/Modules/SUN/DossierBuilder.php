<?php

namespace App\Modules\SUN;

use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POST /sun/dossiers/{id}/build — arma el expediente para una inspección (M-SUN): revisa con los datos reales de la
 * empresa si cada documento o registro clave existe y está vigente en el periodo, y deja una fila por punto
 * (incluido / falta / vencido) con el registro de origen para abrirlo. El expediente queda «listo» o «listo con brechas».
 */
class DossierBuilder extends Controller
{
    public function __invoke(string $dossier): JsonResponse
    {
        $d = Str::isUuid($dossier) ? DB::selectOne('select * from inspection_dossiers where id = ? and deleted_at is null', [$dossier]) : null;
        if (! $d) {
            throw ApiProblem::notFound('El expediente no existe o es de otra empresa.');
        }
        $from = $d->period_from ?? date('Y').'-01-01';
        $to = $d->period_to ?? date('Y-m-d');
        $workers = (int) DB::selectOne("select count(*) as n from workers where deleted_at is null and status in ('active','on_leave')")->n;
        $items = [];
        $add = function (string $code, string $title, string $module, ?string $table, ?string $id, string $status, ?string $gap = null) use (&$items) {
            $items[] = compact('code', 'title', 'module', 'table', 'id', 'status', 'gap');
        };
        $one = fn (string $sql, array $b = []) => DB::selectOne($sql, $b);

        $p = $one("select id from sst_policies where status = 'current' and deleted_at is null limit 1");
        $add('01', 'Política de SST vigente', 'POL', 'sst_policies', $p?->id, $p ? 'included' : 'missing', $p ? null : 'No hay una política vigente.');

        $risstMin = (int) LegalParams::number('risst_threshold_workers', 20);
        $r = $one("select id from risst_versions where status = 'current' and deleted_at is null limit 1");
        if ($workers >= $risstMin) {
            $deliv = $r ? (int) $one('select count(*) as n from risst_deliveries where risst_version_id = ? and deleted_at is null', [$r->id])->n : 0;
            $add('02', "Reglamento Interno de SST vigente y entregado ({$deliv} de {$workers} trabajadores)", 'RIS', 'risst_versions', $r?->id,
                ! $r ? 'missing' : ($deliv < $workers ? 'missing' : 'included'), ! $r ? 'No hay RISST vigente.' : ($deliv < $workers ? 'Falta entregar el RISST a '.($workers - $deliv).' trabajador(es).' : null));
        } else {
            $add('02', "Reglamento Interno de SST (obligatorio desde {$risstMin} trabajadores)", 'RIS', null, null, 'not_applicable');
        }

        $c = $one("select id, committee_type from committees where status = 'active' and deleted_at is null order by created_at desc limit 1");
        $add('03', 'Comité o Supervisor de SST en funciones', 'COM', 'committees', $c?->id, $c ? 'included' : 'missing', $c ? null : 'No hay comité ni supervisor activo.');
        if ($c && $c->committee_type !== 'supervisor') {
            $m = (int) $one("select count(*) as n from committee_meetings where committee_id = ? and status in ('held','minutes_draft','minutes_signed') and deleted_at is null
                                and (held_at at time zone 'America/Lima')::date between ? and ?", [$c->id, $from, $to])->n;
            $add('04', "Actas de sesiones del comité en el periodo ({$m})", 'COM', 'committee_meetings', null, $m > 0 ? 'included' : 'missing', $m > 0 ? null : 'No hay sesiones realizadas en el periodo.');
        }

        $l = $one("select id from baseline_assessments where status = 'approved' and deleted_at is null order by assessment_on desc limit 1");
        $add('05', 'Diagnóstico de línea base aprobado', 'LBA', 'baseline_assessments', $l?->id, $l ? 'included' : 'missing', $l ? null : 'No hay línea base aprobada.');

        $iperc = $one("select count(*) filter (where current_version_id is not null) as ok, count(*) filter (where next_review_on < current_date) as overdue, count(*) as n
                         from iperc_matrices where status = 'active' and deleted_at is null");
        $add('06', "Matrices IPERC aprobadas ({$iperc->ok} de {$iperc->n})", 'IPC', 'iperc_matrices', null,
            $iperc->n > 0 && $iperc->ok === $iperc->n && (int) $iperc->overdue === 0 ? 'included' : ($iperc->overdue > 0 ? 'expired' : 'missing'),
            $iperc->n === 0 ? 'No hay matrices IPERC.' : ($iperc->ok < $iperc->n ? 'Hay matrices sin versión aprobada.' : ($iperc->overdue > 0 ? 'Hay matrices con revisión vencida.' : null)));

        $year = (int) substr($to, 0, 4);
        $pla = $one("select id from annual_plans where year = ? and status in ('approved','current','closed') and deleted_at is null limit 1", [$year]);
        $add('07', "Plan anual de SST {$year} aprobado", 'PLA', 'annual_plans', $pla?->id, $pla ? 'included' : 'missing', $pla ? null : 'No hay plan anual aprobado del año.');
        $pas = $one("select id, progress_pct from annual_programs where year = ? and status in ('approved','in_execution','closed') and deleted_at is null limit 1", [$year]);
        $add('08', "Programa anual de SST {$year} aprobado".($pas ? " (avance {$pas->progress_pct} %)" : ''), 'PAS', 'annual_programs', $pas?->id, $pas ? 'included' : 'missing',
            $pas ? null : 'No hay PASST aprobado del año.');
        $pca = $one("select id from training_programs where year = ? and status in ('approved','in_execution','closed') and deleted_at is null limit 1", [$year]);
        $add('09', "Programa de capacitación {$year} aprobado", 'PCA', 'training_programs', $pca?->id, $pca ? 'included' : 'missing', $pca ? null : 'No hay programa de capacitación aprobado.');

        $min = (int) LegalParams::number('min_trainings_per_year', 4);
        $held = (int) $one("select count(distinct t.id) as n from training_sessions s join trainings t on t.id = s.training_id
                              where s.status = 'held' and s.deleted_at is null and (s.starts_at at time zone 'America/Lima')::date between ? and ?", [$from, $to])->n;
        $add('10', "Capacitaciones realizadas en el periodo ({$held} de {$min} mínimas al año)", 'CAP', 'trainings', null, $held >= $min ? 'included' : 'missing',
            $held >= $min ? null : 'Faltan '.($min - $held).' capacitación(es) para el mínimo legal.');

        $emo = $one("select count(*) filter (where f.id is not null) as ok, count(*) as n from workers w
                       left join fitness_status f on f.worker_id = w.id and f.is_current and f.status in ('fit','fit_with_restrictions') and (f.valid_until is null or f.valid_until >= current_date) and f.deleted_at is null
                      where w.deleted_at is null and w.status = 'active'");
        $add('11', "Trabajadores con aptitud médica vigente ({$emo->ok} de {$emo->n})", 'EMO', 'fitness_status', null,
            (int) $emo->n === 0 ? 'not_applicable' : ($emo->ok === $emo->n ? 'included' : 'missing'), $emo->ok < $emo->n ? ($emo->n - $emo->ok).' trabajador(es) sin aptitud vigente.' : null);

        $epp = (int) $one("select count(*) as n from ppe_deliveries where deleted_at is null and (delivered_at at time zone 'America/Lima')::date between ? and ?", [$from, $to])->n;
        $add('12', "Registro de entrega de EPP en el periodo ({$epp})", 'EPP', 'ppe_deliveries', null, $epp > 0 ? 'included' : 'missing', $epp > 0 ? null : 'No hay entregas de EPP registradas.');

        $insp = (int) $one("select count(*) as n from inspections where deleted_at is null and status in ('completed','closed') and scheduled_on between ? and ?", [$from, $to])->n;
        $add('13', "Inspecciones internas realizadas ({$insp})", 'INS', 'inspections', null, $insp > 0 ? 'included' : 'missing', $insp > 0 ? null : 'No hay inspecciones realizadas en el periodo.');

        $ev = $one("select count(*) as n, count(*) filter (where notification_overdue) as late from event_notifications where deleted_at is null and status <> 'not_required'
                      and deadline_at::date between ? and ?", [$from, $to]);
        $pending = (int) $one("select count(*) as n from event_notifications where deleted_at is null and status = 'pending'")->n;
        $add('14', "Notificaciones de accidentes e incidentes ({$ev->n}, fuera de plazo: {$ev->late})", 'ACC', 'event_notifications', null,
            $pending > 0 ? 'missing' : 'included', $pending > 0 ? "{$pending} notificación(es) pendiente(s)." : null);

        $acr = (int) $one("select count(*) as n from corrective_actions where deleted_at is null and status in ('open','in_progress','overdue') and due_on < current_date")->n;
        $add('15', 'Acciones correctivas sin vencer', 'ACR', 'corrective_actions', null, $acr === 0 ? 'included' : 'expired', $acr === 0 ? null : "{$acr} acción(es) vencida(s).");

        DB::update('update dossier_items set deleted_at = now() where dossier_id = ? and deleted_at is null', [$dossier]);
        foreach ($items as $k => $it) {
            DB::insert(
                "insert into dossier_items (company_id, dossier_id, section_code, title, source_module, source_type, source_id, status, gap_description, sort_order, created_by, updated_by)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$d->company_id, $dossier, $it['code'], mb_substr($it['title'], 0, 300), $it['module'], $it['table'], $it['id'], $it['status'], $it['gap'], $k + 1,
                    (string) auth()->id(), (string) auth()->id()]
            );
        }
        $gaps = array_values(array_filter($items, fn ($i) => in_array($i['status'], ['missing', 'expired'], true)));
        DB::update('update inspection_dossiers set status = ?, gaps = ?::jsonb, built_at = now() where id = ?',
            [$gaps ? 'ready_with_gaps' : 'ready', json_encode(array_map(fn ($g) => ['code' => $g['code'], 'title' => $g['title'], 'gap' => $g['gap']], $gaps), JSON_UNESCAPED_UNICODE), $dossier]);

        return response()->json(['data' => ['items' => $items, 'gaps' => count($gaps), 'total' => count($items)]]);
    }
}

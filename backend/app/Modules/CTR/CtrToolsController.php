<?php

namespace App\Modules\CTR;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Herramientas de contratistas (M-CTR, M-SCT), para la empresa principal:
 *  GET /ctr/summary                         SCR-CTR-01: por contratista, % de documentos aprobados y trabajadores acreditados
 *  GET /ctr/accreditations/{id}/workers     SCR-CTR-06: trabajadores de la acreditación con lo que le falta a cada uno (RN-CTR-02)
 *  GET /ctr/reports.xlsx?by=contractor|worker  §11: reporte por contratista o por trabajador, con documentos vencidos
 *  GET /sct/coverage.xlsx                    M-SCT: cobertura del SCTR (salud y pensión) de trabajadores propios y de contratistas
 */
class CtrToolsController extends Controller
{
    /** Requisitos de un trabajador del contratista que no tiene aprobados y vigentes (y su aptitud). @return list<string> */
    public static function workerMissing(string $workerId): array
    {
        $missing = array_column(DB::select(
            "select r.name from contractor_requirements r
              where r.deleted_at is null and r.is_active and r.is_mandatory and r.applies_to = 'worker'
                and not exists (select 1 from contractor_documents d where d.contractor_worker_id = ? and d.requirement_id = r.id and d.deleted_at is null
                                 and d.status = 'approved' and (d.expires_on is null or d.expires_on >= current_date))
              order by r.name", [$workerId]), 'name');
        $w = DB::selectOne('select status, access_enabled from contractor_workers where id = ?', [$workerId]);
        if ($w && $w->status !== 'active') {
            $missing[] = 'Trabajador inactivo';
        }

        return $missing;
    }

    public function summary(): JsonResponse
    {
        $this->can('ctr.contractors.read');
        $rows = DB::select(
            "select c.id, c.ruc, c.legal_name, c.status,
                    (select count(*) from contractor_documents d where d.contractor_id = c.id and d.deleted_at is null) as docs,
                    (select count(*) from contractor_documents d where d.contractor_id = c.id and d.deleted_at is null and d.status = 'approved' and (d.expires_on is null or d.expires_on >= current_date)) as approved,
                    (select count(*) from contractor_documents d where d.contractor_id = c.id and d.deleted_at is null and d.status = 'approved' and d.expires_on < current_date) as expired,
                    (select count(*) from contractor_workers w where w.contractor_id = c.id and w.deleted_at is null and w.status = 'active') as workers
               from contractors c where c.deleted_at is null order by c.legal_name");
        $out = [];
        foreach ($rows as $r) {
            $accredited = 0;
            foreach (DB::select("select id from contractor_workers where contractor_id = ? and deleted_at is null and status = 'active'", [$r->id]) as $w) {
                if ($this->isAccredited($w->id) && ! self::workerMissing($w->id)) {
                    $accredited++;
                }
            }
            $out[] = ['id' => $r->id, 'ruc' => $r->ruc, 'legal_name' => $r->legal_name, 'status' => $r->status, 'documents' => (int) $r->docs,
                'approved_pct' => (int) $r->docs > 0 ? round((int) $r->approved * 100 / (int) $r->docs) : null, 'expired' => (int) $r->expired,
                'workers' => (int) $r->workers, 'accredited_workers' => $accredited];
        }

        return response()->json(['data' => $out]);
    }

    public function accreditationWorkers(string $id): JsonResponse
    {
        $this->can('ctr.contractor_accreditations.read');
        $a = Str::isUuid($id) ? DB::selectOne('select * from contractor_accreditations where id = ? and deleted_at is null', [$id]) : null;
        if (! $a) {
            throw ApiProblem::notFound('La acreditación no existe.');
        }
        $ids = trim((string) $a->contractor_worker_ids, '{}');
        $workers = DB::select(
            "select id, first_name, paternal_last_name, document_last4, position_text from contractor_workers
              where contractor_id = ? and deleted_at is null".($ids !== '' ? ' and id = any(?::uuid[])' : " and status = 'active'").' order by paternal_last_name, first_name',
            $ids !== '' ? [$a->contractor_id, $a->contractor_worker_ids] : [$a->contractor_id]);
        $sites = trim((string) $a->site_ids, '{}') !== '' ? array_column(DB::select('select name from sites where id = any(?::uuid[]) order by name', [$a->site_ids]), 'name') : [];

        return response()->json(['data' => ['sites' => $sites, 'all_workers' => $ids === '', 'workers' => array_map(fn ($w) => [
            'id' => $w->id, 'name' => trim($w->first_name.' '.$w->paternal_last_name), 'document' => '***'.$w->document_last4, 'position' => $w->position_text,
            'missing' => self::workerMissing($w->id),
        ], $workers)]]);
    }

    /** PUT /ctr/accreditations/{id}/scope {site_ids, contractor_worker_ids} — sedes y trabajadores que cubre (antes de acreditarla). */
    public function scope(Request $request, string $id): JsonResponse
    {
        $this->can('ctr.contractor_accreditations.update');
        $a = Str::isUuid($id) ? DB::selectOne('select * from contractor_accreditations where id = ? and deleted_at is null', [$id]) : null;
        if (! $a) {
            throw ApiProblem::notFound('La acreditación no existe.');
        }
        if (! in_array($a->status, ['draft', 'submitted', 'under_review', 'observed'], true)) {
            throw new ApiProblem(409, 'conflict', 'Acreditación cerrada', 'Las sedes y trabajadores se eligen antes de acreditar.');
        }
        $d = $request->validate(['site_ids' => ['present', 'array'], 'site_ids.*' => ['uuid'], 'contractor_worker_ids' => ['present', 'array'], 'contractor_worker_ids.*' => ['uuid']]);
        $sites = array_values(array_unique($d['site_ids']));
        $workers = array_values(array_unique($d['contractor_worker_ids']));
        if ($sites && count(DB::select('select id from sites where id = any(?::uuid[]) and deleted_at is null', ['{'.implode(',', $sites).'}'])) !== count($sites)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Elige sedes válidas.', ['errors' => ['site_ids' => ['Elige sedes válidas.']]]);
        }
        if ($workers && count(DB::select('select id from contractor_workers where id = any(?::uuid[]) and contractor_id = ? and deleted_at is null', ['{'.implode(',', $workers).'}', $a->contractor_id])) !== count($workers)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Elige trabajadores del contratista.', ['errors' => ['contractor_worker_ids' => ['Elige trabajadores del contratista.']]]);
        }
        DB::update('update contractor_accreditations set site_ids = ?::uuid[], contractor_worker_ids = ?::uuid[], site_id = ?, updated_at = now(), updated_by = ? where id = ?',
            ['{'.implode(',', $sites).'}', '{'.implode(',', $workers).'}', $sites[0] ?? null, (string) auth()->id(), $id]);

        return $this->accreditationWorkers($id);
    }

    public function report(Request $request): Response
    {
        $this->can('ctr.contractors.read');
        $by = $request->query('by') === 'worker' ? 'worker' : 'contractor';
        if ($by === 'contractor') {
            $out = [['Contratistas: documentos y acreditación'], ['Al '.date('d/m/Y').'.'], [],
                ['RUC', 'Razón social', 'Estado', 'Documentos', '% aprobados vigentes', 'Documentos vencidos', 'Trabajadores activos', 'Trabajadores acreditados', 'Acreditación vigente hasta']];
            foreach ($this->summary()->getData(true)['data'] as $r) {
                $until = DB::selectOne("select max(coalesce(expires_on, ends_on)) as d, bool_or(coalesce(expires_on, ends_on) is null) as open from contractor_accreditations
                                         where contractor_id = ? and status = 'accredited' and deleted_at is null", [$r['id']]);
                $out[] = [$r['ruc'], $r['legal_name'], $r['status'], $r['documents'], $r['approved_pct'] === null ? '' : $r['approved_pct'].' %', $r['expired'], $r['workers'],
                    $r['accredited_workers'], $until?->open ? 'Sin fecha de fin' : ($until?->d ?? 'Sin acreditación')];
            }
        } else {
            $out = [['Trabajadores de contratistas: requisitos'], ['Al '.date('d/m/Y').'.'], [],
                ['Contratista', 'Trabajador', 'Documento', 'Puesto', 'Estado', 'Acreditado', 'Le falta', 'Documentos vencidos']];
            foreach (DB::select("select w.id, w.first_name, w.paternal_last_name, w.document_last4, w.position_text, w.status, c.legal_name,
                                        (select string_agg(coalesce(r.name, d.document_kind), ', ') from contractor_documents d left join contractor_requirements r on r.id = d.requirement_id
                                          where d.contractor_worker_id = w.id and d.deleted_at is null and d.status = 'approved' and d.expires_on < current_date) as expired
                                   from contractor_workers w join contractors c on c.id = w.contractor_id where w.deleted_at is null order by c.legal_name, w.paternal_last_name") as $w) {
                $missing = self::workerMissing($w->id);
                $out[] = [$w->legal_name, trim($w->first_name.' '.$w->paternal_last_name), '***'.$w->document_last4, (string) $w->position_text, $w->status,
                    $this->isAccredited($w->id) && ! $missing ? 'Sí' : 'No', implode(', ', $missing), (string) $w->expired];
            }
        }

        return response(Xlsx::build($by === 'worker' ? 'Trabajadores' : 'Contratistas', $out, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"contratistas-{$by}-".date('Y-m-d').'.xlsx"',
        ]);
    }

    public function sctrCoverage(): Response
    {
        $this->can('sct.sctr_policies.read');
        $cov = "select p.coverage_kind, max(coalesce(c.covered_until, p.ends_on)) as until from sctr_coverages c join sctr_policies p on p.id = c.sctr_policy_id
                 where %s and c.status = 'covered' and c.deleted_at is null and (c.covered_from is null or c.covered_from <= current_date)
                   and (c.covered_until is null or c.covered_until >= current_date) and p.deleted_at is null and p.status in ('active', 'expiring')
                   and (p.ends_on is null or p.ends_on >= current_date) group by p.coverage_kind";
        $out = [['Cobertura del SCTR (salud y pensión)'], ['Al '.date('d/m/Y').'. Trabajadores activos propios y de contratistas.'], [],
            ['Empresa', 'Trabajador', 'Documento', 'SCTR salud hasta', 'SCTR pensión hasta', 'Cobertura completa']];
        $row = function (string $company, string $name, ?string $last4, array $kinds) use (&$out) {
            $k = collect($kinds)->keyBy('coverage_kind');
            $h = $k['health']->until ?? null;
            $p = $k['pension']->until ?? null;
            $out[] = [$company, $name, $last4 ? '***'.$last4 : '', $h ?? 'Sin cobertura', $p ?? 'Sin cobertura', $h && $p ? 'Sí' : 'No'];
        };
        $own = DB::selectOne('select coalesce(trade_name, legal_name) as n from companies limit 1')?->n ?? 'Empresa';
        foreach (DB::select("select id, full_name, document_last4 from workers where deleted_at is null and status = 'active' order by full_name") as $w) {
            $row($own, (string) $w->full_name, $w->document_last4, DB::select(sprintf($cov, 'c.worker_id = ?'), [$w->id]));
        }
        foreach (DB::select("select w.id, w.first_name, w.paternal_last_name, w.document_last4, c.legal_name from contractor_workers w join contractors c on c.id = w.contractor_id
                              where w.deleted_at is null and w.status = 'active' order by c.legal_name, w.paternal_last_name") as $w) {
            $row($w->legal_name, trim($w->first_name.' '.$w->paternal_last_name), $w->document_last4, DB::select(sprintf($cov, 'c.contractor_worker_id = ?'), [$w->id]));
        }

        return response(Xlsx::build('Cobertura SCTR', $out, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="cobertura-sctr-'.date('Y-m-d').'.xlsx"',
        ]);
    }

    /** Tiene una acreditación vigente de su contratista que lo incluye (todos los trabajadores o él en la lista). */
    private function isAccredited(string $workerId): bool
    {
        return (bool) DB::selectOne(
            "select 1 as ok from contractor_accreditations a join contractor_workers w on w.contractor_id = a.contractor_id
              where w.id = ? and a.deleted_at is null and a.status in ('accredited', 'expiring') and (a.expires_on is null or a.expires_on >= current_date)
                and a.starts_on <= current_date and (a.ends_on is null or a.ends_on >= current_date) and (a.contractor_worker_ids = '{}' or w.id = any(a.contractor_worker_ids))", [$workerId]);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve esta información de contratistas.');
        }
    }
}

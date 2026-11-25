<?php

namespace App\Modules\SCT;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\TRB\SheetReader;
use App\Modules\TRB\WorkerAppAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /sct/reconcile {sctr_policy_id, from, to, file? | documents?, apply?} — M-SCT SCR-SCT-02 y RN-SCT-04: concilia la lista de
 * asegurados del periodo (Excel/CSV o números de documento pegados) con los trabajadores activos.
 *  - Asegurados que son trabajadores activos: con «apply» se les registra la cobertura del periodo (RN-SCT-03: dentro de la
 *    vigencia de la póliza; RN-SCT-08: sin duplicar trabajador, tipo de cobertura y periodo).
 *  - Asegurados que no están activos (cesados) o que no son trabajadores de la empresa.
 *  - Trabajadores activos que requieren SCTR (RN-SCT-01: puesto o sede de riesgo) y no están en la lista ni tienen cobertura
 *    de ese tipo en el periodo.
 * Solo pólizas propias: las de contratistas se controlan con sus trabajadores (RN-SCT-06).
 */
class ReconcileController extends Controller
{
    private const MAX = 5000;

    public function __invoke(Request $request): JsonResponse
    {
        $apply = $request->boolean('apply');
        $this->can($apply ? 'sct.sctr_coverages.create' : 'sct.sctr_coverages.read');
        $d = $request->validate([
            'sctr_policy_id' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'file' => ['nullable', 'file', 'max:5120'],
            'documents' => ['nullable', 'string', 'max:200000'],
        ], ['sctr_policy_id.required' => 'Elige la póliza.', 'to.after_or_equal' => 'El fin del periodo no puede ser anterior al inicio.', 'file.max' => 'El archivo pesa más de 5 MB.']);
        $p = DB::selectOne('select id, coverage_kind, insurer_name, policy_number, starts_on, ends_on, contractor_id from sctr_policies where id = ? and deleted_at is null', [$d['sctr_policy_id']])
            ?? throw ApiProblem::notFound('La póliza no existe o es de otra empresa.');
        if ($p->contractor_id) {
            $this->fail('sctr_policy_id', 'Esa póliza es de un contratista: su cobertura se controla con los trabajadores del contratista.');
        }
        // RN-SCT-03: el periodo se recorta a la vigencia de la póliza.
        $from = max($d['from'], $p->starts_on);
        $to = min($d['to'], $p->ends_on);
        if ($from > $to) {
            $this->fail('from', 'El periodo está fuera de la vigencia de la póliza ('.date('d/m/Y', strtotime($p->starts_on)).' al '.date('d/m/Y', strtotime($p->ends_on)).').');
        }
        $docs = $this->documents($request);
        if ($docs === []) {
            $this->fail('file', 'Sube la lista de asegurados (Excel o CSV) o pega los números de documento, uno por línea.');
        }
        if (count($docs) > self::MAX) {
            $this->fail('file', 'Carga hasta '.self::MAX.' asegurados por vez.');
        }

        $matched = [];
        $inactive = [];
        $unknown = [];
        $ids = [];
        foreach ($docs as $doc) {
            $w = DB::selectOne(
                "select w.id, w.full_name, w.status, ps.name as position, s.name as site from workers w
                   left join positions ps on ps.id = w.current_position_id left join sites s on s.id = w.current_site_id
                  where w.deleted_at is null and encode(w.document_number_hash, 'hex') = any(?::text[]) limit 1",
                ['{'.implode(',', WorkerAppAccess::documentHashes($doc)).'}']);
            $masked = '****'.substr($doc, -4);
            if (! $w) {
                $unknown[] = ['document' => $masked];
            } elseif (in_array($w->status, ['active', 'on_leave'], true)) {
                if (! isset($ids[$w->id])) {
                    $ids[$w->id] = true;
                    $matched[] = ['worker_id' => $w->id, 'full_name' => $w->full_name, 'document' => $masked, 'position' => $w->position, 'site' => $w->site,
                        'already' => (bool) $this->covered($w->id, $p->coverage_kind, $from, $to)];
                }
            } else {
                $inactive[] = ['full_name' => $w->full_name, 'document' => $masked, 'status' => $w->status];
            }
        }
        $required = DB::select(
            "select w.id, w.full_name, '****' || w.document_last4 as document, ps.name as position, s.name as site
               from workers w left join positions ps on ps.id = w.current_position_id left join sites s on s.id = w.current_site_id
              where w.deleted_at is null and w.status = 'active' and (coalesce(ps.requires_sctr, false) or coalesce(s.requires_sctr, false))
              order by w.full_name");
        $uncovered = array_values(array_map(fn ($w) => ['full_name' => $w->full_name, 'document' => $w->document, 'position' => $w->position, 'site' => $w->site],
            array_filter($required, fn ($w) => ! isset($ids[$w->id]) && ! $this->covered($w->id, $p->coverage_kind, $from, $to))));

        $created = 0;
        if ($apply) {
            $uid = (string) auth()->id();
            $company = app(CompanyContext::class)->companyId;
            DB::transaction(function () use ($matched, $p, $from, $to, $uid, $company, &$created) {
                foreach ($matched as $m) {
                    if ($m['already']) {
                        continue;
                    }
                    DB::insert("insert into sctr_coverages (company_id, sctr_policy_id, worker_id, covered_from, covered_until, status, created_by, updated_by)
                                values (?, ?, ?, ?, ?, 'covered', ?, ?)", [$company, $p->id, $m['worker_id'], $from, $to, $uid, $uid]);
                    $created++;
                }
            });
        }

        return response()->json(['data' => [
            'policy' => ['coverage_kind' => $p->coverage_kind, 'insurer_name' => $p->insurer_name, 'policy_number' => $p->policy_number],
            'period' => ['from' => $from, 'to' => $to],
            'listed' => count($docs),
            'matched' => array_map(fn ($m) => array_diff_key($m, ['worker_id' => 1]), $matched),
            'inactive' => $inactive,
            'unknown' => $unknown,
            'uncovered' => $uncovered,
            'created' => $created,
            'applied' => $apply,
        ]]);
    }

    /** Cobertura del mismo tipo que se cruza con el periodo (RN-SCT-08). */
    private function covered(string $workerId, string $kind, string $from, string $to): ?object
    {
        return DB::selectOne(
            "select 1 as ok from sctr_coverages c join sctr_policies p on p.id = c.sctr_policy_id
              where c.worker_id = ? and c.deleted_at is null and c.status <> 'expired' and p.coverage_kind = ?
                and c.covered_from <= ? and c.covered_until >= ? limit 1", [$workerId, $kind, $to, $from]);
    }

    /** Números de documento de la lista: del archivo (la primera celda de cada fila con 8 a 12 caracteres y números) o pegados. */
    private function documents(Request $request): array
    {
        $norm = fn ($v) => strtoupper(preg_replace('/[\s.\-]/', '', (string) $v));
        $ok = fn (string $v) => (bool) preg_match('/^(?=.*\d)[A-Z0-9]{8,12}$/', $v);
        $cells = [];
        if ($file = $request->file('file')) {
            // docs/10 §6: extensión y tipo real del contenido (magic bytes), no solo el nombre del archivo.
            \App\Core\Support\UploadGuard::spreadsheet($file, 'file', 'Sube la lista en Excel (.xlsx) o CSV.');
            try {
                $sheet = SheetReader::read($file->getRealPath(), $file->getClientOriginalName());
            } catch (\Throwable) {
                $this->fail('file', 'No pudimos leer el archivo. Revisa que sea un Excel (.xlsx) o CSV.');
            }
            foreach ($sheet as $row) {
                foreach ($row as $c) {
                    if ($ok($v = $norm($c))) {
                        $cells[] = $v;
                        break;
                    }
                }
            }
        }
        foreach (preg_split('/[\r\n,;\t]+/', (string) $request->input('documents')) as $line) {
            if ($ok($v = $norm($line))) {
                $cells[] = $v;
            }
        }

        return array_values(array_unique($cells));
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede conciliar el SCTR.');
        }
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}

<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\NOT\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Presupuesto SST (M-PRE):
 *  GET  /pre/execution?budget_id=           SCR-PRE-01: total aprobado, ejecutado y % de ejecución por categoría, sede y actividad;
 *                                           reasignaciones del presupuesto
 *  GET  /pre/execution.xlsx?budget_id=      §11: ejecución presupuestal por categoría, sede y actividad (Excel)
 *  GET  /reports/budget_execution?budget_id= §11: los mismos datos para el informe imprimible (PDF desde el navegador)
 *  POST /pre/transfers                      RN-PRE-05: pide mover {amount} de una línea a otra de un presupuesto aprobado, con motivo
 *  POST /pre/transfers/{id}/decide          gerencia aprueba o rechaza la reasignación {decision: approved|rejected, note?}; queda auditada
 */
class BudgetToolsController extends Controller
{
    private const CATEGORY = ['training' => 'Capacitación', 'ppe' => 'EPP', 'emo' => 'EMO', 'monitoring' => 'Monitoreo', 'emergency_equipment' => 'Equipos de emergencia',
        'signage' => 'Señalización', 'consulting' => 'Consultoría', 'infrastructure' => 'Infraestructura', 'insurance' => 'Seguros', 'other' => 'Otros'];

    public function execution(Request $request): JsonResponse
    {
        $b = $this->budget($request);

        return response()->json(['data' => $this->summary($b) + [
            'transfers' => DB::select(
                "select t.id, t.amount, t.reason, t.status, t.decision_note, t.created_at, t.decided_at, fl.description as from_line, fl.category as from_category,
                        tl.description as to_line, tl.category as to_category, ru.full_name as requested_by, du.full_name as decided_by
                   from budget_transfers t join budget_lines fl on fl.id = t.from_line_id join budget_lines tl on tl.id = t.to_line_id
                   left join users ru on ru.id = t.requested_by left join users du on du.id = t.decided_by
                  where t.budget_id = ? and t.deleted_at is null order by t.created_at desc", [$b->id]),
            'can_request' => in_array('pre.budgets.update', Permissions::effective(), true),
            'can_approve' => in_array('pre.budgets.approve', Permissions::effective(), true),
        ]]);
    }

    /** §11: ejecución presupuestal para el informe imprimible (cabecera de la empresa + el mismo resumen del Excel). */
    public function report(Request $request): JsonResponse
    {
        $b = $this->budget($request);
        $c = DB::selectOne('select legal_name, trade_name, ruc from companies where id = ?', [app(CompanyContext::class)->companyId]);
        $s = $this->summary($b);
        $s['lines'] = array_map(fn ($l) => ['category' => self::CATEGORY[$l->category] ?? $l->category, 'description' => $l->description, 'site' => $l->site,
            'activity' => $l->activity, 'cost_center' => $l->cost_center, 'planned' => (float) $l->planned_amount, 'executed' => (float) $l->executed_amount,
            'pct' => (float) $l->planned_amount > 0 ? round($l->executed_amount * 100 / $l->planned_amount, 1) : null], $s['lines']);

        return response()->json(['data' => ['company' => ['name' => $c->trade_name ?: $c->legal_name, 'legal_name' => $c->legal_name, 'ruc' => trim((string) $c->ruc)],
            'generated_at' => now('America/Lima')->format('Y-m-d H:i')] + $s]);
    }

    public function executionXlsx(Request $request): Response
    {
        $b = $this->budget($request);
        $s = $this->summary($b);
        $c = DB::selectOne('select legal_name, ruc from companies where id = ?', [app(CompanyContext::class)->companyId]);
        $rows = [['EJECUCIÓN PRESUPUESTAL SST '.($b->year ?? '')], ["{$c->legal_name} · RUC ".trim((string) $c->ruc)], ["{$b->title} · {$b->currency}"],
            ['Generado: '.now('America/Lima')->format('d/m/Y H:i')], [],
            ['Total aprobado', (float) ($b->total_approved ?? $s['planned']), 'Ejecutado', $s['executed'], '% de ejecución', $s['pct']], []];
        foreach (['by_category' => 'Por categoría', 'by_site' => 'Por sede', 'by_activity' => 'Por actividad del programa'] as $k => $title) {
            $rows[] = [$title];
            $rows[] = ['Grupo', 'Planificado', 'Ejecutado', '% de ejecución', 'Saldo'];
            foreach ($s[$k] as $r) {
                $rows[] = [$r['name'], $r['planned'], $r['executed'], $r['pct'], round($r['planned'] - $r['executed'], 2)];
            }
            $rows[] = [];
        }
        $rows[] = ['Detalle por línea'];
        $rows[] = ['Categoría', 'Descripción', 'Sede', 'Actividad', 'Centro de costo', 'Planificado', 'Ejecutado', '% de ejecución'];
        foreach ($s['lines'] as $l) {
            $rows[] = [self::CATEGORY[$l->category] ?? $l->category, $l->description, $l->site ?? '', $l->activity ?? '', $l->cost_center ?? '', (float) $l->planned_amount, (float) $l->executed_amount,
                (float) $l->planned_amount > 0 ? round($l->executed_amount * 100 / $l->planned_amount, 1) : null];
        }

        return response(Xlsx::build('Ejecución', $rows, 6), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="ejecucion-presupuesto-'.($b->year ?? 'sst').'-'.date('Y-m-d').'.xlsx"',
        ]);
    }

    public function requestTransfer(Request $request): JsonResponse
    {
        if (! in_array('pre.budgets.update', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no pide reasignaciones del presupuesto.');
        }
        $d = $request->validate(['budget_id' => ['required', 'uuid'], 'from_line_id' => ['required', 'uuid', 'different:to_line_id'], 'to_line_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:1000']],
            ['from_line_id.different' => 'Elige dos líneas distintas.', 'reason.required' => 'Explica por qué se mueve el dinero.', 'amount.gt' => 'El monto debe ser mayor a cero.']);
        $b = DB::selectOne('select * from budgets where id = ? and deleted_at is null', [$d['budget_id']]) ?? throw ApiProblem::notFound('El presupuesto no existe.');
        if (! in_array($b->status, ['approved', 'in_execution'], true)) {
            throw new ApiProblem(409, 'conflict', 'Presupuesto no aprobado', 'La reasignación es para presupuestos aprobados. En borrador, cambia las líneas directamente.');
        }
        $from = DB::selectOne('select * from budget_lines where id = ? and budget_id = ? and deleted_at is null', [$d['from_line_id'], $b->id]);
        $to = DB::selectOne('select id from budget_lines where id = ? and budget_id = ? and deleted_at is null', [$d['to_line_id'], $b->id]);
        if (! $from || ! $to) {
            throw ApiProblem::notFound('Las líneas no son de este presupuesto.');
        }
        $free = (float) $from->planned_amount - (float) $from->executed_amount;
        if ((float) $d['amount'] > $free + 0.005) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'La línea de origen solo tiene '.number_format($free, 2).' sin ejecutar.', ['errors' => ['amount' => ['La línea de origen solo tiene '.number_format($free, 2).' sin ejecutar.']]]);
        }
        $uid = (string) auth()->id();
        $id = DB::selectOne(
            'insert into budget_transfers (company_id, budget_id, from_line_id, to_line_id, amount, reason, requested_by, created_by, updated_by) values (?, ?, ?, ?, ?, ?, ?, ?, ?) returning id',
            [app(CompanyContext::class)->companyId, $b->id, $from->id, $to->id, $d['amount'], trim($d['reason']), $uid, $uid, $uid])->id;
        $this->audit('budget_transfer_requested', $id, ['amount' => (float) $d['amount'], 'from_line_id' => $from->id, 'to_line_id' => $to->id, 'reason' => trim($d['reason'])]);
        Notify::users(Notify::roles(['management']), 'pre.transfer_requested', 'PRE', $b->id, 'Reasignación del presupuesto por aprobar',
            'Se pide mover '.number_format((float) $d['amount'], 2)." {$b->currency} entre líneas de «{$b->title}».", '/m/pre?t=budgets&open='.$b->id, 'normal', 'pre.transfer:'.$id);

        return response()->json(['data' => ['id' => $id, 'status' => 'pending']], 201);
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        if (! in_array('pre.budgets.approve', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('La reasignación la aprueba la gerencia.');
        }
        $d = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'note' => ['nullable', 'string', 'max:1000']]);
        $t = Str::isUuid($id) ? DB::selectOne('select * from budget_transfers where id = ? and deleted_at is null for update', [$id]) : null;
        if (! $t) {
            throw ApiProblem::notFound('La reasignación no existe.');
        }
        if ($t->status !== 'pending') {
            throw new ApiProblem(409, 'conflict', 'Ya resuelta', 'Esta reasignación ya fue resuelta.');
        }
        if ($d['decision'] === 'rejected' && ! trim((string) ($d['note'] ?? ''))) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Indica por qué se rechaza.', ['errors' => ['note' => ['Indica por qué se rechaza.']]]);
        }
        return DB::transaction(function () use ($t, $d) {
            if ($d['decision'] === 'approved') {
                $from = DB::selectOne('select planned_amount, executed_amount from budget_lines where id = ? for update', [$t->from_line_id]);
                if ((float) $from->planned_amount - (float) $from->executed_amount < (float) $t->amount - 0.005) {
                    throw new ApiProblem(409, 'conflict', 'Saldo insuficiente', 'La línea de origen ya no tiene ese saldo sin ejecutar: rechaza la reasignación y pide una nueva.');
                }
                // Mover dinero entre líneas no cambia el total del presupuesto.
                DB::update('update budget_lines set planned_amount = planned_amount - ?, updated_at = now(), updated_by = ? where id = ?', [$t->amount, (string) auth()->id(), $t->from_line_id]);
                DB::update('update budget_lines set planned_amount = planned_amount + ?, updated_at = now(), updated_by = ? where id = ?', [$t->amount, (string) auth()->id(), $t->to_line_id]);
            }
            DB::update('update budget_transfers set status = ?, decided_by = ?, decided_at = now(), decision_note = ?, updated_at = now(), updated_by = ? where id = ?',
                [$d['decision'], (string) auth()->id(), $d['note'] ?? null, (string) auth()->id(), $t->id]);
            $this->audit('budget_transfer_'.$d['decision'], $t->id, ['amount' => (float) $t->amount, 'from_line_id' => $t->from_line_id, 'to_line_id' => $t->to_line_id, 'note' => $d['note'] ?? null]);
            Notify::users(array_filter([$t->requested_by]), 'pre.transfer_'.$d['decision'], 'PRE', $t->budget_id,
                $d['decision'] === 'approved' ? 'Reasignación aprobada' : 'Reasignación rechazada',
                'La reasignación de '.number_format((float) $t->amount, 2).($d['decision'] === 'approved' ? ' fue aprobada por gerencia.' : ' fue rechazada: '.$d['note']),
                '/m/pre?t=budgets&open='.$t->budget_id, 'normal', 'pre.transfer_done:'.$t->id);

            return response()->json(['data' => ['id' => $t->id, 'status' => $d['decision']]]);
        });
    }

    private function budget(Request $request): object
    {
        if (! in_array('pre.budgets.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve el presupuesto SST.');
        }
        $id = (string) $request->query('budget_id');
        $b = Str::isUuid($id) ? DB::selectOne('select * from budgets where id = ? and deleted_at is null', [$id])
            : DB::selectOne("select * from budgets where deleted_at is null order by (status in ('approved', 'in_execution')) desc, year desc nulls last, created_at desc limit 1");

        return $b ?? throw ApiProblem::notFound('No hay presupuesto registrado.');
    }

    /** @return array{planned: float, executed: float, pct: ?float, by_category: list<array>, by_site: list<array>, by_activity: list<array>, lines: list<object>} */
    private function summary(object $b): array
    {
        $lines = DB::select(
            "select l.id, l.category, l.description, l.cost_center, l.planned_amount, l.executed_amount, s.name as site, a.title as activity
               from budget_lines l left join sites s on s.id = l.site_id left join program_activities a on a.id = l.program_activity_id
              where l.budget_id = ? and l.deleted_at is null".\App\Core\Auth\Scope::andTable('budget_lines', 'l').' order by l.category, l.description', [$b->id]);
        $group = function (callable $key) use ($lines) {
            $g = [];
            foreach ($lines as $l) {
                $k = $key($l);
                $g[$k] ??= ['name' => $k, 'planned' => 0.0, 'executed' => 0.0];
                $g[$k]['planned'] += (float) $l->planned_amount;
                $g[$k]['executed'] += (float) $l->executed_amount;
            }
            foreach ($g as &$r) {
                $r['planned'] = round($r['planned'], 2);
                $r['executed'] = round($r['executed'], 2);
                $r['pct'] = $r['planned'] > 0 ? round($r['executed'] * 100 / $r['planned'], 1) : null;
            }

            return array_values($g);
        };
        $planned = round(array_sum(array_map(fn ($l) => (float) $l->planned_amount, $lines)), 2);
        $executed = round(array_sum(array_map(fn ($l) => (float) $l->executed_amount, $lines)), 2);

        return [
            'budget' => ['id' => $b->id, 'title' => $b->title, 'year' => $b->year, 'currency' => $b->currency, 'status' => $b->status, 'total_approved' => $b->total_approved !== null ? (float) $b->total_approved : null],
            'planned' => $planned, 'executed' => $executed, 'pct' => $planned > 0 ? round($executed * 100 / $planned, 1) : null,
            'by_category' => $group(fn ($l) => self::CATEGORY[$l->category] ?? $l->category),
            'by_site' => $group(fn ($l) => $l->site ?? 'Toda la empresa'),
            'by_activity' => $group(fn ($l) => $l->activity ?? 'Sin actividad del programa'),
            'lines' => $lines,
        ];
    }

    private function audit(string $action, string $id, array $changes): void
    {
        $ctx = app(CompanyContext::class);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, ip, user_agent)
             values (?, ?, ?, 'user', ?, 'PRE', 'budget_transfers', ?, ?::jsonb, ?::inet, ?)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $action, $id, json_encode($changes, JSON_UNESCAPED_UNICODE), request()->ip(), mb_substr((string) request()->userAgent(), 0, 300) ?: null]
        );
    }
}

<?php

namespace App\Modules\REC\Rules;

use App\Modules\REC\TraceService;
use Illuminate\Support\Facades\DB;

/**
 * ACR · Acciones correctivas y preventivas (docs/modulos/M-ACR.md §8 y §9).
 * Ciclo: abierta → en curso → por verificar → verificada (eficaz / no eficaz); abierta/en curso → anulada (con motivo).
 * - RN-ACR-01: origen trazable a un registro de la empresa (o «otro» con descripción).
 * - RN-ACR-02: responsable y fecha meta obligatorios; la fecha meta no es anterior a hoy al crear.
 * - RN-ACR-03: pasa a «por verificar» solo con al menos una evidencia.
 * - RN-ACR-04: verifica otra persona, no el responsable.
 * - RN-ACR-05: «no eficaz» abre una nueva acción vinculada al mismo origen.
 * - RN-ACR-08: al verificarse, actualiza su origen (hallazgo resuelto, control IPERC verificado, inspección cerrada).
 * - RN-ACR-09: las evidencias de una acción verificada no se borran.
 */
class AcrRules extends RecordRules
{
    private const FLOW = [
        'open' => ['in_progress', 'pending_verification', 'cancelled'],
        'in_progress' => ['pending_verification', 'cancelled'],
        'overdue' => ['in_progress', 'pending_verification', 'cancelled'],
        'pending_verification' => ['verified', 'in_progress'],
        'verified' => [],
        'cancelled' => [],
    ];

    private const STATUS = ['open' => 'Abierta', 'in_progress' => 'En curso', 'overdue' => 'Vencida', 'pending_verification' => 'Por verificar',
        'verified' => 'Verificada', 'cancelled' => 'Anulada'];

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        $res['table'] === 'action_evidences' ? $this->evidence($op, $values, $current, $extra) : $this->action($op, $values, $current, $extra);
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($res['table'] !== 'corrective_actions' || ($values['status'] ?? null) !== 'verified') {
            return;
        }
        $a = DB::selectOne('select * from corrective_actions where id = ?', [$id]);
        if ($a->effectiveness === 'not_effective') {
            $this->reopen($a);

            return;
        }
        // RN-ACR-08: el origen queda atendido.
        DB::update("update findings set status = 'resolved' where corrective_action_id = ? and status in ('open', 'action_assigned')", [$id]);
        DB::update("update iperc_controls set status = 'verified' where corrective_action_id = ? and status in ('proposed', 'implemented')", [$id]);
        DB::update("update substandard_reports set status = 'closed', closed_at = now() where corrective_action_id = ? and status <> 'closed'", [$id]);
        DB::update("update baseline_gaps set status = 'closed', closed_at = now() where corrective_action_id = ? and status <> 'closed'", [$id]);
        DB::update("update meeting_agreements set status = 'fulfilled', fulfilled_at = now() where corrective_action_id = ? and status not in ('fulfilled','cancelled')", [$id]);
        // RN-INS-07: la inspección se cierra cuando todos sus hallazgos están atendidos.
        DB::update(
            "update inspections i set status = 'closed' where i.status = 'completed' and i.id in (select inspection_id from findings where corrective_action_id = ?)
                and not exists (select 1 from findings f where f.inspection_id = i.id and f.deleted_at is null and f.status in ('open', 'action_assigned'))",
            [$id]
        );
    }

    public function prepareDelete(array $res, object $current): void
    {
        if ($res['table'] === 'action_evidences') {
            $st = DB::selectOne('select status from corrective_actions where id = ?', [$current->corrective_action_id])?->status;
            if ($st === 'verified') {
                $this->conflict('La acción ya fue verificada: sus evidencias no se borran.');
            }
        }
        if ($res['table'] === 'corrective_actions' && $current->status !== 'open') {
            $this->conflict('Solo se archiva una acción recién abierta. Si ya no aplica, anúlala indicando el motivo.');
        }
    }

    private function action(string $op, array &$values, ?object $current, array &$extra): void
    {
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        if ($op === 'create') {
            if (! isset($values['code'])) {
                $n = (int) DB::selectOne("select count(*) + 1 as n from corrective_actions where extract(year from created_at) = extract(year from now())")->n;
                $values['code'] = sprintf('AC-%s-%04d', substr($today, 0, 4), $n);
                while (DB::selectOne('select 1 as ok from corrective_actions where code = ? and deleted_at is null', [$values['code']])) {
                    $values['code'] = sprintf('AC-%s-%04d', substr($today, 0, 4), ++$n);
                }
            }
            if (($values['due_on'] ?? null) && $values['due_on'] < $today) {
                $this->fail('due_on', 'La fecha meta no puede ser anterior a hoy.');
            }
            $values['status'] = 'open';
        }
        if (! $this->value($values, $current, 'responsible_user_id') && ! $this->value($values, $current, 'responsible_worker_id') && ! $this->value($values, $current, 'contractor_id')) {
            $this->fail('responsible_user_id', 'Indica el responsable (usuario, trabajador o contratista).');
        }

        // RN-ACR-01: origen trazable.
        $type = $this->value($values, $current, 'source_type');
        $source = $this->value($values, $current, 'source_id');
        if ($type === 'other') {
            if (! $this->value($values, $current, 'description')) {
                $this->fail('description', 'Si el origen es «otro», describe de dónde surge la acción.');
            }
        } elseif ($op === 'create' || array_key_exists('source_type', $values) || array_key_exists('source_id', $values)) {
            $table = TraceService::SOURCES[$type] ?? null;
            if (! $source) {
                $this->fail('source_type', 'Vincula la acción a su registro de origen, o elige origen «Otro» y descríbelo.');
            }
            if ($table && ! DB::selectOne('select 1 as ok from "'.$table.'" where id = ?', [$source])) {
                $this->fail('source_type', 'El registro de origen no existe en esta empresa.');
            }
        }

        if ($op === 'update' && array_key_exists('status', $values) && $values['status'] !== $current->status) {
            $to = $values['status'];
            $from = $current->status;
            if (! in_array($to, self::FLOW[$from] ?? [], true)) {
                $this->fail('status', 'No se puede pasar de «'.(self::STATUS[$from] ?? $from).'» a «'.(self::STATUS[$to] ?? $to).'».');
            }
            if ($to === 'cancelled' && ! $this->value($values, $current, 'cancel_reason')) {
                $this->fail('cancel_reason', 'Para anular la acción indica el motivo.');
            }
            if ($to === 'pending_verification') {
                if (! DB::selectOne('select 1 as ok from action_evidences where corrective_action_id = ? and deleted_at is null', [$current->id])) {
                    $this->fail('status', 'Agrega al menos una evidencia de lo realizado antes de enviarla a verificación.');
                }
                $extra['completed_at'] = ['now()', []];
            }
            if ($to === 'verified') {
                if (! in_array('acr.corrective_actions.verify', \App\Core\Auth\Permissions::effective(), true)) {
                    $this->fail('status', 'Tu rol no verifica acciones: lo hace el área SST.');
                }
                if ((string) auth()->id() === (string) $current->responsible_user_id) {
                    $this->fail('status', 'El responsable no puede verificar su propia acción: la verifica el área SST u otra persona.');
                }
                $eff = $this->value($values, $current, 'effectiveness');
                if (! in_array($eff, ['effective', 'not_effective'], true)) {
                    $this->fail('effectiveness', 'Indica si la acción fue eficaz o no eficaz.');
                }
                $extra['verified_by'] = ['?', [(string) auth()->id()]];
                $extra['verified_at'] = ['now()', []];
            }
            if ($to === 'in_progress' && $from === 'pending_verification') {
                $extra['completed_at'] = ['null', []];
            }
        }
        if ($op === 'update' && in_array($current->status, ['verified', 'cancelled'], true)) {
            $allowed = array_diff(array_keys($values), ['verification_notes']);
            if ($allowed !== []) {
                $this->conflict('La acción ya está '.(self::STATUS[$current->status]).': no se modifica.');
            }
        }
    }

    private function evidence(string $op, array &$values, ?object $current, array &$extra): void
    {
        $action = $this->value($values, $current, 'corrective_action_id');
        $st = DB::selectOne('select status from corrective_actions where id = ?', [$action])?->status;
        if (in_array($st, ['verified', 'cancelled'], true)) {
            $this->conflict('La acción ya está cerrada: no se agregan ni cambian evidencias.');
        }
        if ($op === 'create') {
            $extra['submitted_by'] = ['?', [(string) auth()->id()]];
            $extra['submitted_at'] = ['now()', []];
            if ($st === 'open') {
                DB::update("update corrective_actions set status = 'in_progress' where id = ?", [$action]);
            }
        }
    }

    /** RN-ACR-05: una acción no eficaz genera otra con el mismo origen. */
    private function reopen(object $a): void
    {
        $today = DB::selectOne("select (now() at time zone 'America/Lima')::date as d")->d;
        $n = (int) DB::selectOne("select count(*) + 1 as n from corrective_actions where extract(year from created_at) = extract(year from now())")->n;
        DB::insert(
            "insert into corrective_actions (company_id, code, source_type, source_id, action_type, control_hierarchy, title, description, site_id, area_id,
                responsible_user_id, responsible_worker_id, contractor_id, due_on, priority, status, reopened_count, created_by, updated_by)
             values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, (?::date + 30), ?, 'open', ?, ?, ?)",
            [$a->company_id, sprintf('AC-%s-%04d-R', substr($today, 0, 4), $n), $a->source_type, $a->source_id, $a->action_type, $a->control_hierarchy,
                mb_substr('Repetir: '.$a->title, 0, 500), "La acción {$a->code} se verificó como no eficaz. ".($a->verification_notes ?? ''), $a->site_id, $a->area_id,
                $a->responsible_user_id, $a->responsible_worker_id, $a->contractor_id, $today, $a->priority, (int) $a->reopened_count + 1,
                (string) auth()->id(), (string) auth()->id()]
        );
    }
}

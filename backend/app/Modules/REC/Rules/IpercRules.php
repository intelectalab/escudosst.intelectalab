<?php

namespace App\Modules\REC\Rules;

use App\Modules\IPC\Services\RiskEvaluator;
use Illuminate\Support\Facades\DB;

/**
 * IPC · Matriz IPERC (docs/modulos/M-IPC.md §8).
 * - Al crear una matriz se abre su versión 1 en borrador.
 * - Solo se editan filas y controles de una versión en borrador (RN-IPC-06): una versión aprobada queda congelada.
 * - Cada fila se evalúa con los criterios de la matriz: probabilidad, puntaje, nivel y si es significativo (RN-IPC-01, 02).
 * - El riesgo residual usa los mismos criterios y no supera al inicial (RN-IPC-04).
 * - Las versiones nuevas y los cambios de estado se hacen con el flujo de la matriz, no editando la versión.
 */
class IpercRules extends RecordRules
{
    public function __construct(private RiskEvaluator $eval) {}

    public function prepare(string $op, array $res, array &$values, ?object $current, array &$extra): void
    {
        match ($res['table']) {
            'iperc_matrices' => $this->matrix($values, $current),
            'iperc_versions' => $this->version($op, $current),
            'iperc_rows' => $this->row($values, $current, $extra),
            'iperc_controls' => $this->control($values, $current),
            default => null,
        };
    }

    public function after(string $op, array $res, string $id, array $values, ?object $current): void
    {
        if ($res['table'] !== 'iperc_matrices') {
            return;
        }
        if ($op === 'create') {
            $m = DB::selectOne('select company_id from iperc_matrices where id = ?', [$id]);
            DB::insert(
                "insert into iperc_versions (company_id, iperc_matrix_id, version_number, status, change_reason, created_by, updated_by)
                 values (?, ?, 1, 'draft', 'Elaboración inicial', ?, ?)",
                [$m->company_id, $id, (string) auth()->id(), (string) auth()->id()]
            );
        } elseif (array_key_exists('risk_criteria_id', $values) && $values['risk_criteria_id'] !== $current->risk_criteria_id) {
            $this->reevaluateDraft($id, $values['risk_criteria_id']);
        }
    }

    public function prepareDelete(array $res, object $current): void
    {
        if ($res['table'] === 'iperc_versions') {
            if ($current->status !== 'draft') {
                $this->conflict('Solo se puede eliminar una versión en borrador.');
            }
            if ((int) DB::selectOne('select count(*) as n from iperc_versions where iperc_matrix_id = ? and deleted_at is null', [$current->iperc_matrix_id])->n === 1) {
                $this->conflict('Es la única versión de la matriz. Archiva la matriz si ya no se usa.');
            }
        }
        if ($res['table'] === 'iperc_rows') {
            $this->draftVersion($current->iperc_version_id);
        }
        if ($res['table'] === 'iperc_controls') {
            $this->draftVersion($this->rowVersion($current->iperc_row_id));
        }
    }

    private function matrix(array &$values, ?object $current): void
    {
        $criteria = $this->value($values, $current, 'risk_criteria_id');
        if ($criteria && ! DB::selectOne("select 1 as ok from risk_criteria where id = ? and status = 'active' and deleted_at is null", [$criteria])) {
            $this->fail('risk_criteria_id', 'Elige criterios de evaluación activos.');
        }
        $site = $this->value($values, $current, 'site_id');
        $area = $this->value($values, $current, 'area_id');
        if ($area && $site && ! DB::selectOne('select 1 as ok from areas where id = ? and site_id = ?', [$area, $site])) {
            $this->fail('area_id', 'El área elegida no pertenece a esa sede.');
        }
        if ($current && array_key_exists('risk_criteria_id', $values) && $values['risk_criteria_id'] !== $current->risk_criteria_id
            && ! DB::selectOne("select 1 as ok from iperc_versions where iperc_matrix_id = ? and status = 'draft' and deleted_at is null", [$current->id])) {
            $this->fail('risk_criteria_id', 'Los criterios solo cambian para una versión nueva: abre una nueva versión primero (las aprobadas no se recalculan).');
        }
    }

    private function version(string $op, ?object $current): void
    {
        if ($op === 'create') {
            $this->conflict('Las versiones se crean desde la matriz con «Nueva versión».');
        }
        if ($current && $current->status !== 'draft') {
            $this->conflict('La versión ya no está en borrador: no se puede editar.');
        }
    }

    private function row(array &$values, ?object $current, array &$extra): void
    {
        $versionId = $this->value($values, $current, 'iperc_version_id');
        if ($current && array_key_exists('iperc_version_id', $values) && $values['iperc_version_id'] !== $current->iperc_version_id) {
            $this->fail('iperc_version_id', 'Una fila no se mueve a otra versión.');
        }
        $version = $this->draftVersion($versionId);
        $c = $this->eval->criteria($version->risk_criteria_id);

        // Peligro del catálogo: completa peligro, riesgo y consecuencia si vienen vacíos.
        $hazardId = $this->value($values, $current, 'hazard_catalog_id');
        if ($hazardId && (! $this->value($values, $current, 'hazard') || ! $this->value($values, $current, 'risk'))) {
            $h = DB::selectOne('select hazard, risk, possible_consequence from hazard_catalog where id = ?', [$hazardId]);
            $values['hazard'] = $this->value($values, $current, 'hazard') ?: $h->hazard;
            $values['risk'] = $this->value($values, $current, 'risk') ?: $h->risk;
            if (! $this->value($values, $current, 'consequence') && $h->possible_consequence) {
                $values['consequence'] = $h->possible_consequence;
            }
        }
        foreach (['hazard' => 'Completa el peligro (o elígelo del catálogo).', 'risk' => 'Completa el riesgo (o elige el peligro del catálogo).'] as $f => $msg) {
            if (! $this->value($values, $current, $f)) {
                $this->fail($f, $msg);
            }
        }

        $detail = $this->value($values, $current, 'probability_detail');
        if (is_string($detail)) {
            $detail = json_decode($detail, true);
        }
        $prob = $this->value($values, $current, 'probability_value');
        $ev = $this->eval->evaluate($c, $prob === null ? null : (int) $prob, $detail, ($s = $this->value($values, $current, 'severity_value')) === null ? null : (int) $s);
        $values['probability_value'] = $ev['probability'];
        $values['severity_value'] = $ev['severity'];
        if ($c['method'] === 'pxs_index') {
            $values['probability_detail'] = $ev['detail'];
        }
        $extra['risk_level'] = ['?', [$ev['level']]];
        $extra['is_significant'] = ['?::boolean', [$ev['significant'] ? 'true' : 'false']];

        $rp = $this->value($values, $current, 'residual_probability');
        $rs = $this->value($values, $current, 'residual_severity');
        if (($rp === null) !== ($rs === null)) {
            $this->fail($rp === null ? 'residual_probability' : 'residual_severity', 'Para el riesgo residual indica probabilidad y severidad.');
        }
        $extra['residual_level'] = $rp === null ? ['null', []] : ['?', [$this->eval->residual($c, (int) $rp, (int) $rs, $ev['score'])['level']]];

        $position = $this->value($values, $current, 'position_id');
        if ($position && ! DB::selectOne("select 1 as ok from positions where id = ? and status = 'active' and deleted_at is null", [$position])) {
            $this->fail('position_id', 'El puesto no está activo.');
        }
        if ($current === null) {
            $next = (int) DB::selectOne('select coalesce(max(row_number), 0) + 1 as n from iperc_rows where iperc_version_id = ? and deleted_at is null', [$versionId])->n;
            $extra['row_number'] = ['?', [$next]];
        }
    }

    private function control(array &$values, ?object $current): void
    {
        $rowId = $this->value($values, $current, 'iperc_row_id');
        if ($current && array_key_exists('iperc_row_id', $values) && $values['iperc_row_id'] !== $current->iperc_row_id) {
            $this->fail('iperc_row_id', 'Un control no se mueve a otra fila.');
        }
        $status = DB::selectOne('select v.status from iperc_rows r join iperc_versions v on v.id = r.iperc_version_id where r.id = ?', [$rowId])?->status;
        // En una versión aprobada solo se sigue la implementación (estado, responsable, fecha); lo demás queda congelado.
        $frozen = array_intersect(array_keys($values), ['hierarchy', 'description', 'is_existing', 'iperc_row_id']);
        if ($status !== 'draft' && ($current === null || $frozen !== [])) {
            $this->conflict('La versión ya fue enviada o aprobada: el control no se puede cambiar. Abre una nueva versión de la matriz.');
        }
        if (($values['is_existing'] ?? false) === true && ! array_key_exists('status', $values) && $current === null) {
            $values['status'] = 'existing';
        }
    }

    private function draftVersion(string $versionId): object
    {
        $v = DB::selectOne('select v.id, v.status, m.risk_criteria_id from iperc_versions v join iperc_matrices m on m.id = v.iperc_matrix_id
                             where v.id = ? and v.deleted_at is null', [$versionId]);
        if (! $v) {
            $this->fail('iperc_version_id', 'La versión no existe.');
        }
        if ($v->status !== 'draft') {
            $this->conflict('Esta versión ya fue enviada o aprobada y quedó congelada. Para cambiarla, abre una nueva versión de la matriz.');
        }

        return $v;
    }

    private function rowVersion(string $rowId): string
    {
        return DB::selectOne('select iperc_version_id from iperc_rows where id = ?', [$rowId])->iperc_version_id;
    }

    /** Al cambiar los criterios de una matriz, se recalculan solo las filas del borrador (RN-IPC-11). */
    private function reevaluateDraft(string $matrixId, string $criteriaId): void
    {
        $c = $this->eval->criteria($criteriaId);
        $rows = DB::select("select r.* from iperc_rows r join iperc_versions v on v.id = r.iperc_version_id
                             where v.iperc_matrix_id = ? and v.status = 'draft' and r.deleted_at is null", [$matrixId]);
        foreach ($rows as $r) {
            try {
                $ev = $this->eval->evaluate($c, (int) $r->probability_value, json_decode((string) $r->probability_detail, true), (int) $r->severity_value);
                $level = $ev['level'];
                $sig = $ev['significant'];
            } catch (\Throwable) {
                // La escala cambió y la fila ya no encaja: queda marcada para revisar con el nivel más alto.
                $codes = array_column($c['bands'], 'code');
                $level = end($codes);
                $sig = true;
            }
            DB::update('update iperc_rows set risk_level = ?, is_significant = ?, residual_probability = null, residual_severity = null, residual_level = null where id = ?',
                [$level, $sig ? 'true' : 'false', $r->id]);
        }
    }
}

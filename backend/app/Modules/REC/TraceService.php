<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Trazabilidad de un registro (procesos de mejora continua):
 *  - «Viene de»: los registros a los que apunta (sus referencias y su origen, p. ej. la inspección que abrió una acción).
 *  - «Generó»: los registros de cualquier módulo que apuntan a él, agrupados, con el filtro para verlos.
 * Solo se muestran tablas de módulos habilitados y que el rol puede leer; RLS limita todo a la empresa activa.
 */
final class TraceService
{
    /** Origen genérico (source_type → tabla) de acciones correctivas y similares. */
    public const SOURCES = [
        'inspection' => 'inspections', 'finding' => 'findings', 'audit' => 'audit_findings', 'event' => 'sst_events',
        'investigation' => 'investigations', 'report' => 'substandard_reports', 'baseline' => 'baseline_gaps',
        'legal_requirement' => 'legal_requirements', 'committee_agreement' => 'meeting_agreements', 'management_review' => 'management_reviews',
        'monitoring' => 'measurements', 'iperc' => 'iperc_rows', 'drill' => 'drills', 'observation' => 'behavior_observations', 'change' => 'change_requests',
    ];

    /** Referencias que no son parte del proceso (personas, catálogos, estructura): no se muestran como «viene de». */
    private const CONTEXT = ['users', 'sites', 'areas', 'positions', 'work_shifts', 'workers', 'hazard_catalog', 'risk_criteria', 'legal_norms',
        'document_categories', 'kpi_definitions', 'agent_types', 'sector_packs', 'sector_pack_items', 'iso_clauses', 'ppe_items', 'credential_types'];

    public function trace(array $res, string $id): array
    {
        $readable = $this->readableTables();
        $row = DB::selectOne('select * from "'.$res['table'].'" where id = ?', [$id]);
        if (! $row) {
            return ['parents' => [], 'children' => []];
        }

        $parents = [];
        foreach ($res['fields'] as $f) {
            if ($f['type'] !== 'ref' || in_array($f['ref'], self::CONTEXT, true) || empty($row->{$f['name']})) {
                continue;
            }
            $p = $this->node($f['ref'], $row->{$f['name']}, $readable);
            if ($p) {
                $parents[] = $p + ['via' => $f['label']];
            }
        }
        if (isset($row->source_type, $row->source_id) && isset(self::SOURCES[$row->source_type])) {
            $p = $this->node(self::SOURCES[$row->source_type], $row->source_id, $readable);
            if ($p) {
                $parents[] = $p + ['via' => 'Origen'];
            }
        }

        $children = [];
        foreach (Registry::data()['resources'] as $table => $other) {
            if (! isset($readable[$table])) {
                continue;
            }
            foreach ($other['fields'] as $f) {
                if ($f['type'] !== 'ref' || $f['ref'] !== $res['table']) {
                    continue;
                }
                $ids = array_column(DB::select('select id from "'.$table.'" where "'.$f['name'].'" = ?'.($other['soft_delete'] ? ' and deleted_at is null' : '').' limit 500', [$id]), 'id');
                $n = count($ids);
                // Si lo único que apunta aquí ya es su origen (p. ej. el hallazgo que abrió la acción), no se repite en «Generó».
                if ($n > 0 && array_diff($ids, array_column(array_filter($parents, fn ($p) => $p['table'] === $table), 'id')) !== []) {
                    $children[] = $this->group($table, $other, $n, $f['name'], $id);
                }
            }
            if (Registry::field($other, 'source_type') && in_array($res['table'], self::SOURCES, true)) {
                $hasSourceId = DB::selectOne("select 1 as ok from information_schema.columns where table_name = ? and column_name = 'source_id'", [$table]);
                if ($hasSourceId) {
                    $n = (int) DB::selectOne('select count(*) as n from "'.$table.'" where source_id = ? and source_type = ?'.($other['soft_delete'] ? ' and deleted_at is null' : ''),
                        [$id, array_search($res['table'], self::SOURCES, true)])->n;
                    if ($n > 0) {
                        $children[] = $this->group($table, $other, $n, 'source_id', $id);
                    }
                }
            }
        }

        return ['parents' => $parents, 'children' => $children];
    }

    private function node(string $table, string $id, array $readable): ?array
    {
        $res = Registry::data()['resources'][$table] ?? null;
        if (! $res || ! isset($readable[$table]) || ! Str::isUuid($id)) {
            return null;
        }
        $label = $res['label'] !== 'id' ? $res['label'] : null;
        $code = Registry::field($res, 'code') && $label !== 'code';
        $row = DB::selectOne('select '.($label ? "\"{$label}\"::text" : "''").' as label'.($code ? ', code' : '').' from "'.$table.'" where id = ?', [$id]);
        if (! $row) {
            return null;
        }

        return [
            'module' => $res['module'], 'table' => $table, 'tab' => $res['tab'], 'id' => $id,
            'label' => trim(($code && $row->code ? $row->code.' · ' : '').Str::limit((string) $row->label, 80)),
        ];
    }

    private function group(string $table, array $res, int $n, string $field, string $id): array
    {
        return ['module' => $res['module'], 'table' => $table, 'tab' => $res['tab'], 'count' => $n, 'filter' => [$field => $id]];
    }

    /** Tablas que el usuario puede leer (permiso {módulo}.{tabla}.read, ya recortado por el plan). */
    private function readableTables(): array
    {
        $out = [];
        foreach (Permissions::effective() as $p) {
            if (str_ends_with($p, '.read')) {
                $out[explode('.', $p)[1]] = true;
            }
        }

        return $out;
    }
}

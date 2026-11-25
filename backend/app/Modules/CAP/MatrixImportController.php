<?php

namespace App\Modules\CAP;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Modules\REC\Registry;
use App\Modules\REC\RecordsService;
use App\Modules\TRB\SheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * M-CAP SCR-CAP-07 «importación desde Excel»: carga la matriz de capacitación por cargo desde un Excel o CSV. Primero muestra la
 * revisión fila por fila (cargo y tema encontrados, nivel, renovación, plazo y si es nueva o cambia una existente); con «apply»
 * guarda las válidas con las reglas de la matriz (un cambio guarda la versión anterior, RN-CAP-06).
 *   GET  /cap/matrix/template.xlsx  plantilla con los cargos y temas de la empresa
 *   POST /cap/matrix/import {file, apply?}
 * SCR-CAP-07 «propuesta desde IPERC y PCA»: arma las filas que la matriz debería tener según los controles del IPERC vigente que piden una
 * capacitación (iperc_controls.training_id: para el puesto de la fila, o el de la matriz, o los puestos del área de la matriz) y los temas
 * del programa anual aprobado o en ejecución con su capacitación y sus puestos objetivo. Nada se aplica solo: se revisa y se aceptan las
 * elegidas, que quedan con su origen (source = iperc / pca).
 *   GET  /cap/matrix/proposals
 *   POST /cap/matrix/proposals {items: [{position_id, training_id, source, requirement_level?}]}
 */
final class MatrixImportController
{
    private const LEVELS = ['obligatorio' => 'mandatory', 'obligatoria' => 'mandatory', 'mandatory' => 'mandatory', 'sugerido' => 'suggested', 'sugerida' => 'suggested', 'suggested' => 'suggested'];

    public function template(): \Illuminate\Http\Response
    {
        $this->can('cap.position_training_requirements.read');
        $rows = [['MATRIZ DE CAPACITACIÓN POR CARGO'], ['Escribe el código o el nombre del cargo y del tema tal como están en EscudoSST. Nivel: obligatorio o sugerido.'], [],
            ['Cargo', 'Tema', 'Nivel', 'Renovación (meses)', 'Plazo desde el ingreso (días)']];
        foreach (DB::select("select coalesce(p.code, p.name) as pos, coalesce(t.code, t.title) as tr, q.requirement_level, q.renewal_months, q.due_within_days
                               from position_training_requirements q join positions p on p.id = q.position_id join trainings t on t.id = q.training_id
                              where q.deleted_at is null order by p.name, t.title") as $r) {
            $rows[] = [$r->pos, $r->tr, $r->requirement_level === 'suggested' ? 'sugerido' : 'obligatorio', $r->renewal_months, $r->due_within_days];
        }

        return response(Xlsx::build('Matriz', $rows, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="matriz-capacitacion.xlsx"',
        ]);
    }

    public function import(Request $request, RecordsService $records): JsonResponse
    {
        $this->can('cap.position_training_requirements.create');
        $request->validate(['file' => ['required', 'file', 'max:5120']], ['file.required' => 'Elige el archivo de Excel.']);
        $file = $request->file('file');
        // docs/10 §6: extensión y tipo real del contenido (magic bytes), no solo el nombre del archivo.
        \App\Core\Support\UploadGuard::spreadsheet($file, 'file', 'Sube el archivo en Excel (.xlsx) o CSV.');
        try {
            $sheet = SheetReader::read($file->getRealPath(), $file->getClientOriginalName());
        } catch (\Throwable $e) {
            report($e);
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'No pudimos leer el archivo.', ['errors' => ['file' => ['No pudimos leer el archivo.']]]);
        }
        $titles = ['position' => 'cargo', 'training' => 'tema', 'level' => 'nivel', 'renewal' => 'renovación (meses)', 'due' => 'plazo desde el ingreso (días)'];
        $map = null;
        $header = 0;
        foreach ($sheet as $n => $cells) {
            $c = array_map(fn ($x) => mb_strtolower(trim((string) $x)), $cells);
            if (in_array('cargo', $c, true) && in_array('tema', $c, true)) {
                $map = [];
                foreach ($titles as $k => $t) {
                    if (($i = array_search($t, $c, true)) !== false) {
                        $map[$k] = $i;
                    }
                }
                $header = $n;
                break;
            }
        }
        if (! $map) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'No encontramos la fila de títulos (Cargo, Tema, Nivel…). Usa la plantilla.',
                ['errors' => ['file' => ['No encontramos la fila de títulos (Cargo, Tema, Nivel…). Usa la plantilla.']]]);
        }
        $find = function (string $table, string $label, string $v): ?object {
            $v = mb_strtolower(trim($v));

            return $v === '' ? null : DB::selectOne("select id, {$label} as label from {$table} where deleted_at is null and (lower(code) = ? or lower({$label}) = ?) limit 1", [$v, $v]);
        };
        $apply = $request->boolean('apply');
        $res = Registry::resource('CAP', 'position_training_requirements');
        $out = [];
        $saved = 0;
        foreach ($sheet as $n => $cells) {
            if ($n <= $header) {
                continue;
            }
            $g = fn ($k) => isset($map[$k]) ? trim((string) ($cells[$map[$k]] ?? '')) : '';
            if ($g('position') === '' && $g('training') === '') {
                continue;
            }
            $err = [];
            $pos = $find('positions', 'name', $g('position'));
            $tr = $find('trainings', 'title', $g('training'));
            if (! $pos) {
                $err[] = 'El cargo «'.$g('position').'» no existe.';
            }
            if (! $tr) {
                $err[] = 'El tema «'.$g('training').'» no existe.';
            }
            $level = self::LEVELS[mb_strtolower($g('level') ?: 'obligatorio')] ?? null;
            if (! $level) {
                $err[] = 'Nivel no válido: usa «obligatorio» o «sugerido».';
            }
            $num = function (string $k, string $what) use ($g, &$err): ?int {
                $v = $g($k);
                if ($v === '') {
                    return null;
                }
                if (! ctype_digit($v) || (int) $v > 600) {
                    $err[] = "{$what} debe ser un número entero.";

                    return null;
                }

                return (int) $v;
            };
            $renewal = $num('renewal', 'La renovación');
            $due = $num('due', 'El plazo');
            $existing = ! $err ? DB::selectOne('select id, requirement_level, renewal_months, due_within_days from position_training_requirements
                                                where position_id = ? and training_id = ? and deleted_at is null limit 1', [$pos->id, $tr->id]) : null;
            $same = $existing && $existing->requirement_level === $level && $existing->renewal_months === $renewal && $existing->due_within_days === $due;
            $row = ['row' => $n + 1, 'position' => $pos->label ?? $g('position'), 'training' => $tr->label ?? $g('training'), 'level' => $level, 'renewal_months' => $renewal,
                'due_within_days' => $due, 'action' => $err ? 'error' : ($existing ? ($same ? 'same' : 'update') : 'create'), 'errors' => $err];
            if ($apply && ! $err && ! $same) {
                try {
                    $body = ['requirement_level' => $level, 'renewal_months' => $renewal, 'due_within_days' => $due];
                    $existing ? $records->update($res, $existing->id, $body) : $records->create($res, $body + ['position_id' => $pos->id, 'training_id' => $tr->id]);
                    $saved++;
                } catch (ApiProblem $e) {
                    $row['action'] = 'error';
                    $row['errors'][] = $e->getMessage() ?: 'No se pudo guardar.';
                }
            }
            $out[] = $row;
        }

        return response()->json(['data' => ['applied' => $apply, 'saved' => $saved, 'rows' => $out,
            'counts' => array_count_values(array_column($out, 'action'))]]);
    }

    public function proposals(): JsonResponse
    {
        $this->can('cap.position_training_requirements.read');

        return response()->json(['data' => self::proposalRows()]);
    }

    public function acceptProposals(Request $request, RecordsService $records): JsonResponse
    {
        $this->can('cap.position_training_requirements.create');
        $d = $request->validate(['items' => ['required', 'array', 'min:1', 'max:500'], 'items.*.position_id' => ['required', 'uuid'], 'items.*.training_id' => ['required', 'uuid'],
            'items.*.source' => ['required', 'in:iperc,pca'], 'items.*.requirement_level' => ['nullable', 'in:mandatory,suggested']],
            ['items.required' => 'Elige al menos una propuesta.']);
        // Solo se aceptan propuestas que el IPERC o el PCA respaldan hoy (no filas armadas por el cliente).
        $valid = [];
        foreach (self::proposalRows() as $r) {
            $valid[$r['position_id'].'|'.$r['training_id'].'|'.$r['source']] = $r;
        }
        $done = [];
        $res = Registry::resource('CAP', 'position_training_requirements');
        $saved = 0;
        $errors = [];
        foreach ($d['items'] as $it) {
            $p = $valid[$it['position_id'].'|'.$it['training_id'].'|'.$it['source']] ?? null;
            if (! $p) {
                $errors[] = 'Una de las propuestas ya no está en el IPERC ni en el programa: vuelve a revisar.';

                continue;
            }
            $pair = $p['position_id'].'|'.$p['training_id'];
            if ($p['action'] === 'exists' || isset($done[$pair])) {
                continue;
            }
            try {
                $records->create($res, ['position_id' => $p['position_id'], 'training_id' => $p['training_id'], 'requirement_level' => $it['requirement_level'] ?? $p['level'],
                    'source' => $p['source'], 'notes' => mb_substr('Propuesta desde '.$p['origin'], 0, 500)]);
                $saved++;
                $done[$pair] = true;
            } catch (ApiProblem $e) {
                $errors[] = "{$p['position']} · {$p['training']}: ".(collect($e->extra['errors'] ?? [])->flatten()->first() ?? ($e->detail ?? $e->title));
            }
        }

        return response()->json(['data' => ['saved' => $saved, 'errors' => array_values(array_unique($errors))]]);
    }

    /** @return list<array{position_id: string, position: string, training_id: string, training: string, source: string, origin: string, level: string, action: string}> */
    public static function proposalRows(): array
    {
        $iperc = DB::select(
            "select distinct on (ps.id, t.id) ps.id as position_id, ps.name as position, t.id as training_id, t.title as training,
                    'IPERC «' || m.title || '», fila ' || r.row_number || ': ' || left(c.description, 120) as origin
               from iperc_controls c
               join iperc_rows r on r.id = c.iperc_row_id and r.deleted_at is null
               join iperc_versions v on v.id = r.iperc_version_id and v.deleted_at is null
               join iperc_matrices m on m.id = v.iperc_matrix_id and m.deleted_at is null and m.status <> 'archived'
                and v.id = coalesce(m.current_version_id, (select v2.id from iperc_versions v2 where v2.iperc_matrix_id = m.id and v2.deleted_at is null
                                                              and v2.status <> 'obsolete' order by v2.version_number desc limit 1))
               join trainings t on t.id = c.training_id and t.deleted_at is null
               join positions ps on ps.deleted_at is null and ps.status = 'active'
                and (ps.id = coalesce(r.position_id, m.position_id) or (r.position_id is null and m.position_id is null and m.area_id is not null and ps.area_id = m.area_id))
              where c.deleted_at is null and c.status <> 'cancelled' and c.training_id is not null
              order by ps.id, t.id, m.title, r.row_number");
        $pca = DB::select(
            "select distinct on (ps.id, t.id) ps.id as position_id, ps.name as position, t.id as training_id, t.title as training,
                    'PCA ' || p.year || ': «' || i.topic || '»' as origin, i.is_mandatory_legal
               from training_program_items i
               join training_programs p on p.id = i.training_program_id and p.deleted_at is null and p.status in ('approved', 'in_execution')
               join trainings t on t.id = i.training_id and t.deleted_at is null
               join positions ps on ps.deleted_at is null and ps.status = 'active'
                and ps.id = any(coalesce(i.target_positions, '{}'::uuid[]) || case when i.position_id is null then '{}'::uuid[] else array[i.position_id] end)
              where i.deleted_at is null and i.status <> 'cancelled'
              order by ps.id, t.id, p.year desc");
        $existing = [];
        foreach (DB::select('select position_id, training_id from position_training_requirements where deleted_at is null and training_id is not null') as $r) {
            $existing[$r->position_id.'|'.$r->training_id] = true;
        }
        $out = [];
        foreach ([['iperc', $iperc], ['pca', $pca]] as [$src, $rows]) {
            foreach ($rows as $r) {
                // Un control del IPERC es una medida para el riesgo del puesto: obligatorio. Del PCA, obligatorio si el tema es obligatorio por ley.
                $out[] = ['position_id' => $r->position_id, 'position' => $r->position, 'training_id' => $r->training_id, 'training' => $r->training, 'source' => $src,
                    'origin' => $r->origin, 'level' => $src === 'iperc' || ($r->is_mandatory_legal ?? false) ? 'mandatory' : 'suggested',
                    'action' => isset($existing[$r->position_id.'|'.$r->training_id]) ? 'exists' : 'create'];
            }
        }
        usort($out, fn ($a, $b) => [$a['position'], $a['training'], $a['source']] <=> [$b['position'], $b['training'], $b['source']]);

        return $out;
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto en la matriz de capacitación.');
        }
    }
}

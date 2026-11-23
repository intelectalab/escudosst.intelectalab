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
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'csv'], true)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', 'Sube el archivo en Excel (.xlsx) o CSV.', ['errors' => ['file' => ['Sube el archivo en Excel (.xlsx) o CSV.']]]);
        }
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

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto en la matriz de capacitación.');
        }
    }
}

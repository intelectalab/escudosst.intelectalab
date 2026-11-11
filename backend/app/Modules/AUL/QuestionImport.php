<?php

namespace App\Modules\AUL;

use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\TRB\SheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importación de preguntas desde Excel a un banco (M-AUL SCR-AUL-09, RN-AUL-14):
 *  GET  /aul/admin/banks/{id}/import/template   plantilla oficial .xlsx versionada
 *  POST /aul/admin/banks/{id}/import/preview    {file} valida fila por fila sin guardar nada
 *  POST /aul/admin/banks/{id}/import            {file} importa solo las filas válidas; cada fila entra completa o no entra
 * Columnas: código, tipo, enunciado, alternativas A a F, correcta(s), dificultad, puntos y retroalimentación.
 */
class QuestionImport extends Controller
{
    public const VERSION = 'AUL-Q1';

    private const MAX_ROWS = 1000;

    private const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];

    private const COLUMNS = [
        'code' => 'Código',
        'type' => 'Tipo',
        'statement' => 'Enunciado',
        'opt_A' => 'Alternativa A', 'opt_B' => 'Alternativa B', 'opt_C' => 'Alternativa C', 'opt_D' => 'Alternativa D', 'opt_E' => 'Alternativa E', 'opt_F' => 'Alternativa F',
        'correct' => 'Correcta',
        'difficulty' => 'Dificultad',
        'points' => 'Puntos',
        'feedback_correct' => 'Retroalimentación si acierta',
        'feedback_incorrect' => 'Retroalimentación si falla',
    ];

    private const TYPES = [
        'opcion unica' => 'single_choice', 'unica' => 'single_choice', 'single_choice' => 'single_choice',
        'opcion multiple' => 'multiple_choice', 'multiple' => 'multiple_choice', 'multiple_choice' => 'multiple_choice',
        'verdadero o falso' => 'true_false', 'verdadero/falso' => 'true_false', 'v/f' => 'true_false', 'true_false' => 'true_false',
        'respuesta corta' => 'short_answer', 'corta' => 'short_answer', 'abierta' => 'short_answer', 'short_answer' => 'short_answer',
    ];

    private const DIFFICULTY = ['baja' => 'easy', 'facil' => 'easy', 'easy' => 'easy', 'media' => 'medium', 'medium' => 'medium', 'alta' => 'hard', 'dificil' => 'hard', 'hard' => 'hard'];

    public function template(string $id): Response
    {
        $bank = $this->bank($id);
        $rows = [
            ['EscudoSST · Plantilla de preguntas del aula · versión '.self::VERSION],
            ['Una fila por pregunta desde la fila 6. Obligatorios: tipo y enunciado. Tipo: Opción única, Opción múltiple, Verdadero o falso o Respuesta corta. Dificultad: Baja, Media o Alta (vacío = Media). Puntos: vacío = 1.'],
            ['Correcta: la letra de la alternativa (A); en opción múltiple varias separadas por coma (A,C); en verdadero o falso escribe V o F; en respuesta corta las respuestas aceptadas separadas por | (vacío = pregunta abierta que califica el capacitador).'],
            ['Banco: '.$bank->code.' · '.$bank->name],
            array_values(self::COLUMNS),
            ['P-01', 'Opción única', '¿Quién firma el permiso de trabajo en caliente?', 'El supervisor del área', 'Cualquier trabajador', 'Nadie', '', '', '', 'A', 'Media', 1, 'Correcto.', 'Revisa el procedimiento PETAR.'],
        ];

        return response(Xlsx::build('Preguntas', $rows, 5), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="plantilla-preguntas-'.self::VERSION.'.xlsx"',
        ]);
    }

    public function preview(Request $request, string $id): JsonResponse
    {
        $bank = $this->bank($id);
        $rows = $this->parse($request, $bank);
        $valid = count(array_filter($rows, fn ($r) => $r['errors'] === []));

        return response()->json(['data' => ['version' => self::VERSION, 'total' => count($rows), 'valid' => $valid, 'with_errors' => count($rows) - $valid,
            'rows' => array_map(fn ($r) => $this->public($r), $rows)]]);
    }

    public function import(Request $request, string $id): JsonResponse
    {
        $bank = $this->bank($id);
        $rows = $this->parse($request, $bank);
        $ctx = app(CompanyContext::class);
        $me = (string) auth()->id();
        $created = 0;
        $out = [];
        foreach ($rows as $r) {
            $r['result'] = 'error';
            if ($r['errors'] === []) {
                try {
                    DB::transaction(function () use ($r, $bank, $ctx, $me) {   // RN-AUL-14: la fila entra completa o no entra
                        DB::insert(
                            "insert into questions (company_id, question_bank_id, code, question_type, statement, options, accepted_answers, points, difficulty, feedback_correct, feedback_incorrect,
                                status, created_by, updated_by) values (?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?, ?, ?, 'active', ?, ?)",
                            [$ctx->companyId, $bank->id, $r['code'] ?: null, $r['type'], $r['statement'], $r['options'] === null ? null : json_encode($r['options'], JSON_UNESCAPED_UNICODE),
                                $r['accepted'] === null ? null : json_encode($r['accepted'], JSON_UNESCAPED_UNICODE), $r['points'], $r['difficulty'], $r['feedback_correct'] ?: null, $r['feedback_incorrect'] ?: null, $me, $me]
                        );
                    });
                    $r['result'] = 'created';
                    $created++;
                } catch (\Throwable $e) {
                    $r['errors'] = ['No se pudo guardar la fila: revisa sus datos.'];
                    report($e);
                }
            }
            $out[] = $this->public($r) + ['result' => $r['result']];
        }
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes)
             values (?, ?, ?, 'user', 'questions.import', 'AUL', 'question_banks', ?, ?::jsonb)",
            [$ctx->companyId, $me, $ctx->roleCode, $bank->id, json_encode(['version' => self::VERSION, 'total' => count($rows), 'created' => $created, 'failed' => count($rows) - $created])]
        );

        return response()->json(['data' => ['total' => count($rows), 'created' => $created, 'failed' => count($rows) - $created, 'rows' => $out]]);
    }

    private function parse(Request $request, object $bank): array
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']], ['file.required' => 'Elige el archivo de Excel.', 'file.max' => 'El archivo pesa más de 5 MB.']);
        $file = $request->file('file');
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'csv'], true)) {
            $this->fail('Sube la plantilla en Excel (.xlsx) o CSV.');
        }
        try {
            $sheet = SheetReader::read($file->getRealPath(), $file->getClientOriginalName());
        } catch (\Throwable) {
            $this->fail('No pudimos leer el archivo. Descarga la plantilla y vuelve a intentarlo.');
        }
        $first = $sheet[array_key_first($sheet) ?? 0][0] ?? '';
        if (! str_contains((string) $first, self::VERSION)) {
            $this->fail('La plantilla no es la vigente (versión '.self::VERSION.'). Descarga la plantilla nueva y copia tus preguntas en ella.');
        }
        $map = null;
        $headerRow = 0;
        foreach ($sheet as $n => $cells) {
            $norm = array_map(fn ($c) => $this->norm((string) $c), $cells);
            if (in_array($this->norm(self::COLUMNS['statement']), $norm, true)) {
                $map = [];
                foreach (self::COLUMNS as $key => $title) {
                    $i = array_search($this->norm($title), $norm, true);
                    if ($i !== false) {
                        $map[$key] = $i;
                    }
                }
                $headerRow = $n;
                break;
            }
        }
        if ($map === null || ! isset($map['type'], $map['correct'])) {
            $this->fail('No encontramos la fila de títulos de la plantilla (Tipo, Enunciado, Alternativa A…, Correcta).');
        }
        $data = array_filter($sheet, fn ($n) => $n > $headerRow, ARRAY_FILTER_USE_KEY);
        $data = array_filter($data, fn ($cells) => implode('', array_map(fn ($c) => trim((string) $c), $cells)) !== '');
        // La fila de ejemplo de la plantilla no se importa si quedó tal cual.
        $data = array_filter($data, fn ($cells) => trim((string) ($cells[$map['code'] ?? -1] ?? '')) !== 'P-01' || ! str_starts_with(trim((string) ($cells[$map['statement']] ?? '')), '¿Quién firma el permiso de trabajo en caliente'));
        if ($data === []) {
            $this->fail('La plantilla no tiene preguntas: completa una fila por pregunta.');
        }
        if (count($data) > self::MAX_ROWS) {
            $this->fail('Carga hasta '.self::MAX_ROWS.' preguntas por archivo.');
        }
        $existing = array_flip(array_map('mb_strtolower', array_column(DB::select('select code from questions where question_bank_id = ? and deleted_at is null and code is not null', [$bank->id]), 'code')));
        $seen = [];
        $rows = [];
        foreach ($data as $n => $cells) {
            $get = fn (string $k) => isset($map[$k]) ? trim((string) ($cells[$map[$k]] ?? '')) : '';
            $errors = [];
            $code = mb_substr($get('code'), 0, 40);
            if ($code !== '') {
                $k = mb_strtolower($code);
                if (isset($existing[$k])) {
                    $errors[] = "El código {$code} ya existe en el banco.";
                } elseif (isset($seen[$k])) {
                    $errors[] = "Código duplicado en el archivo (fila {$seen[$k]}).";
                }
                $seen[$k] = $n;
            }
            $typeRaw = $get('type');
            $type = self::TYPES[$this->norm($typeRaw)] ?? null;
            if ($typeRaw === '') {
                $errors[] = 'Falta el tipo de pregunta.';
            } elseif (! $type) {
                $errors[] = "Tipo «{$typeRaw}» no válido: usa Opción única, Opción múltiple, Verdadero o falso o Respuesta corta.";
            }
            $statement = $get('statement');
            if ($statement === '') {
                $errors[] = 'Falta el enunciado.';
            } elseif (mb_strlen($statement) > 2000) {
                $errors[] = 'El enunciado supera 2000 caracteres.';
            }
            $texts = [];
            foreach (self::LETTERS as $L) {
                $texts[$L] = mb_substr($get('opt_'.$L), 0, 300);
            }
            $correct = $get('correct');
            $options = null;
            $accepted = null;
            if ($type === 'true_false') {
                $v = $this->norm($correct);
                if (! in_array($v, ['v', 'f', 'verdadero', 'falso'], true)) {
                    $errors[] = 'En verdadero o falso, la correcta es V o F.';
                }
                $isTrue = in_array($v, ['v', 'verdadero'], true);
                $options = [['text' => 'Verdadero', 'correct' => $isTrue], ['text' => 'Falso', 'correct' => ! $isTrue]];
            } elseif ($type === 'short_answer') {
                $accepted = array_values(array_filter(array_map(fn ($a) => mb_substr(trim($a), 0, 200), explode('|', $correct)), fn ($a) => $a !== ''));
            } elseif ($type) {
                // Alternativas seguidas desde la A (sin huecos).
                $filled = [];
                $gap = false;
                foreach (self::LETTERS as $L) {
                    if ($texts[$L] === '') {
                        $gap = true;
                    } elseif ($gap) {
                        $errors[] = "La alternativa {$L} está después de una vacía: escribe las alternativas seguidas desde la A.";
                        break;
                    } else {
                        $filled[$L] = $texts[$L];
                    }
                }
                if (count($filled) < 2) {
                    $errors[] = 'Escribe al menos dos alternativas.';
                }
                $marks = array_values(array_unique(array_filter(array_map(fn ($x) => strtoupper(trim($x)), preg_split('/[,;\s]+/', $correct) ?: []))));
                if ($marks === []) {
                    $errors[] = 'Indica la letra de la alternativa correcta.';
                } else {
                    foreach ($marks as $m) {
                        if (! isset($filled[$m])) {
                            $errors[] = "La correcta «{$m}» no es una alternativa escrita.";
                        }
                    }
                    if ($type === 'single_choice' && count($marks) > 1) {
                        $errors[] = 'En opción única marca una sola alternativa correcta.';
                    }
                }
                $options = array_values(array_map(fn ($L) => ['text' => $filled[$L], 'correct' => in_array($L, $marks, true)], array_keys($filled)));
            }
            $diffRaw = $get('difficulty');
            $difficulty = $diffRaw === '' ? 'medium' : (self::DIFFICULTY[$this->norm($diffRaw)] ?? null);
            if (! $difficulty) {
                $errors[] = "Dificultad «{$diffRaw}» no válida: usa Baja, Media o Alta.";
            }
            $pointsRaw = str_replace(',', '.', $get('points'));
            $points = $pointsRaw === '' ? 1.0 : (is_numeric($pointsRaw) ? (float) $pointsRaw : null);
            if ($points === null || $points <= 0 || $points > 100) {
                $errors[] = 'Los puntos deben ser un número mayor que 0 (hasta 100).';
            }
            $rows[] = ['row' => $n, 'code' => $code, 'type' => $type, 'type_label' => $typeRaw, 'statement' => $statement, 'options' => $options, 'accepted' => $accepted,
                'difficulty' => $difficulty, 'points' => $points, 'feedback_correct' => mb_substr($get('feedback_correct'), 0, 500), 'feedback_incorrect' => mb_substr($get('feedback_incorrect'), 0, 500),
                'errors' => $errors];
        }

        return $rows;
    }

    private function public(array $r): array
    {
        $correct = $r['type'] === 'short_answer' ? implode(' | ', $r['accepted'] ?? []) ?: 'Abierta (la califica el capacitador)'
            : implode(', ', array_map(fn ($o) => $o['text'], array_filter($r['options'] ?? [], fn ($o) => $o['correct'])));

        return ['row' => $r['row'], 'code' => $r['code'], 'type' => $r['type'], 'type_label' => $r['type_label'], 'statement' => Str::limit($r['statement'], 160),
            'options' => count($r['options'] ?? []), 'correct' => $correct, 'difficulty' => $r['difficulty'], 'points' => $r['points'],
            'action' => $r['errors'] ? 'error' : 'create', 'errors' => $r['errors']];
    }

    private function bank(string $id): object
    {
        if (in_array(app(CompanyContext::class)->roleCode, ['worker', 'external_learner'], true)) {
            throw ApiProblem::forbidden('Tu rol no administra el banco de preguntas.');
        }
        $b = Str::isUuid($id) ? DB::selectOne("select id, code, name, course_id from question_banks where id = ? and deleted_at is null and status = 'active'", [$id]) : null;

        return $b ?? throw ApiProblem::notFound('El banco no existe, está archivado o es de otra empresa.');
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim(preg_replace('/\(.*?\)|\*/', '', $s)));

        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    private function fail(string $message): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa el archivo', $message, ['errors' => ['file' => [$message]]]);
    }
}

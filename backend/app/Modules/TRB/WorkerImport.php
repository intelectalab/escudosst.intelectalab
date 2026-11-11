<?php

namespace App\Modules\TRB;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use App\Modules\REC\Rules\WorkerRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Carga de trabajadores desde Excel (M-TRB, SCR-TRB-04/05):
 *  GET  /trb/imports/template   plantilla .xlsx versionada (RN-TRB-06) con las sedes y puestos de la empresa
 *  POST /trb/imports/preview    {file, mode} valida fila por fila sin guardar nada
 *  POST /trb/imports            {file, mode} importa las filas válidas; las que fallan no bloquean a las demás (RN-TRB-05)
 *
 * mode: «create» (solo altas; un documento que ya existe es error) o «upsert» (actualiza por documento, sin duplicar).
 * Cada fila se guarda con el motor común de registros, así aplica WorkerRules (DNI de 8 dígitos, documento único
 * cifrado con huella, asignación principal a sede/área/puesto) y queda en la bitácora.
 */
class WorkerImport extends Controller
{
    public const VERSION = 'TRB-W1';

    private const MAX_ROWS = 2000;

    /** Columnas de la plantilla: clave => título. */
    private const COLUMNS = [
        'document_type' => 'Tipo de documento',
        'document_number' => 'Número de documento',
        'first_name' => 'Nombres',
        'paternal_last_name' => 'Apellido paterno',
        'maternal_last_name' => 'Apellido materno',
        'birth_on' => 'Fecha de nacimiento',
        'sex' => 'Sexo',
        'phone_e164' => 'Celular',
        'personal_email' => 'Correo',
        'hire_on' => 'Fecha de ingreso',
        'employment_type' => 'Tipo de vínculo',
        'site' => 'Sede',
        'area' => 'Área',
        'position' => 'Puesto',
        'employee_code' => 'Código de empleado',
    ];

    private const DOC_TYPES = ['DNI' => 'DNI', 'CE' => 'CE', 'PTP' => 'PTP', 'PASAPORTE' => 'PASSPORT', 'PASSPORT' => 'PASSPORT', 'OTRO' => 'OTHER', 'OTHER' => 'OTHER'];

    private const EMPLOYMENT = ['propio' => 'own', 'intermediado' => 'intermediated', 'tercerizado' => 'outsourced', 'practicante' => 'intern',
        'trainee' => 'trainee', 'otro' => 'other', 'own' => 'own', 'intermediated' => 'intermediated', 'outsourced' => 'outsourced', 'intern' => 'intern', 'other' => 'other'];

    public function __construct(private RecordsService $records) {}

    public function template(): Response
    {
        $sites = array_column(DB::select("select coalesce(nullif(code, ''), name) as v from sites where deleted_at is null and status = 'active' order by name limit 30"), 'v');
        $positions = array_column(DB::select("select coalesce(nullif(code, ''), name) as v from positions where deleted_at is null and status = 'active' order by name limit 40"), 'v');
        $rows = [
            ['EscudoSST · Plantilla de trabajadores · versión '.self::VERSION],
            ['Una fila por trabajador desde la fila 6. Obligatorios: número de documento, nombres, apellido paterno, sede y puesto. Fechas en dd/mm/aaaa. Tipo de documento: DNI, CE, PTP o PASAPORTE (vacío = DNI). Sexo: M o F. Tipo de vínculo: Propio, Intermediado, Tercerizado o Practicante. Si el DNI empieza con 0, escribe la columna como texto.'],
            ['Sedes (código o nombre): '.($sites ? implode(', ', $sites) : 'aún no hay sedes; créalas en Empresa y sedes')],
            ['Puestos (código o nombre): '.($positions ? implode(', ', $positions) : 'aún no hay puestos; créalos en Empresa y sedes')],
            array_values(self::COLUMNS),
        ];

        return response(Xlsx::build('Trabajadores', $rows, 5), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="plantilla-trabajadores-'.self::VERSION.'.xlsx"',
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        [$mode, $rows] = $this->parse($request);
        $valid = count(array_filter($rows, fn ($r) => $r['errors'] === []));

        return response()->json(['data' => [
            'version' => self::VERSION,
            'mode' => $mode,
            'total' => count($rows),
            'valid' => $valid,
            'with_errors' => count($rows) - $valid,
            'rows' => array_map(fn ($r) => $this->public($r), $rows),
        ]]);
    }

    public function import(Request $request): JsonResponse
    {
        [$mode, $rows] = $this->parse($request);
        if ($mode === 'upsert' && ! in_array('trb.workers.update', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol puede dar de alta trabajadores, pero no actualizar los existentes. Usa el modo «Solo altas».');
        }
        $res = Registry::resource('TRB', 'workers');
        $created = $updated = 0;
        $out = [];
        foreach ($rows as $r) {
            if ($r['errors'] === []) {
                try {
                    $id = DB::transaction(fn () => $r['existing_id']
                        ? $this->records->update($res, $r['existing_id'], $this->values($r, false))['id']
                        : $this->records->create($res, $this->values($r, true))['id']);
                    $r['worker_id'] = $id;
                    $r['existing_id'] ? $updated++ : $created++;
                    $r['result'] = $r['existing_id'] ? 'updated' : 'created';
                } catch (ApiProblem $e) {
                    $r['errors'] = array_values(array_unique(array_merge(...array_values($e->extra['errors'] ?? [[$e->detail ?? $e->title]]))));
                    $r['result'] = 'error';
                }
            } else {
                $r['result'] = 'error';
            }
            $out[] = $this->public($r) + ['result' => $r['result'], 'worker_id' => $r['worker_id'] ?? null];
        }

        return response()->json(['data' => [
            'mode' => $mode,
            'total' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'failed' => count($rows) - $created - $updated,
            'rows' => $out,
        ]]);
    }

    /** @return array{0: string, 1: array<int, array>} */
    private function parse(Request $request): array
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'mode' => ['nullable', 'in:create,upsert'],
        ], ['file.required' => 'Elige el archivo de Excel.', 'file.max' => 'El archivo pesa más de 5 MB.']);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'csv'], true)) {
            $this->fail('file', 'Sube la plantilla en Excel (.xlsx) o CSV.');
        }
        try {
            $sheet = SheetReader::read($file->getRealPath(), $file->getClientOriginalName());
        } catch (\Throwable) {
            $this->fail('file', 'No pudimos leer el archivo. Descarga la plantilla y vuelve a intentarlo.');
        }
        $first = $sheet[array_key_first($sheet) ?? 0][0] ?? '';
        if (! str_contains((string) $first, self::VERSION)) {
            $this->fail('file', 'La plantilla no es la vigente (versión '.self::VERSION.'). Descarga la plantilla nueva y copia tus datos en ella.');
        }
        // Fila de títulos: la que tiene «Número de documento».
        $map = null;
        $headerRow = 0;
        foreach ($sheet as $n => $cells) {
            $norm = array_map(fn ($c) => $this->norm($c), $cells);
            if (in_array($this->norm(self::COLUMNS['document_number']), $norm, true)) {
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
        if ($map === null || ! isset($map['first_name'], $map['paternal_last_name'])) {
            $this->fail('file', 'No encontramos la fila de títulos de la plantilla (Número de documento, Nombres, Apellido paterno…).');
        }
        $data = array_filter($sheet, fn ($n) => $n > $headerRow, ARRAY_FILTER_USE_KEY);
        if ($data === []) {
            $this->fail('file', 'La plantilla no tiene trabajadores: completa una fila por trabajador.');
        }
        if (count($data) > self::MAX_ROWS) {
            $this->fail('file', 'Carga hasta '.self::MAX_ROWS.' trabajadores por archivo.');
        }
        $mode = (string) ($request->input('mode') ?: 'create');

        $sites = DB::select("select id, lower(code) as code, lower(name) as name from sites where deleted_at is null and status = 'active'");
        $areas = DB::select("select id, site_id, lower(code) as code, lower(name) as name from areas where deleted_at is null and coalesce(status, 'active') = 'active'");
        $positions = DB::select("select id, lower(code) as code, lower(name) as name from positions where deleted_at is null and coalesce(status, 'active') = 'active'");
        $find = fn (array $list, string $v) => collect($list)->first(fn ($x) => $x->code === mb_strtolower($v) || $x->name === mb_strtolower($v));

        $rows = [];
        $seen = [];
        foreach ($data as $n => $cells) {
            $get = fn (string $k) => isset($map[$k]) ? trim((string) ($cells[$map[$k]] ?? '')) : '';
            $v = [];
            foreach (array_keys(self::COLUMNS) as $k) {
                $v[$k] = $get($k);
            }
            $errors = [];
            $type = self::DOC_TYPES[strtoupper($v['document_type'] ?: 'DNI')] ?? null;
            if ($type === null) {
                $errors[] = 'Tipo de documento no válido: usa DNI, CE, PTP o PASAPORTE.';
                $type = 'DNI';
            }
            $number = strtoupper(preg_replace('/[\s.\-]/', '', $v['document_number']));
            if ($number === '') {
                $errors[] = 'Falta el número de documento.';
            } elseif ($type === 'DNI' && ! preg_match('/^\d{8}$/', $number)) {
                $errors[] = 'DNI debe tener 8 dígitos.';
            } elseif ($type !== 'DNI' && ! preg_match('/^[A-Z0-9]{5,20}$/', $number)) {
                $errors[] = 'El documento admite de 5 a 20 letras o números.';
            }
            $existing = null;
            if ($number !== '') {
                $key = $type.'|'.$number;
                if (isset($seen[$key])) {
                    $errors[] = "Documento duplicado en el archivo (fila {$seen[$key]}).";
                } else {
                    $seen[$key] = $n;
                }
                $dup = DB::selectOne("select id, full_name from workers where document_number_hash = decode(?, 'hex') and deleted_at is null", [WorkerRules::documentHash($type, $number)]);
                if ($dup && $mode === 'create') {
                    $errors[] = "Ya existe un trabajador con ese documento: {$dup->full_name}.";
                }
                $existing = $dup?->id;
            }
            if ($v['first_name'] === '') {
                $errors[] = 'Faltan los nombres.';
            }
            if ($v['paternal_last_name'] === '') {
                $errors[] = 'Falta el apellido paterno.';
            }
            $site = $v['site'] !== '' ? $find($sites, $v['site']) : null;
            if ($v['site'] === '') {
                $errors[] = 'Falta la sede.';
            } elseif (! $site) {
                $errors[] = "La sede «{$v['site']}» no existe.";
            }
            $area = null;
            if ($v['area'] !== '' && $site) {
                $area = $find(array_values(array_filter($areas, fn ($a) => $a->site_id === $site->id)), $v['area']);
                if (! $area) {
                    $errors[] = "El área «{$v['area']}» no existe en la sede «{$v['site']}».";
                }
            }
            $position = $v['position'] !== '' ? $find($positions, $v['position']) : null;
            if ($v['position'] === '') {
                $errors[] = 'Falta el puesto.';
            } elseif (! $position) {
                $errors[] = "El puesto «{$v['position']}» no existe.";
            }
            $birth = $this->date($v['birth_on'], 'Fecha de nacimiento', $errors);
            $hire = $this->date($v['hire_on'], 'Fecha de ingreso', $errors);
            if ($birth && $birth > date('Y-m-d')) {
                $errors[] = 'La fecha de nacimiento no puede ser futura.';
            }
            $sex = strtoupper(mb_substr($v['sex'], 0, 1));
            if ($v['sex'] !== '' && ! in_array($sex, ['M', 'F', 'X'], true)) {
                $errors[] = 'Sexo no válido: usa M o F.';
            }
            $employment = $v['employment_type'] !== '' ? (self::EMPLOYMENT[mb_strtolower($v['employment_type'])] ?? null) : null;
            if ($v['employment_type'] !== '' && ! $employment) {
                $errors[] = 'Tipo de vínculo no válido: usa Propio, Intermediado, Tercerizado o Practicante.';
            }
            if ($v['personal_email'] !== '' && ! filter_var($v['personal_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'El correo no es válido.';
            }
            $phone = preg_replace('/[^\d+]/', '', $v['phone_e164']);
            if ($phone !== '' && preg_match('/^9\d{8}$/', $phone)) {
                $phone = '+51'.$phone;
            }
            $rows[] = [
                'row' => $n,
                'type' => $type,
                'number' => $number,
                'first_name' => $v['first_name'],
                'paternal_last_name' => $v['paternal_last_name'],
                'maternal_last_name' => $v['maternal_last_name'],
                'birth_on' => $birth,
                'hire_on' => $hire,
                'sex' => $v['sex'] !== '' ? $sex : null,
                'phone' => $phone ?: null,
                'email' => $v['personal_email'] ?: null,
                'employment_type' => $employment,
                'employee_code' => $v['employee_code'] ?: null,
                'site_id' => $site?->id, 'site' => $v['site'],
                'area_id' => $area?->id, 'area' => $v['area'],
                'position_id' => $position?->id, 'position' => $v['position'],
                'existing_id' => $mode === 'upsert' ? $existing : null,
                'errors' => $errors,
            ];
        }

        return [$mode, $rows];
    }

    /** Valores para el motor de registros (sin campos vacíos para no borrar datos al actualizar). */
    private function values(array $r, bool $creating): array
    {
        $v = array_filter([
            'document_type' => $r['type'],
            'document_number' => $r['number'],
            'first_name' => $r['first_name'],
            'paternal_last_name' => $r['paternal_last_name'],
            'maternal_last_name' => $r['maternal_last_name'] ?: null,
            'birth_on' => $r['birth_on'],
            'hire_on' => $r['hire_on'],
            'sex' => $r['sex'],
            'phone_e164' => $r['phone'],
            'personal_email' => $r['email'],
            'employment_type' => $r['employment_type'],
            'employee_code' => $r['employee_code'],
            'current_site_id' => $r['site_id'],
            'current_area_id' => $r['area_id'],
            'current_position_id' => $r['position_id'],
        ], fn ($x) => $x !== null && $x !== '');
        if ($creating) {
            $v['status'] = 'active';
        } else {
            unset($v['document_type'], $v['document_number']);
        }

        return $v;
    }

    /** Lo que se muestra de la fila: el documento enmascarado (no se devuelve completo). */
    private function public(array $r): array
    {
        return [
            'row' => $r['row'],
            'document' => $r['number'] !== '' ? $r['type'].' ****'.substr($r['number'], -4) : '',
            'name' => trim($r['first_name'].' '.$r['paternal_last_name'].' '.$r['maternal_last_name']),
            'site' => $r['site'],
            'area' => $r['area'],
            'position' => $r['position'],
            'action' => $r['errors'] !== [] ? 'error' : ($r['existing_id'] ? 'update' : 'create'),
            'errors' => $r['errors'],
        ];
    }

    private function date(string $v, string $label, array &$errors): ?string
    {
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d+(\.\d+)?$/', $v) && (float) $v > 1000 && (float) $v < 80000) {
            return gmdate('Y-m-d', ((int) $v - 25569) * 86400);   // número de serie de Excel
        }
        if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$#', $v, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        $errors[] = "{$label} no válida: usa dd/mm/aaaa.";

        return null;
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim(preg_replace('/\(.*?\)|\*/', '', $s)));

        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    private function fail(string $field, string $message): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $message, ['errors' => [$field => [$message]]]);
    }
}

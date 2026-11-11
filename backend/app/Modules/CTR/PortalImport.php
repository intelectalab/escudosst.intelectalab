<?php

namespace App\Modules\CTR;

use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use App\Modules\TRB\SheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Cargar trabajadores desde Excel en el portal del contratista (M-CTR SCR-CTR-04: «alta de trabajadores individual o
 * Excel»). Mismo patrón que la carga de TRB: plantilla versionada, revisión fila por fila sin guardar y alta de las
 * filas válidas; las que fallan no bloquean a las demás. Todo queda en el contratista de la membresía (RN-CTR-04):
 * el motor de registros fuerza contractor_id y PeopleRules valida el DNI y el documento único por contratista.
 *
 *  GET  /ctr/portal/workers/template   plantilla .xlsx (CTR-W1)
 *  POST /ctr/portal/workers/preview    {file} revisión sin guardar
 *  POST /ctr/portal/workers/import     {file} importa las filas válidas
 */
class PortalImport extends Controller
{
    public const VERSION = 'CTR-W1';

    private const MAX_ROWS = 1000;

    private const COLUMNS = [
        'document_type' => 'Tipo de documento',
        'document_number' => 'Número de documento',
        'first_name' => 'Nombres',
        'paternal_last_name' => 'Apellido paterno',
        'maternal_last_name' => 'Apellido materno',
        'position_text' => 'Puesto u oficio',
        'phone_e164' => 'Celular',
    ];

    private const DOC_TYPES = ['DNI' => 'DNI', 'CE' => 'CE', 'PTP' => 'PTP', 'PASAPORTE' => 'PASSPORT', 'PASSPORT' => 'PASSPORT', 'OTRO' => 'OTHER', 'OTHER' => 'OTHER'];

    public function __construct(private RecordsService $records) {}

    public function template(): Response
    {
        $c = ContractorStatus::requirePortalContractor();
        $rows = [
            ['EscudoSST · Plantilla de trabajadores del contratista · versión '.self::VERSION],
            ['Una fila por trabajador desde la fila 5. Obligatorios: número de documento, nombres y apellido paterno. Tipo de documento: DNI, CE, PTP o PASAPORTE (vacío = DNI). Si el DNI empieza con 0, escribe la columna como texto.'],
            ['Contratista: '.$c->legal_name.' · RUC '.$c->ruc.'. No incluyas datos médicos: la aptitud se carga como constancia en cada trabajador.'],
            array_values(self::COLUMNS),
        ];

        return response(Xlsx::build('Trabajadores', $rows, 4), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="plantilla-trabajadores-contratista-'.self::VERSION.'.xlsx"',
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $c = ContractorStatus::requirePortalContractor();
        $rows = $this->parse($request, $c->id);
        $valid = count(array_filter($rows, fn ($r) => $r['errors'] === []));

        return response()->json(['data' => [
            'version' => self::VERSION,
            'total' => count($rows),
            'valid' => $valid,
            'with_errors' => count($rows) - $valid,
            'rows' => array_map(fn ($r) => $this->public($r), $rows),
        ]]);
    }

    public function import(Request $request): JsonResponse
    {
        $c = ContractorStatus::requirePortalContractor();
        $rows = $this->parse($request, $c->id);
        $res = Registry::resource('CTR', 'contractor_workers');
        $created = 0;
        $out = [];
        foreach ($rows as $r) {
            $result = 'error';
            $id = null;
            if ($r['errors'] === []) {
                try {
                    $id = DB::transaction(fn () => $this->records->create($res, array_filter([
                        'contractor_id' => $c->id,
                        'document_type' => $r['type'],
                        'document_number' => $r['number'],
                        'first_name' => $r['first_name'],
                        'paternal_last_name' => $r['paternal_last_name'],
                        'maternal_last_name' => $r['maternal_last_name'] ?: null,
                        'position_text' => $r['position_text'] ?: null,
                        'phone_e164' => $r['phone'],
                        'status' => 'active',
                    ], fn ($v) => $v !== null && $v !== ''))['id']);
                    $created++;
                    $result = 'created';
                } catch (ApiProblem $e) {
                    $r['errors'] = array_values(array_unique(array_merge(...array_values($e->extra['errors'] ?? [[$e->detail ?? $e->title]]))));
                }
            }
            $out[] = $this->public($r) + ['result' => $result, 'worker_id' => $id];
        }

        return response()->json(['data' => [
            'total' => count($rows),
            'created' => $created,
            'failed' => count($rows) - $created,
            'rows' => $out,
        ]]);
    }

    /** @return list<array<string, mixed>> */
    private function parse(Request $request, string $contractorId): array
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
            $this->fail('La plantilla no es la vigente (versión '.self::VERSION.'). Descarga la plantilla nueva y copia tus datos en ella.');
        }
        $map = null;
        $headerRow = 0;
        foreach ($sheet as $n => $cells) {
            $norm = array_map(fn ($x) => $this->norm((string) $x), $cells);
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
            $this->fail('No encontramos la fila de títulos de la plantilla (Número de documento, Nombres, Apellido paterno…).');
        }
        $data = array_filter($sheet, fn ($n) => $n > $headerRow, ARRAY_FILTER_USE_KEY);
        $data = array_filter($data, fn ($cells) => implode('', array_map('trim', array_map('strval', $cells))) !== '');
        if ($data === []) {
            $this->fail('La plantilla no tiene trabajadores: completa una fila por trabajador.');
        }
        if (count($data) > self::MAX_ROWS) {
            $this->fail('Carga hasta '.self::MAX_ROWS.' trabajadores por archivo.');
        }

        $rows = [];
        $seen = [];
        foreach ($data as $n => $cells) {
            $get = fn (string $k) => isset($map[$k]) ? trim((string) ($cells[$map[$k]] ?? '')) : '';
            $errors = [];
            $rawType = $get('document_type');
            $type = self::DOC_TYPES[strtoupper($rawType ?: 'DNI')] ?? null;
            if ($type === null) {
                $errors[] = 'Tipo de documento no válido: usa DNI, CE, PTP o PASAPORTE.';
                $type = 'DNI';
            }
            $number = strtoupper(preg_replace('/[\s.\-]/', '', $get('document_number')));
            if ($number === '') {
                $errors[] = 'Falta el número de documento.';
            } elseif ($type === 'DNI' && ! preg_match('/^\d{8}$/', $number)) {
                $errors[] = 'DNI debe tener 8 dígitos.';
            } elseif ($type !== 'DNI' && ! preg_match('/^[A-Z0-9]{5,20}$/', $number)) {
                $errors[] = 'El documento admite de 5 a 20 letras o números.';
            }
            if ($number !== '') {
                $key = $type.'|'.$number;
                if (isset($seen[$key])) {
                    $errors[] = "Documento duplicado en el archivo (fila {$seen[$key]}).";
                } else {
                    $seen[$key] = $n;
                    $hash = hash_hmac('sha256', 'contractor-doc|'.$type.'|'.$number, (string) config('app.key'));
                    if (DB::selectOne("select 1 as ok from contractor_workers where contractor_id = ? and document_number_hash = decode(?, 'hex') and deleted_at is null", [$contractorId, $hash])) {
                        $errors[] = 'Ese trabajador ya está registrado en tu empresa.';
                    }
                }
            }
            $first = $get('first_name');
            $paternal = $get('paternal_last_name');
            if ($first === '') {
                $errors[] = 'Faltan los nombres.';
            }
            if ($paternal === '') {
                $errors[] = 'Falta el apellido paterno.';
            }
            $phone = preg_replace('/[^\d+]/', '', $get('phone_e164'));
            if ($phone !== '' && preg_match('/^9\d{8}$/', $phone)) {
                $phone = '+51'.$phone;
            }
            if ($phone !== '' && ! preg_match('/^\+\d{8,15}$/', $phone)) {
                $errors[] = 'Celular no válido: usa 9 dígitos (987654321).';
            }
            $rows[] = [
                'row' => $n,
                'type' => $type,
                'number' => $number,
                'first_name' => mb_substr($first, 0, 100),
                'paternal_last_name' => mb_substr($paternal, 0, 100),
                'maternal_last_name' => mb_substr($get('maternal_last_name'), 0, 100),
                'position_text' => mb_substr($get('position_text'), 0, 150),
                'phone' => $phone ?: null,
                'errors' => $errors,
            ];
        }

        return $rows;
    }

    /** El documento se muestra enmascarado. */
    private function public(array $r): array
    {
        return [
            'row' => $r['row'],
            'document' => $r['number'] !== '' ? $r['type'].' ****'.substr($r['number'], -4) : '',
            'name' => trim($r['first_name'].' '.$r['paternal_last_name'].' '.$r['maternal_last_name']),
            'position' => $r['position_text'],
            'action' => $r['errors'] !== [] ? 'error' : 'create',
            'errors' => $r['errors'],
        ];
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim(preg_replace('/\(.*?\)|\*/', '', $s)));

        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    private function fail(string $message): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $message, ['errors' => ['file' => [$message]]]);
    }
}

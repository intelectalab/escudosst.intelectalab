<?php

namespace App\Modules\REG;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\Xlsx;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\FIR\SignatureService;
use App\Modules\REC\Registry;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET /reg/{type}.xlsx?from=AAAA-MM-DD&to=AAAA-MM-DD — registros obligatorios del SGSST (R.M. 050-2013-TR) armados con
 * los datos de los módulos, en Excel. Cada descarga queda en register_exports (quién, qué periodo, cuántas filas).
 * Los registros de salud solo muestran la aptitud (nunca diagnósticos).
 */
class RegistersController extends Controller
{
    public const TYPES = [
        'accidents' => 'Registro de accidentes de trabajo',
        'occupational_diseases' => 'Registro de enfermedades ocupacionales',
        'incidents' => 'Registro de incidentes peligrosos e incidentes',
        'emo' => 'Registro de exámenes médicos ocupacionales (aptitud)',
        'monitoring' => 'Registro de monitoreo de agentes',
        'inspections' => 'Registro de inspecciones internas de SST',
        'statistics' => 'Registro de estadísticas de SST',
        'emergency_equipment' => 'Registro de equipos de seguridad o emergencia',
        'training' => 'Registro de inducción, capacitación, entrenamiento y simulacros',
        'audits' => 'Registro de auditorías',
    ];

    public function index(): \Illuminate\Http\JsonResponse
    {
        $this->can('reg.register_exports.read');

        return response()->json(['data' => array_map(fn ($k, $v) => ['type' => $k, 'name' => $v], array_keys(self::TYPES), self::TYPES)]);
    }

    /**
     * GET /reg/{type}/preview?from=&to= — vista previa del registro (SCR-REG-02): cabecera de la empresa, columnas y las
     * primeras filas del mismo armado que el Excel. No deja constancia en el historial (solo la descarga la deja).
     */
    public function preview(Request $request, string $type): \Illuminate\Http\JsonResponse
    {
        $this->can('reg.register_exports.read');
        if (! isset(self::TYPES[$type])) {
            throw ApiProblem::notFound('Ese registro no existe.');
        }
        $from = (string) $request->query('from', date('Y').'-01-01');
        $to = (string) $request->query('to', date('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            throw ApiProblem::badRequest('Elige un periodo válido.');
        }
        $limit = max(1, min(500, (int) $request->query('limit', 10)));
        $site = $this->period($request, $type)[2];
        [$header, $rows] = $this->data($type, $from, $to, $site);
        $company = DB::selectOne('select legal_name, ruc, fiscal_address, economic_sector from companies where id = ?', [app(CompanyContext::class)->companyId]);
        $workers = (int) DB::selectOne("select count(*) as n from workers where deleted_at is null and status in ('active','on_leave')")->n;

        return response()->json(['data' => [
            'type' => $type,
            'title' => self::TYPES[$type],
            'company' => ['legal_name' => $company->legal_name, 'ruc' => trim((string) $company->ruc), 'address' => $company->fiscal_address,
                'activity' => $company->economic_sector, 'workers' => $workers],
            'period' => ['from' => $from, 'to' => $to],
            'header' => $header,
            'rows' => array_slice($rows, 0, $limit),
            'total' => count($rows),
        ]]);
    }

    public function export(Request $request, string $type): Response
    {
        $this->can('reg.register_exports.export');
        if (! isset(self::TYPES[$type])) {
            throw ApiProblem::notFound('Ese registro no existe.');
        }
        $from = (string) $request->query('from', date('Y').'-01-01');
        $to = (string) $request->query('to', date('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            throw ApiProblem::badRequest('Elige un periodo válido.');
        }
        [$header, $rows] = $this->data($type, $from, $to, $this->period($request, $type)[2]);
        $company = DB::selectOne('select legal_name, ruc from companies where id = ?', [app(CompanyContext::class)->companyId]);
        $sheet = [
            [mb_strtoupper(self::TYPES[$type])],
            ["{$company->legal_name} · RUC ".trim($company->ruc)],
            ['Periodo: '.date('d/m/Y', strtotime($from)).' al '.date('d/m/Y', strtotime($to)).' · Generado: '.now('America/Lima')->format('d/m/Y H:i')],
            [],
            $header,
            ...$rows,
        ];
        $bytes = Xlsx::build('Registro', $sheet, 5);
        DB::insert(
            "insert into register_exports (company_id, register_type, period_from, period_to, format, template_version, status, sha256, rows_count,
                requested_by, requested_at, generated_at, created_by, updated_by)
             values (?, ?, ?, ?, 'xlsx', 'rm050-v1', 'ready', ?, ?, ?, now(), now(), ?, ?)",
            [app(CompanyContext::class)->companyId, $type, $from, $to, hash('sha256', $bytes), count($rows), (string) auth()->id(), (string) auth()->id(), (string) auth()->id()]
        );

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"registro-{$type}-{$from}-{$to}.xlsx\"",
        ]);
    }

    /** Versión del formato referencial por defecto (RN-REG-06). La vigente se administra en el parámetro reg_format_version. */
    public const FORMAT_VERSION = 'rm050-v1';

    /**
     * RN-REG-06: los formatos son plantillas versionadas. La versión vigente es un parámetro con vigencia e historial
     * (reg_format_version); cambiarla crea las exportaciones nuevas con esa versión y no altera las anteriores.
     */
    public static function formatVersion(): string
    {
        $v = trim(\App\Core\Support\LegalParams::text('reg_format_version', self::FORMAT_VERSION));

        return $v !== '' ? mb_substr($v, 0, 40) : self::FORMAT_VERSION;
    }

    /**
     * GET /reg/emo/medical.xlsx?from=&to= — RN-REG-04: versión completa del registro de EMO (con diagnósticos, restricciones y
     * recomendaciones) solo para el médico ocupacional, con su contraseña confirmada (cabecera X-Medical-Reauth). El acceso
     * queda en los registros de la base principal y de la base médica, y una copia cifrada del archivo queda en el almacén médico.
     */
    public function emoMedical(Request $request): Response
    {
        $this->can('reg.register_exports.create');
        if (! in_array('emo.medical_exams.read_sensitive', Permissions::effective(), true)) {
            \App\Modules\VUL\MedicalAccess::denied('Registro de EMO completo sin permiso médico');
            throw ApiProblem::forbidden('La versión completa del registro de exámenes médicos solo la genera el médico ocupacional.');
        }
        [$from, $to] = $this->period($request, 'emo');
        $reauth = \App\Modules\VUL\MedicalAccess::reauthId($request);
        \App\Modules\VUL\MedicalAccess::audit('medical_export', null, null, 'export', $reauth, "Registro de EMO completo {$from} a {$to}");
        $dec = function ($v) {
            if ($v === null || $v === '') {
                return '';
            }
            try {
                return (string) \App\Modules\EMO\MedicalCipher::decrypt(is_string($v) && str_starts_with($v, '"') ? (json_decode($v) ?? $v) : $v);
            } catch (\Throwable) {
                return (string) $v;
            }
        };
        $exams = \App\Modules\VUL\MedicalAccess::med($reauth, function ($db) use ($from, $to) {
            \App\Modules\VUL\MedicalAccess::log($db, 'medical_export', null, null, 'export', "Registro de EMO completo {$from} a {$to}");

            return $db->select("select worker_id, exam_on, exam_type, clinic_name, examining_doctor_name, examining_doctor_cmp, diagnoses, fitness_result, work_restrictions, recommendations, fitness_valid_until
                                  from medical_exams where deleted_at is null and status <> 'annulled' and exam_on between ? and ? order by exam_on", [$from, $to]);
        });
        $names = collect(DB::select('select w.id, w.full_name, w.document_last4, p.name as position from workers w left join positions p on p.id = w.current_position_id where w.id = any(?::uuid[])',
            ['{'.implode(',', array_unique(array_column($exams, 'worker_id'))).'}']))->keyBy('id');
        $type = ['pre_employment' => 'Preocupacional', 'periodic' => 'Periódico', 'exit' => 'De retiro', 'return_to_work' => 'De reintegro', 'position_change' => 'Por cambio de puesto', 'other' => 'Otro'];
        $rows = [[self::TYPES['emo'].' — versión completa (confidencial)'], ["Del {$from} al {$to}. Solo para el médico ocupacional. Formato ".self::formatVersion().'.'], [],
            ['Trabajador', 'Documento (últimos 4)', 'Puesto', 'Tipo de examen', 'Fecha', 'Centro médico', 'Médico (CMP)', 'Diagnósticos (CIE-10)', 'Aptitud', 'Restricciones', 'Recomendaciones', 'Vigente hasta']];
        foreach ($exams as $e) {
            $w = $names[$e->worker_id] ?? null;
            $dx = json_decode($dec($e->diagnoses), true);
            $dxText = is_array($dx) ? implode('; ', array_map(fn ($x) => trim(($x['code'] ?? '').' '.($x['description'] ?? '')), $dx)) : '';
            $rows[] = [$w?->full_name ?? '', $w?->document_last4 ?? '', $w?->position ?? '', $type[$e->exam_type] ?? (string) $e->exam_type, (string) $e->exam_on, (string) $e->clinic_name,
                trim($e->examining_doctor_name.($e->examining_doctor_cmp ? " ({$e->examining_doctor_cmp})" : '')), $dxText,
                $this->label('fitness_status', 'status', $e->fitness_result), $dec($e->work_restrictions), $dec($e->recommendations), (string) $e->fitness_valid_until];
        }
        $bytes = Xlsx::build('EMO completo', $rows, 4);
        $disk = (string) config('escudo.medical.files_disk', 'local');
        \Illuminate\Support\Facades\Storage::disk($disk)->put('medical/'.app(CompanyContext::class)->companyId.'/registros/emo-completo-'.$from.'-'.$to.'-'.now()->format('YmdHis').'.bin',
            \App\Modules\EMO\MedicalCipher::encrypter()->encryptString($bytes));

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"registro-emo-completo-{$from}-{$to}.xlsx\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Estados de una versión generada (§9). */
    private const VERSION_STATES = ['generated', 'pending_signature', 'signed', 'superseded'];

    /**
     * GET /reg/panel — SCR-REG-01/04: sedes para el filtro, responsables que firman (con «reg.register_exports.approve»),
     * usuarios de la empresa (firma del empleador, cuando el formato la pide) y las versiones generadas con su estado de firma.
     */
    public function panel(): JsonResponse
    {
        $this->can('reg.register_exports.read');
        $company = app(CompanyContext::class)->companyId;

        return response()->json(['data' => [
            'sites' => DB::select('select id, name from sites where deleted_at is null order by name'),
            'signers' => DB::select(
                "select distinct u.id, u.full_name from memberships ms join users u on u.id = ms.user_id join role_permissions rp on rp.role_id = ms.role_id
                   join permissions p on p.id = rp.permission_id and p.code = 'reg.register_exports.approve'
                  where ms.company_id = ? and ms.status = 'active' and ms.deleted_at is null order by u.full_name", [$company]),
            'members' => DB::select(
                "select distinct u.id, u.full_name from memberships ms join users u on u.id = ms.user_id
                  where ms.company_id = ? and ms.status = 'active' and ms.deleted_at is null and not ms.is_support_session order by u.full_name", [$company]),
            'versions' => DB::select(
                "select x.id, x.register_type, x.period_from, x.period_to, x.status, x.data_hash, x.rows_count, x.generated_at, s.name as site, u.full_name as generated_by,
                        (select count(*) from signature_requests r where r.subject_type = 'register_exports' and r.subject_id = x.id and r.deleted_at is null and r.status = 'signed') as signed,
                        (select count(*) from signature_requests r where r.subject_type = 'register_exports' and r.subject_id = x.id and r.deleted_at is null and r.status <> 'cancelled') as signers
                   from register_exports x left join sites s on s.id = x.site_id left join users u on u.id = x.requested_by
                  where x.deleted_at is null and x.status = any(?::text[]) order by x.generated_at desc limit 200", ['{'.implode(',', self::VERSION_STATES).'}']),
            'medical' => in_array('emo.medical_exams.read_sensitive', Permissions::effective(), true) && in_array('reg.register_exports.create', Permissions::effective(), true),
        ]]);
    }

    /**
     * GET /reg/{type}/check?from=&to=&site_id= — validación previa (SCR-REG-03, RN-REG-02): registros de origen con campos
     * obligatorios faltantes y el enlace para completarlos.
     */
    public function check(Request $request, string $type): JsonResponse
    {
        $this->can('reg.register_exports.read');
        [$from, $to, $site] = $this->period($request, $type);

        return response()->json(['data' => ['missing' => $this->missing($type, $from, $to, $site)]]);
    }

    /**
     * POST /reg/{type}/generate {from, to, site_id?, signer_user_id, employer_user_id?} — SCR-REG-02: genera una versión del
     * registro. Se bloquea si faltan campos obligatorios (RN-REG-02); congela el encabezado del empleador al corte (RN-REG-08),
     * las columnas y las filas; guarda format_version, data_hash y conteo; marca «reemplazada» la versión anterior del mismo
     * periodo y sede (RN-REG-03) y pide la firma del responsable y, si se indica, del empleador por FIR (RN-REG-05).
     */
    public function generate(Request $request, string $type, AccountsService $accounts): JsonResponse
    {
        $this->can('reg.register_exports.generate');
        [$from, $to, $site] = $this->period($request, $type);
        $d = $request->validate([
            'signer_user_id' => ['required', 'uuid'],
            'employer_user_id' => ['nullable', 'uuid', 'different:signer_user_id'],
        ], ['signer_user_id.required' => 'Elige al responsable que firma el registro.', 'employer_user_id.different' => 'El empleador debe ser otra persona.']);
        $company = app(CompanyContext::class)->companyId;
        if (! DB::selectOne(
            "select 1 as ok from memberships ms join role_permissions rp on rp.role_id = ms.role_id join permissions p on p.id = rp.permission_id
              where ms.user_id = ? and ms.company_id = ? and ms.status = 'active' and ms.deleted_at is null and p.code = 'reg.register_exports.approve'",
            [$d['signer_user_id'], $company])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'El responsable elegido no firma registros obligatorios.',
                ['errors' => ['signer_user_id' => ['El responsable elegido no firma registros obligatorios.']]]);
        }
        if (! empty($d['employer_user_id']) && ! DB::selectOne("select 1 as ok from memberships where user_id = ? and company_id = ? and status = 'active' and deleted_at is null",
            [$d['employer_user_id'], $company])) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Esa persona no es usuaria de la empresa.', ['errors' => ['employer_user_id' => ['Esa persona no es usuaria de la empresa.']]]);
        }
        $missing = $this->missing($type, $from, $to, $site);
        if ($missing) {
            throw new ApiProblem(422, 'register_incomplete', 'Faltan datos', 'Completa los registros de origen antes de generar.', ['missing' => $missing]);
        }
        [$header, $rows] = $this->data($type, $from, $to, $site);
        $snapshot = ['title' => self::TYPES[$type], 'employer' => $this->employer($to), 'site' => $site ? DB::selectOne('select name from sites where id = ?', [$site])->name : null,
            'header' => $header, 'rows' => $rows];
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $json);
        $uid = (string) auth()->id();

        $id = DB::transaction(function () use ($company, $type, $from, $to, $site, $json, $hash, $rows, $uid, $d, $accounts) {
            $id = DB::selectOne(
                "insert into register_exports (company_id, register_type, period_from, period_to, site_id, format, template_version, format_version, status, snapshot,
                    data_hash, rows_count, requested_by, requested_at, generated_at, created_by, updated_by)
                 values (?, ?, ?, ?, ?, 'pdf', ?, ?, 'generated', ?::jsonb, ?, ?, ?, now(), now(), ?, ?) returning id",
                [$company, $type, $from, $to, $site, self::formatVersion(), self::formatVersion(), $json, $hash, count($rows), $uid, $uid, $uid]
            )->id;
            // RN-REG-03: la versión anterior del mismo registro, periodo y sede queda «reemplazada»; sus firmas pendientes se anulan.
            $old = array_column(DB::select(
                "select id from register_exports where register_type = ? and period_from = ? and period_to = ? and site_id is not distinct from ?::uuid
                    and status = any(?::text[]) and id <> ? and deleted_at is null",
                [$type, $from, $to, $site, '{generated,pending_signature,signed}', $id]), 'id');
            foreach ($old as $o) {
                DB::update("update register_exports set status = 'superseded', superseded_by = ?, updated_by = ?, updated_at = now() where id = ?", [$id, $uid, $o]);
                DB::update("update signature_requests set status = 'cancelled', updated_by = ? where subject_type = 'register_exports' and subject_id = ? and status = 'pending'", [$uid, $o]);
            }
            $docHash = SignatureService::hash('register_exports', DB::selectOne('select * from register_exports where id = ?', [$id]));
            $order = 0;
            foreach (array_filter(['responsible' => $d['signer_user_id'], 'approver' => $d['employer_user_id'] ?? null]) as $role => $signer) {
                $req = DB::selectOne(
                    "insert into signature_requests (company_id, subject_type, subject_id, subject_module, document_hash, signer_user_id, role_in_document, sequence_order,
                        status, requested_by, created_by, updated_by)
                     values (?, 'register_exports', ?, 'REG', decode(?, 'hex'), ?, ?, ?, 'pending', ?, ?, ?) returning id",
                    [$company, $id, $docHash, $signer, $role, ++$order, $uid, $uid, $uid]
                )->id;
                \App\Modules\NOT\FichaEvents::signatureRequested($req);
            }
            DB::update("update register_exports set status = 'pending_signature' where id = ?", [$id]);
            $accounts->audit($uid, 'register_generated', 'register_exports', $id, ['type' => $type, 'from' => $from, 'to' => $to, 'site_id' => $site, 'data_hash' => $hash, 'superseded' => $old]);

            return $id;
        });

        return response()->json(['data' => $this->version($id)], 201);
    }

    /** GET /reg/exports/{id} — versión generada con sus datos congelados y sus firmas (SCR-REG-04, impresión en PDF). */
    public function show(string $id): JsonResponse
    {
        $this->can('reg.register_exports.read');

        return response()->json(['data' => $this->version($id)]);
    }

    /** GET /reg/exports/{id}.xlsx — Excel de una versión generada, desde sus datos congelados (no se vuelve a consultar el origen). */
    public function versionXlsx(string $id): Response
    {
        $this->can('reg.register_exports.export');
        $v = $this->version($id);
        $e = $v['snapshot']['employer'];
        $sheet = [
            [mb_strtoupper($v['snapshot']['title'])],
            ["{$e['legal_name']} · RUC {$e['ruc']}".($e['address'] ? " · {$e['address']}" : '').($e['activity'] ? " · {$e['activity']}" : '')." · N.° de trabajadores: {$e['workers']}"],
            ['Periodo: '.date('d/m/Y', strtotime($v['period_from'])).' al '.date('d/m/Y', strtotime($v['period_to'])).($v['snapshot']['site'] ? ' · Sede: '.$v['snapshot']['site'] : '')
                .' · Generado: '.(new \DateTimeImmutable($v['generated_at']))->setTimezone(new \DateTimeZone('America/Lima'))->format('d/m/Y H:i').' · Huella: '.$v['data_hash']],
            [],
            $v['snapshot']['header'],
            ...$v['snapshot']['rows'],
        ];

        return response(Xlsx::build('Registro', $sheet, 5), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"registro-{$v['register_type']}-{$v['period_from']}-{$v['period_to']}.xlsx\"",
        ]);
    }

    /** @return array<string, mixed> */
    private function version(string $id): array
    {
        if (! Str::isUuid($id)) {
            throw ApiProblem::notFound('Esa versión del registro no existe.');
        }
        $v = DB::selectOne(
            "select x.id, x.register_type, x.period_from, x.period_to, x.site_id, x.status, x.format_version, x.data_hash, x.rows_count, x.generated_at, x.snapshot,
                    x.superseded_by, u.full_name as generated_by
               from register_exports x left join users u on u.id = x.requested_by
              where x.id = ? and x.deleted_at is null and x.status = any(?::text[])", [$id, '{'.implode(',', self::VERSION_STATES).'}']);
        if (! $v) {
            throw ApiProblem::notFound('Esa versión del registro no existe.');
        }
        $v = (array) $v;
        $v['snapshot'] = json_decode($v['snapshot'], true);
        $v['signatures'] = array_map(function ($s) {
            $s->image = $s->image ? 'data:'.$s->mime_type.';base64,'.str_replace(["\n", "\r"], '', $s->image) : null;
            unset($s->mime_type);

            return $s;
        }, DB::select(
            "select r.role_in_document as role, r.status, coalesce(sg.signer_name, u.full_name) as signer_name, sg.signed_at, sg.method, k.mime_type, encode(k.image, 'base64') as image
               from signature_requests r left join users u on u.id = r.signer_user_id left join signatures sg on sg.signature_request_id = r.id and ".\App\Modules\FIR\SignatureService::valid('sg')."
               left join signature_strokes k on k.signature_id = sg.id
              where r.subject_type = 'register_exports' and r.subject_id = ? and r.deleted_at is null and r.status <> 'cancelled' order by r.sequence_order", [$id]));

        return $v;
    }

    /**
     * RN-REG-08: datos del empleador desde TEN; el número de trabajadores es el de la fecha de corte (ingresados hasta esa
     * fecha y sin cese anterior). Quedan congelados en la versión generada.
     *
     * @return array<string, mixed>
     */
    private function employer(string $to): array
    {
        $c = DB::selectOne('select legal_name, ruc, fiscal_address, economic_sector, ciiu_code from companies where id = ?', [app(CompanyContext::class)->companyId]);
        $workers = (int) DB::selectOne(
            "select count(*) as n from workers where deleted_at is null and (hire_on is null or hire_on <= ?) and (termination_on is null or termination_on > ?)",
            [$to, $to])->n;

        return ['legal_name' => $c->legal_name, 'ruc' => trim((string) $c->ruc), 'address' => $c->fiscal_address,
            'activity' => trim(($c->economic_sector ?? '').($c->ciiu_code ? " (CIIU {$c->ciiu_code})" : '')) ?: null, 'workers' => $workers, 'as_of' => $to];
    }

    /**
     * RN-REG-02: campos obligatorios del formato que faltan en los registros de origen. Los nombrados en las fichas: datos de
     * la lesión de cada lesionado en un accidente (M-ACC RN-ACC-06: tipo de lesión, parte del cuerpo y agente; M-REG
     * criterio 1). Lo que ya se valida al registrar puede faltar en datos importados o anteriores a esa regla. Los demás
     * campos obligatorios de cada formato están por confirmar por el especialista SST.
     *
     * @return list<array{id: string, code: string|null, missing: list<string>, link: string}>
     */
    private function missing(string $type, string $from, string $to, ?string $site): array
    {
        if ($type !== 'accidents') {
            return [];
        }
        $fields = ['injury_type' => 'Tipo de lesión', 'body_part' => 'Parte del cuerpo afectada', 'injury_agent' => 'Agente de la lesión'];
        $rows = DB::select(
            "select e.id, e.code, coalesce(w.full_name, p.external_person_name, 'persona sin nombre') as person, p.injury_type, p.body_part, p.injury_agent
               from sst_events e join event_people p on p.event_id = e.id and p.deleted_at is null and p.person_role = 'injured'
               left join workers w on w.id = p.worker_id
              where e.deleted_at is null and e.status <> 'discarded' and e.event_type = any(?::text[])
                and (e.occurred_at at time zone 'America/Lima')::date between ? and ?"
                .($site ? ' and e.site_id = ?' : '').' order by e.occurred_at, e.code',
            array_values(array_filter(['{fatal_accident,disabling_accident,minor_accident}', $from, $to, $site])));
        $out = [];
        foreach ($rows as $r) {
            $lack = array_values(array_filter(array_map(fn ($f, $l) => trim((string) $r->{$f}) === '' ? "{$l} ({$r->person})" : null, array_keys($fields), $fields)));
            if ($lack) {
                $out[$r->id] ??= ['id' => $r->id, 'code' => $r->code, 'missing' => [], 'link' => '/m/acc?t=sst_events&open='.$r->id];
                array_push($out[$r->id]['missing'], ...$lack);
            }
        }

        return array_values($out);
    }

    /** @return array{0: string, 1: string, 2: string|null} */
    private function period(Request $request, string $type): array
    {
        if (! isset(self::TYPES[$type])) {
            throw ApiProblem::notFound('Ese registro no existe.');
        }
        $from = (string) $request->input('from', date('Y').'-01-01');
        $to = (string) $request->input('to', date('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            throw ApiProblem::badRequest('Elige un periodo válido.');
        }
        $site = $request->input('site_id') ?: null;
        if ($site !== null && (! Str::isUuid((string) $site) || ! DB::selectOne('select 1 as ok from sites where id = ? and deleted_at is null', [$site]))) {
            throw ApiProblem::badRequest('Esa sede no existe.');
        }

        return [$from, $to, $site];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private function data(string $type, string $from, string $to, ?string $site = null): array
    {
        // Filtro por sede (M-REG §11: por sede o consolidado); sin sede, toda la empresa.
        $sf = fn (string $col) => $site ? " and {$col} = '".$site."'" : '';
        $d = fn ($v) => $v ? date('d/m/Y', strtotime((string) $v)) : '';
        $dt = fn ($v) => $v ? (new \DateTimeImmutable((string) $v))->setTimezone(new \DateTimeZone('America/Lima'))->format('d/m/Y H:i') : '';
        $range = [$from, $to];
        switch ($type) {
            case 'accidents':
            case 'incidents':
            case 'occupational_diseases':
                $types = ['accidents' => ['fatal_accident', 'disabling_accident', 'minor_accident'], 'incidents' => ['dangerous_incident', 'incident'],
                    'occupational_diseases' => ['occupational_disease']][$type];
                $rows = DB::select(
                    "select e.code, e.event_type, e.occurred_at, s.name as site, a.name as area, e.description, e.lost_days, e.injured_count,
                            e.notification_deadline_at, e.status,
                            (select string_agg(coalesce(w.full_name, p.external_person_name, '') || ' (' || p.person_role || coalesce(', ' || p.body_part, '') || ')', '; ')
                               from event_people p left join workers w on w.id = p.worker_id where p.event_id = e.id and p.deleted_at is null) as people,
                            (select string_agg(n.notification_number || ' ' || to_char(n.notified_at at time zone 'America/Lima', 'DD/MM/YYYY'), '; ')
                               from event_notifications n where n.event_id = e.id and n.status in ('sent','acknowledged') and n.deleted_at is null) as notified,
                            (select i.code from investigations i where i.event_id = e.id and i.deleted_at is null limit 1) as investigation,
                            (select string_agg(concat_ws(' · ', w.full_name, '****' || w.document_last4, ps.name, p.disability_type), '; ')
                               from event_people p join workers w on w.id = p.worker_id left join positions ps on ps.id = w.current_position_id
                              where p.event_id = e.id and p.deleted_at is null and p.person_role in ('injured','affected','ill')) as workers,
                            (select string_agg(c.cause_level || ': ' || c.description, '; ' order by c.cause_level)
                               from investigation_causes c join investigations i on i.id = c.investigation_id
                              where i.event_id = e.id and i.deleted_at is null and c.deleted_at is null) as causes,
                            (select string_agg(concat_ws(' · ', ca.code, ca.title, coalesce(u.full_name, wr.full_name), to_char(ca.due_on, 'DD/MM/YYYY'), ca.status), '; ')
                               from corrective_actions ca left join users u on u.id = ca.responsible_user_id left join workers wr on wr.id = ca.responsible_worker_id
                              where ca.deleted_at is null and ca.status <> 'cancelled' and (ca.source_id = e.id or ca.source_id in (select i.id from investigations i where i.event_id = e.id))) as actions
                       from sst_events e left join sites s on s.id = e.site_id left join areas a on a.id = e.area_id
                      where e.deleted_at is null and e.status <> 'discarded' and e.event_type = any(?::text[])
                        and (e.occurred_at at time zone 'America/Lima')::date between ? and ?".$sf('e.site_id').' order by e.occurred_at',
                    ['{'.implode(',', $types).'}', ...$range]
                );

                $causeLevels = fn (?string $t) => $t ? strtr($t, ['immediate:' => 'Inmediata:', 'basic:' => 'Básica:', 'root:' => 'Raíz:', 'lack_of_control:' => 'Falta de control:']) : '';
                $disability = fn (?string $t) => $t ? strtr($t, ['total_temporary' => 'Total temporal', 'partial_temporary' => 'Parcial temporal', 'partial_permanent' => 'Parcial permanente',
                    'total_permanent' => 'Total permanente', ' · none' => '']) : '';
                $status = ['pending_verification' => 'por verificar', 'in_progress' => 'en curso', 'verified' => 'verificada', 'open' => 'abierta', 'overdue' => 'vencida'];

                return [['Código', 'Tipo', 'Fecha y hora', 'Sede', 'Área', 'Descripción', 'Trabajador(es) afectado(s) (DNI, puesto, incapacidad)', 'Personas involucradas',
                    'Días perdidos', 'Lesionados', 'Plazo de notificación', 'Notificado (n.° y fecha)', 'Investigación', 'Causas', 'Medidas correctivas (responsable, fecha, estado)', 'Estado'],
                    array_map(fn ($r) => [$r->code, $this->label('sst_events', 'event_type', $r->event_type).($r->event_type === 'fatal_accident' ? ' (MORTAL)' : ''), $dt($r->occurred_at),
                        $r->site, $r->area, $r->description, $disability($r->workers), $this->roles($r->people), (int) $r->lost_days, (int) $r->injured_count,
                        $dt($r->notification_deadline_at), $r->notified, $r->investigation, $causeLevels($r->causes), $r->actions ? strtr($r->actions, $status) : '',
                        $this->label('sst_events', 'status', $r->status)], $rows)];
            case 'emo':
                $rows = DB::select(
                    "select w.full_name, w.document_last4, p.name as position, f.status, f.valid_from, f.valid_until, f.issued_at
                       from fitness_status f join workers w on w.id = f.worker_id left join positions p on p.id = w.current_position_id
                      where f.deleted_at is null and coalesce(f.valid_from, f.issued_at::date) between ? and ?".$sf('w.current_site_id').' order by w.full_name', $range);

                return [['Trabajador', 'Documento (últimos 4)', 'Puesto', 'Aptitud', 'Vigente desde', 'Vigente hasta', 'Emitido'],
                    array_map(fn ($r) => [$r->full_name, $r->document_last4, $r->position, $this->label('fitness_status', 'status', $r->status), $d($r->valid_from), $d($r->valid_until), $dt($r->issued_at)], $rows)];
            case 'monitoring':
                $rows = DB::select(
                    "select m.measured_at, t.name as agent, pt.name as point, m.value, m.unit, l.limit_value, l.unit as limit_unit, m.result, m.equipment, m.equipment_calibration_on
                       from measurements m left join agent_types t on t.id = m.agent_type_id left join monitoring_points pt on pt.id = m.monitoring_point_id
                       left join exposure_limits l on l.id = m.exposure_limit_id
                      where m.deleted_at is null and m.status <> 'voided' and (m.measured_at at time zone 'America/Lima')::date between ? and ?".$sf('pt.site_id').' order by m.measured_at', $range);

                return [['Fecha', 'Agente', 'Punto', 'Valor', 'Unidad', 'Límite', 'Unidad del límite', 'Resultado', 'Equipo', 'Calibración'],
                    array_map(fn ($r) => [$dt($r->measured_at), $r->agent, $r->point, $r->value === null ? null : (float) $r->value, $r->unit,
                        $r->limit_value === null ? null : (float) $r->limit_value, $r->limit_unit, $this->label('measurements', 'result', $r->result), $r->equipment, $d($r->equipment_calibration_on)], $rows)];
            case 'inspections':
                $rows = DB::select(
                    "select i.code, i.title, i.inspection_type, s.name as site, i.scheduled_on, i.completed_at, i.score_pct, i.status,
                            (select count(*) from findings f where f.inspection_id = i.id and f.deleted_at is null) as findings, i.summary
                       from inspections i left join sites s on s.id = i.site_id
                      where i.deleted_at is null and i.scheduled_on between ? and ?".$sf('i.site_id').' order by i.scheduled_on', $range);

                return [['Código', 'Título', 'Tipo', 'Sede', 'Programada', 'Realizada', 'Puntaje (%)', 'Hallazgos', 'Estado', 'Resumen'],
                    array_map(fn ($r) => [$r->code, $r->title, $this->label('inspections', 'inspection_type', $r->inspection_type), $r->site, $d($r->scheduled_on), $dt($r->completed_at),
                        $r->score_pct === null ? null : (float) $r->score_pct, (int) $r->findings, $this->label('inspections', 'status', $r->status), $r->summary], $rows)];
            case 'statistics':
                $rows = DB::select(
                    "select m.period_year, m.period_month, coalesce(s.name, 'Toda la empresa') as site, m.worker_count, m.hours_worked, m.accidents_fatal, m.accidents_disabling,
                            m.accidents_minor, m.dangerous_incidents, m.incidents, m.occupational_diseases, m.lost_days, m.frequency_index, m.severity_index, m.accident_rate_index, m.is_closed
                       from monthly_statistics m left join sites s on s.id = m.site_id
                      where m.deleted_at is null and make_date(m.period_year, m.period_month, 1) between date_trunc('month', ?::date) and ?::date".$sf('m.site_id')."
                      order by m.period_year, m.period_month, s.name nulls last", $range);

                return [['Año', 'Mes', 'Sede', 'Trabajadores', 'Horas-hombre', 'Mortales', 'Incapacitantes', 'Leves', 'Incidentes peligrosos', 'Incidentes', 'Enf. ocupacionales',
                    'Días perdidos', 'Índice de frecuencia', 'Índice de gravedad', 'Índice de accidentabilidad', 'Mes cerrado'],
                    array_map(fn ($r) => [(int) $r->period_year, (int) $r->period_month, $r->site, (int) $r->worker_count, (float) $r->hours_worked, (int) $r->accidents_fatal,
                        (int) $r->accidents_disabling, (int) $r->accidents_minor, (int) $r->dangerous_incidents, (int) $r->incidents, (int) $r->occupational_diseases, (int) $r->lost_days,
                        $r->frequency_index === null ? null : (float) $r->frequency_index, $r->severity_index === null ? null : (float) $r->severity_index,
                        $r->accident_rate_index === null ? null : (float) $r->accident_rate_index, $r->is_closed ? 'Sí' : 'No'], $rows)];
            case 'emergency_equipment':
                $rows = DB::select(
                    "select e.code, e.equipment_type, s.name as site, e.location_text, c.checked_at, c.check_type, c.result, c.next_due_on, c.provider_name, c.comments
                       from equipment_checks c join emergency_equipment e on e.id = c.equipment_id left join sites s on s.id = e.site_id
                      where c.deleted_at is null and (c.checked_at at time zone 'America/Lima')::date between ? and ?".$sf('e.site_id').' order by c.checked_at', $range);

                return [['Equipo', 'Tipo', 'Sede', 'Ubicación', 'Fecha de revisión', 'Revisión', 'Resultado', 'Próxima', 'Proveedor', 'Comentarios'],
                    array_map(fn ($r) => [$r->code, $this->label('emergency_equipment', 'equipment_type', $r->equipment_type), $r->site, $r->location_text, $dt($r->checked_at),
                        $this->label('equipment_checks', 'check_type', $r->check_type), $this->label('equipment_checks', 'result', $r->result), $d($r->next_due_on),
                        $r->provider_name, $r->comments], $rows)];
            case 'training':
                $rows = DB::select(
                    "select t.code, t.title, t.training_type, s.starts_at, coalesce(t.instructor_name, s.instructor_name) as instructor, w.full_name, w.document_last4, a.hours, a.attended
                       from training_attendances a join training_sessions s on s.id = a.session_id join trainings t on t.id = s.training_id left join workers w on w.id = a.worker_id
                      where a.deleted_at is null and (s.starts_at at time zone 'America/Lima')::date between ? and ?".$sf('s.site_id')."
                     union all
                     select d.code, d.title, 'drill', d.executed_at, d.external_entity, null, null, null, true from drills d
                      where d.deleted_at is null and d.status in ('executed','evaluated') and (d.executed_at at time zone 'America/Lima')::date between ? and ?".$sf('d.site_id')
                    // M-CH5 RN-CH5-07: si la empresa lo configura, las charlas realizadas entran como sensibilización con su duración.
                    .(\App\Core\Support\LegalParams::flag('talks_count_as_training', false) ? "
                     union all
                     select null, k.topic, 'talk', coalesce(k.started_at, k.talk_on::timestamptz), coalesce(su.full_name, sw.full_name), w.full_name, w.document_last4,
                            round(coalesce(k.duration_minutes, 0) / 60.0, 2), true
                       from talk_attendances t join safety_talks k on k.id = t.safety_talk_id and k.status = 'held' and k.deleted_at is null
                       left join workers w on w.id = t.worker_id left join users su on su.id = k.speaker_user_id left join workers sw on sw.id = k.speaker_worker_id
                      where t.deleted_at is null and (t.signature_id is not null or t.method <> 'signature') and k.talk_on between ? and ?".$sf('k.site_id') : '')."
                     order by 4", \App\Core\Support\LegalParams::flag('talks_count_as_training', false) ? [...$range, ...$range, ...$range] : [...$range, ...$range]);

                return [['Código', 'Tema', 'Tipo', 'Fecha', 'Capacitador / entidad', 'Participante', 'Documento (últimos 4)', 'Horas', 'Asistió'],
                    array_map(fn ($r) => [$r->code, $r->title, ($r->training_type === 'drill' ? 'Simulacro' : ($r->training_type === 'talk' ? 'Sensibilización (charla de 5 minutos)' : $this->label('trainings', 'training_type', $r->training_type))), $dt($r->starts_at), $r->instructor, $r->full_name, $r->document_last4,
                        $r->hours === null ? null : (float) $r->hours, $r->attended ? 'Sí' : 'No'], $rows)];
            case 'audits':
                $rows = DB::select(
                    "select a.code, a.audit_type, a.standard, a.starts_on, a.ends_on, a.lead_auditor_name, a.auditor_registration, a.status,
                            count(f.*) filter (where f.finding_type in ('major_nc','minor_nc')) as ncs, count(f.*) as findings, a.conclusions,
                            string_agg(case when f.finding_type in ('major_nc','minor_nc') then
                              (case f.finding_type when 'major_nc' then 'NC mayor: ' else 'NC menor: ' end) || f.description
                              || coalesce(' → ' || (select ca.code || ' ' || ca.title || ' (' || to_char(ca.due_on, 'DD/MM/YYYY') || ')' from corrective_actions ca where ca.id = f.corrective_action_id), '')
                            end, '; ') as nc_detail
                       from audits a left join audit_findings f on f.audit_id = a.id and f.deleted_at is null
                      where a.deleted_at is null and a.starts_on between ? and ?".$sf('a.site_id').' group by a.id order by a.starts_on', $range);

                return [['Código', 'Tipo', 'Norma', 'Inicio', 'Fin', 'Auditor líder', 'Registro del auditor', 'Estado', 'No conformidades', 'Hallazgos',
                    'No conformidades y medidas correctivas', 'Conclusiones'],
                    array_map(fn ($r) => [$r->code, $this->label('audits', 'audit_type', $r->audit_type), $this->label('audits', 'standard', $r->standard), $d($r->starts_on), $d($r->ends_on),
                        $r->lead_auditor_name, $r->auditor_registration, $this->label('audits', 'status', $r->status), (int) $r->ncs, (int) $r->findings, $r->nc_detail, $r->conclusions], $rows)];
        }

        return [[], []];
    }

    /** Texto en español de una opción (p. ej. disabling_accident → «Accidente incapacitante»). */
    private function label(string $table, string $field, ?string $value): string
    {
        static $maps = [];
        if ($value === null || $value === '') {
            return '';
        }
        $key = "{$table}.{$field}";
        if (! isset($maps[$key])) {
            $f = Registry::has($table) ? Registry::field(Registry::data()['resources'][$table], $field) : null;
            $maps[$key] = array_column($f['options'] ?? [], 'label', 'value');
        }

        return $maps[$key][$value] ?? $value;
    }

    /** Reemplaza códigos de rol de persona dentro de un texto armado en SQL («… (injured, Mano)»). */
    private function roles(?string $text): string
    {
        return $text ? preg_replace_callback('/\((injured|affected|ill|witness|involved)/', fn ($m) => '('.$this->label('event_people', 'person_role', $m[1]), $text) : '';
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no descarga los registros obligatorios.');
        }
    }
}

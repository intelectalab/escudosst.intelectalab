<?php

namespace App\Modules\TEN;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Primeros pasos de una empresa nueva (docs/07 §3.3 «onboarding», M-TEN, M-USR):
 *  GET   /ten/onboarding           avance: datos de la empresa, sedes, áreas y puestos, trabajadores, usuarios, línea base
 *  PATCH /ten/company              datos generales de la empresa activa (SCR-TEN-02; el RUC y la razón social no cambian aquí)
 *  POST  /ten/onboarding/dismiss   da por terminados los primeros pasos (companies.onboarding_completed_at)
 */
class OnboardingController extends Controller
{
    private const SECTORS = ['construction', 'mining', 'agroindustry', 'manufacturing', 'retail', 'services', 'office', 'health', 'education', 'transport', 'energy', 'other'];

    public function show(): JsonResponse
    {
        $id = app(CompanyContext::class)->companyId;
        $c = DB::selectOne(
            'select id, ruc, legal_name, trade_name, ciiu_code, economic_sector, risk_level, headcount_declared, fiscal_address, district, province, department,
                    phone, email, onboarding_completed_at from companies where id = ?', [$id]);
        if (! $c) {
            throw ApiProblem::notFound('La empresa no existe.');
        }
        $n = fn (string $sql) => (int) DB::selectOne($sql)->n;
        $counts = [
            'sites' => $n('select count(*) as n from sites where deleted_at is null'),
            'areas' => $n('select count(*) as n from areas where deleted_at is null'),
            'positions' => $n('select count(*) as n from positions where deleted_at is null'),
            'workers' => $n('select count(*) as n from workers where deleted_at is null'),
            'users' => $n("select count(*) as n from memberships where deleted_at is null and status in ('invited','active') and not is_support_session"),
            'assessments' => $n('select count(*) as n from baseline_assessments where deleted_at is null'),
        ];
        $companyDone = $c->economic_sector !== null && $c->risk_level !== null && ($c->fiscal_address ?? '') !== '';
        $steps = [
            ['code' => 'company', 'done' => $companyDone],
            ['code' => 'sites', 'done' => $counts['sites'] > 0],
            ['code' => 'positions', 'done' => $counts['areas'] > 0 && $counts['positions'] > 0],
            ['code' => 'workers', 'done' => $counts['workers'] > 0],
            ['code' => 'users', 'done' => $counts['users'] > 1],
            ['code' => 'baseline', 'done' => $counts['assessments'] > 0],
        ];
        $perms = Permissions::effective();

        return response()->json(['data' => [
            'company' => $c,
            'counts' => $counts,
            'steps' => $steps,
            'completed' => collect($steps)->every(fn ($s) => $s['done']),
            'dismissed_at' => $c->onboarding_completed_at,
            // Empresa nueva: sin sedes, puestos ni trabajadores (así se muestra en el inicio).
            'is_new' => $counts['sites'] === 0 || $counts['positions'] === 0 || $counts['workers'] === 0,
            'can' => [
                'company' => in_array('ten.companies.update', $perms, true),
                'sites' => in_array('ten.sites.create', $perms, true),
                'positions' => in_array('ten.positions.create', $perms, true),
                'workers' => in_array('trb.workers.create', $perms, true),
                'users' => in_array('usr.memberships.create', $perms, true),
                'baseline' => in_array('lba.baseline_assessments.create', $perms, true),
            ],
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'trade_name' => ['nullable', 'string', 'max:200'],
            'ciiu_code' => ['nullable', 'string', 'regex:/^\d{4,5}$/'],
            'economic_sector' => ['nullable', 'in:'.implode(',', self::SECTORS)],
            'risk_level' => ['nullable', 'in:low,medium,high,very_high'],
            'headcount_declared' => ['nullable', 'integer', 'min:0', 'max:500000'],
            'fiscal_address' => ['nullable', 'string', 'max:300'],
            'district' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:200'],
        ], [
            'ciiu_code.regex' => 'El código CIIU tiene 4 o 5 dígitos.',
            'economic_sector.in' => 'Elige un sector de la lista.',
            'risk_level.in' => 'Elige un nivel de riesgo de la lista.',
            'email.email' => 'El correo no es válido.',
            'headcount_declared.integer' => 'Indica el número de trabajadores.',
        ]);
        if ($d === []) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'No hay cambios que guardar.');
        }
        $id = app(CompanyContext::class)->companyId;
        $before = (array) DB::selectOne('select '.implode(', ', array_keys($d)).' from companies where id = ?', [$id]);
        $sets = implode(', ', array_map(fn ($k) => "{$k} = ?", array_keys($d)));
        DB::update("update companies set {$sets}, updated_at = now(), updated_by = ? where id = ?", [...array_values($d), (string) auth()->id(), $id]);
        $changes = [];
        foreach ($d as $k => $v) {
            if (($before[$k] ?? null) != $v) {
                $changes[$k] = ['from' => $before[$k] ?? null, 'to' => $v];
            }
        }
        if ($changes !== []) {
            $ctx = app(CompanyContext::class);
            DB::insert(
                "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes)
                 values (?, ?, ?, 'user', 'update', 'TEN', 'companies', ?, ?::jsonb)",
                [$id, (string) auth()->id(), $ctx->roleCode, $id, json_encode($changes, JSON_UNESCAPED_UNICODE)]
            );
        }

        return $this->show();
    }

    public function dismiss(): JsonResponse
    {
        DB::update('update companies set onboarding_completed_at = coalesce(onboarding_completed_at, now()), updated_at = now(), updated_by = ? where id = ?',
            [(string) auth()->id(), app(CompanyContext::class)->companyId]);

        return $this->show();
    }
}

<?php

namespace App\Modules\AUL;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Activación por empresa del aula y de la venta de cursos (M-AUL RN-AUL-20/21, M-VTA):
 *  GET /aul/settings   si la empresa tiene el aula y la venta incluidas en su plan y si las tiene activadas
 *  PUT /aul/settings   {aul?: bool, vta?: bool}: la venta exige el aula activa; el aula no se desactiva mientras la venta esté activa.
 *                      Desactivada, el aula queda en solo lectura y los certificados emitidos siguen verificables.
 */
class ModuleSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $this->can();

        return response()->json(['data' => $this->state()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->can();
        $d = $request->validate(['aul' => ['sometimes', 'boolean'], 'vta' => ['sometimes', 'boolean']]);
        $st = $this->state();
        $aul = array_key_exists('aul', $d) ? (bool) $d['aul'] : $st['aul']['company_enabled'];
        $vta = array_key_exists('vta', $d) ? (bool) $d['vta'] : $st['vta']['company_enabled'];
        if ($vta && ! $aul) {
            throw new ApiProblem(409, 'conflict', 'La venta necesita el aula', array_key_exists('aul', $d)
                ? 'No se puede desactivar el aula mientras la venta de cursos esté activa: desactiva primero la venta.'
                : 'Para vender cursos activa primero el aula virtual.');
        }
        foreach (['AUL' => $aul, 'VTA' => $vta] as $code => $on) {
            if (! $st[strtolower($code)]['in_plan'] && $on) {
                throw new ApiProblem(409, 'conflict', 'No está en el plan', ($code === 'AUL' ? 'El aula virtual' : 'La venta de cursos').' no está incluida en el plan de la empresa.');
            }
            // El estado del módulo acompaña la decisión de la empresa (incluido en el plan: activo o desactivado por la empresa).
            DB::connection('platform')->update("update feature_flags set company_enabled = ?,
                    mode = case when ? and mode = 'disabled_by_company' then 'enabled' when not ? and mode = 'enabled' then 'disabled_by_company' else mode end,
                    updated_at = now() where company_id = ? and module_code = ? and deleted_at is null",
                [$on ? 'true' : 'false', $on ? 'true' : 'false', $on ? 'true' : 'false', app(CompanyContext::class)->companyId, $code]);
        }
        $company = app(CompanyContext::class)->companyId;
        DB::connection('platform')->select('select refresh_feature_flags(?)', [$company]);
        DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, changes) values (?, ?, ?, 'user', 'update', 'AUL', 'feature_flags', ?::jsonb)",
            [$company, (string) auth()->id(), app(CompanyContext::class)->roleCode, json_encode(['aul' => $aul, 'vta' => $vta])]);

        return response()->json(['data' => $this->state()]);
    }

    private function state(): array
    {
        $rows = collect(DB::select("select module_code, mode, company_enabled from feature_flags where module_code in ('AUL', 'VTA') and deleted_at is null"))->keyBy('module_code');
        $one = fn (string $c) => ['mode' => $rows[$c]->mode ?? 'locked', 'company_enabled' => (bool) ($rows[$c]->company_enabled ?? false),
            'in_plan' => in_array($rows[$c]->mode ?? 'locked', ['enabled', 'disabled_by_company'], true)];

        return ['aul' => $one('AUL'), 'vta' => $one('VTA')];
    }

    private function can(): void
    {
        if (! in_array('ten.companies.update', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no cambia los módulos de la empresa.');
        }
    }
}

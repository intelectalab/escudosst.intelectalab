<?php

namespace App\Modules\NOT;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de alerta por evento (M-NOT SCR-NOT-01, RN-NOT-03):
 *  GET /not/rules           eventos de alerta agrupados por módulo, con lo que trae por defecto y lo que ajustó la empresa
 *  PUT /not/rules/{event}   {is_active, roles[], lead_days[], channels[]} la empresa ajusta el evento (sin tocar código)
 */
class NotRulesController extends Controller
{
    public const ROLES = ['sst_manager', 'sst_supervisor', 'management', 'hr', 'area_lead', 'health_staff', 'occupational_doctor', 'committee_member', 'consultant', 'trainer', 'account_admin'];

    public function index(): JsonResponse
    {
        $company = app(CompanyContext::class)->companyId;
        $mine = [];
        foreach (DB::select('select event_code, is_active, recipients_spec, lead_days, channels from notification_rules where company_id = ? and deleted_at is null', [$company]) as $r) {
            $mine[$r->event_code] = $r;
        }
        $out = [];
        foreach (self::events() as $code => $e) {
            $r = $mine[$code] ?? null;
            $spec = $r ? (json_decode((string) $r->recipients_spec, true) ?: []) : [];
            $out[] = $e + ['code' => $code, 'customized' => (bool) $r, 'is_active' => $r ? (bool) $r->is_active : true,
                'roles' => isset($spec['roles']) ? $spec['roles'] : $e['default_roles'],
                'lead_days' => $r && $r->lead_days ? array_map('intval', str_getcsv(trim((string) $r->lead_days, '{}'))) : [],
                'channels' => $r && $r->channels ? str_getcsv(trim((string) $r->channels, '{}')) : ['in_app', 'email']];
        }
        usort($out, fn ($a, $b) => [$a['module'], $a['title']] <=> [$b['module'], $b['title']]);

        return response()->json(['data' => $out, 'roles' => self::ROLES]);
    }

    public function update(Request $request, string $event): JsonResponse
    {
        if (! array_intersect(['not.notification_rules.update', 'not.notification_rules.create'], Permissions::effective())) {
            throw ApiProblem::forbidden('Tu rol no configura las reglas de alerta.');
        }
        $e = self::events()[$event] ?? throw ApiProblem::notFound('Ese evento de alerta no existe.');
        $d = $request->validate([
            'is_active' => ['required', 'boolean'],
            'roles' => ['present', 'array'], 'roles.*' => ['string', 'in:'.implode(',', self::ROLES)],
            'lead_days' => ['present', 'array', 'max:6'], 'lead_days.*' => ['integer', 'min:0', 'max:365'],
            'channels' => ['required', 'array', 'min:1'], 'channels.*' => ['string', 'in:in_app,email,whatsapp'],
        ]);
        $company = app(CompanyContext::class)->companyId;
        $uid = (string) auth()->id();
        $lead = array_values(array_unique(array_map('intval', $d['lead_days'])));
        rsort($lead);
        $vals = [$e['title'], $e['module'], json_encode(['roles' => array_values(array_unique($d['roles']))]), '{'.implode(',', array_unique($d['channels'])).'}',
            $lead ? '{'.implode(',', $lead).'}' : null, $d['is_active'] ? 'true' : 'false', $uid];
        $id = DB::selectOne('select id from notification_rules where company_id = ? and event_code = ? and deleted_at is null', [$company, $event])?->id;
        if ($id) {
            DB::update('update notification_rules set name = ?, module_code = ?, recipients_spec = ?::jsonb, channels = ?::text[], lead_days = ?::int[], is_active = ?, updated_by = ?, updated_at = now() where id = ?',
                [...$vals, $id]);
        } else {
            DB::insert("insert into notification_rules (company_id, event_code, name, module_code, recipients_spec, channels, lead_days, is_active, updated_by, created_by, template_code, priority)
                        values (?, ?, ?, ?, ?::jsonb, ?::text[], ?::int[], ?, ?, ?, ?, ?)", [$company, $event, ...$vals, $uid, $event, $e['priority']]);
        }
        $ctx = app(CompanyContext::class);
        DB::insert("insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes) values (?, ?, ?, 'user', 'update', 'NOT', 'notification_rules', ?, ?::jsonb)",
            [$company, $uid, $ctx->roleCode, $id ?? DB::selectOne('select id from notification_rules where company_id = ? and event_code = ? and deleted_at is null', [$company, $event])->id,
                json_encode(['event' => $event] + $d, JSON_UNESCAPED_UNICODE)]);

        return response()->json(['data' => ['code' => $event, 'is_active' => $d['is_active'], 'roles' => $d['roles'], 'lead_days' => $lead, 'channels' => $d['channels']]]);
    }

    /** @return array<string, array{module: string, title: string, priority: string, default_roles: list<string>}> */
    private static function events(): array
    {
        $out = [];
        foreach (SstAlerts::checks() as $c) {
            $out[$c[2]] ??= ['module' => $c[0], 'title' => $c[4], 'priority' => $c[3], 'default_roles' => array_values($c[7] ?? [])];
        }

        return $out;
    }
}

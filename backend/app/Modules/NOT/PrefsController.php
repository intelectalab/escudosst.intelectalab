<?php

namespace App\Modules\NOT;

use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * SCR-NOT-03 «Mis preferencias» (M-NOT): interruptores por categoría (módulo) y canal, modo resumen diario y número de
 * WhatsApp verificado. La campana siempre recibe los avisos; los críticos (RN-NOT-05) llegan aunque se desactive el canal.
 *  GET /me/notification-prefs   categorías de los módulos activos de la empresa con su estado por canal
 *  PUT /me/notification-prefs   {digest_daily: bool, channels: {email: {MOD: bool, ...}, whatsapp: {MOD: bool, ...}}}
 */
class PrefsController extends Controller
{
    private const CHANNELS = ['email', 'whatsapp'];

    public function show(): JsonResponse
    {
        $ctx = app(CompanyContext::class);
        $uid = (string) auth()->id();
        $modules = DB::select(
            "select m.code, m.name from modules m join feature_flags f on f.module_code = m.code and f.mode = 'enabled' and f.deleted_at is null
              where m.deleted_at is null order by m.number"
        );
        $rows = DB::select('select channel, event_category, enabled, digest_mode from user_notification_prefs where company_id = ? and user_id = ? and deleted_at is null', [$ctx->companyId, $uid]);
        $off = [];
        $digest = false;
        foreach ($rows as $r) {
            if (! $r->enabled) {
                $off[$r->channel][$r->event_category] = true;
            }
            if ($r->channel === 'email' && $r->event_category === '*' && $r->digest_mode === 'daily') {
                $digest = true;
            }
        }
        $channels = [];
        foreach (self::CHANNELS as $ch) {
            foreach ($modules as $m) {
                $channels[$ch][$m->code] = empty($off[$ch]['*']) && empty($off[$ch][strtolower($m->code)]);
            }
        }

        return response()->json(['data' => [
            'modules' => $modules,
            'channels' => $channels,
            'digest_daily' => $digest,
            'whatsapp' => $this->whatsapp($ctx->companyId, $uid),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'digest_daily' => ['required', 'boolean'],
            'channels' => ['required', 'array'],
            'channels.email' => ['array'], 'channels.email.*' => ['boolean'],
            'channels.whatsapp' => ['array'], 'channels.whatsapp.*' => ['boolean'],
        ]);
        $ctx = app(CompanyContext::class);
        $uid = (string) auth()->id();
        $codes = array_column(DB::select("select module_code from feature_flags where mode = 'enabled' and deleted_at is null"), 'module_code');
        DB::transaction(function () use ($d, $ctx, $uid, $codes) {
            DB::delete('delete from user_notification_prefs where company_id = ? and user_id = ?', [$ctx->companyId, $uid]);
            foreach (self::CHANNELS as $ch) {
                foreach ($d['channels'][$ch] ?? [] as $code => $on) {
                    if (! $on && in_array($code, $codes, true)) {
                        DB::insert("insert into user_notification_prefs (company_id, user_id, channel, event_category, enabled, digest_mode) values (?, ?, ?, ?, false, 'immediate')",
                            [$ctx->companyId, $uid, $ch, strtolower($code)]);
                    }
                }
            }
            if ($d['digest_daily']) {
                DB::insert("insert into user_notification_prefs (company_id, user_id, channel, event_category, enabled, digest_mode) values (?, ?, 'email', '*', true, 'daily')",
                    [$ctx->companyId, $uid]);
            }
        });

        return $this->show();
    }

    /** Número de WhatsApp verificado: el del trabajador vinculado a la cuenta, solo si aceptó el canal (RN-NOT-04). */
    private function whatsapp(string $company, string $uid): array
    {
        $w = DB::selectOne(
            "select w.phone_e164, w.whatsapp_opt_in from workers w
              where w.deleted_at is null and w.status <> 'terminated' and (w.user_id = ?
                 or w.id in (select m.worker_id from memberships m where m.user_id = ? and m.company_id = ? and m.status = 'active' and m.deleted_at is null))
              order by w.whatsapp_opt_in desc limit 1", [$uid, $uid, $company]);
        if (! $w || ! $w->phone_e164) {
            return ['verified' => false, 'last4' => null];
        }
        try {
            $phone = Crypt::decryptString($w->phone_e164);
        } catch (\Throwable) {
            $phone = $w->phone_e164;
        }

        return ['verified' => (bool) $w->whatsapp_opt_in, 'last4' => substr(preg_replace('/\D+/', '', (string) $phone), -4) ?: null];
    }
}

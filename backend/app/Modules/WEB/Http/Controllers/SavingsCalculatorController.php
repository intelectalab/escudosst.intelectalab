<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\WEB\Http\Controllers\Concerns\SitePanelHelpers;
use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Calculadora de ahorro frente a Excel y papel (SCR-WEB-82 /herramientas/calculadora-ahorro y SCR-WEB-83 panel).
 *  Público: GET /public/tools/savings-calculator    supuestos publicados (y si ya se puede calcular)
 *           POST /public/tools/savings-calculator   calcula; si deja su correo, le llega el resultado y entra a Interesados
 *  Panel:   GET /internal/site/savings-calculator, PUT (supuestos y aviso), POST .../publish (Dueño)
 *
 * El cálculo lo hace la base (public_calculate_savings) con los supuestos vigentes y guarda una copia de ellos.
 * Los supuestos empiezan vacíos: hasta que el Dueño los define y publica, la página dice que aún no está disponible.
 */
class SavingsCalculatorController extends Controller
{
    use SitePanelHelpers;

    public function publicShow(): JsonResponse
    {
        $r = json_decode($this->db()->selectOne('select public_savings_calculator() as r')->r, true);
        unset($r['result']);

        return response()->json(['data' => $r ?: null]);
    }

    public function calculate(Request $request): JsonResponse
    {
        if ($request->filled('website')) {
            return response()->json(['data' => ['result' => 'ok']]);
        }
        $d = $request->validate([
            'workers' => ['required', 'integer', 'between:1,100000'],
            'sites' => ['required', 'integer', 'between:1,1000'],
            'records_per_month' => ['required', 'integer', 'between:0,1000000'],
            'hours_per_month' => ['nullable', 'numeric', 'between:0,10000'],
            'email' => ['nullable', 'email:rfc', 'max:160'],
            'full_name' => ['nullable', 'required_with:email', 'string', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'privacy_consent' => ['boolean'],
            'marketing_consent' => ['boolean'],
            'utm_source' => ['nullable', 'string', 'max:100'],
            'utm_medium' => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:100'],
        ], self::MESSAGES + ['full_name.required_with' => 'Escribe tu nombre para enviarte el resultado.']);
        if (! empty($d['email']) && empty($d['privacy_consent'])) {
            $this->fail('privacy_consent', 'Acepta la política de privacidad para recibir el resultado por correo.');
        }
        $d['privacy_consent'] = ! empty($d['email']);
        $d['marketing_consent'] = (bool) ($d['marketing_consent'] ?? false);
        $r = json_decode($this->db()->selectOne("select public_calculate_savings(?::jsonb, decode(?, 'hex')) as r", [
            json_encode($d, JSON_UNESCAPED_UNICODE), hash('sha256', (string) $request->ip()),
        ])->r, true);
        if (($r['result'] ?? '') !== 'ok') {
            $this->conflict('La calculadora aún no está disponible.');
        }
        if (! empty($d['email'])) {
            $rows = [['Horas que podrías ahorrar al mes', number_format((float) $r['result_hours_month'], 1).' h']];
            if ($r['result_cost_month'] !== null) {
                $rows[] = ['Equivale aprox. a', 'S/ '.number_format((float) $r['result_cost_month'], 2).' al mes'];
            }
            foreach ($this->assumptionRows($r['assumptions'] ?? []) as $a) {
                $rows[] = $a;
            }
            try {
                Mail::to($d['email'])->send(new SiteRequestMail(explode(' ', trim($d['full_name']))[0], 'Tu estimación de ahorro con EscudoSST',
                    "Con los datos que ingresaste ({$d['workers']} trabajadores, {$d['sites']} sedes y {$d['records_per_month']} registros al mes), esta es la estimación:",
                    $rows, (string) ($r['disclaimer'] ?? ''), 'Agenda una demo', rtrim((string) config('escudo.frontend_url'), '/').'/demo'));
            } catch (\Throwable $e) {
                Log::warning('No se pudo enviar el resultado de la calculadora de ahorro', ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['data' => [
            'result_hours_month' => (float) $r['result_hours_month'], 'result_cost_month' => $r['result_cost_month'] !== null ? (float) $r['result_cost_month'] : null,
            'assumptions' => $r['assumptions'], 'disclaimer' => $r['disclaimer'] ?? null, 'emailed' => ! empty($d['email']),
        ]]);
    }

    public function show(): JsonResponse
    {
        $s = $this->db()->selectOne('select * from savings_calculator_settings where singleton and deleted_at is null');
        $stats = $this->db()->selectOne(
            "select count(*) as total, count(*) filter (where created_at > now() - interval '30 days') as last_30,
                    count(lead_id) as with_email, round(avg(result_hours_month), 1) as avg_hours
               from savings_calculations where deleted_at is null"
        );

        return response()->json(['data' => [
            'assumptions' => json_decode($s->assumptions_json, true), 'disclaimer' => $s->disclaimer, 'status' => $s->status,
            'to_validate' => (bool) $s->to_validate, 'updated_at' => $s->updated_at, 'stats' => $stats,
        ]]);
    }

    public function save(Request $request, AccountsService $accounts): JsonResponse
    {
        $d = $request->validate([
            'minutes_saved_per_record' => ['nullable', 'numeric', 'between:0,600'],
            'manual_hours_reduction_pct' => ['nullable', 'numeric', 'between:0,100'],
            'hourly_cost_pen' => ['nullable', 'numeric', 'between:0,10000'],
            'disclaimer' => ['required', 'string', 'min:20', 'max:500'],
            'to_validate' => ['boolean'],
        ], self::MESSAGES);
        $this->assertAllowedText('disclaimer', $d['disclaimer']);
        $assumptions = [
            'minutes_saved_per_record' => isset($d['minutes_saved_per_record']) ? (float) $d['minutes_saved_per_record'] : null,
            'manual_hours_reduction_pct' => isset($d['manual_hours_reduction_pct']) ? (float) $d['manual_hours_reduction_pct'] : null,
            'hourly_cost_pen' => isset($d['hourly_cost_pen']) ? (float) $d['hourly_cost_pen'] : null,
        ];
        $this->db()->update(
            'update savings_calculator_settings set assumptions_json = ?::jsonb, disclaimer = ?, to_validate = ?, updated_by = ?, updated_at = now() where singleton',
            [json_encode($assumptions), trim($d['disclaimer']), (bool) ($d['to_validate'] ?? true), $request->user()->id]
        );
        $accounts->audit($request->user()->id, 'savings_calculator_saved', 'savings_calculator_settings', null, $assumptions);

        return $this->show();
    }

    public function publish(Request $request, AccountsService $accounts): JsonResponse
    {
        $s = $this->db()->selectOne('select * from savings_calculator_settings where singleton');
        $a = json_decode($s->assumptions_json, true);
        $publish = $request->boolean('publish', true);
        if ($publish && ($a['minutes_saved_per_record'] === null || $a['manual_hours_reduction_pct'] === null)) {
            $this->fail('minutes_saved_per_record', 'Define al menos los minutos ahorrados por registro y el % de horas manuales que se reducen.');
        }
        $this->db()->update('update savings_calculator_settings set status = ?, updated_by = ?, updated_at = now() where singleton', [$publish ? 'published' : 'draft', $request->user()->id]);
        $accounts->audit($request->user()->id, $publish ? 'savings_calculator_published' : 'savings_calculator_unpublished', 'savings_calculator_settings', null, []);

        return $this->show();
    }

    private function assumptionRows(array $a): array
    {
        $rows = [];
        if (isset($a['minutes_saved_per_record'])) {
            $rows[] = ['Supuesto: minutos ahorrados por registro', (string) $a['minutes_saved_per_record']];
        }
        if (isset($a['manual_hours_reduction_pct'])) {
            $rows[] = ['Supuesto: reducción de horas manuales', $a['manual_hours_reduction_pct'].' %'];
        }
        if (isset($a['hourly_cost_pen'])) {
            $rows[] = ['Supuesto: costo por hora', 'S/ '.number_format((float) $a['hourly_cost_pen'], 2)];
        }

        return $rows;
    }
}

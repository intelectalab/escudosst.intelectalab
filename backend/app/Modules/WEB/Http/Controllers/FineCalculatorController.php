<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\CatalogsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Calculadora de multas SUNAFIL (SCR-WEB-28 pública, SCR-WEB-41 panel; docs/11 P-57 a P-61).
 *  Público: GET  /public/tools/fine-calculator    parámetros vigentes (UIT, tablas, descuento) y si están confirmados
 *           POST /public/tools/fine-calculator    calcula un rango referencial; con correo, la persona entra a Interesados
 *  Panel:   GET  /internal/site/fine-calculator   parámetros con su vigencia, últimos cálculos y resumen
 *           POST /internal/site/fine-calculator/{key}   nueva versión de un parámetro (UIT, una tabla o el descuento)
 *
 * Los montos NO están en el código: salen de los parámetros legales (D.S. 019-2006-TR y modificatorias), que debe cargar
 * y confirmar un especialista. Mientras falten, la calculadora dice que aún no está disponible. El resultado es
 * referencial y lo dice; cada cálculo guarda la foto de los parámetros que usó.
 */
class FineCalculatorController extends Controller
{
    public const KEYS = [
        'uit' => 'web.uit_value',
        'micro' => 'web.fine_table_micro',
        'small' => 'web.fine_table_small',
        'non_mype' => 'web.fine_table_non_mype',
        'discount' => 'web.fine_correction_discount_pct',
    ];

    private const SEVERITIES = ['minor', 'serious', 'very_serious'];

    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'in' => 'Elige una opción de la lista.',
        'integer' => 'Escribe un número entero.',
        'between' => 'Debe estar entre :min y :max.',
        'email' => 'Escribe un correo válido.',
        'accepted' => 'Para recibir el detalle, acepta la política de privacidad.',
        'max' => 'Es muy largo.',
        'min' => 'Es muy corto.',
        'date' => 'Escribe una fecha válida.',
    ];

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    // ---------------------------------------------------------------- Público

    public function parameters(): JsonResponse
    {
        $p = json_decode($this->db()->selectOne('select public_fine_calculator_parameters() as r')->r, true);
        unset($p['result']);

        return response()->json(['data' => $p])->header('Cache-Control', 'public, max-age=60');
    }

    public function calculate(Request $request): JsonResponse
    {
        if (filled($request->input('website'))) {
            throw ApiProblem::notFound('No disponible.');
        }
        $d = $request->validate([
            'company_size' => ['required', 'in:micro,small,non_mype'],
            'affected_workers' => ['required', 'integer', 'between:1,100000'],
            'severity' => ['required', 'in:'.implode(',', self::SEVERITIES)],
            'infraction_code' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:160'],
            'full_name' => ['required_with:email', 'nullable', 'string', 'min:3', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'privacy_consent' => ['nullable', 'boolean'],
            'marketing_consent' => ['boolean'],
        ], self::MESSAGES + ['full_name.required_with' => 'Escribe tu nombre para enviarte el detalle.']);
        if (filled($d['email'] ?? null) && ! $request->boolean('privacy_consent')) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Acepta la política de privacidad.', ['errors' => ['privacy_consent' => ['Para recibir el detalle, acepta la política de privacidad.']]]);
        }
        $payload = $d + ['privacy_consent' => $request->boolean('privacy_consent'), 'marketing_consent' => $request->boolean('marketing_consent'), 'source_page' => '/herramientas/calculadora-multas'];
        $ipHash = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        $r = json_decode($this->db()->selectOne(
            "select public_calculate_fine(?::jsonb, decode(?, 'hex'), ?) as r",
            [json_encode($payload, JSON_UNESCAPED_UNICODE), $ipHash, (string) Str::uuid7()]
        )->r, true);
        if (($r['result'] ?? '') === 'parameters_pending') {
            throw new ApiProblem(409, 'parameters_pending', 'Calculadora en preparación', 'Todavía no están cargados los valores oficiales para este caso. Escríbenos y te ayudamos.');
        }

        if (! empty($r['lead_registered']) && ! ($r['replay'] ?? false)) {
            $this->mailResult($d, $r);
        }

        return response()->json(['data' => $r]);
    }

    /** Resultado completo por correo a quien lo pidió (SCR-WEB-28). Si el correo falla, el cálculo igual se muestra. */
    private function mailResult(array $d, array $r): void
    {
        $sizes = ['micro' => 'Microempresa', 'small' => 'Pequeña empresa', 'non_mype' => 'No MYPE (mediana o grande)'];
        $sev = ['minor' => 'Leve', 'serious' => 'Grave', 'very_serious' => 'Muy grave'];
        $pen = fn ($v) => 'S/ '.number_format((float) $v, 2, '.', ',');
        $rows = [
            ['Tamaño de empresa', $sizes[$d['company_size']]], ['Trabajadores afectados', (string) $d['affected_workers']],
            ['Gravedad', $sev[$d['severity']]],
            ['Multa referencial', $pen($r['result_min_pen']).' a '.$pen($r['result_max_pen'])],
            ['En UIT', rtrim(rtrim((string) $r['result_min_uit'], '0'), '.').' a '.rtrim(rtrim((string) $r['result_max_uit'], '0'), '.').' UIT (UIT = '.$pen($r['uit_value']).')'],
        ];
        if (! empty($r['with_correction_discount'])) {
            $w = $r['with_correction_discount'];
            $rows[] = ["Si subsanas a tiempo (−{$w['pct']} %)", $pen($w['min_pen']).' a '.$pen($w['max_pen'])];
        }
        try {
            \Illuminate\Support\Facades\Mail::to($d['email'])->send(new \App\Modules\WEB\Mail\SiteRequestMail(
                explode(' ', trim($d['full_name'] ?? ''))[0] ?: 'Hola', 'Tu cálculo de multa SUNAFIL',
                'Este es el detalle del cálculo que hiciste en EscudoSST. Con un sistema de gestión al día, la mayoría de estas multas se evitan.',
                $rows, 'Resultado referencial calculado con parámetros legales por confirmar; no es asesoría legal.',
                'Ver cómo evitarlas', rtrim((string) config('escudo.frontend_url'), '/').'/demo'
            ));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('No se pudo enviar el resultado de la calculadora', ['error' => $e->getMessage()]);
        }
    }

    // ---------------------------------------------------------------- Panel

    public function panel(): JsonResponse
    {
        $params = [];
        foreach (self::KEYS as $k => $key) {
            $rows = $this->db()->select(
                'select id, value, value_type, valid_from, valid_to, to_confirm, confirmed_by, confirmed_at, legal_reference
                   from legal_parameters where key = ? and company_id is null and deleted_at is null order by valid_from desc limit 5',
                [$key]
            );
            $current = collect($rows)->first(fn ($r) => $r->valid_from <= now()->toDateString() && (! $r->valid_to || $r->valid_to >= now()->toDateString()));
            $params[$k] = ['key' => $key, 'current' => $current, 'history' => $rows];
        }
        $stats = $this->db()->selectOne(
            "select count(*) as total, count(lead_id) as with_lead, count(*) filter (where created_at > now() - interval '30 days') as last_30
               from fine_calculations where deleted_at is null"
        );
        $recent = $this->db()->select(
            'select f.created_at, f.company_size, f.affected_workers, f.severity, f.result_min_pen, f.result_max_pen, l.full_name, l.company_name
               from fine_calculations f left join marketing_leads l on l.id = f.lead_id
              where f.deleted_at is null order by f.created_at desc limit 20'
        );

        return response()->json(['data' => ['params' => $params, 'stats' => $stats, 'recent' => $recent]]);
    }

    /** Nueva versión de un parámetro de la calculadora (queda «a confirmar» hasta que un especialista lo confirme). */
    public function saveParameter(Request $request, string $k, CatalogsService $catalogs): JsonResponse
    {
        $key = self::KEYS[$k] ?? throw ApiProblem::notFound('Parámetro desconocido.');
        $d = $request->validate([
            'value' => ['required'],
            'valid_from' => ['required', 'date'],
            'legal_reference' => ['nullable', 'string', 'max:200'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ], self::MESSAGES + ['reason.required' => 'Indica la norma o el motivo del cambio.']);

        $value = match ($k) {
            'uit' => $this->number($d['value'], 'value', 1, 100000),
            'discount' => $this->number($d['value'], 'value', 0, 99),
            default => $this->table($d['value']),
        };
        $current = $this->db()->selectOne(
            'select id from legal_parameters where key = ? and company_id is null and deleted_at is null order by valid_from desc limit 1', [$key]
        ) ?? throw ApiProblem::notFound('El parámetro no existe.');

        return response()->json(['data' => $catalogs->newParameterVersion($current->id, [
            'value' => $value, 'valid_from' => $d['valid_from'], 'legal_reference' => $d['legal_reference'] ?? null, 'reason' => $d['reason'],
        ], $request->user()->id)], 201);
    }

    // ---------------------------------------------------------------- Apoyo

    private function number(mixed $v, string $field, float $min, float $max): string
    {
        if (! is_numeric($v) || (float) $v < $min || (float) $v > $max) {
            $this->fail($field, "Escribe un número entre {$min} y {$max}.");
        }

        return (string) (0 + $v);
    }

    /**
     * Tabla de multas: tramos de trabajadores afectados (desde/hasta) con el rango en UIT (mín./máx.) para leve, grave y
     * muy grave. Los tramos no se pueden cruzar ni dejar el «hasta» antes del «desde».
     */
    private function table(mixed $v): string
    {
        $rows = is_array($v) ? ($v['rows'] ?? $v) : null;
        if (! is_array($rows) || count($rows) > 30) {
            $this->fail('value', 'La tabla no tiene un formato válido.');
        }
        $out = [];
        $prevTo = 0;
        usort($rows, fn ($a, $b) => (int) ($a['workers_from'] ?? 0) <=> (int) ($b['workers_from'] ?? 0));
        foreach ($rows as $i => $r) {
            $from = filter_var($r['workers_from'] ?? null, FILTER_VALIDATE_INT);
            $to = ($r['workers_to'] ?? null) === null || $r['workers_to'] === '' ? null : filter_var($r['workers_to'], FILTER_VALIDATE_INT);
            if ($from === false || $from < 1 || $to === false || ($to !== null && $to < $from)) {
                $this->fail("value.{$i}", 'Revisa el tramo de trabajadores (desde y hasta).');
            }
            if ($from <= $prevTo) {
                $this->fail("value.{$i}", 'Los tramos de trabajadores se cruzan.');
            }
            $row = ['workers_from' => $from, 'workers_to' => $to];
            foreach (self::SEVERITIES as $s) {
                $min = $r[$s]['min'] ?? null;
                $max = $r[$s]['max'] ?? null;
                if (! is_numeric($min) || ! is_numeric($max) || $min < 0 || $max < $min) {
                    $this->fail("value.{$i}", 'Cada gravedad necesita un mínimo y un máximo en UIT (el máximo no menor que el mínimo).');
                }
                $row[$s] = ['min' => 0 + $min, 'max' => 0 + $max];
            }
            $out[] = $row;
            $prevTo = $to ?? PHP_INT_MAX;
        }

        return json_encode(['unit' => 'UIT', 'rows' => $out]);
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}

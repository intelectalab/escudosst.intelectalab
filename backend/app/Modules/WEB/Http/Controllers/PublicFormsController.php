<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\SUS\Support\Pricing;
use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Formularios públicos del sitio (sin sesión):
 *  POST /public/trial          «Prueba gratis»: registro autoservicio con RUC (SCR-WEB-14, docs/03 §10)
 *  POST /public/demo-requests  «Agenda una demo», contacto y cotización (SCR-WEB-12)
 *
 * Al recibir una solicitud: se guarda (bandeja del panel), se responde al momento por correo a quien la envió
 * (en la cotización, con los precios del plan y complementos elegidos) y se avisa al correo de ventas.
 * Coordinar la demo y negociar sigue siendo trabajo del equipo comercial.
 * Protección mientras se decide el captcha (DA-13): límite por IP en la ruta y un campo trampa («website»)
 * que las personas no ven; si llega lleno, se responde como si todo saliera bien y no se guarda nada.
 */
class PublicFormsController extends Controller
{
    private const SECTORS = 'construction,mining,agroindustry,manufacturing,retail,services,office,health,education,transport,energy,other';

    public function __construct(private readonly AccountsService $accounts) {}

    public function trial(Request $request): JsonResponse
    {
        if ($this->isBot($request)) {
            return response()->json(['data' => ['email' => $request->input('email')]], 201);
        }
        $data = $request->validate([
            'ruc' => ['required', 'digits:11'],
            'legal_name' => ['required', 'string', 'min:3', 'max:160'],
            'trade_name' => ['nullable', 'string', 'max:160'],
            'economic_sector' => ['nullable', 'in:'.self::SECTORS],
            'headcount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
            'accept_legal' => ['accepted'],
            'ref' => ['nullable', 'string', 'max:32'],
        ], $this->messages());

        $email = mb_strtolower(trim($data['email']));
        // Por seguridad, el registro público no da acceso a un usuario que ya existe: debe iniciar sesión.
        if (DB::connection('platform')->selectOne('select 1 from users where lower(email) = ? and deleted_at is null', [$email])) {
            throw ValidationException::withMessages(['email' => 'Este correo ya tiene una cuenta en EscudoSST. Inicia sesión para continuar.']);
        }

        $created = $this->accounts->create([
            'account_type' => 'direct',
            'ruc' => $data['ruc'],
            'legal_name' => trim($data['legal_name']),
            'trade_name' => ($data['trade_name'] ?? null) ? trim($data['trade_name']) : null,
            'economic_sector' => $data['economic_sector'] ?? null,
            'headcount' => $data['headcount'],
            'start' => 'trial',
            'plan' => null,
            'billing_period' => 'monthly',
            'admin_name' => trim($data['full_name']),
            'admin_email' => $email,
            'billing_phone' => $data['phone'] ?? null,
            'accepted_legal' => true,
            'ip_hash' => $this->ipHash($request),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
        ], null);
        PartnersController::attribute($data['ref'] ?? null, $created['account_id'], $data['ruc'], $email);   // RN-WEB-48

        return response()->json(['data' => ['email' => $email]], 201);
    }

    public function demoRequest(Request $request): JsonResponse
    {
        if ($this->isBot($request)) {
            return response()->json(['data' => ['ok' => true]], 201);
        }
        $data = $request->validate([
            'request_type' => ['required', 'in:demo,contact,quote'],
            'full_name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
            'company_name' => ['required', 'string', 'min:2', 'max:160'],
            'ruc' => ['nullable', 'required_if:request_type,quote', 'regex:/^(10|15|17|20)\d{9}$/'],
            'companies_count' => ['nullable', 'integer', 'min:1', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'headcount' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'economic_sector' => ['nullable', 'in:'.self::SECTORS],
            'interest_plan_code' => ['nullable', 'in:E,P,C,K'],
            'addon_codes' => ['nullable', 'array', 'max:10'],
            'addon_codes.*' => ['string', 'distinct', 'exists:addons,code'],
            'message' => ['nullable', 'string', 'max:2000'],
            'privacy_consent' => ['accepted'],
            'marketing_consent' => ['boolean'],
            'source_page' => ['nullable', 'string', 'max:300'],
        ], $this->messages() + [
            'ruc.regex' => 'El RUC tiene 11 dígitos y empieza con 10, 15, 17 o 20.',
            'ruc.required_if' => 'Para cotizar necesitamos el RUC de la empresa.',
        ]);

        $data['privacy_consent'] = true;
        $data['marketing_consent'] = (bool) ($data['marketing_consent'] ?? false);
        $pricing = json_decode(DB::selectOne('select public_pricing() as p')->p, true);
        $addons = collect($pricing['addons'] ?? [])->whereIn('code', $data['addon_codes'] ?? [])->values();
        // La tabla no tiene columna de complementos: quedan al inicio del mensaje para el equipo.
        if ($addons->isNotEmpty()) {
            $data['message'] = trim('Complementos de interés: '.$addons->pluck('name')->implode(', ').".\n".($data['message'] ?? ''));
        }
        // Grupo de empresas: el equipo arma la cotización con cada RUC (cada empresa tiene su propia suscripción).
        $groupSize = (int) ($data['companies_count'] ?? 1);
        if ($data['request_type'] === 'quote' && $groupSize > 1) {
            $data['message'] = trim("Empresas del grupo: {$groupSize}.
".($data['message'] ?? ''));
        }
        $row = collect($data)->except(['addon_codes', 'companies_count'])->all();
        $r = json_decode(DB::selectOne(
            "select public_create_demo_request(?::jsonb, decode(?, 'hex')) as r",
            [json_encode($row, JSON_UNESCAPED_UNICODE), $this->ipHash($request)]
        )->r, true);

        $this->replyToVisitor($data, $pricing, $addons->all());
        $this->notifyTeam($data);

        return response()->json(['data' => ['id' => $r['id']]], 201);
    }

    /**
     * Respuesta automática a quien envió el formulario. En la cotización arma el detalle con los precios
     * vigentes del panel (mensual y anual); lo que no tiene precio figura como «a cotizar».
     */
    private function replyToVisitor(array $d, array $pricing, array $addons): void
    {
        $site = rtrim(config('escudo.frontend_url'), '/');
        $first = explode(' ', trim($d['full_name']))[0];
        $rows = [];
        $note = null;

        if ($d['request_type'] === 'quote') {
            $discount = (float) ($pricing['annual_discount_pct'] ?? 0);
            $plan = collect($pricing['plans'] ?? [])->firstWhere('code', $d['interest_plan_code'] ?? null);
            $monthly = 0.0;
            $complete = true;
            if ($plan) {
                $rows[] = ["Plan {$plan['name']}", $this->money($plan['price'], '/mes')];
                $plan['price'] === null ? $complete = false : $monthly += (float) $plan['price'];
            }
            foreach ($addons as $a) {
                $rows[] = [$a['name'].($a['available'] ? '' : ' (próximamente)'), $this->money($a['price'], '/mes')];
                $a['price'] === null ? $complete = false : $monthly += (float) $a['price'];
            }
            if ($rows && $complete && $monthly > 0) {
                $rows[] = ['Total si pagas mes a mes', $this->money($monthly, '/mes')];
                $rows[] = ['Total si pagas el año completo', $this->money(Pricing::forPeriod($monthly, 'annual', $discount), '/año')];
            }
            $title = 'Tu cotización de EscudoSST';
            $intro = $rows
                ? 'Gracias por tu interés. Este es el detalle de lo que elegiste, con los precios vigentes:'
                : 'Gracias por tu interés. Como aún no elegiste un plan, te escribiremos para recomendarte el adecuado según el tamaño de tu empresa.';
            $note = $rows
                ? 'Precios referenciales en soles. Lo que figura «a cotizar» te lo confirmamos por correo. Un asesor te escribirá para resolver tus dudas.'
                : null;
        } else {
            $title = $d['request_type'] === 'demo' ? 'Recibimos tu solicitud de demo' : 'Recibimos tu consulta';
            $intro = $d['request_type'] === 'demo'
                ? 'Gracias por tu interés en EscudoSST. Un asesor te escribirá para coordinar el día y la hora de la demostración.'
                : 'Gracias por escribirnos. Te responderemos a este correo lo antes posible.';
        }

        // Demo: si el dueño configuró la demo interactiva (video o recorrido), se manda al momento para que no espere.
        [$btnText, $btnUrl] = ['Probar gratis', "{$site}/prueba-gratis"];
        if ($d['request_type'] === 'demo') {
            $demoUrl = json_decode(DB::selectOne('select public_site_settings() as s')->s, true)['interactive_demo_url'] ?? null;
            if ($demoUrl) {
                [$btnText, $btnUrl] = ['Ver la demo ahora', $demoUrl];
                $note = 'Mientras coordinamos la demo en vivo, puedes ver este recorrido del sistema con datos de ejemplo.';
            }
        }

        try {
            Mail::to($d['email'])->send(new SiteRequestMail($first, $title, $intro, $rows, $note, $btnText, $btnUrl));
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar la respuesta automática del sitio', ['error' => $e->getMessage()]);
        }
    }

    private function money(string|float|null $amount, string $per): string
    {
        return $amount === null ? 'A cotizar' : 'S/ '.number_format((float) $amount, 2, '.', ',').' '.$per;
    }

    /** Aviso al equipo comercial (correo de ventas o, si no hay, el de contacto). Si falla el correo, la solicitud igual queda guardada. */
    private function notifyTeam(array $d): void
    {
        $s = json_decode(DB::selectOne('select public_site_settings() as s')->s, true);
        $to = ($s['sales_email'] ?? null) ?: ($s['contact_email'] ?? null);
        if (! $to) {
            return;
        }
        $kind = ['demo' => 'Nueva solicitud de demo', 'contact' => 'Nueva consulta', 'quote' => 'Nuevo pedido de cotización'][$d['request_type']];
        $plans = ['E' => 'Esencial', 'P' => 'Profesional', 'C' => 'Corporativo', 'K' => 'Consultora'];
        $rows = array_values(array_filter([
            ['Empresa', $d['company_name'].(! empty($d['ruc']) ? " (RUC {$d['ruc']})" : '')],
            ['Contacto', $d['full_name']],
            ['Correo', $d['email']],
            ! empty($d['phone']) ? ['Celular', $d['phone']] : null,
            ! empty($d['headcount']) ? ['Trabajadores', (string) $d['headcount']] : null,
            ! empty($d['interest_plan_code']) ? ['Plan de interés', $plans[$d['interest_plan_code']]] : null,
        ]));
        try {
            Mail::to($to)->send(new SiteRequestMail(
                'equipo', "{$kind}: {$d['company_name']}", 'Llegó desde el sitio web. Ya le enviamos una respuesta automática; falta contactarla.',
                $rows, $d['message'] ?? null, 'Ver en el panel', rtrim(config('escudo.frontend_url'), '/').'/plataforma/sitio/solicitudes'
            ));
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar la solicitud del sitio', ['error' => $e->getMessage()]);
        }
    }

    private function isBot(Request $request): bool
    {
        return filled($request->input('website'));
    }

    /** Huella de la IP (no se guarda la IP): sha256 con la clave de la app, en hexadecimal. */
    private function ipHash(Request $request): string
    {
        return hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
    }

    private function messages(): array
    {
        return [
            'required' => 'Completa este campo.',
            'accepted' => 'Debes aceptar para continuar.',
            'email' => 'Escribe un correo válido.',
            'digits' => 'Debe tener :digits dígitos.',
            'integer' => 'Escribe un número.',
            'min' => 'Es muy corto.',
            'headcount.min' => 'Debe ser al menos 1.',
            'max' => 'Es muy largo.',
            'in' => 'Elige una opción de la lista.',
        ];
    }
}

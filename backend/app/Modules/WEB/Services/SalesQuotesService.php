<?php

namespace App\Modules\WEB\Services;

use App\Core\Http\ApiProblem;
use App\Core\Support\Ruc;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\SUS\Services\SubscriptionService;
use App\Modules\SUS\Support\Pricing;
use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cotizaciones formales y alta de la cuenta al confirmar el pago (pago manual, DA-07 solución interina).
 *
 *  1. El equipo arma la cotización (desde una solicitud del sitio o en blanco): contacto, periodo y una o varias
 *     empresas (grupo: cada RUC con su plan, complementos y su propia suscripción, docs/03 §1). Los precios salen
 *     del panel (precio mensual de referencia; el anual con el descuento anual vigente). Se puede aplicar un descuento.
 *  2. «Enviar»: se genera el PDF y un enlace personal (solo se guarda su hash). Le llega al cliente por correo.
 *  3. En el enlace el cliente acepta o no. Si acepta, ve los datos para pagar y sube su constancia.
 *  4. El equipo recibe aviso en cada paso (correo + campana del panel), revisa la constancia y confirma el pago
 *     (o la devuelve con el motivo para que la vuelva a enviar).
 *  5. Al confirmar: se crea la cuenta y sus empresas (o se usa la que ya tenía en prueba), se registran los pagos
 *     (uno por empresa, repartiendo el monto), se agregan los complementos y al administrador le llega el correo
 *     para crear su contraseña. Cada paso deja su avance guardado: si algo falla a la mitad, se puede reintentar.
 */
class SalesQuotesService
{
    private const SECTORS = ['construction', 'mining', 'agroindustry', 'manufacturing', 'retail', 'services', 'office', 'health', 'education', 'transport', 'energy', 'other'];

    public const METHODS = ['transfer' => 'Transferencia', 'deposit' => 'Depósito', 'yape' => 'Yape', 'plin' => 'Plin', 'other' => 'Otro'];

    public const STATUS = [
        'draft' => 'Borrador', 'sent' => 'Enviada', 'accepted' => 'Aceptada · esperando pago', 'rejected' => 'No aceptada',
        'payment_submitted' => 'Pago por revisar', 'paid' => 'Pagada · cuenta creada', 'cancelled' => 'Anulada',
    ];

    private const EVIDENCE_MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly AccountsService $accounts,
        private readonly SubscriptionService $subscriptions,
    ) {}

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    // ------------------------------------------------------------------ Ajustes

    /** @return array{payment_instructions: ?string, quote_validity_days: int} */
    public function settings(): array
    {
        $s = $this->db()->selectOne('select payment_instructions, quote_validity_days from site_settings where singleton');

        return ['payment_instructions' => $s->payment_instructions, 'quote_validity_days' => (int) $s->quote_validity_days];
    }

    public function saveSettings(array $d, string $actor): void
    {
        $this->db()->update(
            'update site_settings set payment_instructions = ?, quote_validity_days = ?, updated_at = now(), updated_by = ? where singleton',
            [($d['payment_instructions'] ?? null) ?: null, (int) $d['quote_validity_days'], $actor]
        );
        $this->accounts->audit($actor, 'quote_settings_changed', 'site_settings', null, ['quote_validity_days' => (int) $d['quote_validity_days']]);
    }

    // ------------------------------------------------------------------ Panel

    public function list(): array
    {
        return array_map(fn ($r) => [
            'id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'status_label' => self::STATUS[$r->status],
            'contact_name' => $r->contact_name, 'contact_email' => $r->contact_email,
            'companies' => array_map(fn ($c) => ['ruc' => $c['ruc'], 'legal_name' => $c['legal_name']], json_decode($r->companies, true)),
            'billing_period' => $r->billing_period, 'total' => $r->total, 'valid_until' => $r->valid_until,
            'expired' => $this->expired($r), 'sent_at' => $r->sent_at, 'payment_submitted_at' => $r->payment_submitted_at,
            'created_at' => $r->created_at, 'account_id' => $r->account_id,
        ], $this->db()->select('select * from sales_quotes where deleted_at is null order by created_at desc limit 300'));
    }

    public function show(string $id): array
    {
        $q = $this->find($id);

        return $this->present($q) + [
            'internal_notes' => $q->internal_notes,
            'demo_request_id' => $q->demo_request_id,
            'evidence_rejected_reason' => $q->evidence_rejected_reason,
            'has_evidence' => $q->evidence_file_id !== null,
            'has_pdf' => $q->pdf_file_id !== null,
            'sent_count' => (int) $q->sent_count,
            'confirmed_at' => $q->confirmed_at,
            'account_id' => $q->account_id,
            'cancel_reason' => $q->cancel_reason,
            'editable' => in_array($q->status, ['draft', 'sent', 'rejected'], true),
        ];
    }

    /** Datos para empezar una cotización desde una solicitud del sitio. */
    public function prefill(?string $requestId): array
    {
        $settings = $this->settings();
        $base = [
            'demo_request_id' => null, 'contact_name' => '', 'contact_email' => '', 'contact_phone' => '', 'billing_period' => 'monthly',
            'companies' => [['ruc' => '', 'legal_name' => '', 'trade_name' => '', 'headcount' => null, 'economic_sector' => null, 'plan_code' => 'P', 'addon_codes' => []]],
            'discount_amount' => 0, 'valid_until' => now()->addDays($settings['quote_validity_days'])->toDateString(),
            'client_notes' => '', 'internal_notes' => '',
        ];
        if (! $requestId || ! Str::isUuid($requestId)) {
            return $base;
        }
        $r = $this->db()->selectOne('select * from demo_requests where id = ? and deleted_at is null', [$requestId]);
        if (! $r) {
            throw ApiProblem::notFound('La solicitud no existe.');
        }
        // Los complementos llegaron como texto al inicio del mensaje («Complementos de interés: A, B.»).
        $groupSize = preg_match('/Empresas del grupo: (\d+)\./u', (string) $r->message, $g) ? min(30, (int) $g[1]) : 1;
        $addons = [];
        if (preg_match('/Complementos de interés: (.+?)\.\n?/u', (string) $r->message, $m)) {
            $names = array_map('trim', explode(',', $m[1]));
            $addons = array_map(fn ($a) => trim($a->code), $this->db()->select(
                'select code from addons where name = any(?::text[]) and is_active and deleted_at is null', ['{'.implode(',', array_map(fn ($n) => '"'.str_replace('"', '', $n).'"', $names)).'}']
            ));
        }

        $first = [
            'ruc' => trim((string) $r->ruc), 'legal_name' => $r->company_name, 'trade_name' => '', 'headcount' => $r->headcount,
            'economic_sector' => $r->economic_sector, 'plan_code' => in_array($r->interest_plan_code, ['E', 'P', 'C'], true) ? $r->interest_plan_code : 'P',
            'addon_codes' => $addons,
        ];
        // Grupo: filas vacías para el resto de empresas (con el mismo plan y complementos de partida).
        $others = array_fill(0, $groupSize - 1, ['ruc' => '', 'legal_name' => '', 'headcount' => null] + $first);

        return array_merge($base, [
            'demo_request_id' => $r->id, 'contact_name' => $r->full_name, 'contact_email' => $r->email, 'contact_phone' => $r->phone ?? '',
            'companies' => [$first, ...$others],
            'internal_notes' => $r->message ? "Mensaje del cliente: {$r->message}" : '',
        ]);
    }

    /** Calcula montos sin guardar (el editor del panel los muestra mientras se escribe). */
    public function preview(array $d): array
    {
        return $this->price($d['companies'] ?? [], $d['billing_period'] ?? 'monthly', (float) ($d['discount_amount'] ?? 0), false);
    }

    public function save(?string $id, array $d, string $actor): string
    {
        $q = $id ? $this->find($id) : null;
        if ($q && ! in_array($q->status, ['draft', 'sent', 'rejected'], true)) {
            throw $this->conflict('Esta cotización ya fue aceptada o cerrada: no se puede cambiar. Anúlala y crea otra si hace falta.');
        }
        $companies = $this->normalizeCompanies($d['companies']);
        $this->checkCompanies($companies);
        $priced = $this->price($companies, $d['billing_period'], (float) ($d['discount_amount'] ?? 0), false);

        $row = [
            $d['demo_request_id'] ?? ($q->demo_request_id ?? null), trim($d['contact_name']), Str::lower(trim($d['contact_email'])), ($d['contact_phone'] ?? null) ?: null,
            $d['billing_period'], json_encode($priced['companies'], JSON_UNESCAPED_UNICODE), $priced['annual_discount_pct'],
            $priced['subtotal'], $priced['discount_amount'], $priced['total'], $d['valid_until'] ?? null,
            ($d['client_notes'] ?? null) ?: null, ($d['internal_notes'] ?? null) ?: null, $actor,
        ];

        return $this->db()->transaction(function () use ($q, $row, $actor) {
            if ($q) {
                // Si ya se había enviado, el enlace anterior deja de servir: hay que volver a enviarla.
                $this->db()->update(
                    "update sales_quotes set demo_request_id = ?, contact_name = ?, contact_email = ?, contact_phone = ?, billing_period = ?,
                            companies = ?::jsonb, annual_discount_pct = ?, subtotal = ?, discount_amount = ?, total = ?, valid_until = ?::date,
                            client_notes = ?, internal_notes = ?, updated_by = ?, updated_at = now(),
                            status = 'draft', token_hash = null, rejected_at = null, reject_reason = null
                      where id = ?",
                    [...$row, $q->id]
                );
                $this->accounts->audit($actor, 'quote_updated', 'sales_quotes', $q->id, ['number' => $q->number, 'total' => $row[9]]);

                return $q->id;
            }
            $this->db()->select("select pg_advisory_xact_lock(hashtext('sales_quotes_number'))");
            $year = now()->format('Y');
            $next = (int) $this->db()->selectOne("select coalesce(max(substring(number from 10)::int), 0) + 1 as n from sales_quotes where number like ?", ["COT-{$year}-%"])->n;
            $number = sprintf('COT-%s-%04d', $year, $next);
            $newId = $this->db()->selectOne(
                "insert into sales_quotes (demo_request_id, contact_name, contact_email, contact_phone, billing_period, companies, annual_discount_pct,
                                           subtotal, discount_amount, total, valid_until, client_notes, internal_notes, created_by, number)
                 values (?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?::date, ?, ?, ?, ?) returning id",
                [...$row, $number]
            )->id;
            $this->accounts->audit($actor, 'quote_created', 'sales_quotes', $newId, ['number' => $number, 'total' => $row[9]]);

            return $newId;
        });
    }

    /** Genera el PDF y el enlace personal, y se lo envía al cliente. */
    public function send(string $id, string $actor): void
    {
        $q = $this->find($id);
        if (! in_array($q->status, ['draft', 'sent', 'rejected'], true)) {
            throw $this->conflict('Solo se envía una cotización en borrador, enviada o no aceptada.');
        }
        if (! $this->settings()['payment_instructions']) {
            throw $this->conflict('Antes de enviar, escribe los datos para el pago (cuenta bancaria, Yape…) en «Datos para el pago». El cliente los ve al aceptar.');
        }
        $companies = json_decode($q->companies, true);
        $this->checkCompanies($companies);
        // Se recalcula con los precios vigentes del panel al momento de enviar.
        $priced = $this->price($companies, $q->billing_period, (float) $q->discount_amount, true);
        $validUntil = $q->valid_until && $q->valid_until >= now()->toDateString()
            ? $q->valid_until : now()->addDays($this->settings()['quote_validity_days'])->toDateString();

        $token = Str::random(48);
        $this->db()->update(
            "update sales_quotes set companies = ?::jsonb, annual_discount_pct = ?, subtotal = ?, discount_amount = ?, total = ?, valid_until = ?::date,
                    status = 'sent', token_hash = ?, sent_at = now(), sent_count = sent_count + 1, rejected_at = null, reject_reason = null,
                    updated_by = ?, updated_at = now()
              where id = ?",
            [json_encode($priced['companies'], JSON_UNESCAPED_UNICODE), $priced['annual_discount_pct'], $priced['subtotal'], $priced['discount_amount'], $priced['total'], $validUntil, hash('sha256', $token), $actor, $id]
        );
        $q = $this->find($id);
        $pdf = $this->renderPdf($q);
        $fileId = $this->storeFile('quote_pdf', 'application/pdf', $pdf, "cotizacion-{$q->number}.pdf");
        $this->db()->update('update sales_quotes set pdf_file_id = ? where id = ?', [$fileId, $id]);
        if ($q->demo_request_id) {
            $this->db()->update("update demo_requests set status = 'contacted', contacted_at = coalesce(contacted_at, now()), updated_by = ? where id = ? and status = 'new'", [$actor, $q->demo_request_id]);
        }
        $this->accounts->audit($actor, 'quote_sent', 'sales_quotes', $id, ['number' => $q->number, 'total' => $q->total, 'to' => $q->contact_email]);

        $this->mailClient($q, "Cotización {$q->number}", 'Te enviamos la cotización de EscudoSST que pediste. La tienes adjunta en PDF. Desde el botón puedes revisarla y decirnos si la aceptas; si la aceptas, ahí mismo verás cómo pagar.',
            $this->summaryRows($q), 'La cotización vale hasta el '.$this->date($q->valid_until).'. Si tienes dudas, responde a este correo.',
            'Ver y responder la cotización', $this->link($token), [$pdf, "cotizacion-{$q->number}.pdf"], throw: true);
    }

    public function cancel(string $id, string $reason, string $actor): void
    {
        $q = $this->find($id);
        if (in_array($q->status, ['paid', 'cancelled'], true)) {
            throw $this->conflict('Esta cotización ya está cerrada.');
        }
        $this->db()->update("update sales_quotes set status = 'cancelled', cancel_reason = ?, token_hash = null, updated_by = ?, updated_at = now() where id = ?", [$reason, $actor, $id]);
        $this->accounts->audit($actor, 'quote_cancelled', 'sales_quotes', $id, ['number' => $q->number], $reason);
    }

    /** La constancia no sirve (ilegible, monto distinto…): vuelve a «aceptada» y el cliente puede subir otra. */
    public function rejectEvidence(string $id, string $reason, string $actor): void
    {
        $q = $this->find($id);
        if ($q->status !== 'payment_submitted') {
            throw $this->conflict('No hay una constancia por revisar.');
        }
        $token = Str::random(48);
        $this->db()->update(
            "update sales_quotes set status = 'accepted', evidence_rejected_reason = ?, token_hash = ?, updated_by = ?, updated_at = now() where id = ?",
            [$reason, hash('sha256', $token), $actor, $id]
        );
        $this->accounts->audit($actor, 'quote_payment_evidence_returned', 'sales_quotes', $id, ['number' => $q->number], $reason);
        $this->mailClient($q, "Revisa tu pago · {$q->number}", 'Revisamos la constancia de pago que enviaste y no pudimos validarla.', [['Motivo', $reason]],
            'Puedes enviar una nueva constancia desde el botón. Si ya pagaste, adjunta la constancia correcta.', 'Enviar otra constancia', $this->link($token));
    }

    /**
     * Confirma el pago y da de alta. Se puede confirmar con la constancia del cliente o sin ella (si el pago llegó
     * por otra vía) mientras la cotización esté aceptada.
     *
     * @param  array{paid_on: string, amount: float|string, method: string, reference?: ?string}  $d
     */
    public function confirmPayment(string $id, array $d, string $actor): array
    {
        $q = $this->find($id);
        if (! in_array($q->status, ['accepted', 'payment_submitted'], true)) {
            throw $this->conflict('Solo se confirma el pago de una cotización aceptada.');
        }
        $companies = json_decode($q->companies, true);
        $plan = $this->provisionPlan($companies);
        $amount = round((float) $d['amount'], 2);
        $shares = $this->shares($companies, $amount);
        $method = $d['method'];
        $reference = ($d['reference'] ?? null) ?: null;
        $note = "Cotización {$q->number}";

        $this->db()->update(
            'update sales_quotes set payment_method = ?, payment_reference = ?, payment_paid_on = ?::date, payment_amount = ?, updated_by = ? where id = ?',
            [$method, $reference, $d['paid_on'], $amount, $actor, $id]
        );

        // 1. La cuenta: la que ya tiene alguna de las empresas (p. ej. su prueba gratis) o una nueva con la primera empresa nueva.
        $accountId = $q->account_id ?? $plan['account_id'];
        $adminIds = [];
        foreach ($companies as $i => $c) {
            if (! empty($c['done'])) {
                continue;
            }
            $existing = $plan['existing'][$c['ruc']] ?? null;
            if ($existing) {
                $companies[$i]['company_id'] = $existing->company_id;
                $companies[$i]['subscription_id'] = $existing->subscription_id;
            } elseif (empty($c['company_id'])) {
                $companyData = [
                    'ruc' => $c['ruc'], 'legal_name' => $c['legal_name'], 'trade_name' => $c['trade_name'] ?: null, 'economic_sector' => $c['economic_sector'] ?: null,
                    'headcount' => (int) $c['headcount'], 'plan' => $c['plan_code'], 'billing_period' => $q->billing_period, 'prepaid' => true,
                ];
                if (! $accountId) {
                    $created = $this->accounts->create($companyData + [
                        'account_type' => count($companies) > 1 ? 'group' : 'direct', 'start' => 'active',
                        'admin_name' => $q->contact_name, 'admin_email' => $q->contact_email, 'billing_email' => $q->contact_email,
                        'billing_phone' => $q->contact_phone, 'notes' => $note,
                    ], $actor);
                    $accountId = $created['account_id'];
                    $this->db()->update('update sales_quotes set account_id = ? where id = ?', [$accountId, $id]);
                    $companies[$i]['company_id'] = $created['company_id'];
                    $companies[$i]['subscription_id'] = $created['subscription_id'];
                } else {
                    $adminIds = $adminIds ?: $this->accountAdminIds($accountId);
                    $added = $this->accounts->addCompany($accountId, $companyData, $adminIds, $actor);
                    $companies[$i]['company_id'] = $added['company_id'];
                    $companies[$i]['subscription_id'] = $added['subscription_id'];
                }
            }
            $this->saveProgress($id, $companies, $accountId);

            // 2. El pago de esta empresa (abre su periodo y la deja activa), su plan y sus complementos.
            $subId = $companies[$i]['subscription_id'];
            $this->subscriptions->registerPayment($subId, [
                'amount' => $shares[$i], 'method' => $method, 'paid_at' => $d['paid_on'], 'reference' => $reference, 'periods' => 1,
                'note' => $note.(count($companies) > 1 ? ' · '.$c['legal_name'] : ''),
            ], $actor, notify: false);
            if ($existing && $existing->plan_code !== $c['plan_code']) {
                $this->subscriptions->changePlan($subId, $c['plan_code'], $note, $actor);
            }
            $active = array_map(fn ($r) => trim($r->code), $this->db()->select(
                "select a.code from subscription_addons sa join addons a on a.id = sa.addon_id where sa.subscription_id = ? and sa.status = 'active' and sa.deleted_at is null", [$subId]
            ));
            $missing = array_values(array_diff($c['addon_codes'] ?? [], $active));
            if ($missing) {
                $this->subscriptions->addAddons($subId, $missing, $actor);
            }
            $companies[$i]['done'] = true;
            $this->saveProgress($id, $companies, $accountId);
        }

        $this->db()->update(
            "update sales_quotes set status = 'paid', confirmed_at = now(), confirmed_by = ?, token_hash = null, updated_by = ?, updated_at = now() where id = ?",
            [$actor, $actor, $id]
        );
        if ($q->demo_request_id) {
            $this->db()->update("update demo_requests set status = 'converted', converted_account_id = ?, updated_by = ? where id = ?", [$accountId, $actor, $q->demo_request_id]);
        }
        $this->accounts->audit($actor, 'quote_paid', 'sales_quotes', $id, ['number' => $q->number, 'amount' => $amount, 'method' => $method, 'account_id' => $accountId]);

        $isNew = $plan['account_id'] === null;
        $this->mailClient($q, "Pago confirmado · {$q->number}", 'Confirmamos tu pago. ¡Gracias por elegir EscudoSST!',
            [['Monto', $this->money($amount)], ['Empresas', (string) count($companies)]],
            $isNew ? 'En otro correo te llegó el enlace para crear tu contraseña. Con él entras al sistema.' : 'Tu cuenta ya quedó con el plan contratado. Entra con tu correo y contraseña de siempre.',
            'Ir a EscudoSST', rtrim((string) config('escudo.frontend_url'), '/').'/login');

        return ['account_id' => $accountId];
    }

    /** Archivo privado de la cotización (PDF o constancia) para descargarlo desde el panel. */
    public function file(string $id, string $kind): array
    {
        $q = $this->find($id);
        $fileId = $kind === 'pdf' ? $q->pdf_file_id : $q->evidence_file_id;

        return $this->readFile($fileId);
    }

    // ------------------------------------------------------------------ Cliente (enlace personal)

    public function publicView(string $token): array
    {
        $q = $this->byToken($token);
        $s = (array) $this->db()->selectOne('select legal_name, trade_name, ruc, payment_instructions from site_settings where singleton');
        $showPayment = in_array($q->status, ['accepted', 'payment_submitted'], true);

        return $this->present($q) + [
            'expired' => $this->expired($q),
            'provider' => ['legal_name' => $s['legal_name'], 'trade_name' => $s['trade_name'], 'ruc' => $s['ruc']],
            'payment_instructions' => $showPayment ? $s['payment_instructions'] : null,
            'evidence_rejected_reason' => $q->status === 'accepted' ? $q->evidence_rejected_reason : null,
            'methods' => self::METHODS,
        ];
    }

    public function publicPdf(string $token): array
    {
        return $this->readFile($this->byToken($token)->pdf_file_id);
    }

    public function accept(string $token, string $ipHash): void
    {
        $q = $this->byToken($token);
        if ($q->status === 'accepted') {
            return;
        }
        if ($q->status !== 'sent') {
            throw $this->conflict('Esta cotización ya no se puede aceptar.');
        }
        if ($this->expired($q)) {
            throw $this->conflict('La cotización venció. Escríbenos y te enviamos una actualizada.');
        }
        $this->db()->update("update sales_quotes set status = 'accepted', accepted_at = now(), accepted_ip_hash = decode(?, 'hex'), updated_at = now() where id = ?", [$ipHash, $q->id]);
        $this->accounts->audit(null, 'quote_accepted', 'sales_quotes', $q->id, ['number' => $q->number]);
        $this->notifyTeam($q, "Cotización aceptada: {$q->number}", 'El cliente aceptó la cotización. Ya ve los datos para pagar; te avisaremos cuando envíe su constancia.');
        $this->mailClient($q, "Aceptaste la cotización {$q->number}", 'Gracias. Estos son los datos para el pago. Cuando pagues, envíanos la constancia desde el mismo enlace.',
            [['Total a pagar', $this->money((float) $q->total)]], $this->settings()['payment_instructions'], 'Enviar mi constancia de pago', $this->link($token));
    }

    public function reject(string $token, ?string $reason): void
    {
        $q = $this->byToken($token);
        if ($q->status !== 'sent') {
            throw $this->conflict('Esta cotización ya no se puede cambiar.');
        }
        $this->db()->update("update sales_quotes set status = 'rejected', rejected_at = now(), reject_reason = ?, updated_at = now() where id = ?", [$reason ?: null, $q->id]);
        $this->accounts->audit(null, 'quote_rejected', 'sales_quotes', $q->id, ['number' => $q->number], $reason);
        $this->notifyTeam($q, "Cotización no aceptada: {$q->number}", 'El cliente indicó que no acepta la cotización.'.($reason ? " Motivo: {$reason}" : ''));
    }

    /** @param  array{method: string, paid_on: string, amount: float|string, reference?: ?string}  $d */
    public function submitPayment(string $token, array $d, UploadedFile $file): void
    {
        $q = $this->byToken($token);
        if ($q->status !== 'accepted') {
            throw $this->conflict($q->status === 'payment_submitted' ? 'Ya recibimos tu constancia: la estamos revisando.' : 'Primero acepta la cotización.');
        }
        $mime = $file->getMimeType();
        if (! isset(self::EVIDENCE_MIMES[$mime])) {
            throw $this->invalid(['file' => ['Sube la constancia en PDF, JPG, PNG o WEBP.']]);
        }
        $fileId = $this->storeFile('payment_evidence', $mime, (string) file_get_contents($file->getRealPath()), 'constancia-'.$q->number.'.'.self::EVIDENCE_MIMES[$mime]);
        $this->db()->update(
            "update sales_quotes set status = 'payment_submitted', evidence_file_id = ?, payment_method = ?, payment_paid_on = ?::date,
                    payment_amount = ?, payment_reference = ?, payment_submitted_at = now(), evidence_rejected_reason = null, updated_at = now()
              where id = ?",
            [$fileId, $d['method'], $d['paid_on'], round((float) $d['amount'], 2), ($d['reference'] ?? null) ?: null, $q->id]
        );
        $this->accounts->audit(null, 'quote_payment_submitted', 'sales_quotes', $q->id, ['number' => $q->number, 'amount' => (float) $d['amount'], 'method' => $d['method']]);
        $this->notifyTeam($q, "Constancia de pago recibida: {$q->number}", 'El cliente envió su constancia de pago. Revísala y confirma el pago para crear su cuenta.', [
            ['Monto declarado', $this->money((float) $d['amount'])], ['Total de la cotización', $this->money((float) $q->total)],
            ['Medio', self::METHODS[$d['method']] ?? $d['method']], ['Fecha de pago', $this->date($d['paid_on'])],
        ]);
    }

    // ------------------------------------------------------------------ Reglas

    /** @return list<array<string, mixed>> */
    private function normalizeCompanies(array $companies): array
    {
        $out = [];
        foreach (array_values($companies) as $c) {
            $out[] = [
                'ruc' => preg_replace('/\D/', '', (string) $c['ruc']),
                'legal_name' => trim((string) $c['legal_name']),
                'trade_name' => trim((string) ($c['trade_name'] ?? '')),
                'headcount' => (int) $c['headcount'],
                'economic_sector' => in_array($c['economic_sector'] ?? null, self::SECTORS, true) ? $c['economic_sector'] : null,
                'plan_code' => $c['plan_code'],
                'addon_codes' => array_values(array_unique(array_map(fn ($a) => Str::upper((string) $a), $c['addon_codes'] ?? []))),
            ];
        }

        return $out;
    }

    /**
     * Cada RUC es una empresa única en EscudoSST. Si alguna ya existe (p. ej. hizo la prueba gratis), todas las que ya
     * existen deben ser de la misma cuenta: al pagar se activa esa cuenta y las nuevas se le agregan.
     */
    private function checkCompanies(array $companies): void
    {
        $seen = [];
        foreach ($companies as $i => $c) {
            $f = "companies.{$i}.ruc";
            if (! Ruc::isValid($c['ruc'])) {
                throw $this->invalid([$f => ["El RUC {$c['ruc']} no es válido: revisa los 11 dígitos."]]);
            }
            if (isset($seen[$c['ruc']])) {
                throw $this->invalid([$f => ['Ese RUC está repetido en la cotización.']]);
            }
            $seen[$c['ruc']] = true;
        }
        $this->provisionPlan($companies);
    }

    /** @return array{account_id: ?string, existing: array<string, object>} */
    private function provisionPlan(array $companies): array
    {
        $existing = [];
        $accounts = [];
        foreach ($companies as $i => $c) {
            $row = $this->db()->selectOne(
                "select c.id as company_id, c.account_id, c.managed_by_account_id, a.account_type, s.id as subscription_id, s.status, p.code as plan_code
                   from companies c join accounts a on a.id = c.account_id
                   left join lateral (select * from subscriptions s where s.company_id = c.id and s.status <> 'cancelled' and s.deleted_at is null
                                       order by s.created_at desc limit 1) s on true
                   left join plans p on p.id = s.plan_id
                  where c.ruc = ? and c.deleted_at is null",
                [$c['ruc']]
            );
            if (! $row) {
                continue;
            }
            $f = "companies.{$i}.ruc";
            if ($row->managed_by_account_id || $row->account_type === 'consultancy' || $row->plan_code === 'K') {
                throw $this->invalid([$f => ["El RUC {$c['ruc']} lo gestiona una consultora: su plan se ve con esa consultora."]]);
            }
            if (! $row->subscription_id || in_array($row->status, ['pending_cancellation'], true)) {
                throw $this->invalid([$f => ["El RUC {$c['ruc']} ya está en EscudoSST con una suscripción que no se puede renovar desde aquí. Revísala en «Cuentas y suscripciones»."]]);
            }
            $existing[$c['ruc']] = $row;
            $accounts[$row->account_id] = true;
        }
        if (count($accounts) > 1) {
            throw $this->invalid(['companies' => ['Algunas empresas ya están en EscudoSST en cuentas distintas. Una cotización solo puede renovar o ampliar una cuenta.']]);
        }

        return ['account_id' => array_key_first($accounts), 'existing' => $existing];
    }

    /**
     * Precio de cada empresa: plan + complementos (mensual de referencia); anual = 12 × mensual − descuento anual.
     * Al enviar ($strict) todo debe tener precio; en borrador lo que no tiene precio queda como «por definir».
     */
    private function price(array $companies, string $period, float $discount, bool $strict): array
    {
        $plans = collect($this->db()->select("select code, name, price_reference from plans where is_active and deleted_at is null and code <> 'K'"))->keyBy(fn ($p) => trim($p->code));
        $addons = collect($this->db()->select('select code, name, price_reference from addons where is_active and deleted_at is null'))->keyBy(fn ($a) => trim($a->code));
        $pct = Pricing::annualDiscountPct('platform');
        $subtotal = 0.0;
        $complete = true;
        $out = [];
        foreach ($companies as $i => $c) {
            $plan = $plans[$c['plan_code']] ?? null;
            if (! $plan) {
                throw $this->invalid(["companies.{$i}.plan_code" => ['Elige un plan activo (Esencial, Profesional o Corporativo).']]);
            }
            $lines = [['code' => $plan->code, 'name' => "Plan {$plan->name}", 'monthly' => $plan->price_reference]];
            foreach ($c['addon_codes'] ?? [] as $code) {
                $a = $addons[$code] ?? throw $this->invalid(["companies.{$i}.addon_codes" => ["El complemento {$code} no existe o no está activo."]]);
                $lines[] = ['code' => trim($a->code), 'name' => $a->name, 'monthly' => $a->price_reference];
            }
            $missing = collect($lines)->whereNull('monthly')->pluck('name')->all();
            if ($missing) {
                $complete = false;
                if ($strict) {
                    throw $this->conflict('Falta el precio de: '.implode(', ', $missing).'. Defínelo en «Planes y precios» antes de enviar.');
                }
            }
            $monthly = (float) collect($lines)->sum(fn ($l) => (float) $l['monthly']);
            $amount = Pricing::forPeriod($monthly, $period, $pct);
            foreach ($lines as &$l) {
                $l['amount'] = $l['monthly'] === null ? null : Pricing::forPeriod((float) $l['monthly'], $period, $pct);
            }
            unset($l);
            $subtotal += $amount;
            $out[] = $c + ['lines' => $lines, 'monthly' => round($monthly, 2), 'amount' => $amount];
        }
        $subtotal = round($subtotal, 2);
        if ($discount > $subtotal) {
            throw $this->invalid(['discount_amount' => ['El descuento no puede ser mayor que el subtotal.']]);
        }
        if ($strict && $subtotal - $discount <= 0) {
            throw $this->conflict('El total de la cotización debe ser mayor que cero.');
        }

        return [
            'companies' => $out, 'annual_discount_pct' => $pct, 'subtotal' => $subtotal, 'discount_amount' => round($discount, 2),
            'total' => round($subtotal - $discount, 2), 'complete' => $complete,
        ];
    }

    /** Reparte el monto pagado entre las empresas según el peso de cada una (la última se lleva el redondeo). */
    private function shares(array $companies, float $amount): array
    {
        $sum = array_sum(array_map(fn ($c) => (float) $c['amount'], $companies)) ?: 1.0;
        $out = [];
        $left = $amount;
        $last = count($companies) - 1;
        foreach (array_values($companies) as $i => $c) {
            $out[$i] = $i === $last ? round($left, 2) : round($amount * (float) $c['amount'] / $sum, 2);
            $left -= $out[$i];
        }

        return $out;
    }

    private function accountAdminIds(string $accountId): array
    {
        $first = $this->db()->selectOne('select id from companies where account_id = ? and deleted_at is null order by created_at limit 1', [$accountId]);

        return $first ? array_column($this->accounts->companyAdmins($first->id), 'user_id') : [];
    }

    private function saveProgress(string $id, array $companies, ?string $accountId): void
    {
        $this->db()->update('update sales_quotes set companies = ?::jsonb, account_id = coalesce(account_id, ?) where id = ?', [json_encode($companies, JSON_UNESCAPED_UNICODE), $accountId, $id]);
    }

    // ------------------------------------------------------------------ Apoyo

    private function present(object $q): array
    {
        return [
            'id' => $q->id, 'number' => $q->number, 'status' => $q->status, 'status_label' => self::STATUS[$q->status],
            'contact_name' => $q->contact_name, 'contact_email' => $q->contact_email, 'contact_phone' => $q->contact_phone,
            'billing_period' => $q->billing_period, 'companies' => json_decode($q->companies, true),
            'annual_discount_pct' => (float) $q->annual_discount_pct, 'subtotal' => $q->subtotal, 'discount_amount' => $q->discount_amount,
            'total' => $q->total, 'currency' => $q->currency, 'valid_until' => $q->valid_until, 'client_notes' => $q->client_notes,
            'sent_at' => $q->sent_at, 'accepted_at' => $q->accepted_at, 'rejected_at' => $q->rejected_at, 'reject_reason' => $q->reject_reason,
            'payment' => $q->payment_submitted_at || $q->payment_amount ? [
                'method' => $q->payment_method, 'method_label' => self::METHODS[$q->payment_method] ?? null, 'reference' => $q->payment_reference,
                'paid_on' => $q->payment_paid_on, 'amount' => $q->payment_amount, 'submitted_at' => $q->payment_submitted_at,
            ] : null,
            'created_at' => $q->created_at,
        ];
    }

    private function find(string $id): object
    {
        $q = Str::isUuid($id) ? $this->db()->selectOne('select * from sales_quotes where id = ? and deleted_at is null', [$id]) : null;

        return $q ?? throw ApiProblem::notFound('La cotización no existe.');
    }

    private function byToken(string $token): object
    {
        $q = strlen($token) === 48 ? $this->db()->selectOne('select * from sales_quotes where token_hash = ? and deleted_at is null', [hash('sha256', $token)]) : null;

        return $q ?? throw ApiProblem::notFound('El enlace no es válido o la cotización fue actualizada. Revisa el último correo que te enviamos.');
    }

    private function expired(object $q): bool
    {
        return $q->status === 'sent' && $q->valid_until !== null && $q->valid_until < now()->toDateString();
    }

    private function link(string $token): string
    {
        return rtrim((string) config('escudo.frontend_url'), '/')."/cotizacion/{$token}";
    }

    private function summaryRows(object $q): array
    {
        $rows = [];
        foreach (json_decode($q->companies, true) as $c) {
            $rows[] = [$c['legal_name'].' (RUC '.$c['ruc'].')', $this->money((float) $c['amount'])];
        }
        if ((float) $q->discount_amount > 0) {
            $rows[] = ['Descuento', '− '.$this->money((float) $q->discount_amount)];
        }
        $rows[] = ['Total '.($q->billing_period === 'annual' ? 'por el año' : 'por el mes'), $this->money((float) $q->total)];

        return $rows;
    }

    private function renderPdf(object $q): string
    {
        $s = (array) $this->db()->selectOne('select legal_name, trade_name, ruc, legal_address, contact_email, sales_email, contact_phone from site_settings where singleton');
        $html = view('reports.quote', [
            'q' => $q, 'companies' => json_decode($q->companies, true), 's' => $s, 'period' => $q->billing_period === 'annual' ? 'anual' : 'mensual',
            'issued' => now()->format('d/m/Y'), 'valid' => $this->date($q->valid_until), 'money' => fn ($v) => $v === null ? 'Por definir' : $this->money((float) $v),
        ])->render();
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function storeFile(string $kind, string $mime, string $content, string $name): string
    {
        $disk = config('escudo.site_assets_disk', 'local');
        $id = (string) Str::uuid7();
        $ext = self::EVIDENCE_MIMES[$mime] ?? 'bin';
        $path = "private/{$kind}-{$id}.{$ext}";
        Storage::disk($disk)->put($path, $content);
        $this->db()->insert(
            'insert into site_assets (id, kind, disk, path, mime_type, size_bytes, original_name) values (?, ?, ?, ?, ?, ?, ?)',
            [$id, $kind, $disk, $path, $mime, strlen($content), mb_substr($name, 0, 200)]
        );

        return $id;
    }

    /** @return array{content: string, mime: string, name: string} */
    private function readFile(?string $fileId): array
    {
        $f = $fileId ? $this->db()->selectOne('select disk, path, mime_type, original_name from site_assets where id = ?', [$fileId]) : null;
        if (! $f || ! Storage::disk($f->disk)->exists($f->path)) {
            throw ApiProblem::notFound('El archivo no existe.');
        }

        return ['content' => (string) Storage::disk($f->disk)->get($f->path), 'mime' => $f->mime_type, 'name' => $f->original_name];
    }

    private function mailClient(object $q, string $title, string $intro, array $rows, ?string $note, string $btn, string $url, ?array $pdf = null, bool $throw = false): void
    {
        try {
            Mail::to($q->contact_email)->send(new SiteRequestMail(explode(' ', trim($q->contact_name))[0], $title, $intro, $rows, $note, $btn, $url, attachment: $pdf));
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el correo de la cotización', ['quote' => $q->number, 'error' => $e->getMessage()]);
            if ($throw) {
                throw $this->conflict('La cotización quedó lista, pero no se pudo enviar el correo. Revisa el correo del cliente e inténtalo otra vez.');
            }
        }
    }

    /** Aviso al equipo: correo de ventas (o contacto) y, si no hay, a los Dueños de la plataforma. También sale en la campana. */
    private function notifyTeam(object $q, string $title, string $intro, array $rows = []): void
    {
        $s = $this->db()->selectOne('select sales_email, contact_email from site_settings where singleton');
        $to = array_filter([$s->sales_email ?: $s->contact_email]);
        if (! $to) {
            $to = array_column($this->db()->select("select email from users where platform_role = 'owner' and status = 'active' and deleted_at is null"), 'email');
        }
        $rows = array_merge([['Cliente', $q->contact_name.' · '.$q->contact_email], ['Total', $this->money((float) $q->total)]], $rows);
        foreach ($to as $email) {
            try {
                Mail::to($email)->send(new SiteRequestMail('equipo', $title, $intro, $rows, null, 'Ver la cotización', rtrim((string) config('escudo.frontend_url'), '/')."/plataforma/sitio/cotizaciones/{$q->id}"));
            } catch (\Throwable $e) {
                Log::warning('No se pudo avisar al equipo de la cotización', ['quote' => $q->number, 'error' => $e->getMessage()]);
            }
        }
    }

    private function money(float $v): string
    {
        return 'S/ '.number_format($v, 2, '.', ',');
    }

    private function date(?string $d): string
    {
        return $d ? \Carbon\Carbon::parse($d)->locale('es')->isoFormat('D [de] MMMM [de] YYYY') : '';
    }

    /** @param  array<string, list<string>>  $errors */
    private function invalid(array $errors): ApiProblem
    {
        return new ApiProblem(422, 'validation_failed', 'Revisa los datos', (string) collect($errors)->flatten()->first(), ['errors' => $errors]);
    }

    private function conflict(string $detail): ApiProblem
    {
        return new ApiProblem(409, 'conflict', 'No se puede hacer', $detail);
    }
}

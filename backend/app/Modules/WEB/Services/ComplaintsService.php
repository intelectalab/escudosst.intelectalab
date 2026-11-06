<?php

namespace App\Modules\WEB\Services;

use App\Core\Http\ApiProblem;
use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Libro de Reclamaciones virtual de EscudoSST (Ley 29571, D.S. 011-2011-PCM; SCR-WEB-17, 18 y 25).
 *
 * - Los datos personales del consumidor se cifran aquí antes de guardarse (columnas «[CIFRADO EN APLICACIÓN]»).
 * - El correlativo LR-AAAA-NNNNNN, la fecha límite en días hábiles y la conservación los calcula la base
 *   (public_submit_complaint) con los parámetros legales vigentes; las acciones del panel van por web_complaint_action.
 * - El consumidor consulta su hoja con el código + una clave de seguimiento que solo él recibe (en pantalla y por correo).
 */
class ComplaintsService
{
    /** Columnas que se guardan cifradas. */
    private const ENCRYPTED = [
        'consumer_full_name', 'consumer_document_number', 'consumer_address', 'consumer_email', 'consumer_phone',
        'guardian_full_name', 'guardian_document_number', 'guardian_email', 'guardian_phone',
    ];

    public const TYPES = ['claim' => 'Reclamo', 'complaint' => 'Queja'];

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    /** Datos que muestra la hoja pública: proveedor (copiados de Marca y ajustes) y plazo de respuesta vigente. */
    public function publicInfo(): array
    {
        $s = json_decode(DB::selectOne('select public_site_settings() as s')->s, true);

        return [
            'provider' => [
                'legal_name' => $s['legal_name'] ?? null,
                'trade_name' => $s['trade_name'] ?? null,
                'ruc' => isset($s['ruc']) ? trim($s['ruc']) : null,
                'legal_address' => $s['legal_address'] ?? null,
            ],
            'response_days' => (int) ($this->param('web.complaint_response_days') ?? 0) ?: null,
            'ready' => ! empty($s['legal_address']),
        ];
    }

    public function submit(array $data, string $ipHash): array
    {
        $payload = $data;
        foreach (self::ENCRYPTED as $k) {
            if (isset($payload[$k]) && $payload[$k] !== '') {
                $payload[$k] = Crypt::encryptString((string) $payload[$k]);
            }
        }
        $trackingKey = Str::upper(Str::random(20));
        $r = json_decode($this->db()->selectOne(
            "select public_submit_complaint(?::jsonb, ?, decode(?, 'hex')) as r",
            [json_encode($payload, JSON_UNESCAPED_UNICODE), $trackingKey, $ipHash]
        )->r, true);

        $this->sendAcknowledgment($r['complaint_code'], $trackingKey, $data, $r);

        return [
            'complaint_code' => $r['complaint_code'],
            'filed_at' => $r['filed_at'],
            'response_due_on' => $r['response_due_on'],
            'tracking_key' => $trackingKey,
        ];
    }

    public function status(string $code, string $key): array
    {
        $r = json_decode($this->db()->selectOne('select public_complaint_status(?, ?) as r', [Str::upper(trim($code)), Str::upper(trim($key))])->r, true);
        if (($r['result'] ?? '') !== 'ok') {
            throw ValidationException::withMessages(['code' => 'No encontramos una hoja con ese código y clave. Revisa la constancia que te enviamos.']);
        }
        unset($r['result']);

        return $r;
    }

    // ---------------------------------------------------------------- Panel

    /** Plazos vigentes (parámetros legales) para explicarlos en el panel. */
    public function rules(): array
    {
        return [
            'response_days' => (int) ($this->param('web.complaint_response_days') ?? 0) ?: null,
            'extension_days' => (int) ($this->param('web.complaint_extension_days') ?? 0) ?: null,
            'warning_days' => (int) ($this->param('web.complaint_warning_days') ?? 0) ?: null,
            'retention_years' => (int) ($this->param('web.complaint_retention_years') ?? 0) ?: null,
        ];
    }

    public function list(): array
    {
        $warning = (int) ($this->param('web.complaint_warning_days') ?? 3);
        $rows = $this->db()->select(
            "select id, complaint_code, complaint_type, filed_at, status, consumer_full_name, item_type,
                    response_due_on, extended_until, answered_at, closed_at,
                    (coalesce(extended_until, response_due_on) - (now() at time zone 'America/Lima')::date) as days_left
               from consumer_complaints order by filed_at desc limit 500"
        );

        return array_map(function ($r) use ($warning) {
            $done = in_array($r->status, ['answered', 'closed'], true);
            $left = (int) $r->days_left;

            return [
                'id' => $r->id,
                'complaint_code' => $r->complaint_code,
                'complaint_type' => $r->complaint_type,
                'filed_at' => $r->filed_at,
                'status' => $r->status,
                'consumer_name' => $this->decrypt($r->consumer_full_name),
                'item_type' => $r->item_type,
                'due_on' => $r->extended_until ?? $r->response_due_on,
                'days_left' => $done ? null : $left,
                // Semáforo (SCR-WEB-25): verde en plazo, ámbar por vencer, rojo vencido, gris atendido.
                'light' => $done ? 'gray' : ($left < 0 ? 'red' : ($left <= $warning ? 'amber' : 'green')),
            ];
        }, $rows);
    }

    /**
     * Reporte para Indecopi (parámetro web.complaint_report_format): código, fecha, tipo, estado, respuesta y si fue
     * a tiempo. Sin datos personales del consumidor. Fechas en hora de Lima.
     *
     * @return array<int, array<int, string>>
     */
    public function report(?string $from, ?string $to): array
    {
        $rows = $this->db()->select(
            "select complaint_code, to_char(filed_at at time zone 'America/Lima', 'DD/MM/YYYY HH24:MI') as filed,
                    complaint_type, item_type, status, response_due_on, extended_until,
                    to_char(answered_at at time zone 'America/Lima', 'DD/MM/YYYY HH24:MI') as answered,
                    (answered_at at time zone 'America/Lima')::date <= coalesce(extended_until, response_due_on) as on_time
               from consumer_complaints
              where (?::date is null or (filed_at at time zone 'America/Lima')::date >= ?::date)
                and (?::date is null or (filed_at at time zone 'America/Lima')::date <= ?::date)
              order by filed_at",
            [$from, $from, $to, $to]
        );
        $status = ['received' => 'Recibida', 'in_progress' => 'En revisión', 'extended' => 'Prorrogada', 'answered' => 'Respondida', 'closed' => 'Cerrada'];
        $d = fn (?string $x) => $x ? date('d/m/Y', strtotime($x)) : '';
        $out = [['Código', 'Fecha de registro', 'Tipo', 'Bien contratado', 'Estado', 'Fecha límite', 'Prorrogada hasta', 'Fecha de respuesta', '¿Respondida a tiempo?']];
        foreach ($rows as $r) {
            $out[] = [
                $r->complaint_code, $r->filed, self::TYPES[$r->complaint_type], $r->item_type === 'product' ? 'Producto' : 'Servicio',
                $status[$r->status], $d($r->response_due_on), $d($r->extended_until), $r->answered ?? '',
                $r->answered === null ? 'Pendiente' : ($r->on_time ? 'Sí' : 'No'),
            ];
        }

        return $out;
    }

    public function detail(string $id): array
    {
        $c = $this->db()->selectOne('select * from consumer_complaints where id = ?', [$id]);
        if (! $c) {
            throw ApiProblem::notFound('La hoja no existe.');
        }
        $row = (array) $c;
        foreach (self::ENCRYPTED as $k) {
            $row[$k] = $this->decrypt($row[$k]);
        }
        unset($row['tracking_key_hash'], $row['consumer_ip_hash'], $row['idempotency_key']);
        $row['provider_snapshot'] = json_decode($row['provider_snapshot'], true);
        $extDays = (int) ($this->param('web.complaint_extension_days') ?? 0);
        $row['max_extension_on'] = $extDays > 0
            ? $this->db()->selectOne('select add_business_days(?::date, ?) as d', [$c->response_due_on, $extDays])->d
            : null;
        $row['events'] = $this->db()->select(
            "select e.event_type, e.from_status, e.to_status, e.reason, e.details, e.actor_type, e.occurred_at, u.full_name as actor_name
               from complaint_events e left join users u on u.id = e.actor_user_id
              where e.consumer_complaint_id = ? order by e.occurred_at",
            [$id]
        );

        return $row;
    }

    /** Acción del panel: start | extend | answer | close | note. Responder envía la respuesta al correo del consumidor. */
    public function act(string $id, array $data, string $actor): void
    {
        $c = $this->db()->selectOne('select id, status, complaint_code, consumer_full_name, consumer_email, complaint_type from consumer_complaints where id = ?', [$id]);
        if (! $c) {
            throw ApiProblem::notFound('La hoja no existe.');
        }
        $action = $data['action'];
        $allowed = [
            'start' => ['received'],
            'extend' => ['received', 'in_progress'],
            'answer' => ['received', 'in_progress', 'extended'],
            'close' => ['answered'],
            'note' => ['received', 'in_progress', 'extended', 'answered', 'closed'],
        ][$action];
        if (! in_array($c->status, $allowed, true)) {
            throw new ApiProblem(409, 'conflict', 'No se puede realizar la acción', 'Esa acción no corresponde al estado actual de la hoja.');
        }

        $this->db()->transaction(function () use ($id, $data, $actor, $action) {
            $this->db()->select("select set_config('app.user_id', ?, true)", [$actor]);
            try {
                $this->db()->select('select web_complaint_action(?, ?, ?, ?::date, ?) as r', [
                    $id, $action, $data['text'] ?? null, $data['until'] ?? null, $action === 'answer' ? ($data['channel'] ?? 'email') : null,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'prórroga excede')) {
                    throw ValidationException::withMessages(['until' => 'La nueva fecha supera la prórroga máxima permitida.']);
                }
                throw $e;
            }
        });

        if ($action === 'answer' && ($data['channel'] ?? 'email') === 'email') {
            $this->sendResponse($c, $data['text']);
        }
        // La prórroga se le comunica al consumidor antes de que venza el plazo (D.S. 011-2011-PCM).
        if ($action === 'extend') {
            $this->sendExtension($c, $data['text'], $data['until']);
        }
    }

    // ---------------------------------------------------------------- Correos

    private function sendAcknowledgment(string $code, string $key, array $d, array $r): void
    {
        $site = rtrim(config('escudo.frontend_url'), '/');
        $type = self::TYPES[$d['complaint_type']];
        $due = date('d/m/Y', strtotime($r['response_due_on']));
        $rows = [
            ['Código', $code],
            ['Clave de seguimiento', $key],
            ['Tipo', $type],
            ['Registrada', now('America/Lima')->format('d/m/Y H:i')],
            ['Respuesta a más tardar', $due],
        ];
        $first = explode(' ', trim($d['consumer_full_name']))[0];
        try {
            Mail::to($d['consumer_email'])->send(new SiteRequestMail(
                $first, "Constancia de tu {$type} {$code}",
                "Registramos tu hoja en el Libro de Reclamaciones de EscudoSST. Guarda este correo: es tu constancia. Te responderemos a este correo.",
                $rows, "Detalle: {$d['detail']}\nPedido: {$d['consumer_request']}",
                'Ver el estado de mi hoja', "{$site}/libro-de-reclamaciones/seguimiento?codigo={$code}"
            ));
            $officer = $this->db()->selectOne('select complaints_officer_email from site_settings where singleton and deleted_at is null')?->complaints_officer_email;
            if ($officer) {
                Mail::to($officer)->send(new SiteRequestMail(
                    'equipo', "Nueva hoja del Libro de Reclamaciones: {$code}",
                    "Llegó un {$type} desde el sitio web. El plazo para responder vence el {$due}.",
                    [['Código', $code], ['Tipo', $type], ['Vence', $due]], null,
                    'Atender en el panel', "{$site}/plataforma/sitio/reclamos"
                ));
            }
            $id = $this->db()->selectOne('select id from consumer_complaints where complaint_code = ?', [$code])->id;
            $this->db()->select("select web_complaint_action(?, 'acknowledge')", [$id]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar la constancia del Libro de Reclamaciones', ['code' => $code, 'error' => $e->getMessage()]);
        }
    }

    private function sendExtension(object $c, string $reason, string $until): void
    {
        try {
            Mail::to($this->decrypt($c->consumer_email))->send(new SiteRequestMail(
                explode(' ', trim((string) $this->decrypt($c->consumer_full_name)))[0],
                'Necesitamos más tiempo para responder tu '.mb_strtolower(self::TYPES[$c->complaint_type])." {$c->complaint_code}",
                'Estamos revisando tu caso con cuidado y necesitamos unos días más para darte una respuesta completa.',
                [['Nueva fecha de respuesta', date('d/m/Y', strtotime($until))]],
                "Motivo: {$reason}"
            ));
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar la prórroga del Libro de Reclamaciones', ['code' => $c->complaint_code, 'error' => $e->getMessage()]);
        }
    }

    private function sendResponse(object $c, string $text): void
    {
        try {
            Mail::to($this->decrypt($c->consumer_email))->send(new SiteRequestMail(
                explode(' ', trim((string) $this->decrypt($c->consumer_full_name)))[0],
                "Respuesta a tu ".mb_strtolower(self::TYPES[$c->complaint_type])." {$c->complaint_code}",
                $text, [], 'Este correo es la respuesta formal a tu hoja del Libro de Reclamaciones de EscudoSST.'
            ));
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar la respuesta del Libro de Reclamaciones', ['code' => $c->complaint_code, 'error' => $e->getMessage()]);
        }
    }

    // ---------------------------------------------------------------- Apoyo

    private function decrypt(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return $v;
        }
        try {
            return Crypt::decryptString($v);
        } catch (\Throwable) {
            return $v;   // dato antiguo sin cifrar
        }
    }

    private function param(string $key): ?string
    {
        $v = DB::selectOne(
            "select value from legal_parameters where company_id is null and key = ? and deleted_at is null
                and valid_from <= current_date and (valid_to is null or valid_to >= current_date) order by valid_from desc limit 1",
            [$key]
        );

        return $v && is_numeric($v->value) ? $v->value : null;
    }
}

<?php

namespace App\Modules\AUL;

use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisos §10 del aula virtual (M-AUL) y de la venta de cursos (M-VTA).
 *
 * Inmediatos (los llama la acción que los origina; nunca la interrumpen):
 *  aul.enrolled          matrícula en un curso → participante (app; correo y WhatsApp vía AlertDelivery; correo directo
 *                        al participante externo sin cuenta, salvo la matrícula por compra, que ya avisa vta.order_paid)
 *  aul.course_completed  curso completado → participante y capacitadores del curso
 *  vta.order_paid        pedido pagado y acceso al curso → comprador y participante (correo)
 *  vta.licenses_available cupos comprados disponibles → representante de la empresa compradora (correo)
 * Cada hora (escudo:aul-alerts), por empresa con el módulo activo:
 *  aul.course_due        curso por vencer sin completar (course_due_notice_days) → participante y jefe del área
 *  aul.inactive_learner  participante sin actividad (aul_inactive_learner_days) → capacitadores del curso y Jefe SST
 *  aul.recommendations_pending resumen diario (desde digest_hour) de recomendaciones por aprobar → Jefe SST
 *  vta.order_expiring    pedido por vencer sin pago (order_expiry_notice_minutes) → comprador (correo)
 *  vta.paid_without_enrollment pedido pagado con una línea sin matrícula ni cupos → Jefe SST (administra la venta)
 *  y repasa las matrículas y finalizaciones del último día que llegaron por otra vía (sin repetir avisos).
 * Los avisos de la app no se repiten (dedupe_key con el destinatario); los correos a personas sin cuenta, tampoco
 * (marca en caché con la misma clave). Ningún texto lleva datos médicos ni de pago con tarjeta.
 */
final class AulAlerts
{
    public function __construct(private AccountsService $accounts) {}

    /** @return int avisos creados */
    public function run(): int
    {
        $companies = DB::connection('platform')->select(
            "select c.id from companies c join subscriptions s on s.company_id = c.id
              where c.deleted_at is null and c.status = 'active' and s.deleted_at is null and s.status in ('trialing','active','past_due','grace')"
        );
        $total = 0;
        foreach ($companies as $c) {
            try {
                $total += $this->accounts->withCompany($c->id, fn (ConnectionInterface $db) => $this->forCompany($db, $c->id));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $total;
    }

    /** @param ?bool $digest null = el resumen de recomendaciones solo desde la hora digest_hour (Lima); true/false para forzarlo. */
    public function forCompany(ConnectionInterface $db, string $company, ?bool $digest = null): int
    {
        $enabled = array_column($db->select("select module_code from feature_flags where mode = 'enabled' and deleted_at is null and module_code in ('AUL', 'VTA')"), 'module_code');
        $n = 0;
        if (in_array('AUL', $enabled, true)) {
            $recent = fn (string $col, string $status) => array_column($db->select(
                "select id from course_enrollments where deleted_at is null and {$col} > now() - interval '1 day' and status {$status}"), 'id');
            $n += self::enrolled($recent('coalesce(enrolled_at, created_at)', "<> 'cancelled'"), $db, $company);
            $n += self::completedMany($recent('completed_at', "= 'completed'"), $db, $company);
            $n += $this->dueSoon($db, $company);
            $n += $this->inactive($db, $company);
            if ($digest ?? ((int) now('America/Lima')->format('G') >= self::days('digest_hour', 7))) {
                $n += $this->pendingRecommendations($db, $company);
            }
        }
        if (in_array('VTA', $enabled, true)) {
            $n += $this->ordersExpiring($db, $company);
            $n += $this->paidWithoutEnrollment($db, $company);
        }

        return $n;
    }

    // ------------------------------------------------------------------ Inmediatos

    /** M-AUL §10 «Matrícula en un curso» → participante. @param list<string> $ids matrículas */
    public static function enrolled(array $ids, ?ConnectionInterface $db = null, ?string $company = null): int
    {
        return self::safely($db, $company, function (ConnectionInterface $db, string $company) use ($ids) {
            $n = 0;
            foreach (self::participants($db, $ids) as $p) {
                $body = "Curso «{$p->title}».".($p->due_on ? ' Fecha límite: '.self::d($p->due_on).'.' : '').($p->access_until ? ' Tienes acceso hasta el '.self::d($p->access_until).'.' : '');
                $key = "aul.enrolled:{$p->id}";
                $n += self::note($db, $company, 'aul.enrolled', $p->id, $p->user_id, $p->worker_id, 'Te matricularon en un curso', $body, self::learnerLink($p), 'normal', $key);
                // La matrícula por compra ya se informa en el correo del pedido pagado (vta.order_paid).
                if ($p->email && $p->source !== 'purchase') {
                    $n += self::mail($db, $company, $p->email, $p->first_name, 'Te matricularon en un curso', $body, $key);
                }
            }

            return $n;
        });
    }

    /** M-AUL §10 «Curso completado» → participante y capacitadores del curso. */
    public static function completed(string $id): int
    {
        return self::completedMany([$id]);
    }

    /** @param list<string> $ids */
    private static function completedMany(array $ids, ?ConnectionInterface $db = null, ?string $company = null): int
    {
        return self::safely($db, $company, function (ConnectionInterface $db, string $company) use ($ids) {
            $n = 0;
            foreach (self::participants($db, $ids) as $p) {
                if ($p->status !== 'completed') {
                    continue;
                }
                $cert = $p->certificate_id ? $db->selectOne('select certificate_code from training_certificates where id = ?', [$p->certificate_id])?->certificate_code : null;
                $body = "Terminaste el curso «{$p->title}».".($p->final_score_label ? " Nota final: {$p->final_score_label}." : '').($cert ? " Se emitió tu certificado {$cert}." : '');
                $key = "aul.course_completed:{$p->id}";
                $n += self::note($db, $company, 'aul.course_completed', $p->id, $p->user_id, $p->worker_id, 'Completaste un curso', $body, self::learnerLink($p), 'normal', $key);
                if ($p->email) {
                    $n += self::mail($db, $company, $p->email, $p->first_name, 'Completaste un curso', $body, $key);
                }
                foreach (self::trainers($db, $p->course_id) as $t) {
                    if ($t !== $p->user_id) {
                        $n += self::note($db, $company, 'aul.course_completed', $p->id, $t, null, 'Participante completó un curso', "{$p->name} completó el curso «{$p->title}».",
                            '/m/aul/'.$p->course_id, 'normal', $key);
                    }
                }
            }

            return $n;
        });
    }

    /**
     * M-VTA §10 «Pedido pagado y acceso al curso» (comprador y participante) y «Cupos comprados disponibles»
     * (representante de la empresa compradora), por correo al registrarse el pago.
     */
    public static function orderPaid(string $orderId): int
    {
        return self::safely(null, null, function (ConnectionInterface $db, string $company) use ($orderId) {
            $o = $db->selectOne("select * from orders where id = ? and status = 'paid' and deleted_at is null", [$orderId]);
            if (! $o) {
                return 0;
            }
            $total = 'S/ '.number_format((float) $o->total, 2);
            $n = 0;
            if ($o->buyer_type === 'company') {
                foreach ($db->select('select i.description, l.seats_purchased, l.license_code, l.buyer_contact_email from order_items i join course_licenses l on l.id = i.course_license_id
                                       where i.order_id = ? and i.deleted_at is null', [$o->id]) as $l) {
                    $body = "Se registró el pago del pedido {$o->order_number} ({$total}). {$o->buyer_legal_name} tiene {$l->seats_purchased} cupo(s) del curso «{$l->description}». Código de los cupos: {$l->license_code}.";
                    foreach (array_unique(array_filter([$o->buyer_email, $l->buyer_contact_email])) as $to) {
                        $n += self::mail($db, $company, $to, (string) $o->buyer_legal_name, 'Cupos disponibles para tu empresa', $body, "vta.licenses_available:{$l->license_code}");
                    }
                }

                return $n;
            }
            $rows = $db->select(
                "select i.description, e.access_until, xl.email, xl.first_name from order_items i
                   join course_enrollments e on e.order_item_id = i.id and e.deleted_at is null
                   left join external_learners xl on xl.id = e.external_learner_id
                  where i.order_id = ? and i.deleted_at is null", [$o->id]);
            foreach ($rows as $r) {
                $body = "Se registró el pago del pedido {$o->order_number} ({$total}). Ya estás matriculado en el curso «{$r->description}»."
                    .($r->access_until ? ' Tienes acceso hasta el '.self::d($r->access_until).'.' : '');
                $to = [$o->buyer_email => explode(' ', (string) $o->buyer_name)[0]];
                if ($r->email) {
                    $to[$r->email] ??= (string) $r->first_name;
                }
                foreach ($to as $email => $name) {
                    if ($email) {
                        $n += self::mail($db, $company, (string) $email, $name ?: 'Hola', 'Pedido pagado: ya tienes acceso al curso', $body, "vta.order_paid:{$o->id}");
                    }
                }
            }

            return $n;
        });
    }

    // ------------------------------------------------------------------ Cada hora

    /** M-AUL §10 «Curso por vencer sin completar» → participante y jefe del área (course_due_notice_days). */
    private function dueSoon(ConnectionInterface $db, string $company): int
    {
        $days = self::days('course_due_notice_days', 7);
        $due = "least(e.due_on, (e.access_until at time zone 'America/Lima')::date)";
        $ids = $db->select(
            "select e.id, {$due} as due,
                    (select mw.user_id from workers w join areas ar on ar.id = w.current_area_id join workers mw on mw.id = ar.manager_worker_id where w.id = e.worker_id) as lead
               from course_enrollments e
              where e.deleted_at is null and e.status in ('enrolled', 'in_progress')
                and {$due} between (now() at time zone 'America/Lima')::date and (now() at time zone 'America/Lima')::date + ?::int",
            [$days]);
        $byId = [];
        foreach ($ids as $r) {
            $byId[$r->id] = $r;
        }
        $n = 0;
        foreach (self::participants($db, array_keys($byId)) as $p) {
            $r = $byId[$p->id];
            $pct = rtrim(rtrim(number_format((float) $p->progress_pct, 1), '0'), '.');
            $key = "aul.course_due:{$p->id}:{$r->due}";
            $body = "El curso «{$p->title}» vence el ".self::d($r->due)." y aún no lo completas (avance {$pct} %).";
            $n += self::note($db, $company, 'aul.course_due', $p->id, $p->user_id, $p->worker_id, 'Curso por vencer sin completar', $body, self::learnerLink($p), 'normal', $key);
            if ($p->email) {
                $n += self::mail($db, $company, $p->email, $p->first_name, 'Curso por vencer sin completar', $body, $key);
            }
            if ($r->lead && $r->lead !== $p->user_id) {
                $n += self::note($db, $company, 'aul.course_due', $p->id, $r->lead, null, 'Curso por vencer sin completar',
                    "{$p->name} no completa el curso «{$p->title}», que vence el ".self::d($r->due)." (avance {$pct} %).", '/m/aul/'.$p->course_id, 'normal', $key);
            }
        }

        return $n;
    }

    /** M-AUL §10 «Participante rezagado» → capacitadores del curso y Jefe SST (aul_inactive_learner_days sin actividad). */
    private function inactive(ConnectionInterface $db, string $company): int
    {
        $days = self::days('aul_inactive_learner_days', 7);
        if ($days < 1) {
            return 0;
        }
        $rows = $db->select(
            "select e.id, coalesce(e.last_activity_at, e.enrolled_at, e.created_at) as since from course_enrollments e
              where e.deleted_at is null and e.status in ('enrolled', 'in_progress') and (e.access_until is null or e.access_until > now())
                and coalesce(e.last_activity_at, e.enrolled_at, e.created_at) < now() - make_interval(days => ?::int)",
            [$days]);
        $since = [];
        foreach ($rows as $r) {
            $since[$r->id] = $r->since;
        }
        $sst = self::roleUsers($db, ['sst_manager']);
        $n = 0;
        foreach (self::participants($db, array_keys($since)) as $p) {
            $from = Carbon::parse($since[$p->id])->timezone('America/Lima');
            $idle = (int) $from->copy()->startOfDay()->diffInDays(now('America/Lima')->startOfDay());
            $pct = rtrim(rtrim(number_format((float) $p->progress_pct, 1), '0'), '.');
            $body = "{$p->name} no avanza en el curso «{$p->title}» desde hace {$idle} días (avance {$pct} %).";
            foreach (array_unique(array_merge(self::trainers($db, $p->course_id), $sst)) as $u) {
                $n += self::note($db, $company, 'aul.inactive_learner', $p->id, $u, null, 'Participante rezagado', $body, '/m/aul/'.$p->course_id, 'low',
                    "aul.inactive_learner:{$p->id}:".$from->format('Y-m-d'));
            }
        }

        return $n;
    }

    /** M-AUL §10 «Recomendaciones por aprobar» → Jefe SST, un resumen al día. */
    private function pendingRecommendations(ConnectionInterface $db, string $company): int
    {
        $count = (int) $db->selectOne("select count(*) as n from course_recommendations where deleted_at is null and status = 'suggested'")->n;
        if ($count === 0) {
            return 0;
        }
        $today = now('America/Lima')->format('Y-m-d');
        $body = $count === 1 ? 'Hay 1 recomendación de curso esperando tu aprobación.' : "Hay {$count} recomendaciones de cursos esperando tu aprobación.";
        $n = 0;
        foreach (self::roleUsers($db, ['sst_manager']) as $u) {
            $n += self::note($db, $company, 'aul.recommendations_pending', $company, $u, null, 'Recomendaciones por aprobar', $body, '/m/aul/recomendaciones', 'normal',
                "aul.recommendations_pending:{$today}");
        }

        return $n;
    }

    /** M-VTA §10 «Pedido por vencer sin pago» → comprador, por correo (order_expiry_notice_minutes antes de expires_at). */
    private function ordersExpiring(ConnectionInterface $db, string $company): int
    {
        $minutes = self::days('order_expiry_notice_minutes', 1440);
        $n = 0;
        foreach ($db->select(
            "select o.id, o.order_number, o.buyer_name, o.buyer_legal_name, o.buyer_type, o.buyer_email, o.expires_at,
                    (select string_agg(i.description, ', ') from order_items i where i.order_id = o.id and i.deleted_at is null) as items
               from orders o
              where o.deleted_at is null and o.status = 'pending' and o.buyer_email is not null
                and o.expires_at > now() and o.expires_at <= now() + make_interval(mins => ?::int)", [$minutes]) as $o) {
            $at = Carbon::parse($o->expires_at)->timezone('America/Lima');
            $name = $o->buyer_type === 'company' ? (string) $o->buyer_legal_name : explode(' ', (string) $o->buyer_name)[0];
            $n += self::mail($db, $company, $o->buyer_email, $name, 'Tu pedido vence pronto',
                "Tu pedido {$o->order_number} (curso «{$o->items}») vence el ".$at->format('d/m/Y').' a las '.$at->format('H:i').' si no se registra el pago.',
                "vta.order_expiring:{$o->id}");
        }

        return $n;
    }

    /** M-VTA §10 «Pago confirmado sin matrícula creada» → Jefe SST, que administra la venta (app y correo). */
    private function paidWithoutEnrollment(ConnectionInterface $db, string $company): int
    {
        $rows = $db->select(
            "select i.id, o.order_number, i.description, i.item_type from orders o join order_items i on i.order_id = o.id and i.deleted_at is null
              where o.deleted_at is null and o.status = 'paid'
                and ((i.item_type = 'license' and i.course_license_id is null)
                  or (i.item_type <> 'license' and not exists (select 1 from course_enrollments e where e.order_item_id = i.id and e.deleted_at is null)))");
        if (! $rows) {
            return 0;
        }
        $sst = self::roleUsers($db, ['sst_manager']);
        $n = 0;
        foreach ($rows as $r) {
            $what = $r->item_type === 'license' ? 'los cupos' : 'la matrícula';
            foreach ($sst as $u) {
                $n += self::note($db, $company, 'vta.paid_without_enrollment', $r->id, $u, null, 'Pago confirmado sin matrícula creada',
                    "El pedido {$r->order_number} está pagado, pero falta crear {$what} del curso «{$r->description}».", '/m/vta', 'high', "vta.paid_without_enrollment:{$r->id}");
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------ Apoyo

    /** Corre el aviso sin romper la acción: con la conexión y la empresa del pedido (o del job) y en un punto de guardado. */
    private static function safely(?ConnectionInterface $db, ?string $company, callable $fn): int
    {
        try {
            $db ??= DB::connection();
            $company ??= app()->bound(CompanyContext::class) ? app(CompanyContext::class)->companyId : null;
            if (! $company) {
                return 0;
            }

            return (int) $db->transaction(fn () => $fn($db, $company));
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Matrículas con su participante: trabajador (campana y su cuenta si tiene), participante externo con cuenta (campana)
     * o sin cuenta (correo). Los trabajadores de contratistas no tienen cuenta ni correo registrado: no se les avisa.
     * @param list<string> $ids
     * @return list<object>
     */
    private static function participants(ConnectionInterface $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        return $db->select(
            "select e.id, e.course_id, e.status, e.source, e.due_on, e.access_until, e.progress_pct, e.final_score_label, e.certificate_id, e.worker_id, c.title,
                    case when e.worker_id is not null then w.user_id else coalesce(xl.user_id, e.user_id) end as user_id,
                    case when e.external_learner_id is not null and xl.user_id is null and e.user_id is null and xl.anonymized_at is null then xl.email end as email,
                    coalesce(w.first_name, xl.first_name, 'Hola') as first_name,
                    coalesce(w.full_name, xl.first_name || ' ' || xl.last_name, cw.first_name || ' ' || cw.paternal_last_name, 'Un participante') as name
               from course_enrollments e join courses c on c.id = e.course_id
               left join workers w on w.id = e.worker_id
               left join external_learners xl on xl.id = e.external_learner_id and xl.deleted_at is null
               left join contractor_workers cw on cw.id = e.contractor_worker_id
              where e.deleted_at is null and e.id = any(?::uuid[])",
            ['{'.implode(',', $ids).'}']);
    }

    private static function learnerLink(object $p): string
    {
        return $p->worker_id ? '/mi/cursos/'.$p->id : '/inicio';
    }

    /** Capacitadores asignados al curso (permiso vigente del curso y membresía activa). @return list<string> */
    private static function trainers(ConnectionInterface $db, string $course): array
    {
        return array_column($db->select(
            "select distinct tp.trainer_user_id as user_id from trainer_permissions tp
               join memberships m on m.user_id = tp.trainer_user_id and m.company_id = tp.company_id and m.status = 'active' and m.deleted_at is null
              where tp.course_id = ? and tp.trainer_user_id is not null and tp.deleted_at is null
                and (tp.valid_from is null or tp.valid_from <= current_date) and (tp.valid_until is null or tp.valid_until >= current_date)",
            [$course]), 'user_id');
    }

    /** @return list<string> */
    private static function roleUsers(ConnectionInterface $db, array $roles): array
    {
        return array_column($db->select(
            "select distinct m.user_id from memberships m join roles r on r.id = m.role_id
              where m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = any(?::text[])",
            ['{'.implode(',', $roles).'}']), 'user_id');
    }

    /** Aviso en la campana (sin repetir: la clave lleva al destinatario). */
    private static function note(ConnectionInterface $db, string $company, string $event, string $subjectId, ?string $user, ?string $worker, string $title, string $body,
        string $link, string $priority, string $dedupe): int
    {
        if (! $user && ! $worker) {
            return 0;
        }

        return $db->affectingStatement(
            'insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, recipient_worker_id, title, body, link, priority, dedupe_key)
             values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) on conflict do nothing',
            [$company, $event, strtoupper(explode('.', $event)[0]), $subjectId, $user, $worker, $title, mb_substr($body, 0, 500), $link, $priority, $dedupe.':'.($worker ?? $user)]
        );
    }

    /** Correo a quien no tiene cuenta (participante externo o comprador); una sola vez por clave. */
    private static function mail(ConnectionInterface $db, string $company, string $email, string $name, string $title, string $body, string $dedupe): int
    {
        $mark = 'aulvta-mail:'.$company.':'.$dedupe.':'.hash('sha256', mb_strtolower($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! Cache::add($mark, 1, now()->addDays(120))) {
            return 0;
        }
        try {
            $from = $db->selectOne('select coalesce(trade_name, legal_name) as n from companies where id = ?', [$company])?->n ?? 'EscudoSST';
            Mail::to($email)->send(new LearnerMail($name ?: 'Hola', $from, $title, $body));

            return 1;
        } catch (Throwable $e) {
            Cache::forget($mark);
            report($e);

            return 0;
        }
    }

    private static function days(string $key, int $default): int
    {
        return max(0, (int) LegalParams::number($key, $default));
    }

    private static function d(string $date): string
    {
        return Carbon::parse($date)->timezone('America/Lima')->format('d/m/Y');
    }
}

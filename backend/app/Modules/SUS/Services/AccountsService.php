<?php

namespace App\Modules\SUS\Services;

use App\Core\Http\ApiProblem;
use App\Core\Support\Ruc;
use App\Modules\SUS\Support\Pricing;
use App\Modules\USR\Services\InvitationService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SCR-SUS-02 / SCR-SUS-03 · Cuentas y suscripciones (panel del dueño de la plataforma).
 *
 * - Corre con la conexión 'platform' (rol escudo_platform), que ve todas las cuentas, empresas y
 *   suscripciones, pero no datos de negocio. Las membresías son datos de la empresa: se leen y escriben
 *   con la conexión de la app fijando app.company_id solo dentro de la transacción.
 * - Todo cambio queda en audit_logs como evento de plataforma (company_id NULL) con el account_id en
 *   changes, para armar la línea de tiempo de la cuenta.
 */
class AccountsService
{
    public const ADMIN_ROLE = 'account_admin';

    private ?float $annualDiscount = null;

    public function __construct(private readonly InvitationService $invitations) {}

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    /** Lista de cuentas con sus empresas y la suscripción vigente de cada una. */
    public function list(): array
    {
        $rows = $this->db()->select(
            "select a.id, a.name, a.account_type, a.tax_id, a.status, a.created_at,
                    coalesce(json_agg(to_jsonb(cs) order by cs.legal_name) filter (where cs.company_id is not null), '[]') as companies
               from accounts a
               left join ({$this->companySubscriptionsSql()}) cs on cs.account_id = a.id
              where a.deleted_at is null
              group by a.id
              order by a.created_at desc"
        );

        return array_map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'account_type' => $r->account_type,
            'tax_id' => $r->tax_id,
            'status' => $r->status,
            'created_at' => $r->created_at,
            'companies' => array_map($this->companyRow(...), json_decode($r->companies, true)),
        ], $rows);
    }

    /** Detalle: datos de la cuenta, empresas con suscripción, complementos, módulos y administradores. */
    public function show(string $accountId): array
    {
        $a = $this->findAccount($accountId);

        $companies = array_map(
            fn ($r) => $this->companyRow((array) $r),
            $this->db()->select("select * from ({$this->companySubscriptionsSql()}) cs where cs.account_id = ? order by cs.legal_name", [$accountId])
        );

        foreach ($companies as &$c) {
            $c['addons'] = $c['subscription'] ? $this->subscriptionAddons($c['subscription']['id']) : [];
            $c['modules'] = $this->moduleSummary($c['id']);
            $c['admins'] = $this->companyAdmins($c['id']);
            $c['payments'] = $c['subscription'] ? $this->subscriptionPayments($c['subscription']['id']) : [];
        }

        return [
            'id' => $a->id,
            'name' => $a->name,
            'account_type' => $a->account_type,
            'tax_id_type' => $a->tax_id_type,
            'tax_id' => $a->tax_id,
            'legal_name' => $a->legal_name,
            'billing_email' => $a->billing_email,
            'billing_phone' => $a->billing_phone,
            'status' => $a->status,
            'trial_used_at' => $a->trial_used_at,
            'notes' => $a->notes,
            'created_at' => $a->created_at,
            'companies' => $companies,
            'timeline' => $this->timeline($accountId),
        ];
    }

    /**
     * Alta de cuenta con su primera empresa, la suscripción y el administrador de cuenta (invitado por correo).
     *
     * @param  array<string, mixed>  $data  validado en el controlador
     * @return array{account_id: string, company_id: string, subscription_id: string, invitation: string}
     */
    /**
     * Alta de cuenta + empresa + suscripción + administrador invitado.
     * $actorUserId es null cuando la crea el propio cliente desde «Prueba gratis» del sitio (registro autoservicio).
     */
    public function create(array $data, ?string $actorUserId): array
    {
        $ruc = $data['ruc'];
        if (! Ruc::isValid($ruc)) {
            throw $this->validation('ruc', 'El RUC no es válido: revisa los 11 dígitos.');
        }
        if ($this->db()->selectOne('select 1 from companies where ruc = ? and deleted_at is null', [$ruc])) {
            throw $this->validation('ruc', $actorUserId ? 'Ya existe una empresa con este RUC.' : 'Esta empresa ya está registrada en EscudoSST. Si eres parte de ella, pide acceso a su administrador o inicia sesión.');
        }
        // Una sola prueba por RUC (docs/03 §9), aunque la empresa anterior se haya dado de baja.
        if ($data['start'] === 'trial' && $this->db()->selectOne("select 1 from accounts where tax_id = ? and trial_used_at is not null", [$ruc])) {
            throw $this->validation('ruc', 'Este RUC ya usó su prueba gratuita. Escríbenos para contratar un plan.');
        }

        $trial = $data['start'] === 'trial';
        $planCode = $trial ? 'P' : $data['plan'];   // docs/03 §9: la prueba es Profesional completo, sin complementos
        $plan = $this->db()->selectOne("select id, code, name from plans where code = ? and is_active and deleted_at is null and code <> 'K'", [$planCode]);
        if (! $plan) {
            throw $this->validation('plan', 'Ese plan no está activo.');
        }

        $email = Str::lower(trim($data['admin_email']));
        $result = $this->db()->transaction(function () use ($data, $ruc, $trial, $plan, $email, $actorUserId) {
            $this->db()->select("select set_config('app.user_id', ?, true)", [$actorUserId ?? '']);

            $accountId = $this->db()->selectOne(
                "insert into accounts (name, account_type, tax_id_type, tax_id, legal_name, billing_email, billing_phone, notes, trial_used_at, created_by)
                 values (?, ?, 'RUC', ?, ?, ?, ?, ?, ?, ?) returning id",
                [
                    ($data['account_name'] ?? null) ?: $data['legal_name'], $data['account_type'], $ruc, $data['legal_name'],
                    $data['billing_email'] ?? $email, $data['billing_phone'] ?? null, $data['notes'] ?? null,
                    $trial ? now() : null, $actorUserId,
                ]
            )->id;

            $headcount = (int) $data['headcount'];
            $companyId = $this->db()->selectOne(
                'insert into companies (account_id, ruc, legal_name, trade_name, economic_sector, headcount_declared, sst_model, created_by)
                 values (?, ?, ?, ?, ?, ?, ?, ?) returning id',
                [
                    $accountId, $ruc, $data['legal_name'], $data['trade_name'] ?? null, $data['economic_sector'] ?? null,
                    $headcount, $headcount >= $this->committeeThreshold() ? 'committee' : 'supervisor', $actorUserId,
                ]
            )->id;

            $period = $data['billing_period'];
            $start = now();
            $end = $trial ? $start->copy()->addDays(config('escudo.subscriptions.trial_days'))
                : (! empty($data['prepaid']) ? $start->copy()   // alta desde una cotización: el periodo lo abre el pago que se registra enseguida
                    : ($period === 'annual' ? $start->copy()->addYear() : $start->copy()->addMonth()));

            $subscriptionId = $this->db()->selectOne(
                'insert into subscriptions (account_id, company_id, plan_id, status, billing_period, started_at, trial_ends_at,
                                            current_period_start, current_period_end, created_by)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) returning id',
                [$accountId, $companyId, $plan->id, $trial ? 'trialing' : 'active', $period, $start, $trial ? $end : null, $start, $end, $actorUserId]
            )->id;

            // Sede principal, área General, criterios, tableros y feature flags desde la suscripción.
            $this->db()->select('select seed_company_defaults(?)', [$companyId]);

            $user = $this->db()->selectOne('select id, full_name, status from users where lower(email) = ? and deleted_at is null', [$email]);
            if ($user?->status === 'disabled') {
                throw $this->validation('admin_email', 'Ese correo pertenece a un usuario deshabilitado.');
            }
            $newUser = $user === null;
            $userId = $user->id ?? $this->db()->selectOne(
                "insert into users (email, full_name, status, created_by) values (?, ?, 'invited', ?) returning id",
                [$email, trim($data['admin_name']), $actorUserId]
            )->id;

            $this->audit($actorUserId, 'account_created', 'accounts', $accountId, [
                'account_id' => $accountId,
                'source' => $actorUserId ? 'panel' : 'self_signup',
                'company' => ['id' => $companyId, 'ruc' => $ruc, 'legal_name' => $data['legal_name']],
                'plan' => $plan->code,
                'status' => $trial ? 'trialing' : 'active',
                'billing_period' => $period,
                'admin' => $email,
            ]);

            // Registro autoservicio: queda la prueba de qué versión de Términos y Privacidad aceptó (si ya hay versión vigente).
            if (! empty($data['accepted_legal'])) {
                $this->db()->insert(
                    "insert into legal_acceptances (legal_document_version_id, legal_document_id, doc_type, version_label, user_id, account_id, company_id, context, ip_hash, user_agent)
                     select v.id, d.id, d.doc_type, v.version_label, ?, ?, ?, 'signup', decode(?, 'hex'), ?
                       from legal_documents d join legal_document_versions v on v.id = d.current_version_id
                      where d.doc_type in ('terms', 'privacy') and d.deleted_at is null",
                    [$userId, $accountId, $companyId, $data['ip_hash'] ?? null, $data['user_agent'] ?? null]
                );
            }

            return compact('accountId', 'companyId', 'subscriptionId', 'userId', 'newUser') + ['adminName' => $user->full_name ?? trim($data['admin_name']), 'userActive' => $user?->status === 'active'];
        });

        // La membresía es dato de la empresa: va por la conexión de la app con app.company_id.
        // Un usuario que ya existe y está activo entra directo; uno nuevo queda invitado hasta crear su contraseña.
        $membershipId = $this->withCompany($result['companyId'], fn (ConnectionInterface $db) => $db->selectOne(
            'insert into memberships (company_id, user_id, role_id, status, invited_by, invited_at, accepted_at, created_by)
             select ?, ?, r.id, ?, ?, now(), ?, ? from roles r where r.account_id is null and r.code = ? returning id',
            [
                $result['companyId'], $result['userId'], $result['userActive'] ? 'active' : 'invited', $actorUserId,
                $result['userActive'] ? now() : null, $actorUserId, self::ADMIN_ROLE,
            ]
        )->id);

        $this->invitations->send(
            userId: $result['userId'],
            email: $email,
            name: $result['adminName'],
            companyId: $result['companyId'],
            companyName: ($data['trade_name'] ?? null) ?: $data['legal_name'],
            membershipId: $membershipId,
            needsPassword: ! $result['userActive'],
        );

        return [
            'account_id' => $result['accountId'],
            'company_id' => $result['companyId'],
            'subscription_id' => $result['subscriptionId'],
            'invitation' => $result['userActive'] ? 'access_granted' : 'invited',
            'admin_user_id' => $result['userId'],
        ];
    }

    /**
     * Agrega otra empresa (otro RUC) a una cuenta que ya existe: grupo de empresas, cada una con su propia
     * suscripción (docs/03 §1). Los administradores indicados entran también a esta empresa.
     * Con «prepaid» el periodo queda en cero: lo abre el pago que se registra enseguida (alta desde cotización).
     *
     * @param  array{ruc: string, legal_name: string, trade_name?: ?string, economic_sector?: ?string, headcount: int, plan: string, billing_period: string, prepaid?: bool}  $data
     * @param  list<string>  $adminUserIds
     * @return array{company_id: string, subscription_id: string}
     */
    public function addCompany(string $accountId, array $data, array $adminUserIds, string $actorUserId): array
    {
        $ruc = $data['ruc'];
        if (! Ruc::isValid($ruc)) {
            throw $this->validation('ruc', "El RUC {$ruc} no es válido.");
        }
        if ($this->db()->selectOne('select 1 from companies where ruc = ? and deleted_at is null', [$ruc])) {
            throw $this->validation('ruc', "Ya existe una empresa con el RUC {$ruc}.");
        }
        $plan = $this->db()->selectOne("select id, code from plans where code = ? and is_active and deleted_at is null and code <> 'K'", [$data['plan']]);
        if (! $plan) {
            throw $this->validation('plan', 'Ese plan no está activo.');
        }
        $account = $this->findAccount($accountId);
        // RN-TEN-02: la cuenta no supera el número de empresas activas que permite su plan vigente (si el plan lo limita).
        $limit = $this->db()->selectOne(
            "select max(p.max_companies) as m, count(*) filter (where p.max_companies is null) as unlimited from companies c
               join subscriptions s on s.company_id = c.id and s.deleted_at is null and s.status in ('trialing', 'active', 'past_due', 'grace')
               join plans p on p.id = s.plan_id
              where c.account_id = ? and c.deleted_at is null and c.status = 'active'", [$accountId]);
        if ($limit && (int) $limit->unlimited === 0 && $limit->m !== null) {
            $active = (int) $this->db()->selectOne("select count(*) as n from companies where account_id = ? and deleted_at is null and status = 'active'", [$accountId])->n;
            if ($active >= (int) $limit->m) {
                throw $this->validation('plan', "El plan de la cuenta permite hasta {$limit->m} empresa(s) activa(s): mejora el plan para agregar otra.");
            }
        }

        $ids = $this->db()->transaction(function () use ($accountId, $account, $data, $ruc, $plan, $actorUserId) {
            $this->db()->select("select set_config('app.user_id', ?, true)", [$actorUserId]);
            $headcount = (int) $data['headcount'];
            $companyId = $this->db()->selectOne(
                'insert into companies (account_id, ruc, legal_name, trade_name, economic_sector, headcount_declared, sst_model, created_by)
                 values (?, ?, ?, ?, ?, ?, ?, ?) returning id',
                [
                    $accountId, $ruc, $data['legal_name'], $data['trade_name'] ?? null, $data['economic_sector'] ?? null,
                    $headcount, $headcount >= $this->committeeThreshold() ? 'committee' : 'supervisor', $actorUserId,
                ]
            )->id;
            $start = now();
            $end = ! empty($data['prepaid']) ? $start->copy() : ($data['billing_period'] === 'annual' ? $start->copy()->addYear() : $start->copy()->addMonth());
            $subscriptionId = $this->db()->selectOne(
                "insert into subscriptions (account_id, company_id, plan_id, status, billing_period, started_at, current_period_start, current_period_end, created_by)
                 values (?, ?, ?, 'active', ?, ?, ?, ?, ?) returning id",
                [$accountId, $companyId, $plan->id, $data['billing_period'], $start, $start, $end, $actorUserId]
            )->id;
            $this->db()->select('select seed_company_defaults(?)', [$companyId]);
            if ($account->account_type === 'direct') {
                $this->db()->update("update accounts set account_type = 'group', updated_by = ? where id = ?", [$actorUserId, $accountId]);
            }
            $this->audit($actorUserId, 'account_company_added', 'accounts', $accountId, [
                'account_id' => $accountId,
                'company' => ['id' => $companyId, 'ruc' => $ruc, 'legal_name' => $data['legal_name']],
                'plan' => $plan->code,
                'billing_period' => $data['billing_period'],
            ]);

            return compact('companyId', 'subscriptionId');
        });

        // Los administradores de la cuenta entran también a esta empresa. Si aún no crean su contraseña, no pueden
        // iniciar sesión: al aceptar la invitación de la primera empresa ya ven todas.
        foreach (array_unique($adminUserIds) as $userId) {
            $this->withCompany($ids['companyId'], fn (ConnectionInterface $db) => $db->insert(
                "insert into memberships (company_id, user_id, role_id, status, invited_by, invited_at, accepted_at, created_by)
                 select ?, ?, r.id, 'active', ?, now(), now(), ? from roles r where r.account_id is null and r.code = ?",
                [$ids['companyId'], $userId, $actorUserId, $actorUserId, self::ADMIN_ROLE]
            ));
        }

        return ['company_id' => $ids['companyId'], 'subscription_id' => $ids['subscriptionId']];
    }

    /** Reenvía la invitación al administrador de cuenta que aún no crea su contraseña. */
    public function resendInvitation(string $accountId, string $companyId, string $userId, string $actorUserId): void
    {
        $company = $this->db()->selectOne('select id, legal_name, trade_name from companies where id = ? and account_id = ? and deleted_at is null', [$companyId, $accountId]);
        if (! $company) {
            throw ApiProblem::notFound('La empresa no pertenece a esta cuenta.');
        }
        $admin = collect($this->companyAdmins($companyId))->firstWhere('user_id', $userId);
        if (! $admin) {
            throw ApiProblem::notFound('Esa persona no es administradora de la empresa.');
        }
        if ($admin['status'] !== 'invited') {
            throw new ApiProblem(409, 'conflict', 'Invitación ya aceptada', 'Esta persona ya activó su acceso.');
        }

        $this->invitations->send($userId, $admin['email'], $admin['full_name'], $companyId, $company->trade_name ?: $company->legal_name, $admin['membership_id'], true);
        $this->audit($actorUserId, 'invitation_resent', 'memberships', $admin['membership_id'], ['account_id' => $accountId, 'company_id' => $companyId, 'admin' => $admin['email']]);
    }

    public function findAccount(string $accountId): object
    {
        $a = Str::isUuid($accountId)
            ? $this->db()->selectOne('select * from accounts where id = ? and deleted_at is null', [$accountId])
            : null;

        return $a ?? throw ApiProblem::notFound('La cuenta no existe.');
    }

    /** Línea de tiempo: eventos de plataforma de la cuenta, del más reciente al más antiguo. */
    public function timeline(string $accountId): array
    {
        return array_map(fn ($r) => [
            'id' => $r->id,
            'occurred_at' => $r->occurred_at,
            'action' => $r->action,
            'actor' => $r->actor,
            'reason' => $r->reason,
            'changes' => json_decode($r->changes ?? 'null', true),
        ], $this->db()->select(
            "select l.id, l.occurred_at, l.action, l.reason, l.changes, u.full_name as actor
               from audit_logs l left join users u on u.id = l.actor_user_id
              where l.company_id is null and (l.entity_id = ?::uuid or l.changes->>'account_id' = ?)
              order by l.occurred_at desc limit 100",
            [$accountId, $accountId]
        ));
    }

    /** Evento de plataforma en audit_logs. Sin actor = acción automática del sistema (proceso diario). */
    public function audit(?string $actorUserId, string $action, string $entity, ?string $entityId, array $changes, ?string $reason = null): void
    {
        $this->db()->insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, reason)
             values (null, ?, ?, ?, ?, 'SUS', ?, ?, ?::jsonb, ?)",
            [
                $actorUserId, $actorUserId ? 'platform_owner' : null, $actorUserId ? 'user' : 'system',
                $action, $entity, $entityId, json_encode($changes, JSON_UNESCAPED_UNICODE), $reason,
            ]
        );
    }

    /** Administradores de cuenta de la empresa (lectura con app.company_id de esa empresa). */
    public function companyAdmins(string $companyId): array
    {
        return $this->withCompany($companyId, fn (ConnectionInterface $db) => array_map(fn ($r) => (array) $r, $db->select(
            'select m.id as membership_id, u.id as user_id, u.full_name, u.email, m.status, u.mfa_enabled, u.last_login_at, m.invited_at
               from memberships m join roles r on r.id = m.role_id join users u on u.id = m.user_id
              where m.company_id = ? and r.code = ? and m.deleted_at is null and u.deleted_at is null
              order by u.full_name',
            [$companyId, self::ADMIN_ROLE]
        )));
    }

    /**
     * @template T
     *
     * @param  callable(ConnectionInterface): T  $fn
     * @return T
     */
    public function withCompany(string $companyId, callable $fn): mixed
    {
        $db = DB::connection('pgsql');

        return $db->transaction(function () use ($db, $companyId, $fn) {
            // Si ya hay una transacción abierta (pruebas), SET LOCAL duraría hasta su fin: se restaura el valor previo.
            $previous = $db->selectOne("select current_setting('app.company_id', true) as v")->v ?? '';
            $db->select("select set_config('app.company_id', ?, true)", [$companyId]);
            try {
                return $fn($db);
            } finally {
                $db->select("select set_config('app.company_id', ?, true)", [$previous]);
            }
        });
    }

    private function subscriptionAddons(string $subscriptionId): array
    {
        return array_map(fn ($r) => (array) $r, $this->db()->select(
            "select a.code, a.name, a.module_code, sa.status, sa.started_at, sa.ended_at
               from subscription_addons sa join addons a on a.id = sa.addon_id
              where sa.subscription_id = ? and sa.deleted_at is null and sa.status = 'active'
              order by a.name",
            [$subscriptionId]
        ));
    }

    /** Pagos registrados de la suscripción, del más reciente al más antiguo. */
    private function subscriptionPayments(string $subscriptionId): array
    {
        return array_map(fn ($r) => array_merge((array) $r, ['amount' => (float) $r->amount]), $this->db()->select(
            "select pa.id, pa.amount, pa.currency, pa.method, pa.reference, pa.paid_at, pa.status, i.lines->0->>'description' as concept,
                    i.sunat_status, u.full_name as registered_by
               from payments pa join invoices i on i.id = pa.invoice_id left join users u on u.id = pa.created_by
              where i.subscription_id = ? and pa.deleted_at is null
              order by pa.paid_at desc, pa.created_at desc limit 50",
            [$subscriptionId]
        ));
    }

    /** Feature flags resultantes de la empresa: conteo por modo y módulos no bloqueados. */
    private function moduleSummary(string $companyId): array
    {
        $rows = $this->db()->select(
            'select f.module_code as code, m.name, f.mode, f.source
               from feature_flags f join modules m on m.code = f.module_code
              where f.company_id = ? and f.deleted_at is null and m.deleted_at is null
              order by m.name',
            [$companyId]
        );
        $counts = ['enabled' => 0, 'read_only' => 0, 'locked' => 0, 'disabled_by_company' => 0];
        foreach ($rows as $r) {
            $counts[$r->mode] = ($counts[$r->mode] ?? 0) + 1;
        }

        return [
            'counts' => $counts,
            'items' => array_values(array_map(fn ($r) => (array) $r, array_filter($rows, fn ($r) => $r->mode !== 'locked'))),
        ];
    }

    private function companySubscriptionsSql(): string
    {
        return "select c.id as company_id, c.account_id, c.ruc, c.legal_name, c.trade_name, c.headcount_declared, c.sst_model,
                       c.status as company_status, c.created_at as company_created_at,
                       s.id as subscription_id, s.status, s.billing_period, s.started_at, s.trial_ends_at,
                       s.current_period_end, s.grace_until, s.cancel_reason, p.code as plan_code, p.name as plan_name,
                       p.price_reference as plan_price,
                       (select count(*) from subscription_addons sa
                         where sa.subscription_id = s.id and sa.status = 'active' and sa.deleted_at is null) as addons_count,
                       (select sum(ad.price_reference * sa.quantity) from subscription_addons sa join addons ad on ad.id = sa.addon_id
                         where sa.subscription_id = s.id and sa.status = 'active' and sa.deleted_at is null) as addons_price,
                       (select count(*) from subscription_addons sa join addons ad on ad.id = sa.addon_id
                         where sa.subscription_id = s.id and sa.status = 'active' and sa.deleted_at is null and ad.price_reference is null) as addons_unpriced
                  from companies c
                  left join lateral (
                       select * from subscriptions s
                        where s.company_id = c.id and s.deleted_at is null
                        order by (s.status not in ('cancelled', 'trial_expired')) desc, s.started_at desc
                        limit 1) s on true
                  left join plans p on p.id = s.plan_id
                 where c.deleted_at is null";
    }

    private function companyRow(array $r): array
    {
        return [
            'id' => $r['company_id'],
            'ruc' => trim($r['ruc']),
            'legal_name' => $r['legal_name'],
            'trade_name' => $r['trade_name'],
            'headcount_declared' => $r['headcount_declared'] !== null ? (int) $r['headcount_declared'] : null,
            'sst_model' => $r['sst_model'],
            'status' => $r['company_status'],
            'subscription' => $r['subscription_id'] ? [
                'id' => $r['subscription_id'],
                'status' => $r['status'],
                'plan_code' => $r['plan_code'],
                'plan_name' => $r['plan_name'],
                'billing_period' => $r['billing_period'],
                'started_at' => $r['started_at'],
                'trial_ends_at' => $r['trial_ends_at'],
                'current_period_end' => $r['current_period_end'],
                'grace_until' => $r['grace_until'],
                'cancel_reason' => $r['cancel_reason'],
                'addons_count' => (int) $r['addons_count'],
                // Monto de un periodo (plan + complementos activos). Null si algún precio está «por definir».
                'period_price' => $r['plan_price'] === null || (int) $r['addons_unpriced'] > 0 ? null
                    : Pricing::forPeriod((float) $r['plan_price'] + (float) ($r['addons_price'] ?? 0), $r['billing_period'], $this->annualDiscount ??= Pricing::annualDiscountPct('platform')),
            ] : null,
        ];
    }

    /** Parámetro legal committee_threshold_workers (Ley 29783, art. 29) vigente a hoy. */
    private function committeeThreshold(): int
    {
        $v = $this->db()->selectOne(
            "select value from legal_parameters
              where company_id is null and key = 'committee_threshold_workers' and valid_from <= current_date and (valid_to is null or valid_to >= current_date) and deleted_at is null
              order by valid_from desc limit 1"
        );

        return (int) ($v->value ?? 20);
    }

    private function validation(string $field, string $message): ApiProblem
    {
        return new ApiProblem(422, 'validation_failed', 'Revisa los datos ingresados', $message, ['errors' => [$field => [$message]]]);
    }
}

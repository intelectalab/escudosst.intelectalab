<?php

namespace App\Modules\USR\Services;

use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * M-USR: un acceso con fecha de vencimiento (auditor externo, acceso temporal) se cierra solo al vencer: la membresía
 * pasa a «vencida», queda en la bitácora y, si la persona ya no tiene otra empresa activa, se cierran sus sesiones abiertas.
 * (Mientras tanto, el acceso vencido ya no se acepta en ninguna petición: app_my_memberships lo excluye.)
 */
final class AccessExpiry
{
    public function __construct(private AccountsService $accounts) {}

    public function run(): int
    {
        $n = 0;
        $users = [];
        $companies = DB::connection('platform')->select('select id from companies where deleted_at is null');
        foreach ($companies as $c) {
            try {
                $rows = $this->accounts->withCompany($c->id, fn (ConnectionInterface $db) => $db->select(
                    "update memberships set status = 'expired', updated_at = now() where status = 'active' and expires_at is not null and expires_at <= now() and deleted_at is null
                     returning id, user_id"));
                foreach ($rows as $r) {
                    $this->accounts->withCompany($c->id, fn (ConnectionInterface $db) => $db->insert(
                        "insert into audit_logs (company_id, actor_type, action, module_code, entity_type, entity_id, changes) values (?, 'system', 'update', 'USR', 'memberships', ?, ?::jsonb)",
                        [$c->id, $r->id, json_encode(['status' => ['to' => 'expired'], 'reason' => 'Acceso vencido'])]));
                    $users[$r->user_id] = true;
                    $n++;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
        foreach (array_keys($users) as $u) {
            $other = false;
            foreach ($companies as $c) {
                if ($this->accounts->withCompany($c->id, fn (ConnectionInterface $db) => $db->selectOne(
                    "select 1 as ok from memberships where user_id = ? and status = 'active' and deleted_at is null and (expires_at is null or expires_at > now())", [$u]))) {
                    $other = true;
                    break;
                }
            }
            $staff = DB::connection('platform')->selectOne('select is_platform_staff from users where id = ?', [$u])?->is_platform_staff;
            if (! $other && ! $staff) {
                DB::table('personal_access_tokens')->where('tokenable_id', $u)->delete();
            }
        }

        return $n;
    }
}

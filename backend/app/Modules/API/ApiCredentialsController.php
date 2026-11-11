<?php

namespace App\Modules\API;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\Rules\ApiRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Secretos de la API (M-API RN-API-03, SCR-API-01 y SCR-API-02): se muestran una sola vez.
 *  - POST api-clients/{id}/rotate-secret: nuevo secreto del cliente; el anterior deja de servir.
 *  - POST webhooks/{id}/rotate-secret: nuevo secreto de firma del webhook.
 *  - POST webhooks/{id}/test: envía un evento de prueba a la URL y registra la entrega.
 */
class ApiCredentialsController extends Controller
{
    public function rotateClient(string $id): JsonResponse
    {
        $this->can('api.api_clients.update');
        $c = DB::selectOne('select id, status from api_clients where id = ? and deleted_at is null', [$id])
            ?? throw ApiProblem::notFound('El cliente no existe o es de otra empresa.');
        if ($c->status === 'revoked') {
            throw new ApiProblem(409, 'conflict', 'No se puede hacer este cambio', 'El cliente está revocado: crea uno nuevo.');
        }
        $secret = ApiRules::newClientSecret();
        DB::update('update api_clients set secret_hash = ?, updated_at = now(), updated_by = ? where id = ?',
            [ApiRules::hashClientSecret($secret), (string) auth()->id(), $id]);
        $this->audit('rotate_secret', 'api_clients', $id);

        return response()->json(['data' => ['secret' => $secret]]);
    }

    public function rotateWebhook(string $id): JsonResponse
    {
        $this->can('api.webhooks.update');
        $this->webhook($id);
        $secret = ApiRules::newWebhookSecret();
        DB::update('update webhooks set secret = ?, updated_at = now(), updated_by = ? where id = ?',
            [Crypt::encryptString($secret), (string) auth()->id(), $id]);
        $this->audit('rotate_secret', 'webhooks', $id);

        return response()->json(['data' => ['secret' => $secret]]);
    }

    public function testWebhook(string $id): JsonResponse
    {
        $this->can('api.webhooks.update');

        return response()->json(['data' => Webhooks::test($this->webhook($id))]);
    }

    private function webhook(string $id): object
    {
        return DB::selectOne('select id, company_id from webhooks where id = ? and deleted_at is null', [$id])
            ?? throw ApiProblem::notFound('El webhook no existe o es de otra empresa.');
    }

    private function can(string $permission): void
    {
        if (! in_array($permission, Permissions::effective(), true)) {
            throw ApiProblem::forbidden();
        }
    }

    private function audit(string $action, string $table, string $id): void
    {
        $ctx = app(CompanyContext::class);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes)
             values (?, ?, ?, 'user', ?, 'API', ?, ?, '{}'::jsonb)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $action, $table, $id]
        );
    }
}

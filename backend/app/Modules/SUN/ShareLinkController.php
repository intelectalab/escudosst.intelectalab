<?php

namespace App\Modules\SUN;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Core\Tenancy\DbContext;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enlace temporal del expediente para el inspector (M-SUN):
 *  POST /sun/dossiers/{id}/share         crea el enlace {recipient_name?, days (1..máximo), max_views?}; el enlace se muestra una sola vez
 *  POST /sun/share-links/{id}/revoke     lo anula al momento
 *  GET  /public/sun/{company}/{token}    el inspector ve el índice del expediente (sin sesión); cuenta la visita y respeta vencimiento y máximo
 * En la base solo queda el hash del token.
 */
class ShareLinkController extends Controller
{
    public function store(Request $request, string $dossier, AccountsService $accounts): JsonResponse
    {
        $this->can('sun.dossier_share_links.share');
        $d = Str::isUuid($dossier) ? DB::selectOne('select id, status, title from inspection_dossiers where id = ? and deleted_at is null', [$dossier]) : null;
        if (! $d) {
            throw ApiProblem::notFound('El expediente no existe o es de otra empresa.');
        }
        if (! in_array($d->status, ['ready', 'ready_with_gaps'], true)) {
            throw new ApiProblem(409, 'conflict', 'Arma el expediente', 'Primero arma el expediente: se comparte lo que ya está revisado.');
        }
        $max = (int) LegalParams::number('dossier_share_max_days', 15);
        $v = $request->validate([
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'days' => ['required', 'integer', 'min:1', "max:{$max}"],
            'max_views' => ['nullable', 'integer', 'min:1', 'max:200'],
        ], ['days.max' => "El enlace puede durar como máximo {$max} días.", 'days.required' => 'Indica cuántos días estará disponible.']);
        $token = strtoupper(Str::random(40));
        $company = app(CompanyContext::class)->companyId;
        $id = DB::selectOne(
            "insert into dossier_share_links (company_id, dossier_id, token_hash, token_last4, recipient_name, expires_at, max_views, view_count, allow_download, status, created_by, updated_by)
             values (?, ?, decode(?, 'hex'), ?, ?, now() + make_interval(days => ?::int), ?, 0, false, 'active', ?, ?) returning id",
            [$company, $dossier, hash('sha256', $token), substr($token, -4), $v['recipient_name'] ?? null, $v['days'], $v['max_views'] ?? null, (string) auth()->id(), (string) auth()->id()]
        )->id;
        $accounts->audit((string) auth()->id(), 'sun_share_link_created', 'dossier_share_links', $id, ['days' => $v['days'], 'max_views' => $v['max_views'] ?? null]);

        return response()->json(['data' => ['id' => $id, 'url' => rtrim((string) config('escudo.frontend_url'), '/')."/expediente/{$company}/{$token}"]], 201);
    }

    public function revoke(string $id, AccountsService $accounts): JsonResponse
    {
        $this->can('sun.dossier_share_links.revoke');
        $n = Str::isUuid($id) ? DB::update("update dossier_share_links set status = 'revoked', revoked_at = now(), revoked_by = ? where id = ? and status = 'active'", [(string) auth()->id(), $id]) : 0;
        if (! $n) {
            throw ApiProblem::notFound('El enlace no existe o ya no está activo.');
        }
        $accounts->audit((string) auth()->id(), 'sun_share_link_revoked', 'dossier_share_links', $id, []);

        return response()->json(['data' => ['status' => 'revoked']]);
    }

    public function show(string $company, string $token): JsonResponse
    {
        if (! Str::isUuid($company) || ! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            throw ApiProblem::notFound('El enlace no es válido.');
        }

        return DB::transaction(function () use ($company, $token) {
            DbContext::setCompany($company);
            $l = DB::selectOne("select * from dossier_share_links where token_hash = decode(?, 'hex') and deleted_at is null", [hash('sha256', strtoupper($token))]);
            if (! $l) {
                throw ApiProblem::notFound('El enlace no es válido.');
            }
            if ($l->status === 'active' && strtotime($l->expires_at) < time()) {
                DB::update("update dossier_share_links set status = 'expired' where id = ?", [$l->id]);
                $l->status = 'expired';
            }
            if ($l->status !== 'active') {
                $why = ['expired' => 'El enlace venció.', 'exhausted' => 'El enlace llegó a su número máximo de visitas.', 'revoked' => 'La empresa anuló este enlace.'][$l->status] ?? 'El enlace ya no está disponible.';
                throw new ApiProblem(410, 'gone', 'Enlace no disponible', $why.' Pide uno nuevo a la empresa.');
            }
            $views = (int) $l->view_count + 1;
            $exhausted = $l->max_views !== null && $views >= (int) $l->max_views;
            DB::update('update dossier_share_links set view_count = ?, last_accessed_at = now(), status = ? where id = ?', [$views, $exhausted ? 'exhausted' : 'active', $l->id]);

            $d = DB::selectOne('select title, request_type, inspection_order_number, period_from, period_to, built_at, status from inspection_dossiers where id = ?', [$l->dossier_id]);
            $c = DB::selectOne('select legal_name, ruc from companies where id = ?', [$company]);
            $items = DB::select('select section_code as code, title, status, gap_description as gap, worker_id is not null as per_worker from dossier_items
                                  where dossier_id = ? and deleted_at is null order by sort_order', [$l->dossier_id]);

            return response()->json(['data' => [
                'company' => ['name' => $c->legal_name, 'ruc' => trim($c->ruc)], 'dossier' => $d, 'items' => $items,
                'expires_at' => $l->expires_at, 'views_left' => $l->max_views === null ? null : max(0, (int) $l->max_views - $views),
            ]]);
        });
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no comparte expedientes.');
        }
    }
}

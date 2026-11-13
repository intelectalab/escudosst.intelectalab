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
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Enlace temporal del expediente para el inspector (M-SUN SCR-SUN-06/07/08, RN-SUN-08/09/10):
 *  GET  /sun/dossiers/{id}/share-links     enlaces del expediente con su historial de accesos (Fecha, Desde, Acción)
 *  POST /sun/dossiers/{id}/share           crea el enlace {recipient_name?, days (1..máximo), max_views?, allow_download?, max_downloads?,
 *                                          requires_approval?}; el enlace se muestra una sola vez. Con aprobación queda «pendiente» y
 *                                          los días corren desde que gerencia lo aprueba
 *  POST /sun/share-links/{id}/approve      gerencia (o el administrador de la cuenta) lo aprueba; no quien lo pidió
 *  POST /sun/share-links/{id}/reject       gerencia lo rechaza {reason?}
 *  POST /sun/share-links/{id}/revoke       lo anula al momento
 *  GET  /public/sun/{company}/{token}            el inspector ve el índice (sin sesión); cuenta la visita, respeta vencimiento y máximo
 *  GET  /public/sun/{company}/{token}/download   el inspector descarga el ZIP si el enlace lo permite; cuenta la descarga
 * En la base solo queda el hash del token. Cada acceso (y cada intento rechazado) queda en dossier_share_accesses.
 */
class ShareLinkController extends Controller
{
    /** Roles que aprueban el enlace (RN-SUN-09: gerencia, o el administrador de la cuenta). */
    private const APPROVER_ROLES = ['management', 'account_admin'];

    public function index(string $dossier): JsonResponse
    {
        $this->dossier($dossier);
        $links = DB::select(
            "select l.id, l.token_last4, l.recipient_name, l.status, l.expires_at, l.valid_days, l.max_views, l.view_count, l.allow_download, l.max_downloads,
                    l.download_count, l.requires_approval, l.approved_at, l.revoked_at, l.last_accessed_at, l.created_at, l.created_by,
                    cu.full_name as created_by_name, au.full_name as approved_by_name
               from dossier_share_links l left join users cu on cu.id = l.created_by left join users au on au.id = l.approved_by
              where l.dossier_id = ? and l.deleted_at is null order by l.created_at desc", [$dossier]);
        $ids = array_column($links, 'id');
        $acc = $ids ? DB::select(
            "select a.share_link_id, a.action, a.detail, a.ip_prefix, a.occurred_at, u.full_name as user_name
               from dossier_share_accesses a left join users u on u.id = a.user_id
              where a.share_link_id = any(?::uuid[]) order by a.occurred_at desc, a.id desc", ['{'.implode(',', $ids).'}']) : [];
        $byLink = [];
        foreach ($acc as $a) {
            $byLink[$a->share_link_id][] = ['occurred_at' => $a->occurred_at, 'from' => $a->user_name ?? $a->ip_prefix, 'action' => $a->action, 'detail' => $a->detail];
        }
        $me = (string) auth()->id();
        $approver = in_array(app(CompanyContext::class)->roleCode, self::APPROVER_ROLES, true);

        return response()->json(['data' => array_map(fn ($l) => [
            'id' => $l->id, 'token_last4' => $l->token_last4, 'recipient_name' => $l->recipient_name, 'status' => $this->liveStatus($l),
            'expires_at' => $l->status === 'pending_approval' ? null : $l->expires_at, 'valid_days' => $l->valid_days,
            'max_views' => $l->max_views, 'view_count' => (int) $l->view_count, 'allow_download' => (bool) $l->allow_download,
            'max_downloads' => $l->max_downloads, 'download_count' => (int) $l->download_count, 'requires_approval' => (bool) $l->requires_approval,
            'approved_at' => $l->approved_at, 'approved_by_name' => $l->approved_by_name, 'created_at' => $l->created_at, 'created_by_name' => $l->created_by_name,
            'last_accessed_at' => $l->last_accessed_at,
            'can_approve' => $l->status === 'pending_approval' && $approver && $l->created_by !== $me,
            'accesses' => $byLink[$l->id] ?? [],
        ], $links), 'meta' => ['can_share' => $this->has('sun.dossier_share_links.share'), 'can_revoke' => $this->has('sun.dossier_share_links.revoke'), 'is_approver' => $approver,
            'max_days' => (int) LegalParams::number('dossier_share_max_days', 15)]]);
    }

    public function store(Request $request, string $dossier, AccountsService $accounts): JsonResponse
    {
        $this->can('sun.dossier_share_links.share');
        $d = $this->dossier($dossier);
        if (! in_array($d->status, ['ready', 'ready_with_gaps'], true)) {
            throw new ApiProblem(409, 'conflict', 'Arma el expediente', 'Primero arma el expediente: se comparte lo que ya está revisado.');
        }
        $max = (int) LegalParams::number('dossier_share_max_days', 15);
        $v = $request->validate([
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'days' => ['required', 'integer', 'min:1', "max:{$max}"],
            'max_views' => ['nullable', 'integer', 'min:1', 'max:200'],
            'allow_download' => ['nullable', 'boolean'],
            'max_downloads' => ['nullable', 'integer', 'min:1', 'max:50'],
            'requires_approval' => ['nullable', 'boolean'],
        ], ['days.max' => "El enlace puede durar como máximo {$max} días.", 'days.required' => 'Indica cuántos días estará disponible.',
            'max_views.max' => 'Hasta 200 visitas.', 'max_downloads.max' => 'Hasta 50 descargas.']);
        $download = (bool) ($v['allow_download'] ?? false);
        // RN-SUN-09: todo enlace para el inspector necesita la aprobación de gerencia (o del administrador de la cuenta).
        $approval = true;
        $token = strtoupper(Str::random(40));
        $company = app(CompanyContext::class)->companyId;
        $me = (string) auth()->id();
        $id = DB::selectOne(
            "insert into dossier_share_links (company_id, dossier_id, token_hash, token_last4, recipient_name, expires_at, valid_days, max_views, view_count,
                                              allow_download, max_downloads, download_count, requires_approval, status, created_by, updated_by)
             values (?, ?, decode(?, 'hex'), ?, ?, now() + make_interval(days => ?::int), ?, ?, 0, ?, ?, 0, ?, ?, ?, ?) returning id",
            [$company, $dossier, hash('sha256', $token), substr($token, -4), $v['recipient_name'] ?? null, $v['days'], $v['days'], $v['max_views'] ?? null,
                $download, $download ? ($v['max_downloads'] ?? null) : null, $approval, $approval ? 'pending_approval' : 'active', $me, $me]
        )->id;
        $this->log($id, 'created', $approval ? 'Pendiente de aprobación de gerencia' : null, $me);
        $accounts->audit($me, 'sun_share_link_created', 'dossier_share_links', $id, ['days' => $v['days'], 'max_views' => $v['max_views'] ?? null, 'requires_approval' => $approval]);

        if ($approval) {
            $approvers = array_column(DB::select(
                "select m.user_id from memberships m join roles r on r.id = m.role_id
                  where m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = any(?::text[]) and m.user_id <> ?",
                ['{'.implode(',', self::APPROVER_ROLES).'}', $me]), 'user_id');
            foreach (array_unique($approvers) as $u) {
                DB::insert(
                    "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                     values (?, 'sun.share_link_pending', 'SUN', ?, ?, 'Enlace del inspector por aprobar', ?, ?, 'high', ?)",
                    [$company, $id, $u, mb_substr("Se pidió compartir «{$d->title}» con el inspector. Revísalo y apruébalo para activarlo.", 0, 500),
                        '/m/sun?t=inspection_dossiers&open='.$dossier, 'sun.share_link_pending:'.$id]
                );
            }
        }

        return response()->json(['data' => [
            'id' => $id, 'status' => $approval ? 'pending_approval' : 'active',
            'url' => rtrim((string) config('escudo.frontend_url'), '/')."/expediente/{$company}/{$token}",
        ]], 201);
    }

    public function approve(string $id, AccountsService $accounts): JsonResponse
    {
        $l = $this->pending($id);
        $me = (string) auth()->id();
        if ($l->created_by === $me) {
            throw ApiProblem::forbidden('Quien pidió el enlace no puede aprobarlo: debe aprobarlo otra persona de gerencia.');
        }
        DB::update("update dossier_share_links set status = 'active', approved_by = ?, approved_at = now(), expires_at = now() + make_interval(days => ?::int), updated_by = ? where id = ?",
            [$me, (int) ($l->valid_days ?: 1), $me, $id]);
        $this->log($id, 'approved', null, $me);
        $accounts->audit($me, 'sun_share_link_approved', 'dossier_share_links', $id, []);

        return response()->json(['data' => ['status' => 'active']]);
    }

    public function reject(Request $request, string $id, AccountsService $accounts): JsonResponse
    {
        $this->pending($id);
        $me = (string) auth()->id();
        $reason = mb_substr(trim((string) $request->input('reason')), 0, 300) ?: null;
        DB::update("update dossier_share_links set status = 'rejected', updated_by = ? where id = ?", [$me, $id]);
        $this->log($id, 'rejected', $reason, $me);
        $accounts->audit($me, 'sun_share_link_rejected', 'dossier_share_links', $id, ['reason' => $reason]);

        return response()->json(['data' => ['status' => 'rejected']]);
    }

    public function revoke(string $id, AccountsService $accounts): JsonResponse
    {
        $this->can('sun.dossier_share_links.revoke');
        $me = (string) auth()->id();
        $n = Str::isUuid($id) ? DB::update("update dossier_share_links set status = 'revoked', revoked_at = now(), revoked_by = ? where id = ? and status in ('active', 'pending_approval')", [$me, $id]) : 0;
        if (! $n) {
            throw ApiProblem::notFound('El enlace no existe o ya no está activo.');
        }
        $this->log($id, 'revoked', null, $me);
        $accounts->audit($me, 'sun_share_link_revoked', 'dossier_share_links', $id, []);

        return response()->json(['data' => ['status' => 'revoked']]);
    }

    public function show(Request $request, string $company, string $token): JsonResponse
    {
        return $this->publicLink($request, $company, $token, function (object $l, int $views) use ($company) {
            $d = DB::selectOne('select title, request_type, inspection_order_number, period_from, period_to, built_at, status from inspection_dossiers where id = ?', [$l->dossier_id]);
            $c = DB::selectOne('select legal_name, ruc from companies where id = ?', [$company]);
            $items = DB::select('select section_code as code, title, status, gap_description as gap, worker_id is not null as per_worker from dossier_items
                                  where dossier_id = ? and deleted_at is null order by sort_order', [$l->dossier_id]);
            $downloads = (bool) $l->allow_download && ($l->max_downloads === null || (int) $l->download_count < (int) $l->max_downloads);

            return response()->json(['data' => [
                'company' => ['name' => $c->legal_name, 'ruc' => trim($c->ruc)], 'dossier' => $d, 'items' => $items,
                'expires_at' => $l->expires_at, 'views_left' => $l->max_views === null ? null : max(0, (int) $l->max_views - $views),
                'allow_download' => $downloads,
                'downloads_left' => $downloads && $l->max_downloads !== null ? max(0, (int) $l->max_downloads - (int) $l->download_count) : null,
            ]]);
        }, 'view');
    }

    public function download(Request $request, string $company, string $token): BinaryFileResponse
    {
        return $this->publicLink($request, $company, $token, function (object $l) use ($company) {
            $d = DB::selectOne('select * from inspection_dossiers where id = ?', [$l->dossier_id]);

            return response()->download(DossierZip::pack($d, $company), 'expediente-'.Str::slug($d->title).'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
        }, 'download');
    }

    /** Valida el enlace público, cuenta la visita o la descarga, deja el acceso en el historial y responde. */
    private function publicLink(Request $request, string $company, string $token, \Closure $respond, string $action): mixed
    {
        if (! Str::isUuid($company) || ! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            throw ApiProblem::notFound('El enlace no es válido.');
        }
        $ip = $this->ipPrefix((string) $request->ip());
        $ua = mb_substr((string) $request->userAgent(), 0, 200) ?: null;
        // Los rechazos se devuelven (no se lanzan) para que el intento quede guardado en el historial al cerrar la transacción.
        $out = DB::transaction(function () use ($company, $token, $respond, $action, $ip, $ua) {
            DbContext::setCompany($company);
            $l = DB::selectOne("select * from dossier_share_links where token_hash = decode(?, 'hex') and deleted_at is null", [hash('sha256', strtoupper($token))]);
            if (! $l) {
                return ApiProblem::notFound('El enlace no es válido.');
            }
            if ($l->status === 'active' && strtotime($l->expires_at) < time()) {
                DB::update("update dossier_share_links set status = 'expired' where id = ?", [$l->id]);
                $l->status = 'expired';
            }
            if ($l->status !== 'active') {
                $why = ['expired' => 'El enlace venció.', 'exhausted' => 'El enlace llegó a su número máximo de visitas.', 'revoked' => 'La empresa anuló este enlace.',
                    'pending_approval' => 'El enlace aún no está aprobado por la empresa.', 'rejected' => 'La empresa no aprobó este enlace.'][$l->status] ?? 'El enlace ya no está disponible.';
                $this->log($l->id, 'denied', $why, null, $ip, $ua, $company);

                return new ApiProblem(410, 'gone', 'Enlace no disponible', $why.' Pide uno nuevo a la empresa.');
            }

            if ($action === 'download') {
                if (! $l->allow_download) {
                    $this->log($l->id, 'denied', 'Descarga no permitida', null, $ip, $ua, $company);

                    return ApiProblem::forbidden('La empresa no permitió descargar este expediente.');
                }
                if ($l->max_downloads !== null && (int) $l->download_count >= (int) $l->max_downloads) {
                    $this->log($l->id, 'denied', 'Sin descargas disponibles', null, $ip, $ua, $company);

                    return new ApiProblem(410, 'gone', 'Sin descargas', 'El enlace llegó a su número máximo de descargas.');
                }
                DB::update('update dossier_share_links set download_count = download_count + 1, last_accessed_at = now() where id = ?', [$l->id]);
                $this->log($l->id, 'download', 'Expediente completo (ZIP)', null, $ip, $ua, $company);

                return $respond($l);
            }

            $views = (int) $l->view_count + 1;
            $exhausted = $l->max_views !== null && $views >= (int) $l->max_views;
            DB::update('update dossier_share_links set view_count = ?, last_accessed_at = now(), status = ? where id = ?', [$views, $exhausted ? 'exhausted' : 'active', $l->id]);
            $this->log($l->id, 'view', 'Índice del expediente', null, $ip, $ua, $company);

            return $respond($l, $views);
        });
        if ($out instanceof ApiProblem) {
            throw $out;
        }

        return $out;
    }

    private function log(string $linkId, string $action, ?string $detail, ?string $user, ?string $ip = null, ?string $ua = null, ?string $company = null): void
    {
        DB::insert('insert into dossier_share_accesses (company_id, share_link_id, action, detail, ip_prefix, user_agent, user_id) values (?, ?, ?, ?, ?, ?, ?)',
            [$company ?? app(CompanyContext::class)->companyId, $linkId, $action, $detail, $ip, $ua, $user]);
        if (in_array($action, ['view', 'download'], true) && $user === null) {
            try {
                $l = DB::selectOne("select coalesce(recipient_name, 'el inspector') as who from dossier_share_links where id = ?", [$linkId]);
                $to = array_column(DB::select("select distinct m.user_id from memberships m join roles r on r.id = m.role_id where m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) = 'sst_manager'"), 'user_id');
                \App\Modules\NOT\Notify::users($to, 'sun.inspector_access', 'SUN', $linkId, 'El inspector abrió el expediente', "{$l->who} entró hoy al expediente compartido.",
                    '/m/sun?t=dossier_share_links&open='.$linkId, 'normal', 'sun.inspector_access:'.$linkId.':'.now('America/Lima')->format('Ymd'), $company ?? app(CompanyContext::class)->companyId);
            } catch (\Throwable) {
            }
        }
    }

    /** IP abreviada (RN-SUN-09): los dos primeros octetos en IPv4, los tres primeros grupos en IPv6. */
    private function ipPrefix(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);

            return "{$p[0]}.{$p[1]}.x.x";
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)).':…';
        }

        return null;
    }

    private function liveStatus(object $l): string
    {
        return $l->status === 'active' && strtotime($l->expires_at) < time() ? 'expired' : $l->status;
    }

    private function pending(string $id): object
    {
        if (! in_array(app(CompanyContext::class)->roleCode, self::APPROVER_ROLES, true)) {
            throw ApiProblem::forbidden('Solo gerencia o el administrador de la cuenta aprueban enlaces del inspector.');
        }
        $l = Str::isUuid($id) ? DB::selectOne('select id, status, created_by, valid_days from dossier_share_links where id = ? and deleted_at is null', [$id]) : null;
        if (! $l) {
            throw ApiProblem::notFound('El enlace no existe.');
        }
        if ($l->status !== 'pending_approval') {
            throw new ApiProblem(409, 'conflict', 'Ya revisado', 'El enlace no está pendiente de aprobación.');
        }

        return $l;
    }

    private function dossier(string $dossier): object
    {
        $d = Str::isUuid($dossier) ? DB::selectOne('select id, status, title from inspection_dossiers where id = ? and deleted_at is null', [$dossier]) : null;

        return $d ?? throw ApiProblem::notFound('El expediente no existe o es de otra empresa.');
    }

    private function has(string $p): bool
    {
        return in_array($p, Permissions::effective(), true);
    }

    private function can(string $p): void
    {
        if (! $this->has($p)) {
            throw ApiProblem::forbidden('Tu rol no comparte expedientes.');
        }
    }
}

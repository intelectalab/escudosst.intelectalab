<?php

namespace App\Modules\INV;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\FIR\SignatureService;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Firmas del equipo investigador (M-INV SCR-INV-02 «equipo con firmas», SCR-INV-06 «informe con firmas del equipo»).
 *  GET  /inv/investigations/{id}/signatures   integrantes con el estado de su firma (firmó, pendiente, sin pedir, externo)
 *  POST /inv/investigations/{id}/signatures   «Solicitar firmas»: pide por M-FIR la firma del informe a cada integrante
 *                                             (usuario o trabajador) que aún no firmó ni tiene una solicitud pendiente;
 *                                             avisa a los que tienen usuario. Los externos firman en papel.
 *
 * La huella firmada cubre el contenido del informe (no su estado): la firma sigue «Íntegra» cuando se aprueba o cierra.
 */
class TeamSignaturesController extends Controller
{
    /** Rol en el equipo => calidad en que firma (signature_requests.role_in_document). */
    private const ROLE_IN_DOCUMENT = [
        'leader' => 'responsible', 'committee_representative' => 'committee_member', 'worker_representative' => 'worker',
        'member' => 'reviewer', 'expert' => 'reviewer', 'witness' => 'witness',
    ];

    public function index(string $id): JsonResponse
    {
        $inv = $this->investigation($id);

        return response()->json(['data' => [
            'members' => array_map(fn ($m) => $this->present($m), self::members($inv->id)),
            'can_request' => $this->canRequest(),
        ]]);
    }

    public function request(string $id, AccountsService $accounts): JsonResponse
    {
        $inv = $this->investigation($id);
        if (! $this->canRequest()) {
            throw ApiProblem::forbidden('Tu rol no pide las firmas del equipo investigador.');
        }
        if ($inv->status === 'closed') {
            throw new ApiProblem(409, 'conflict', 'Investigación cerrada', 'La investigación está cerrada: ya no se piden firmas.');
        }
        $members = self::members($inv->id);
        if (! $members) {
            throw new ApiProblem(409, 'conflict', 'Sin equipo', 'Primero registra al equipo investigador.');
        }
        $ctx = app(CompanyContext::class);
        $uid = (string) auth()->id();
        $hash = SignatureService::hash('investigations', $inv);
        $label = $inv->code ?: 'Investigación';
        $created = 0;
        $skipped = ['signed' => 0, 'pending' => 0, 'external' => 0];
        DB::transaction(function () use ($members, $inv, $ctx, $uid, $hash, $label, &$created, &$skipped) {
            $order = (int) DB::selectOne("select coalesce(max(sequence_order), 0) as n from signature_requests where subject_type = 'investigations' and subject_id = ? and deleted_at is null", [$inv->id])->n;
            foreach ($members as $m) {
                $state = $this->state($m);
                if ($state !== 'none') {
                    $skipped[$state === 'external' ? 'external' : ($state === 'signed' ? 'signed' : 'pending')]++;

                    continue;
                }
                $signerUser = $m->signer_user_id;
                $signerWorker = $signerUser ? null : $m->worker_id;
                $reqId = DB::selectOne(
                    "insert into signature_requests (company_id, subject_type, subject_id, subject_module, document_hash, signer_user_id, signer_worker_id, role_in_document,
                        sequence_order, status, requested_by, created_by, updated_by)
                     values (?, 'investigations', ?, 'INV', decode(?, 'hex'), ?, ?, ?, ?, 'pending', ?, ?, ?) returning id",
                    [$ctx->companyId, $inv->id, $hash, $signerUser, $signerWorker, self::ROLE_IN_DOCUMENT[$m->role] ?? 'reviewer', ++$order, $uid, $uid, $uid]
                )->id;
                if ($signerUser) {
                    DB::insert(
                        "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                         values (?, 'fir.signature_requested', 'signature_requests', ?, ?, ?, ?, ?, 'high', ?) on conflict do nothing",
                        [$ctx->companyId, $reqId, $signerUser, "Firma pendiente: informe {$label}", 'Revisa el informe de la investigación y fírmalo como integrante del equipo investigador.',
                            "/m/inv?t=investigations&open={$inv->id}", "fir_req:{$reqId}:{$signerUser}"]
                    );
                }
                $created++;
            }
        });
        $accounts->audit($uid, 'signatures_requested', 'investigations', $inv->id, ['created' => $created]);

        return response()->json(['data' => [
            'requested' => $created, 'skipped' => $skipped,
            'members' => array_map(fn ($m) => $this->present($m), self::members($inv->id)),
            'message' => $created ? "Se pidió la firma a {$created} integrante(s) del equipo." : 'No había integrantes por pedir firma: ya firmaron o tienen su firma pendiente.',
        ]], $created ? 201 : 200);
    }

    /**
     * Integrantes con su firma: la de la ficha (signature_id) o la última hecha por M-FIR sobre la investigación,
     * y la solicitud pendiente si la hay.
     *
     * @return list<object>
     */
    public static function members(string $investigationId): array
    {
        return DB::select(
            "select t.id, t.role, t.worker_id, t.user_id, coalesce(t.user_id, w.user_id) as signer_user_id, t.external_name,
                    coalesce(w.full_name, u.full_name, t.external_name, 'Sin nombre') as name,
                    coalesce(t.signature_id, sg.id) as signature_id, coalesce(s0.signed_at, sg.signed_at) as signed_at, coalesce(s0.method, sg.method) as method,
                    pr.id as pending_request_id
               from investigation_team t left join workers w on w.id = t.worker_id left join users u on u.id = t.user_id
               left join signatures s0 on s0.id = t.signature_id
               left join lateral (select s.id, s.signed_at, s.method from signatures s
                                   where s.subject_type = 'investigations' and s.subject_id = t.investigation_id
                                     and ((t.user_id is not null and s.signer_user_id = t.user_id) or (w.user_id is not null and s.signer_user_id = w.user_id)
                                          or (t.worker_id is not null and s.signer_worker_id = t.worker_id))
                                   order by s.signed_at desc limit 1) sg on true
               left join lateral (select r.id from signature_requests r
                                   where r.subject_type = 'investigations' and r.subject_id = t.investigation_id and r.status = 'pending' and r.deleted_at is null
                                     and (r.expires_at is null or r.expires_at > now())
                                     and ((r.signer_user_id is not null and r.signer_user_id = coalesce(t.user_id, w.user_id))
                                          or (r.signer_worker_id is not null and r.signer_worker_id = t.worker_id))
                                   limit 1) pr on true
              where t.investigation_id = ? and t.deleted_at is null
              order by array_position(array['leader','committee_representative','worker_representative','member','expert','witness'], t.role), 7",
            [$investigationId]
        );
    }

    private function state(object $m): string
    {
        return match (true) {
            (bool) $m->signature_id => 'signed',
            (bool) $m->pending_request_id => 'pending',
            ! $m->signer_user_id && ! $m->worker_id => 'external',
            default => 'none',
        };
    }

    private function present(object $m): array
    {
        return ['id' => $m->id, 'name' => $m->name, 'role' => $m->role, 'state' => $this->state($m), 'signed_at' => $m->signed_at, 'method' => $m->method,
            'request_id' => $m->pending_request_id, 'is_me' => $m->signer_user_id && (string) $m->signer_user_id === (string) auth()->id()];
    }

    private function canRequest(): bool
    {
        $p = Permissions::effective();

        return in_array('fir.signature_requests.create', $p, true) && (in_array('inv.investigation_team.create', $p, true) || in_array('inv.investigations.update', $p, true));
    }

    private function investigation(string $id): object
    {
        $inv = Str::isUuid($id) ? DB::selectOne('select * from investigations where id = ? and deleted_at is null', [$id]) : null;
        if (! $inv) {
            throw ApiProblem::notFound('La investigación no existe o es de otra empresa.');
        }

        return $inv;
    }
}

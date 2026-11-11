<?php

namespace App\Modules\COM;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Core\Tenancy\DbContext;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Voto por enlace para los trabajadores sin usuario de la app (M-COM RN-COM-03, docs/07 §5.2) y tarjeta
 * «Elección del comité» del inicio del trabajador.
 *
 *  GET  /com/elections/{id}/credentials          lista de credenciales para imprimir (junta electoral)
 *  POST /com/elections/{id}/credentials          emitir credenciales {roll_ids?: [...]}: sin roll_ids, a todos los
 *                                                electores habilitados sin usuario de la app y sin credencial;
 *                                                con roll_ids, emite o vuelve a emitir (anula el enlace anterior)
 *  GET  /com/my-election                         elección con votación abierta para la tarjeta del inicio
 *  GET  /public/com/vote/{company}/{token}       cédula del elector con su enlace (sin sesión)
 *  POST /public/com/vote/{company}/{token}       emitir el voto {candidate_id} | {blank: true}
 *
 * Secreto del voto: el token solo marca en el padrón que el elector votó (y el día); el voto se guarda en
 * election_ballots con un identificador aleatorio, sin worker_id ni el token. Un token usado se rechaza.
 */
class VoteLinkController extends Controller
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private AccountsService $accounts) {}

    public function credentials(string $id): JsonResponse
    {
        $e = $this->election($id);
        $rows = DB::select(
            "select r.id, r.worker_id, w.full_name, w.document_last4, p.name as position, s.name as site, r.has_voted, r.vote_token_enc, r.vote_token_issued_at,
                    (w.user_id is not null or exists (select 1 from memberships m where m.worker_id = w.id and m.status = 'active' and m.deleted_at is null)) as has_app_user
               from election_roll r join workers w on w.id = r.worker_id left join positions p on p.id = w.current_position_id left join sites s on s.id = w.current_site_id
              where r.election_id = ? and not r.excluded order by s.name nulls last, w.full_name", [$id]);
        $company = $this->company();
        $out = [];
        foreach ($rows as $r) {
            $token = null;
            if ($r->vote_token_enc) {
                try {
                    $token = Crypt::decryptString($r->vote_token_enc);
                } catch (\Throwable) {
                    $token = null;
                }
            }
            $out[] = [
                'roll_id' => $r->id, 'worker_id' => $r->worker_id, 'full_name' => $r->full_name, 'document_last4' => $r->document_last4,
                'position' => $r->position, 'site' => $r->site, 'has_voted' => (bool) $r->has_voted, 'has_app_user' => (bool) $r->has_app_user,
                'issued_at' => $r->vote_token_issued_at, 'token' => $r->has_voted ? null : $token,
                'path' => $token && ! $r->has_voted ? "/votar/{$company}/{$token}" : null,
            ];
        }
        $co = DB::selectOne('select legal_name, trade_name, ruc from companies where id = ?', [$company]);

        return response()->json(['data' => $out, 'meta' => [
            'election' => ['id' => $e->id, 'title' => $e->title, 'status' => $e->status, 'voting_opens_at' => $e->voting_opens_at, 'voting_closes_at' => $e->voting_closes_at],
            'company' => ['name' => $co->trade_name ?: $co->legal_name, 'ruc' => trim((string) $co->ruc)],
            'issued' => count(array_filter($out, fn ($r) => $r['token'])),
            'without_app_user' => count(array_filter($out, fn ($r) => ! $r['has_app_user'])),
        ]]);
    }

    public function issue(Request $request, string $id): JsonResponse
    {
        $e = $this->election($id);
        if (! in_array($e->status, ['nominations_open', 'nominations_closed', 'voting_open'], true)) {
            throw new ApiProblem(409, 'conflict', 'Paso no disponible', 'Las credenciales de voto se emiten con el padrón armado y antes del cierre de la votación.');
        }
        $ids = array_values(array_unique(array_map('strval', (array) ($request->input('roll_ids') ?? []))));
        if ($ids && array_filter($ids, fn ($x) => ! Str::isUuid($x))) {
            $this->fail('roll_ids', 'Una de las personas no está en el padrón.');
        }
        $rows = $ids
            ? DB::select('select id, excluded, has_voted from election_roll where election_id = ? and id = any(?::uuid[])', [$id, '{'.implode(',', $ids).'}'])
            : DB::select(
                "select r.id, r.excluded, r.has_voted from election_roll r join workers w on w.id = r.worker_id
                  where r.election_id = ? and not r.excluded and not r.has_voted and r.vote_token_hash is null and w.user_id is null
                    and not exists (select 1 from memberships m where m.worker_id = w.id and m.status = 'active' and m.deleted_at is null)", [$id]);
        if ($ids && count($rows) !== count($ids)) {
            $this->fail('roll_ids', 'Una de las personas no está en el padrón.');
        }
        $issued = 0;
        $issuedIds = [];
        DB::transaction(function () use ($rows, &$issued, &$issuedIds) {
            foreach ($rows as $r) {
                if ($r->excluded || $r->has_voted) {
                    continue;
                }
                $token = $this->newToken();
                DB::update("update election_roll set vote_token_hash = decode(?, 'hex'), vote_token_enc = ?, vote_token_issued_at = now(), updated_at = now(), updated_by = ? where id = ?",
                    [hash('sha256', $token), Crypt::encryptString($token), (string) auth()->id(), $r->id]);
                $issued++;
                $issuedIds[] = $r->id;
            }
        });
        // Con WhatsApp en vivo, el enlace personal también llega por WhatsApp a quien lo aceptó (M-COM §10).
        \App\Modules\NOT\AlertDelivery::queueVoteLinks($this->company(), $issuedIds);
        $this->accounts->audit((string) auth()->id(), 'election_vote_credentials_issued', 'elections', $id, ['issued' => $issued, 'reissue' => (bool) $ids]);

        return response()->json(['data' => ['issued' => $issued]], 201);
    }

    /** Tarjeta «Elección del comité» del inicio: solo con votación abierta y si quien entra puede votar. */
    public function mine(): JsonResponse
    {
        if (! in_array('com.election_ballots.vote', Permissions::effective(), true)) {
            return response()->json(['data' => null]);
        }
        $e = DB::selectOne("select id, title, status, voting_opens_at, voting_closes_at from elections where deleted_at is null and status = 'voting_open' order by created_at desc limit 1");
        if (! $e || ($e->voting_closes_at && strtotime($e->voting_closes_at) <= time()) || ($e->voting_opens_at && strtotime($e->voting_opens_at) > time())) {
            return response()->json(['data' => null]);
        }
        $ctx = app(CompanyContext::class);
        $worker = DB::selectOne('select worker_id from memberships where id = ?', [$ctx->membershipId])?->worker_id
            ?: DB::selectOne("select id from workers where user_id = ? and deleted_at is null and status <> 'terminated' limit 1", [(string) auth()->id()])?->id;
        $roll = $worker ? DB::selectOne('select excluded, has_voted from election_roll where election_id = ? and worker_id = ?', [$e->id, $worker]) : null;

        return response()->json(['data' => [
            'id' => $e->id, 'title' => $e->title, 'voting_closes_at' => $e->voting_closes_at,
            'on_roll' => $roll && ! $roll->excluded, 'has_voted' => (bool) ($roll->has_voted ?? false), 'linked' => (bool) $worker,
        ]]);
    }

    // ── Público (sin sesión) ──

    public function show(string $company, string $token): JsonResponse
    {
        return $this->withVoter($company, $token, function (object $r, object $e) use ($company) {
            $open = $this->open($e);
            $co = DB::selectOne('select legal_name, trade_name from companies where id = ?', [$company]);

            return response()->json(['data' => [
                'company' => ['name' => $co->trade_name ?: $co->legal_name],
                'election' => ['title' => $e->title, 'status' => $e->status, 'voting_closes_at' => $e->voting_closes_at, 'allow_blank' => (bool) $e->allow_blank],
                'voter' => ['first_name' => Str::before(trim((string) $r->full_name), ' ')],
                'open' => $open,
                'has_voted' => (bool) $r->has_voted,
                'candidates' => $open && ! $r->has_voted ? DB::select(
                    "select c.id, w.full_name as name, p.name as position, c.list_name
                       from election_candidates c join workers w on w.id = c.worker_id left join positions p on p.id = w.current_position_id
                      where c.election_id = ? and c.status = 'accepted' and c.deleted_at is null order by w.full_name", [$e->id]) : [],
            ]]);
        });
    }

    public function vote(Request $request, string $company, string $token): JsonResponse
    {
        return $this->withVoter($company, $token, function (object $r, object $e) use ($request, $company) {
            if (! $this->open($e)) {
                return new ApiProblem(409, 'conflict', 'Votación cerrada', 'La votación no está abierta en este momento.');
            }
            $blank = $request->boolean('blank');
            $candidate = $blank ? null : (string) $request->input('candidate_id');
            if (! $blank) {
                if (! Str::isUuid($candidate) || ! DB::selectOne("select 1 as ok from election_candidates where id = ? and election_id = ? and status = 'accepted' and deleted_at is null", [$candidate, $e->id])) {
                    return new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Elige a un candidato de la cédula.', ['errors' => ['candidate_id' => ['Elige a un candidato de la cédula.']]]);
                }
            } elseif (! $e->allow_blank) {
                return new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'Esta elección no admite voto en blanco.', ['errors' => ['blank' => ['Esta elección no admite voto en blanco.']]]);
            }
            $roll = DB::selectOne('select id, excluded, has_voted from election_roll where id = ? for update', [$r->id]);
            if ($roll->has_voted) {
                return new ApiProblem(409, 'already_voted', 'Ya votaste', 'Este enlace ya se usó: el voto es único y no se puede cambiar.');
            }
            $receipt = strtoupper(Str::random(4).'-'.Str::random(4).'-'.Str::random(4));
            DB::update("update election_roll set has_voted = true, voted_on = (now() at time zone 'America/Lima')::date, updated_at = now() where id = ?", [$roll->id]);
            // Igual que en la app: el voto no lleva worker_id ni el token; su identificador es aleatorio.
            DB::insert(
                "insert into election_ballots (company_id, election_id, voter_token_hash, candidate_id, is_blank, is_null_vote, channel, ballot_hash)
                 values (?, ?, decode(?, 'hex'), ?, ?, false, 'web', decode(?, 'hex'))",
                [$company, $e->id, bin2hex(random_bytes(32)), $candidate, $blank, hash('sha256', $e->id.'|'.$receipt)]
            );
            DB::update('update elections set votes_cast = votes_cast + 1 where id = ?', [$e->id]);

            return response()->json(['data' => ['voted' => true, 'receipt' => $receipt]], 201);
        });
    }

    // ── Internos ──

    /** Abre la transacción con la empresa del enlace, valida el token y responde (los rechazos se devuelven). */
    private function withVoter(string $company, string $token, \Closure $respond): JsonResponse
    {
        $token = strtoupper($token);
        if (! Str::isUuid($company) || ! preg_match('/^['.self::ALPHABET.']{24}$/', $token)) {
            throw ApiProblem::notFound('El enlace de votación no es válido.');
        }
        $out = DB::transaction(function () use ($company, $token, $respond) {
            DbContext::setCompany($company);
            $r = DB::selectOne(
                "select r.id, r.election_id, r.excluded, r.has_voted, w.full_name from election_roll r join workers w on w.id = r.worker_id
                  where r.vote_token_hash = decode(?, 'hex')", [hash('sha256', $token)]);
            if (! $r || $r->excluded) {
                return ApiProblem::notFound('El enlace de votación no es válido o fue reemplazado por uno nuevo.');
            }
            $e = DB::selectOne('select * from elections where id = ? and deleted_at is null', [$r->election_id]);
            if (! $e) {
                return ApiProblem::notFound('El enlace de votación no es válido.');
            }

            return $respond($r, $e);
        });
        if ($out instanceof ApiProblem) {
            throw $out;
        }

        return $out;
    }

    private function open(object $e): bool
    {
        if ($e->status !== 'voting_open') {
            return false;
        }
        $now = time();

        return (! $e->voting_opens_at || strtotime($e->voting_opens_at) <= $now) && (! $e->voting_closes_at || strtotime($e->voting_closes_at) > $now);
    }

    private function newToken(): string
    {
        $t = '';
        for ($i = 0; $i < 24; $i++) {
            $t .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $t;
    }

    private function election(string $id): object
    {
        $e = Str::isUuid($id) ? DB::selectOne('select * from elections where id = ? and deleted_at is null', [$id]) : null;

        return $e ?? throw ApiProblem::notFound('La elección no existe o es de otra empresa.');
    }

    private function company(): string
    {
        return app(CompanyContext::class)->companyId;
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}

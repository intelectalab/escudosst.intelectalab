<?php

namespace App\Modules\COM;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Elección electrónica de los representantes de los trabajadores (M-COM SCR-COM-02..06, docs/06 §5).
 *
 *  GET   /com/elections                         elecciones (la más reciente primero)
 *  POST  /com/elections                         convocatoria {title, seats_titular, seats_alternate, nominations_close_at?, voting_opens_at?, voting_closes_at?, electoral_board[], tie_break_rule}
 *  GET   /com/elections/{id}                    asistente: paso, candidatos con requisitos, participación; resultados solo tras el cierre (RN-COM-04)
 *  POST  /com/elections/{id}/open-nominations   «Abrir inscripción»: arma el padrón y avisa a los trabajadores
 *  POST  /com/elections/{id}/open-voting        «Abrir votación» {voting_closes_at?}: cierra la inscripción, congela el padrón (RN-COM-02)
 *  POST  /com/elections/{id}/close              «Cerrar votación y escrutar»: escrutinio con titulares, suplentes y desempate (RN-COM-06)
 *  POST  /com/elections/{id}/publish            «Generar acta»: publica resultados y registra a los elegidos en el comité
 *  POST  /com/elections/{id}/cancel             cancelar (antes de votar) o anular (durante la votación) {reason}
 *  GET   /com/elections/{id}/roll               padrón (junta electoral y RR. HH.)
 *  PATCH /com/elections/{id}/roll/{rollId}      excluir o reincorporar {excluded, reason}
 *  POST  /com/elections/{id}/candidates         la junta inscribe a un trabajador {worker_id, list_name?}
 *  POST  /com/elections/{id}/apply              el trabajador postula {list_name?}
 *  POST  /com/elections/{id}/candidates/{cid}/review   habilitar u observar {decision: accept|reject, reason?}
 *  GET   /com/elections/{id}/ballot             cédula del elector con sesión
 *  POST  /com/elections/{id}/vote               emitir el voto {candidate_id} | {blank: true}
 *  GET   /com/elections/{id}/minutes            datos del acta de elección para imprimir
 *
 * Voto secreto (RN-COM-03): el padrón marca que el elector votó (solo el día); el voto se guarda en election_ballots
 * con un identificador aleatorio y sin worker_id. El comprobante es un código cuyo hash queda en el voto.
 */
class ElectionController extends Controller
{
    private const STEP = ['convened' => 0, 'nominations_open' => 1, 'nominations_closed' => 1, 'voting_open' => 2, 'voting_closed' => 3, 'results_published' => 4];

    public function __construct(private AccountsService $accounts) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::select(
            "select id, title, period_label, status, seats_titular, seats_alternate, eligible_voters, votes_cast, voting_opens_at, voting_closes_at, results_published_at, created_at
               from elections where deleted_at is null order by created_at desc limit 20"
        )]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'period_label' => ['nullable', 'string', 'max:40'],
            'seats_titular' => ['required', 'integer', 'min:1', 'max:6'],
            'seats_alternate' => ['nullable', 'integer', 'min:0', 'max:6'],
            'nominations_close_at' => ['nullable', 'date'],
            'voting_opens_at' => ['nullable', 'date'],
            'voting_closes_at' => ['nullable', 'date', 'after:voting_opens_at'],
            'electoral_board' => ['nullable', 'array', 'max:6'],
            'electoral_board.*.name' => ['required', 'string', 'max:120'],
            'electoral_board.*.role' => ['nullable', 'string', 'max:60'],
            'tie_break_rule' => ['nullable', 'in:seniority,lottery'],
            'committee_id' => ['nullable', 'uuid'],
        ], [
            'title.required' => 'Escribe el nombre de la elección.',
            'seats_titular.required' => 'Indica cuántos representantes titulares se eligen.',
            'voting_closes_at.after' => 'El cierre de la votación debe ser posterior a su apertura.',
        ]);
        if (DB::selectOne("select 1 as ok from elections where deleted_at is null and status in ('convened','nominations_open','nominations_closed','voting_open','voting_closed')")) {
            throw new ApiProblem(409, 'conflict', 'Hay una elección en curso', 'Ya hay una elección en curso: termínala o cancélala antes de convocar otra.');
        }
        if (! empty($d['committee_id']) && ! DB::selectOne('select 1 as ok from committees where id = ? and deleted_at is null', [$d['committee_id']])) {
            $this->fail('committee_id', 'El comité no existe en esta empresa.');
        }
        $uid = (string) auth()->id();
        $id = DB::selectOne(
            "insert into elections (company_id, committee_id, title, period_label, status, convened_at, nominations_open_at, nominations_close_at, voting_opens_at, voting_closes_at,
                seats_titular, seats_alternate, electoral_board, tie_break_rule, created_by, updated_by)
             values (?, ?, ?, ?, 'convened', now(), null, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?) returning id",
            [$this->company(), $d['committee_id'] ?? null, $d['title'], $d['period_label'] ?? null, $d['nominations_close_at'] ?? null, $d['voting_opens_at'] ?? null,
                $d['voting_closes_at'] ?? null, $d['seats_titular'], $d['seats_alternate'] ?? 0, json_encode(array_values($d['electoral_board'] ?? []), JSON_UNESCAPED_UNICODE),
                $d['tie_break_rule'] ?? 'seniority', $uid, $uid]
        )->id;
        $this->accounts->audit($uid, 'election_convened', 'elections', $id, ['title' => $d['title']]);

        return response()->json(['data' => $this->detail($id)], 201);
    }

    public function show(string $id): JsonResponse
    {
        $this->election($id);

        return response()->json(['data' => $this->detail($id)]);
    }

    public function openNominations(string $id): JsonResponse
    {
        $e = $this->election($id);
        $this->expect($e, ['convened'], 'La inscripción solo se abre desde la convocatoria.');
        $this->syncRoll($id);
        DB::update("update elections set status = 'nominations_open', nominations_open_at = now(), updated_by = ? where id = ?", [(string) auth()->id(), $id]);
        $this->notifyRoll($id, 'com.election_called', 'Convocatoria a elecciones del Comité SST',
            "Se abrió la inscripción de candidatos para «{$e->title}». Si cumples los requisitos, puedes postular desde la app.");
        $this->accounts->audit((string) auth()->id(), 'election_nominations_open', 'elections', $id, []);

        return response()->json(['data' => $this->detail($id)]);
    }

    public function openVoting(Request $request, string $id): JsonResponse
    {
        $e = $this->election($id);
        $this->expect($e, ['nominations_open', 'nominations_closed'], 'La votación se abre después de la inscripción de candidatos.');
        $closes = $request->input('voting_closes_at') ?: $e->voting_closes_at;
        if (! $closes || strtotime((string) $closes) <= time()) {
            $this->fail('voting_closes_at', 'Indica la fecha y hora de cierre de la votación (en el futuro).');
        }
        $accepted = (int) DB::selectOne("select count(*) as n from election_candidates where election_id = ? and status = 'accepted' and deleted_at is null", [$id])->n;
        if ($accepted === 0) {
            $this->fail('candidates', 'Habilita al menos a un candidato antes de abrir la votación.');
        }
        if (DB::selectOne("select 1 as ok from election_candidates where election_id = ? and status = 'nominated' and deleted_at is null", [$id])) {
            $this->fail('candidates', 'Hay candidaturas sin revisar: habilítalas u obsérvalas antes de abrir la votación.');
        }
        $this->syncRoll($id);
        $eligible = (int) DB::selectOne('select count(*) as n from election_roll where election_id = ? and not excluded', [$id])->n;
        DB::update(
            "update elections set status = 'voting_open', nominations_close_at = coalesce(least(nominations_close_at, now()), now()),
                    voting_opens_at = case when voting_opens_at is null or voting_opens_at > now() then now() else voting_opens_at end,
                    voting_closes_at = ?::timestamptz, roll_snapshot_at = now(), eligible_voters = ?, updated_by = ? where id = ?",
            [date('c', strtotime((string) $closes)), $eligible, (string) auth()->id(), $id]
        );
        $this->notifyRoll($id, 'com.election_voting_open', 'Ya puedes votar por el Comité SST',
            "La votación de «{$e->title}» está abierta hasta el ".date('d/m/Y H:i', strtotime((string) $closes)).'. Tu voto es secreto y se emite una sola vez.', true);
        $this->accounts->audit((string) auth()->id(), 'election_voting_open', 'elections', $id, ['eligible' => $eligible]);

        return response()->json(['data' => $this->detail($id)]);
    }

    public function close(string $id): JsonResponse
    {
        $e = $this->election($id);
        $this->expect($e, ['voting_open'], 'Solo se escruta una votación abierta.');
        DB::transaction(function () use ($e) {
            DB::update("update elections set status = 'voting_closed', voting_closes_at = greatest(least(coalesce(voting_closes_at, now()), now()), voting_opens_at + interval '1 second'), updated_by = ? where id = ?",
                [(string) auth()->id(), $e->id]);
            $this->count($e);
        });
        $this->accounts->audit((string) auth()->id(), 'election_counted', 'elections', $id, []);

        return response()->json(['data' => $this->detail($id)]);
    }

    public function publish(string $id): JsonResponse
    {
        $e = $this->election($id);
        $this->expect($e, ['voting_closed'], 'El acta se genera después del escrutinio.');
        DB::transaction(function () use ($e) {
            $committee = $e->committee_id ?: $this->committeeFor($e);
            $term = (int) LegalParams::number('committee_term_years', 2);
            $type = DB::selectOne('select committee_type from committees where id = ?', [$committee])->committee_type;
            $uid = (string) auth()->id();
            foreach (DB::select("select worker_id, status from election_candidates where election_id = ? and status in ('elected_titular','elected_alternate') and deleted_at is null", [$e->id]) as $c) {
                if (DB::selectOne("select 1 as ok from committee_members where committee_id = ? and worker_id = ? and status = 'active' and deleted_at is null", [$committee, $c->worker_id])) {
                    continue;
                }
                DB::insert(
                    "insert into committee_members (company_id, committee_id, worker_id, representation, member_type, role_in_committee, election_id, starts_on, ends_on, status, created_by, updated_by)
                     values (?, ?, ?, ?, ?, 'member', ?, current_date, (current_date + make_interval(years => ?))::date, 'active', ?, ?)",
                    [$this->company(), $committee, $c->worker_id, $type === 'supervisor' ? 'supervisor' : 'workers', $c->status === 'elected_titular' ? 'titular' : 'alternate',
                        $e->id, $term, $uid, $uid]
                );
            }
            DB::update("update elections set status = 'results_published', results_published_at = now(), committee_id = ?, updated_by = ? where id = ?", [$committee, $uid, $e->id]);
        });
        $this->notifyRoll($id, 'com.election_results', 'Resultados de la elección del Comité SST', "Ya se publicó el acta de «{$e->title}» con los representantes elegidos.");
        $this->accounts->audit((string) auth()->id(), 'election_results_published', 'elections', $id, []);

        return response()->json(['data' => $this->detail($id)]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $e = $this->election($id);
        $reason = trim((string) $request->input('reason'));
        if ($reason === '') {
            $this->fail('reason', 'Indica el motivo.');
        }
        $this->expect($e, ['convened', 'nominations_open', 'nominations_closed', 'voting_open'], 'Esta elección ya no se puede cancelar.');
        // Durante la votación se anula: los votos se conservan, sin efecto (docs/06 §5).
        $to = $e->status === 'voting_open' ? 'annulled' : 'cancelled';
        DB::update('update elections set status = ?, annulled_reason = ?, updated_by = ? where id = ?', [$to, $reason, (string) auth()->id(), $id]);
        $this->accounts->audit((string) auth()->id(), 'election_'.$to, 'elections', $id, [], $reason);

        return response()->json(['data' => $this->detail($id)]);
    }

    // ── Padrón ──

    public function roll(string $id): JsonResponse
    {
        $this->election($id);
        $this->canSeeRoll();
        $rows = DB::select(
            "select r.id, r.worker_id, w.full_name, w.document_last4, p.name as position, r.excluded, r.exclusion_reason, r.has_voted
               from election_roll r join workers w on w.id = r.worker_id left join positions p on p.id = w.current_position_id
              where r.election_id = ? order by r.excluded, w.full_name", [$id]);

        return response()->json(['data' => $rows, 'meta' => [
            'total' => count($rows),
            'excluded' => count(array_filter($rows, fn ($r) => $r->excluded)),
            'voted' => count(array_filter($rows, fn ($r) => $r->has_voted)),
        ]]);
    }

    public function updateRoll(Request $request, string $id, string $rollId): JsonResponse
    {
        $e = $this->election($id);
        $this->expect($e, ['convened', 'nominations_open', 'nominations_closed'], 'El padrón ya está congelado: se congeló al abrir la votación.');
        $row = Str::isUuid($rollId) ? DB::selectOne('select * from election_roll where id = ? and election_id = ?', [$rollId, $id]) : null;
        if (! $row) {
            throw ApiProblem::notFound('La persona no está en el padrón.');
        }
        $excluded = $request->boolean('excluded');
        $reason = trim((string) $request->input('reason'));
        if ($excluded && $reason === '') {
            $this->fail('reason', 'Indica el motivo de la exclusión (por ejemplo, personal de dirección o de confianza).');
        }
        DB::update('update election_roll set excluded = ?, exclusion_reason = ?, updated_at = now(), updated_by = ? where id = ?',
            [$excluded, $excluded ? $reason : null, (string) auth()->id(), $rollId]);
        $this->accounts->audit((string) auth()->id(), $excluded ? 'election_roll_excluded' : 'election_roll_included', 'elections', $id, ['worker_id' => $row->worker_id], $reason ?: null);

        return response()->json(['data' => ['id' => $rollId, 'excluded' => $excluded]]);
    }

    // ── Candidaturas ──

    public function addCandidate(Request $request, string $id): JsonResponse
    {
        $e = $this->election($id);
        $d = $request->validate(['worker_id' => ['required', 'uuid'], 'list_name' => ['nullable', 'string', 'max:120']], ['worker_id.required' => 'Elige al trabajador.']);

        return $this->nominate($e, $d['worker_id'], $d['list_name'] ?? null);
    }

    public function apply(Request $request, string $id): JsonResponse
    {
        $e = $this->election($id);
        $worker = $this->myWorker();
        if (! $worker) {
            throw ApiProblem::forbidden('Tu usuario no está vinculado a un trabajador de la empresa: pide a RR. HH. que lo vincule.');
        }

        return $this->nominate($e, $worker, $request->input('list_name') ? mb_substr((string) $request->input('list_name'), 0, 120) : null);
    }

    public function review(Request $request, string $id, string $cid): JsonResponse
    {
        $e = $this->election($id);
        $this->expect($e, ['nominations_open', 'nominations_closed'], 'Las candidaturas se revisan durante la inscripción.');
        $c = Str::isUuid($cid) ? DB::selectOne('select * from election_candidates where id = ? and election_id = ? and deleted_at is null', [$cid, $id]) : null;
        if (! $c) {
            throw ApiProblem::notFound('La candidatura no existe.');
        }
        $decision = (string) $request->input('decision');
        if (! in_array($decision, ['accept', 'reject'], true)) {
            $this->fail('decision', 'Elige «Habilitar» u «Observar».');
        }
        $reason = trim((string) $request->input('reason'));
        if ($decision === 'reject' && $reason === '') {
            $this->fail('reason', 'Explica qué debe subsanar el candidato.');
        }
        if ($decision === 'accept') {
            $blocking = array_filter($this->requirements($c->worker_id), fn ($r) => ! $r['ok'] && $r['blocking']);
            if ($blocking) {
                $this->fail('decision', 'No cumple: '.implode(', ', array_column($blocking, 'label')).'.');
            }
        }
        DB::update('update election_candidates set status = ?, rejection_reason = ?, updated_by = ? where id = ?',
            [$decision === 'accept' ? 'accepted' : 'rejected', $decision === 'accept' ? null : $reason, (string) auth()->id(), $cid]);
        $this->accounts->audit((string) auth()->id(), 'election_candidate_'.($decision === 'accept' ? 'accepted' : 'observed'), 'election_candidates', $cid, [], $reason ?: null);

        return response()->json(['data' => $this->detail($id)]);
    }

    // ── Votación ──

    public function ballot(string $id): JsonResponse
    {
        $e = $this->election($id);
        $worker = $this->myWorker();
        $roll = $worker ? DB::selectOne('select excluded, has_voted from election_roll where election_id = ? and worker_id = ?', [$id, $worker]) : null;
        $open = $this->votingWindowOpen($e);

        return response()->json(['data' => [
            'election' => ['id' => $e->id, 'title' => $e->title, 'status' => $e->status, 'voting_closes_at' => $e->voting_closes_at, 'allow_blank' => (bool) $e->allow_blank,
                'seats_titular' => (int) $e->seats_titular],
            'open' => $open,
            'linked' => (bool) $worker,
            'on_roll' => $roll && ! $roll->excluded,
            'has_voted' => (bool) ($roll->has_voted ?? false),
            'candidates' => $open ? DB::select(
                "select c.id, w.full_name as name, p.name as position, c.list_name, w.photo_file_id
                   from election_candidates c join workers w on w.id = c.worker_id left join positions p on p.id = w.current_position_id
                  where c.election_id = ? and c.status = 'accepted' and c.deleted_at is null order by w.full_name", [$id]) : [],
        ]]);
    }

    public function vote(Request $request, string $id): JsonResponse
    {
        $e = $this->election($id);
        if (! $this->votingWindowOpen($e)) {
            throw new ApiProblem(409, 'conflict', 'Votación cerrada', 'La votación no está abierta en este momento.');
        }
        $worker = $this->myWorker();
        if (! $worker) {
            throw ApiProblem::forbidden('Tu usuario no está vinculado a un trabajador de la empresa.');
        }
        $blank = $request->boolean('blank');
        $candidate = $blank ? null : (string) $request->input('candidate_id');
        if (! $blank) {
            if (! Str::isUuid($candidate) || ! DB::selectOne("select 1 as ok from election_candidates where id = ? and election_id = ? and status = 'accepted' and deleted_at is null", [$candidate, $id])) {
                $this->fail('candidate_id', 'Elige a un candidato de la cédula.');
            }
        } elseif (! $e->allow_blank) {
            $this->fail('blank', 'Esta elección no admite voto en blanco.');
        }
        $receipt = strtoupper(Str::random(4).'-'.Str::random(4).'-'.Str::random(4));
        DB::transaction(function () use ($id, $worker, $candidate, $blank, $receipt) {
            $roll = DB::selectOne('select id, excluded, has_voted from election_roll where election_id = ? and worker_id = ? for update', [$id, $worker]);
            if (! $roll || $roll->excluded) {
                throw ApiProblem::forbidden('No estás en el padrón electoral de esta elección.');
            }
            if ($roll->has_voted) {
                throw new ApiProblem(409, 'already_voted', 'Ya votaste', 'Ya emitiste tu voto en esta elección: el voto es único y no se puede cambiar.');
            }
            DB::update("update election_roll set has_voted = true, voted_on = (now() at time zone 'America/Lima')::date, updated_at = now() where id = ?", [$roll->id]);
            // Sin worker_id ni usuario: el identificador del voto es aleatorio y el comprobante solo se guarda como hash.
            DB::insert(
                "insert into election_ballots (company_id, election_id, voter_token_hash, candidate_id, is_blank, is_null_vote, channel, ballot_hash)
                 values (?, ?, decode(?, 'hex'), ?, ?, false, 'app', decode(?, 'hex'))",
                [$this->company(), $id, bin2hex(random_bytes(32)), $candidate, $blank, hash('sha256', $id.'|'.$receipt)]
            );
            DB::update('update elections set votes_cast = votes_cast + 1 where id = ?', [$id]);
        });

        return response()->json(['data' => ['voted' => true, 'receipt' => $receipt]], 201);
    }

    public function minutes(string $id): JsonResponse
    {
        $e = $this->election($id);
        if (! in_array($e->status, ['voting_closed', 'results_published'], true)) {
            throw new ApiProblem(409, 'conflict', 'Sin escrutinio', 'El acta de elección está disponible después del escrutinio.');
        }
        $co = DB::selectOne('select legal_name, trade_name, ruc from companies where id = ?', [$this->company()]);

        return response()->json(['data' => [
            'company' => ['name' => $co->trade_name ?: $co->legal_name, 'legal_name' => $co->legal_name, 'ruc' => trim((string) $co->ruc)],
            'election' => $this->detail($id),
        ]]);
    }

    // ── Internos ──

    private function nominate(object $e, string $worker, ?string $list): JsonResponse
    {
        $this->expect($e, ['nominations_open'], 'La inscripción de candidatos no está abierta.');
        $w = DB::selectOne("select id, status from workers where id = ? and deleted_at is null", [$worker]);
        if (! $w || $w->status === 'terminated') {
            $this->fail('worker_id', 'El trabajador no existe o ya no labora en la empresa.');
        }
        $roll = DB::selectOne('select excluded from election_roll where election_id = ? and worker_id = ?', [$e->id, $worker]);
        if ($roll?->excluded) {
            $this->fail('worker_id', 'El trabajador está excluido del padrón (personal de dirección o de confianza): no puede postular.');
        }
        $prev = DB::selectOne('select id, status from election_candidates where election_id = ? and worker_id = ? and deleted_at is null', [$e->id, $worker]);
        if ($prev && $prev->status !== 'rejected') {
            throw new ApiProblem(409, 'conflict', 'Ya inscrito', 'El trabajador ya está inscrito como candidato.');
        }
        $uid = (string) auth()->id();
        if ($prev) {
            // Subsana la observación dentro del periodo de inscripción (RN-COM-05): vuelve a revisión.
            DB::update("update election_candidates set status = 'nominated', rejection_reason = null, list_name = coalesce(?, list_name), nominated_at = now(), updated_by = ? where id = ?",
                [$list, $uid, $prev->id]);
            $cid = $prev->id;
        } else {
            $cid = DB::selectOne(
                "insert into election_candidates (company_id, election_id, worker_id, list_name, status, created_by, updated_by) values (?, ?, ?, ?, 'nominated', ?, ?) returning id",
                [$this->company(), $e->id, $worker, $list, $uid, $uid]
            )->id;
        }
        $this->accounts->audit($uid, 'election_candidate_nominated', 'election_candidates', $cid, ['worker_id' => $worker]);

        return response()->json(['data' => $this->detail($e->id)], 201);
    }

    /** Padrón: trabajadores activos (o con licencia) al corte; conserva exclusiones y votos ya marcados. */
    private function syncRoll(string $id): void
    {
        $uid = (string) auth()->id();
        DB::insert(
            "insert into election_roll (company_id, election_id, worker_id, created_by, updated_by)
             select w.company_id, ?, w.id, ?, ? from workers w
              where w.deleted_at is null and w.status in ('active', 'on_leave')
                and not exists (select 1 from election_roll r where r.election_id = ? and r.worker_id = w.id)",
            [$id, $uid, $uid, $id]
        );
        DB::delete(
            "delete from election_roll r using workers w where r.election_id = ? and w.id = r.worker_id and not r.has_voted
                and (w.deleted_at is not null or w.status not in ('active', 'on_leave'))", [$id]);
    }

    /** Escrutinio: conteo por candidato, blancos y nulos; titulares y suplentes con la regla de desempate. */
    private function count(object $e): void
    {
        $tally = DB::select(
            "select c.id, c.worker_id, w.full_name as name, w.hire_on, count(b.id) as votes
               from election_candidates c join workers w on w.id = c.worker_id
               left join election_ballots b on b.candidate_id = c.id and b.election_id = c.election_id
              where c.election_id = ? and c.status = 'accepted' and c.deleted_at is null
              group by c.id, c.worker_id, w.full_name, w.hire_on", [$e->id]);
        $t = DB::selectOne('select count(*) as total, count(*) filter (where is_blank) as blank, count(*) filter (where is_null_vote) as nulls from election_ballots where election_id = ?', [$e->id]);
        $rule = $e->tie_break_rule ?? 'seniority';
        $lottery = [];
        foreach ($tally as $r) {
            $r->votes = (int) $r->votes;
            $lottery[$r->id] = random_int(1, PHP_INT_MAX);
        }
        // Mayor votación primero; en empate, mayor antigüedad (fecha de ingreso más antigua) o el sorteo registrado.
        usort($tally, function ($a, $b) use ($rule, $lottery) {
            if ($a->votes !== $b->votes) {
                return $b->votes <=> $a->votes;
            }
            if ($rule === 'seniority') {
                $ha = $a->hire_on ?? '9999-12-31';
                $hb = $b->hire_on ?? '9999-12-31';
                if ($ha !== $hb) {
                    return strcmp($ha, $hb);
                }
            }

            return $lottery[$a->id] <=> $lottery[$b->id];
        });
        $tit = (int) $e->seats_titular;
        $alt = (int) $e->seats_alternate;
        $ranking = [];
        $ties = [];
        foreach ($tally as $i => $r) {
            $result = $r->votes === 0 ? 'not_elected' : ($i < $tit ? 'elected_titular' : ($i < $tit + $alt ? 'elected_alternate' : 'not_elected'));
            $ranking[] = ['candidate_id' => $r->id, 'worker_id' => $r->worker_id, 'name' => $r->name, 'votes' => $r->votes, 'result' => $result, 'position' => $i + 1];
            DB::update('update election_candidates set votes_count = ?, status = ? where id = ?', [$r->votes, $result, $r->id]);
        }
        // Empates que deciden un puesto: en el límite de titulares o de suplentes.
        foreach ([$tit, $tit + $alt] as $cut) {
            if ($cut <= 0 || $cut >= count($ranking)) {
                continue;
            }
            $v = $ranking[$cut - 1]['votes'];
            if ($v > 0 && $ranking[$cut]['votes'] === $v) {
                $names = array_column(array_filter($ranking, fn ($x) => $x['votes'] === $v), 'name');
                $ties[] = ['votes' => $v, 'candidates' => array_values($names), 'winner' => $ranking[$cut - 1]['name'],
                    'seat' => $cut === $tit ? 'titular' : 'suplente',
                    'note' => 'Empate a '.$v.' votos entre '.implode(', ', $names).'; por '.($rule === 'seniority' ? 'mayor antigüedad' : 'sorteo registrado').' resulta '
                        .($cut === $tit ? 'titular' : 'suplente').' '.$ranking[$cut - 1]['name'].'.'];
            }
        }
        $total = (int) $t->total;
        $eligible = (int) ($e->eligible_voters ?? 0);
        $results = [
            'total' => $total, 'blank' => (int) $t->blank, 'null' => (int) $t->nulls, 'valid' => $total - (int) $t->blank - (int) $t->nulls,
            'eligible' => $eligible, 'participation_pct' => $eligible > 0 ? round($total * 100 / $eligible, 1) : null,
            'tie_break_rule' => $rule, 'ties' => $ties, 'ranking' => $ranking, 'counted_at' => now()->toIso8601String(),
        ];
        DB::update('update elections set results = ?::jsonb, votes_cast = ? where id = ?', [json_encode($results, JSON_UNESCAPED_UNICODE), $total, $e->id]);
    }

    /** Comité que recibe a los elegidos: el que se está formando o el vigente; si no hay, se crea uno «en formación». */
    private function committeeFor(object $e): string
    {
        $c = DB::selectOne("select id from committees where deleted_at is null and status in ('forming','active') order by (status = 'forming') desc, period_start_on desc limit 1");
        if ($c) {
            return $c->id;
        }
        $term = (int) LegalParams::number('committee_term_years', 2);
        $model = DB::selectOne('select sst_model from companies where id = ?', [$this->company()])->sst_model ?? 'committee';
        $y = (int) date('Y');

        return DB::selectOne(
            "insert into committees (company_id, committee_type, name, period_start_on, period_end_on, status, created_by, updated_by)
             values (?, ?, ?, current_date, (current_date + make_interval(years => ?))::date, 'forming', ?, ?) returning id",
            [$this->company(), $model === 'supervisor' ? 'supervisor' : 'committee', ($model === 'supervisor' ? 'Supervisor SST ' : 'Comité SST ').$y.'–'.($y + $term),
                $term, (string) auth()->id(), (string) auth()->id()]
        )->id;
    }

    /** @return list<array{label: string, ok: bool, blocking: bool, detail: string}> requisitos del candidato (RN-COM-05) */
    private function requirements(string $worker): array
    {
        $w = DB::selectOne("select birth_on, status, hire_on, date_part('year', age(current_date, birth_on)) as age from workers where id = ?", [$worker]);
        $min = (int) LegalParams::number('committee_candidate_min_age', 18);
        $training = (bool) DB::selectOne("select 1 as ok from training_certificates where worker_id = ? and status = 'validated' and deleted_at is null limit 1", [$worker]);

        return [
            ['label' => "Mayor de {$min} años", 'ok' => $w?->birth_on ? (int) $w->age >= $min : false, 'blocking' => true,
                'detail' => $w?->birth_on ? ((int) $w->age).' años' : 'Falta la fecha de nacimiento en su legajo'],
            ['label' => 'Trabajador en planilla', 'ok' => in_array($w?->status, ['active', 'on_leave'], true), 'blocking' => true,
                'detail' => $w?->hire_on ? 'Desde '.date('d/m/Y', strtotime($w->hire_on)) : 'Activo'],
            ['label' => 'Capacitación en SST', 'ok' => $training, 'blocking' => false,
                'detail' => $training ? 'Con certificado validado' : 'Sin certificado: se compromete a recibirla'],
        ];
    }

    private function detail(string $id): array
    {
        $e = DB::selectOne('select * from elections where id = ?', [$id]);
        $closed = in_array($e->status, ['voting_closed', 'results_published'], true);
        $candidates = DB::select(
            "select c.id, c.worker_id, w.full_name as name, p.name as position, c.list_name, c.status, c.rejection_reason, c.votes_count, w.photo_file_id
               from election_candidates c join workers w on w.id = c.worker_id left join positions p on p.id = w.current_position_id
              where c.election_id = ? and c.deleted_at is null order by c.votes_count desc nulls last, w.full_name", [$id]);
        $manage = $this->can('com.elections.update');
        foreach ($candidates as $c) {
            if (! $closed) {
                $c->votes_count = null;   // resultados solo después del cierre (RN-COM-04)
            }
            $c->requirements = $manage ? $this->requirements($c->worker_id) : null;
        }
        $roll = DB::selectOne('select count(*) as total, count(*) filter (where excluded) as excluded from election_roll where election_id = ?', [$id]);
        $eligible = $e->eligible_voters !== null ? (int) $e->eligible_voters : (int) $roll->total - (int) $roll->excluded;
        $worker = $this->myWorker();
        $mine = $worker ? DB::selectOne('select excluded, has_voted from election_roll where election_id = ? and worker_id = ?', [$id, $worker]) : null;
        $myCandidacy = $worker ? DB::selectOne('select status, rejection_reason from election_candidates where election_id = ? and worker_id = ? and deleted_at is null', [$id, $worker]) : null;
        $results = $closed && $e->results ? json_decode($e->results, true) : null;

        return [
            'id' => $e->id,
            'title' => $e->title,
            'period_label' => $e->period_label,
            'status' => $e->status,
            'step' => self::STEP[$e->status] ?? -1,
            'seats_titular' => (int) $e->seats_titular,
            'seats_alternate' => (int) $e->seats_alternate,
            'tie_break_rule' => $e->tie_break_rule ?? 'seniority',
            'allow_blank' => (bool) $e->allow_blank,
            'electoral_board' => $e->electoral_board ? json_decode($e->electoral_board, true) : [],
            'convened_at' => $e->convened_at,
            'nominations_open_at' => $e->nominations_open_at,
            'nominations_close_at' => $e->nominations_close_at,
            'voting_opens_at' => $e->voting_opens_at,
            'voting_closes_at' => $e->voting_closes_at,
            'roll_snapshot_at' => $e->roll_snapshot_at ?? null,
            'results_published_at' => $e->results_published_at,
            'annulled_reason' => $e->annulled_reason,
            'committee_id' => $e->committee_id,
            'roll' => ['total' => (int) $roll->total, 'excluded' => (int) $roll->excluded],
            'eligible_voters' => $eligible,
            'votes_cast' => (int) $e->votes_cast,
            'participation_pct' => $eligible > 0 ? round(((int) $e->votes_cast) * 100 / $eligible, 1) : null,
            'candidates' => $candidates,
            'results' => $results,
            'can' => [
                'manage' => $manage,
                'close' => $this->can('com.elections.close') || $manage,
                'vote' => $this->can('com.election_ballots.vote'),
                'apply' => $this->can('com.election_candidates.create'),
                'see_roll' => $manage || app(CompanyContext::class)->roleCode === 'hr',
            ],
            'me' => [
                'linked' => (bool) $worker,
                'on_roll' => $mine && ! $mine->excluded,
                'has_voted' => (bool) ($mine->has_voted ?? false),
                'candidacy' => $myCandidacy,
            ],
        ];
    }

    private function votingWindowOpen(object $e): bool
    {
        if ($e->status !== 'voting_open') {
            return false;
        }
        $now = time();

        return (! $e->voting_opens_at || strtotime($e->voting_opens_at) <= $now) && (! $e->voting_closes_at || strtotime($e->voting_closes_at) > $now);
    }

    /** Avisa a los trabajadores del padrón que tienen usuario (si $pending, solo a quienes aún no votaron). */
    private function notifyRoll(string $id, string $event, string $title, string $body, bool $pending = false): void
    {
        $rows = DB::select(
            "select distinct r.worker_id, coalesce(w.user_id, m.user_id) as user_id
               from election_roll r join workers w on w.id = r.worker_id
               left join memberships m on m.worker_id = w.id and m.company_id = w.company_id and m.status = 'active' and m.deleted_at is null
              where r.election_id = ? and not r.excluded".($pending ? ' and not r.has_voted' : ''), [$id]);
        foreach ($rows as $r) {
            if (! $r->user_id) {
                continue;
            }
            $dedupe = $event.':'.$id;
            if (DB::selectOne('select 1 as ok from notifications where recipient_user_id = ? and dedupe_key = ? and deleted_at is null', [$r->user_id, $dedupe])) {
                continue;
            }
            DB::insert(
                "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, recipient_worker_id, title, body, link, priority, dedupe_key)
                 values (?, ?, 'elections', ?, ?, ?, ?, ?, '/m/com', 'high', ?)",
                [$this->company(), $event, $id, $r->user_id, $r->worker_id, $title, mb_substr($body, 0, 500), $dedupe]
            );
        }
    }

    private function myWorker(): ?string
    {
        $ctx = app(CompanyContext::class);
        $w = DB::selectOne('select worker_id from memberships where id = ?', [$ctx->membershipId])?->worker_id;

        return $w ?: DB::selectOne("select id from workers where user_id = ? and deleted_at is null and status <> 'terminated' limit 1", [(string) auth()->id()])?->id;
    }

    private function canSeeRoll(): void
    {
        if (! $this->can('com.elections.update') && app(CompanyContext::class)->roleCode !== 'hr') {
            throw ApiProblem::forbidden('El padrón lo ven la junta electoral y Recursos Humanos.');
        }
    }

    private function election(string $id): object
    {
        $e = Str::isUuid($id) ? DB::selectOne('select * from elections where id = ? and deleted_at is null', [$id]) : null;
        if (! $e) {
            throw ApiProblem::notFound('La elección no existe o es de otra empresa.');
        }

        return $e;
    }

    /** @param list<string> $states */
    private function expect(object $e, array $states, string $msg): void
    {
        if (! in_array($e->status, $states, true)) {
            throw new ApiProblem(409, 'conflict', 'Paso no disponible', $msg);
        }
    }

    private function can(string $p): bool
    {
        return in_array($p, Permissions::effective(), true);
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

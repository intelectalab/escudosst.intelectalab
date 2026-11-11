<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consultas al equipo de EscudoSST.
 *  Empresa (con sesión y empresa activa):
 *   GET  /me/support-tickets            mis consultas en la empresa activa, con la respuesta
 *   POST /me/support-tickets            escribir al equipo {topic, subject, message, page?}
 *  Panel de la plataforma (equipo):
 *   GET  /internal/support-tickets      bandeja (filtro ?status=open|answered|closed)
 *   POST /internal/support-tickets/{id}/reply   responder {reply, close?}
 *   POST /internal/support-tickets/{id}/close   cerrar sin responder
 * Se usa la conexión de plataforma: la consulta no es un dato de negocio de la empresa.
 */
class SupportTicketsController extends Controller
{
    private const MESSAGES = [
        'subject.required' => 'Escribe el asunto de tu consulta.',
        'subject.min' => 'El asunto debe tener al menos 3 caracteres.',
        'message.required' => 'Cuéntanos qué necesitas.',
        'message.min' => 'Cuéntanos un poco más (al menos 5 caracteres).',
        'reply.required' => 'Escribe la respuesta.',
    ];

    public function mine(): JsonResponse
    {
        $rows = $this->db()->select("select id, code, topic, subject, message, status, reply, replied_at, created_at
            from support_tickets where company_id = ? and user_id = ? order by created_at desc limit 50", [$this->company(), (string) auth()->id()]);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'topic' => ['nullable', 'in:question,problem,suggestion,billing'],
            'subject' => ['required', 'string', 'min:3', 'max:120'],
            'message' => ['required', 'string', 'min:5', 'max:4000'],
            'page' => ['nullable', 'string', 'max:200'],
        ], self::MESSAGES);
        $n = (int) $this->db()->selectOne('select count(*) as n from support_tickets')->n + 1;
        $code = 'CON-'.now('America/Lima')->format('Y').'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $row = $this->db()->selectOne('insert into support_tickets (code, company_id, user_id, topic, subject, message, page) values (?, ?, ?, ?, ?, ?, ?) returning id, code, status, created_at',
            [$code, $this->company(), (string) auth()->id(), $d['topic'] ?? 'question', trim($d['subject']), trim($d['message']), $d['page'] ?? null]);

        return response()->json(['data' => $row], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $status = in_array($request->query('status'), ['open', 'answered', 'closed'], true) ? $request->query('status') : null;
        $rows = $this->db()->select('select t.id, t.code, t.topic, t.subject, t.message, t.page, t.status, t.reply, t.replied_at, t.created_at,
                c.trade_name as company, u.full_name as user_name, u.email as user_email, r.full_name as replied_by_name
            from support_tickets t join companies c on c.id = t.company_id join users u on u.id = t.user_id left join users r on r.id = t.replied_by
            where (?::text is null or t.status = ?::text) order by (t.status = \'open\') desc, t.created_at desc limit 200', [$status, $status]);
        $counts = $this->db()->select('select status, count(*) as n from support_tickets group by status');

        return response()->json(['data' => $rows, 'counts' => collect($counts)->pluck('n', 'status')]);
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['reply' => ['required', 'string', 'min:2', 'max:4000'], 'close' => ['boolean']], self::MESSAGES);
        $t = $this->find($id);
        if ($t->status === 'closed') {
            throw ApiProblem::badRequest('La consulta ya está cerrada.');
        }
        $this->db()->update('update support_tickets set reply = ?, replied_by = ?, replied_at = now(), status = ?, updated_at = now() where id = ?',
            [trim($d['reply']), (string) auth()->id(), ! empty($d['close']) ? 'closed' : 'answered', $id]);

        return response()->json(['data' => $this->find($id)]);
    }

    public function close(string $id): JsonResponse
    {
        $this->find($id);
        $this->db()->update("update support_tickets set status = 'closed', updated_at = now() where id = ?", [$id]);

        return response()->json(['data' => $this->find($id)]);
    }

    private function find(string $id): object
    {
        return $this->db()->selectOne('select * from support_tickets where id = ?', [$id]) ?? throw ApiProblem::notFound('La consulta no existe.');
    }

    private function company(): string
    {
        return (string) app(CompanyContext::class)->companyId;
    }

    private function db()
    {
        return DB::connection('platform');
    }
}

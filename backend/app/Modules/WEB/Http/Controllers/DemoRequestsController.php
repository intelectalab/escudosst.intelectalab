<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Panel del dueño · Sitio web › Solicitudes (demo, contacto y cotización que llegan del sitio).
 *  GET   /internal/site/requests        lista (las más nuevas primero) y conteo por estado
 *  PATCH /internal/site/requests/{id}   cambia estado y nota interna
 */
class DemoRequestsController extends Controller
{
    public function __construct(private readonly AccountsService $accounts) {}

    public function index(): JsonResponse
    {
        $rows = DB::connection('platform')->select(
            "select id, request_type, full_name, company_name, trim(ruc) as ruc, job_title, email, phone, headcount, economic_sector,
                    interest_plan_code, message, marketing_consent, source_page, status, contacted_at, demo_scheduled_at,
                    discard_reason, notes, created_at
               from demo_requests where deleted_at is null
              order by (status = 'new') desc, created_at desc
              limit 300"
        );

        return response()->json(['data' => $rows]);
    }

    public function update(Request $request, string $id): Response
    {
        $data = $request->validate([
            'status' => ['required', 'in:new,contacted,demo_scheduled,converted,discarded'],
            'demo_scheduled_at' => ['nullable', 'required_if:status,demo_scheduled', 'date'],
            'discard_reason' => ['nullable', 'required_if:status,discarded', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'demo_scheduled_at.required_if' => 'Indica la fecha y hora de la demo.',
            'discard_reason.required_if' => 'Escribe por qué se descarta.',
        ]);

        $db = DB::connection('platform');
        $before = $db->selectOne('select status from demo_requests where id = ? and deleted_at is null', [$id]);
        if (! $before) {
            throw ApiProblem::notFound('La solicitud no existe.');
        }
        $actor = $request->user()->id;
        $db->transaction(function () use ($db, $data, $id, $actor, $before) {
            $db->update(
                "update demo_requests set status = ?, demo_scheduled_at = case when ? = 'demo_scheduled' then ?::timestamptz else demo_scheduled_at end,
                        discard_reason = case when ? = 'discarded' then ? else null end, notes = ?,
                        contacted_at = case when ? <> 'new' then coalesce(contacted_at, now()) else contacted_at end, updated_by = ?
                  where id = ?",
                [
                    $data['status'], $data['status'], $data['demo_scheduled_at'] ?? null,
                    $data['status'], $data['discard_reason'] ?? null, $data['notes'] ?? null,
                    $data['status'], $actor, $id,
                ]
            );
            if ($before->status !== $data['status']) {
                $this->accounts->audit($actor, 'demo_request_status_changed', 'demo_requests', $id, ['status' => [$before->status, $data['status']]], $data['discard_reason'] ?? null);
            }
        });

        return response()->noContent();
    }
}

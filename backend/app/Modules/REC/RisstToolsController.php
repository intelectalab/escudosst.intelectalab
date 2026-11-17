<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Support\Xlsx;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\CTR\ContractorStatus;
use App\Modules\NOT\AckCampaigns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reglamento Interno de SST (M-RIS):
 *  GET  /ris/overview                    SCR-RIS-01/02/03: versión vigente con su acta, % de entregas, versiones en trámite con las
 *                                        secciones del art. 74 marcadas (parámetro ris_risst_sections), tabla de trabajadores con su
 *                                        estado (entregado / pendiente en plazo / pendiente fuera de plazo) y entregas a contratistas
 *  POST /ris/deliver-all                 entrega electrónica a todos los trabajadores activos que aún no la tienen (RN-RIS-04) y aviso
 *  GET  /ris/deliveries.xlsx             §11: relación de cargos de entrega por trabajador y versión
 *  GET  /ctr/portal/risst                RN-RIS-08: el contratista ve el RISST vigente y sus trabajadores con su cargo
 *  GET  /ctr/portal/risst/file           descarga el documento del RISST vigente
 *  POST /ctr/portal/risst/deliveries     registra el cargo físico firmado de un trabajador del contratista {contractor_worker_id, acknowledgement_file_id}
 */
class RisstToolsController extends Controller
{
    private const METHOD = ['app_signature' => 'Firma en la app', 'physical_signed' => 'Cargo físico firmado', 'email' => 'Correo', 'in_person' => 'En persona'];

    public function overview(): JsonResponse
    {
        $this->can('ris.risst_versions.read', 'Tu rol no ve el RISST.');
        $rows = DB::select(
            "select v.id, v.version_number, v.title, v.status, v.effective_on, v.approved_at, v.content_outline, v.committee_meeting_id, v.file_id, u.full_name as approved_by,
                    m.held_at as meeting_on
               from risst_versions v left join users u on u.id = v.approved_by left join committee_meetings m on m.id = v.committee_meeting_id
              where v.deleted_at is null order by v.version_number desc");
        foreach ($rows as $r) {
            $o = $r->content_outline;
            for ($i = 0; $i < 2 && is_string($o); $i++) {
                $o = json_decode($o, true);
            }
            $r->content_outline = is_array($o) ? $o : [];
        }
        $cur = collect($rows)->first(fn ($r) => $r->status === 'current');
        $grace = (int) LegalParams::number('risst_delivery_grace_days', 30);
        $workers = [];
        $stats = null;
        $contractors = [];
        if ($cur) {
            $workers = $this->workers($cur->id, $grace);
            $done = count(array_filter($workers, fn ($w) => $w->state === 'delivered'));
            $stats = ['active' => count($workers), 'delivered' => $done, 'late' => count(array_filter($workers, fn ($w) => $w->state === 'late')),
                'pct' => $workers ? (int) round($done * 100 / count($workers)) : null];
            $contractors = DB::select(
                "select c.legal_name, count(cw.id) as workers, count(d.id) filter (where d.status in ('delivered', 'signed')) as delivered
                   from contractors c join contractor_workers cw on cw.contractor_id = c.id and cw.deleted_at is null and cw.status = 'active'
                   left join risst_deliveries d on d.contractor_worker_id = cw.id and d.risst_version_id = ? and d.deleted_at is null
                  where c.deleted_at is null group by c.id, c.legal_name order by c.legal_name", [$cur->id]);
        }

        return response()->json(['data' => [
            'current' => $cur,
            'stats' => $stats,
            'workers' => $workers,
            'contractors' => $contractors,
            'grace_days' => $grace,
            'drafts' => array_values(array_filter($rows, fn ($r) => in_array($r->status, ['draft', 'in_review', 'approved'], true))),
            'sections' => LegalParams::list('ris_risst_sections'),
            'can_deliver' => in_array('ris.risst_deliveries.create', Permissions::effective(), true),
        ]]);
    }

    public function deliverAll(): JsonResponse
    {
        $this->can('ris.risst_deliveries.create', 'Tu rol no registra entregas del RISST.');
        $cur = DB::selectOne("select id from risst_versions where status = 'current' and deleted_at is null limit 1")
            ?? throw new ApiProblem(409, 'conflict', 'Sin RISST vigente', 'Publica primero una versión vigente del RISST.');
        $n = AckCampaigns::risstPublished($cur->id);

        return response()->json(['data' => ['created' => $n]]);
    }

    public function deliveriesXlsx(): Response
    {
        $this->can('ris.risst_deliveries.read', 'Tu rol no ve las entregas del RISST.');
        $c = DB::selectOne('select legal_name, ruc from companies where id = ?', [app(CompanyContext::class)->companyId]);
        $rows = DB::select(
            "select v.version_number, v.title, coalesce(w.full_name, trim(concat_ws(' ', cw.first_name, cw.paternal_last_name, cw.maternal_last_name))) as name,
                    '••••'||coalesce(w.document_last4, cw.document_last4) as doc, ct.legal_name as contractor, d.status, d.method, d.delivered_on, d.void_reason, d.acknowledgement_file_id, d.signature_id
               from risst_deliveries d join risst_versions v on v.id = d.risst_version_id
               left join workers w on w.id = d.worker_id left join contractor_workers cw on cw.id = d.contractor_worker_id left join contractors ct on ct.id = cw.contractor_id
              where d.deleted_at is null order by v.version_number desc, name");
        $st = ['pending' => 'Pendiente', 'delivered' => 'Entregado', 'signed' => 'Firmado', 'voided' => 'Anulado'];
        $out = [['RELACIÓN DE CARGOS DE ENTREGA DEL RISST'], ["{$c->legal_name} · RUC ".trim((string) $c->ruc)], ['Generado: '.now('America/Lima')->format('d/m/Y H:i').' · '.count($rows).' fila(s)'], [],
            ['Versión', 'Título', 'Trabajador', 'Documento', 'Contratista', 'Estado', 'Medio', 'Fecha de entrega', 'Evidencia', 'Motivo de anulación']];
        foreach ($rows as $r) {
            $out[] = [$r->version_number, $r->title, $r->name, $r->doc, $r->contractor ?? '', $st[$r->status] ?? $r->status, self::METHOD[$r->method] ?? ($r->method ?? ''),
                $r->delivered_on ? date('d/m/Y', strtotime($r->delivered_on)) : '', $r->signature_id ? 'Firma electrónica' : ($r->acknowledgement_file_id ? 'Cargo escaneado' : ''), $r->void_reason ?? ''];
        }

        return response(Xlsx::build('Cargos RISST', $out, 5), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="cargos-risst-'.date('Y-m-d').'.xlsx"',
        ]);
    }

    public function portal(): JsonResponse
    {
        $c = ContractorStatus::requirePortalContractor();
        $cur = DB::selectOne("select id, version_number, title, effective_on, file_id from risst_versions where status = 'current' and deleted_at is null limit 1");
        $workers = $cur ? DB::select(
            "select cw.id, trim(concat_ws(' ', cw.first_name, cw.paternal_last_name, cw.maternal_last_name)) as name, '••••'||cw.document_last4 as document_number, d.status, d.delivered_on
               from contractor_workers cw left join risst_deliveries d on d.contractor_worker_id = cw.id and d.risst_version_id = ? and d.deleted_at is null and d.status <> 'voided'
              where cw.contractor_id = ? and cw.deleted_at is null and cw.status = 'active' order by name", [$cur->id, $c->id]) : [];

        return response()->json(['data' => ['current' => $cur ? ['id' => $cur->id, 'version_number' => $cur->version_number, 'title' => $cur->title, 'effective_on' => $cur->effective_on, 'has_file' => (bool) $cur->file_id] : null,
            'workers' => $workers, 'can_register' => in_array('ris.risst_deliveries.create', Permissions::effective(), true)]]);
    }

    public function portalFile(): Response
    {
        ContractorStatus::requirePortalContractor();
        $cur = DB::selectOne("select v.title, v.version_number, f.bucket, f.object_key, f.original_name, f.mime_type from risst_versions v join files f on f.id = v.file_id and f.deleted_at is null
                               where v.status = 'current' and v.deleted_at is null limit 1");
        if (! $cur || ! Storage::disk($cur->bucket)->exists($cur->object_key)) {
            throw ApiProblem::notFound('El RISST vigente no tiene documento adjunto.');
        }

        return response(Storage::disk($cur->bucket)->get($cur->object_key), 200, [
            'Content-Type' => $cur->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="RISST-v'.$cur->version_number.'-'.Str::slug($cur->title).'.'.(pathinfo((string) $cur->original_name, PATHINFO_EXTENSION) ?: 'pdf').'"',
        ]);
    }

    public function portalDeliver(Request $request, RecordsService $records): JsonResponse
    {
        $c = ContractorStatus::requirePortalContractor();
        $this->can('ris.risst_deliveries.create', 'Tu rol no registra cargos del RISST.');
        $d = $request->validate(['contractor_worker_id' => ['required', 'uuid'], 'acknowledgement_file_id' => ['required', 'uuid']],
            ['acknowledgement_file_id.required' => 'Adjunta el cargo firmado por el trabajador.']);
        if (! DB::selectOne('select 1 as ok from contractor_workers where id = ? and contractor_id = ? and deleted_at is null', [$d['contractor_worker_id'], $c->id])) {
            throw ApiProblem::notFound('Ese trabajador no es de tu empresa.');
        }
        $cur = DB::selectOne("select id from risst_versions where status = 'current' and deleted_at is null limit 1")
            ?? throw new ApiProblem(409, 'conflict', 'Sin RISST vigente', 'La empresa principal no tiene un RISST vigente.');
        if (DB::selectOne("select 1 as ok from risst_deliveries where risst_version_id = ? and contractor_worker_id = ? and deleted_at is null and status <> 'voided'", [$cur->id, $d['contractor_worker_id']])) {
            throw new ApiProblem(409, 'conflict', 'Ya registrado', 'Ese trabajador ya tiene su cargo del RISST vigente.');
        }
        $row = $records->create(Registry::resource('RIS', 'risst_deliveries'), ['risst_version_id' => $cur->id, 'contractor_worker_id' => $d['contractor_worker_id'],
            'method' => 'physical_signed', 'acknowledgement_file_id' => $d['acknowledgement_file_id']]);

        return response()->json(['data' => $row], 201);
    }

    /** @return list<object> trabajadores activos con su estado frente al RISST vigente */
    private function workers(string $version, int $grace): array
    {
        $rows = DB::select(
            "select w.id, w.full_name as name, '••••'||w.document_last4 as document_number, w.hire_on, s.name as site, d.id as delivery_id, d.status, d.method, d.delivered_on
               from workers w left join sites s on s.id = w.current_site_id
               left join lateral (select id, status, method, delivered_on from risst_deliveries x where x.risst_version_id = ? and x.worker_id = w.id and x.deleted_at is null and x.status <> 'voided'
                                   order by (x.status in ('delivered', 'signed')) desc, x.created_at desc limit 1) d on true
              where w.deleted_at is null and w.status = 'active' order by w.full_name", [$version]);
        $today = now('America/Lima')->format('Y-m-d');
        foreach ($rows as $r) {
            $r->state = in_array($r->status, ['delivered', 'signed'], true) ? 'delivered'
                : ($r->hire_on && date('Y-m-d', strtotime($r->hire_on." +{$grace} days")) >= $today ? 'in_grace' : 'late');
        }

        return $rows;
    }

    private function can(string $perm, string $msg): void
    {
        if (! in_array($perm, Permissions::effective(), true)) {
            throw ApiProblem::forbidden($msg);
        }
    }
}

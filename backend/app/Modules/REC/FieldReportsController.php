<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Datos de los informes imprimibles (PDF desde el navegador) que piden las fichas en su §11:
 *  GET /reports/inspections/{id}   M-INS: informe de la inspección con respuestas, fotos, puntaje, hallazgos y firmas
 *  GET /reports/work_permits/{id}  M-PTR: PETAR con verificaciones, mediciones de gases, aprobaciones, cuadrilla y cierre
 *  GET /reports/ats_forms/{id}     M-ATS: ATS con pasos, peligros y controles, participantes y sus firmas
 * Las fotos van incrustadas (solo imágenes de la empresa, nunca archivos médicos) para que el PDF quede completo.
 */
class FieldReportsController extends Controller
{
    public function inspection(string $id): JsonResponse
    {
        $this->can('ins.inspections.read');
        $i = $this->row('inspections', $id, 'La inspección no existe o es de otra empresa.');
        $answers = DB::select(
            'select a.question_snapshot, a.answer, a.numeric_value, a.is_compliant, a.is_critical, a.comment, a.photo_file_id, t.section, t.item_code
               from inspection_answers a left join inspection_template_items t on t.id = a.template_item_id
              where a.inspection_id = ? and a.deleted_at is null order by t.sort_order nulls last, a.created_at', [$id]);
        foreach ($answers as $a) {
            $a->photo = $this->image($a->photo_file_id);
            unset($a->photo_file_id);
        }
        $findings = DB::select(
            "select f.finding_type, f.description, f.severity, f.status, f.photo_file_id, c.code as action_code, c.due_on as action_due
               from findings f left join corrective_actions c on c.id = f.corrective_action_id
              where f.inspection_id = ? and f.deleted_at is null order by f.created_at", [$id]);
        foreach ($findings as $f) {
            $f->photo = $this->image($f->photo_file_id);
            unset($f->photo_file_id);
        }

        return response()->json(['data' => [
            'company' => $this->company(),
            'inspection' => [
                'code' => $i->code, 'title' => $i->title, 'inspection_type' => $i->inspection_type, 'status' => $i->status, 'scheduled_on' => $i->scheduled_on,
                'started_at' => $i->started_at, 'completed_at' => $i->completed_at, 'score_pct' => $i->score_pct === null ? null : (float) $i->score_pct,
                'summary' => $i->summary, 'participants' => json_decode((string) $i->participants, true), 'committee_participation' => (bool) $i->committee_participation,
                'template' => $i->template_id ? DB::selectOne('select name, version_number from inspection_templates where id = ?', [$i->template_id]) : null,
                'site' => $this->name('sites', $i->site_id), 'area' => $this->name('areas', $i->area_id),
                'inspector' => $this->person($i->inspector_user_id, $i->inspector_worker_id),
            ],
            'answers' => $answers,
            'findings' => $findings,
            'signatures' => $this->signatures('inspections', $id),
        ]]);
    }

    public function permit(string $id): JsonResponse
    {
        $this->can('ptr.work_permits.read');
        $p = $this->row('work_permits', $id, 'El permiso no existe o es de otra empresa.');
        $type = $p->permit_type_id ? DB::selectOne('select name, category, legal_reference from permit_types where id = ?', [$p->permit_type_id]) : null;
        $checks = DB::select(
            'select k.check_phase, k.question, k.answer, k.value, k.comment, k.photo_file_id, k.checked_at, u.full_name as checked_by
               from permit_checks k left join users u on u.id = k.checked_by where k.work_permit_id = ? and k.deleted_at is null order by k.check_phase, k.created_at', [$id]);
        foreach ($checks as $k) {
            $k->photo = $this->image($k->photo_file_id);
            unset($k->photo_file_id);
        }
        $approvals = DB::select(
            'select a.step_order, a.approver_role, a.decision, a.decided_at, a.comments, u.full_name as approver
               from permit_approvals a left join users u on u.id = a.approver_user_id where a.work_permit_id = ? and a.deleted_at is null order by a.step_order', [$id]);
        $crew = [];
        foreach ((array) (json_decode((string) $p->crew, true) ?: []) as $c) {
            $wid = is_array($c) ? ($c['worker_id'] ?? null) : $c;
            $crew[] = ['name' => is_array($c) ? ($c['name'] ?? $this->name('workers', $wid, 'full_name')) : $this->name('workers', $wid, 'full_name'), 'role' => is_array($c) ? ($c['role'] ?? null) : null];
        }

        return response()->json(['data' => [
            'company' => $this->company(),
            'permit' => [
                'code' => $p->code, 'type' => $type, 'status' => $p->status, 'work_description' => $p->work_description, 'location_text' => $p->location_text,
                'site' => $this->name('sites', $p->site_id), 'area' => $this->name('areas', $p->area_id), 'contractor' => $this->name('contractors', $p->contractor_id, 'legal_name'),
                'leader' => $p->executor_leader_worker_id ? $this->name('workers', $p->executor_leader_worker_id, 'full_name') : $this->name('contractor_workers', $p->executor_leader_contractor_worker_id, "concat_ws(' ', first_name, paternal_last_name)"),
                'requested_by' => $this->person($p->requested_by, null), 'valid_from' => $p->valid_from, 'valid_until' => $p->valid_until, 'activated_at' => $p->activated_at,
                'extension_count' => (int) $p->extension_count, 'suspended_reason' => $p->suspended_reason, 'closed_at' => $p->closed_at,
                'closed_by' => $this->person($p->closed_by, null), 'closure_notes' => $p->closure_notes, 'area_left_safe' => $p->area_left_safe,
                'ats' => $this->name('ats_forms', $p->ats_form_id, 'code'), 'procedure' => $this->name('procedures', $p->procedure_id, "concat_ws(' · ', code, title)"),
                'gas_measurements' => json_decode((string) $p->gas_measurements, true) ?: [], 'isolation_points' => json_decode((string) $p->isolation_points, true) ?: [],
            ],
            'crew' => $crew,
            'checks' => $checks,
            'approvals' => $approvals,
            'signatures' => $this->signatures('work_permits', $id),
        ]]);
    }

    public function ats(string $id): JsonResponse
    {
        $this->can('ats.ats_forms.read');
        $a = $this->row('ats_forms', $id, 'El ATS no existe o es de otra empresa.');
        $steps = DB::select('select step_number, description, hazards, risks, controls, responsible from ats_steps where ats_form_id = ? and deleted_at is null order by step_number', [$id]);
        $people = DB::select(
            "select coalesce(w.full_name, concat_ws(' ', cw.first_name, cw.paternal_last_name)) as name, p.role, p.signed_at, p.late_signature, p.signature_id
               from ats_participants p left join workers w on w.id = p.worker_id left join contractor_workers cw on cw.id = p.contractor_worker_id
              where p.ats_form_id = ? and p.deleted_at is null order by name", [$id]);
        foreach ($people as $p) {
            $p->image = $this->stroke($p->signature_id);
            unset($p->signature_id);
        }

        return response()->json(['data' => [
            'company' => $this->company(),
            'ats' => [
                'code' => $a->code, 'task' => $a->task, 'status' => $a->status, 'performed_on' => $a->performed_on, 'work_location' => $a->work_location,
                'site' => $this->name('sites', $a->site_id), 'area' => $this->name('areas', $a->area_id), 'contractor' => $this->name('contractors', $a->contractor_id, 'legal_name'),
                'supervisor' => $this->person($a->supervisor_user_id, null), 'leader' => $this->name('workers', $a->leader_worker_id, 'full_name'),
                'permit' => $this->name('work_permits', $a->work_permit_id, 'code'), 'procedure' => $this->name('procedures', $a->procedure_id, "concat_ws(' · ', code, title)"),
                'required_ppe' => json_decode((string) $a->required_ppe, true) ?: [], 'tools_equipment' => json_decode((string) $a->tools_equipment, true) ?? $a->tools_equipment,
                'started_at' => $a->started_at, 'closed_at' => $a->closed_at, 'is_high_risk' => (bool) $a->is_high_risk,
            ],
            'steps' => $steps,
            'participants' => $people,
            'signatures' => $this->signatures('ats_forms', $id),
        ]]);
    }

    protected function can(string $perm): void
    {
        if (! in_array($perm, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no ve este registro.');
        }
    }

    protected function row(string $table, string $id, string $msg): object
    {
        $r = Str::isUuid($id) ? DB::selectOne("select * from {$table} where id = ? and deleted_at is null", [$id]) : null;

        return $r ?? throw ApiProblem::notFound($msg);
    }

    protected function name(string $table, ?string $id, string $col = 'name'): ?string
    {
        return $id ? DB::selectOne("select {$col} as n from {$table} where id = ?", [$id])?->n : null;
    }

    protected function person(?string $user, ?string $worker): ?string
    {
        return $user ? $this->name('users', $user, 'full_name') : $this->name('workers', $worker, 'full_name');
    }

    protected function company(): array
    {
        $c = DB::selectOne('select legal_name, trade_name, ruc from companies where id = ?', [app(CompanyContext::class)->companyId]);

        return ['name' => $c->trade_name ?: $c->legal_name, 'legal_name' => $c->legal_name, 'ruc' => trim((string) $c->ruc)];
    }

    /** Firmas con evidencia (M-FIR) del registro, con la imagen del trazo si la hay. */
    protected function signatures(string $table, string $id): array
    {
        $rows = DB::select(
            "select s.signer_name, s.signed_at, s.method, k.mime_type, encode(k.image, 'base64') as image
               from signatures s left join signature_strokes k on k.signature_id = s.id
              where s.subject_type = ? and s.subject_id = ? order by s.signed_at", [$table, $id]);
        foreach ($rows as $s) {
            $s->image = $s->image ? 'data:'.$s->mime_type.';base64,'.str_replace(["\n", "\r"], '', $s->image) : null;
            unset($s->mime_type);
        }

        return $rows;
    }

    protected function stroke(?string $signatureId): ?string
    {
        $k = $signatureId ? DB::selectOne("select mime_type, encode(image, 'base64') as image from signature_strokes where signature_id = ?", [$signatureId]) : null;

        return $k ? 'data:'.$k->mime_type.';base64,'.str_replace(["\n", "\r"], '', $k->image) : null;
    }

    /** Foto incrustada (máx. 2 MB, solo imágenes no médicas de la empresa). */
    protected function image(?string $fileId): ?string
    {
        if (! $fileId) {
            return null;
        }
        try {
            $f = DB::selectOne("select bucket, object_key, mime_type, size_bytes, is_medical from files where id = ? and deleted_at is null and status = 'available'", [$fileId]);
            if (! $f || $f->is_medical || ! str_starts_with((string) $f->mime_type, 'image/') || (int) $f->size_bytes > 2_000_000) {
                return null;
            }

            return 'data:'.$f->mime_type.';base64,'.base64_encode((string) Storage::disk($f->bucket)->get($f->object_key));
        } catch (\Throwable) {
            return null;
        }
    }
}

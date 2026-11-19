<?php

namespace App\Modules\REC;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * M-PLA:
 * - RN-PLA-04 y flujo 5: al aprobarse, el PDF del plan (estructura referencial R.M. 050-2013-TR) se guarda como archivo del plan y
 *   como nueva versión vigente de su documento en la gestión documental (DOC).
 * - Criterio 4: el PDF incluye la política, los objetivos, los programas vinculados y la referencia al acta.
 * - RN-PLA-08: al cerrar el año se genera el informe de cumplimiento con el avance final del PASST y de los objetivos.
 * Si la generación falla, la aprobación o el cierre siguen válidos (se registra el error y se puede volver a generar).
 */
final class AnnualPlanDocs
{
    public const ITEM_TYPE = ['objective' => 'Objetivo', 'goal' => 'Meta', 'human_resource' => 'Recurso humano', 'material_resource' => 'Recurso material',
        'financial_resource' => 'Recurso financiero', 'program' => 'Programa', 'indicator' => 'Indicador'];

    public const OBJECTIVE_STATUS = ['draft' => 'Borrador', 'active' => 'En curso', 'achieved' => 'Logrado', 'not_achieved' => 'No logrado', 'cancelled' => 'Anulado'];

    public const PROGRAM_STATUS = ['draft' => 'Borrador', 'committee_review' => 'En revisión del comité', 'approved' => 'Aprobado', 'in_execution' => 'En ejecución', 'closed' => 'Cerrado'];

    /** Todo lo que muestra el plan: datos, diagnóstico, política, componentes por tipo, programas vinculados y aprobación. */
    public static function data(string $planId): array
    {
        $p = DB::selectOne('select * from annual_plans where id = ? and deleted_at is null', [$planId]);
        $company = DB::selectOne('select legal_name, trade_name, ruc, sst_model from companies where id = ?', [$p->company_id]);
        $items = DB::select(
            "select i.*, u.full_name as responsible, o.code as objective_code, o.title as objective_title, o.status as objective_status, o.target_value, o.unit
               from annual_plan_items i left join users u on u.id = i.responsible_user_id left join sst_objectives o on o.id = i.objective_id
              where i.annual_plan_id = ? and i.deleted_at is null order by i.sort_order nulls last, i.code", [$planId]);
        $programs = [];
        foreach ($items as $i) {
            if ($i->item_type === 'program' && in_array($i->reference_type, ['annual_programs', 'training_programs'], true) && $i->reference_id) {
                $programs[] = self::program($i->reference_type, $i->reference_id);
            }
        }
        // Programas enlazados al plan por su propio campo aunque no estén como componente (PASST con annual_plan_id; capacitación del plan).
        foreach (DB::select('select id from annual_programs where annual_plan_id = ? and deleted_at is null', [$planId]) as $r) {
            $programs[] = self::program('annual_programs', $r->id);
        }
        if ($p->training_program_id) {
            $programs[] = self::program('training_programs', $p->training_program_id);
        }
        $programs = collect($programs)->filter()->unique('id')->values()->all();

        return [
            'company' => $company,
            'plan' => $p,
            'baseline' => $p->baseline_assessment_id ? DB::selectOne('select title, assessment_on, score_pct, approved_at from baseline_assessments where id = ?', [$p->baseline_assessment_id]) : null,
            'policy' => $p->policy_id ? DB::selectOne('select title, version_number, content_html, effective_on from sst_policies where id = ?', [$p->policy_id]) : null,
            'items' => $items,
            'programs' => $programs,
            'meeting' => $p->committee_meeting_id ? DB::selectOne('select number, meeting_type, held_at, scheduled_at from committee_meetings where id = ?', [$p->committee_meeting_id]) : null,
            'approvedBy' => $p->approved_by ? DB::selectOne('select full_name from users where id = ?', [$p->approved_by])?->full_name : null,
            'signatures' => DB::select("select coalesce(u.full_name, s.signer_name) as full_name, s.method, s.signed_at from signatures s left join users u on u.id = s.signer_user_id
                                         where s.subject_type = 'annual_plans' and s.subject_id = ? order by s.signed_at", [$planId]),
        ];
    }

    private static function program(string $table, string $id): ?array
    {
        $r = DB::selectOne("select id, title, year, status".($table === 'annual_programs' ? ', progress_pct, version_number' : '')." from {$table} where id = ? and deleted_at is null", [$id]);

        return $r ? ['id' => $r->id, 'kind' => $table === 'annual_programs' ? 'Programa Anual SST (PASST)' : 'Programa anual de capacitación', 'title' => $r->title,
            'year' => $r->year, 'status' => self::PROGRAM_STATUS[$r->status] ?? $r->status, 'progress' => $r->progress_pct ?? null] : null;
    }

    public static function pdf(string $planId, string $view = 'reports.annual_plan'): array
    {
        $d = self::data($planId);
        $html = view($view, $d + [
            'itemType' => self::ITEM_TYPE, 'objectiveStatus' => self::OBJECTIVE_STATUS,
            'date' => fn ($v) => $v ? Carbon::parse($v)->timezone('America/Lima')->format('d/m/Y') : '—',
            'policyText' => $d['policy'] ? trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '</li>'], "\n", (string) $d['policy']->content_html))))) : null,
        ])->render();
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        $suffix = $view === 'reports.annual_plan' ? '' : '-informe-de-cumplimiento';

        return ["plan-anual-sst-{$d['plan']->year}-v{$d['plan']->version_number}{$suffix}.pdf", $dompdf->output(), $d['plan']];
    }

    /** RN-PLA-04: PDF del plan aprobado como archivo y como versión vigente de su documento en DOC. */
    public static function archive(string $planId): void
    {
        try {
            DB::transaction(function () use ($planId) {
                [$name, $bytes, $p] = self::pdf($planId);
                $fid = self::store($p->company_id, $name, $bytes, $planId, $p->approved_by);
                $code = 'PLAN-ANUAL-SST-'.$p->year;
                $doc = DB::selectOne('select id from documents where code = ? and deleted_at is null', [$code])?->id
                    ?? DB::selectOne("insert into documents (company_id, category_id, code, title, description, owner_user_id, scope_type, status, created_by, updated_by)
                                      values (?, ?, ?, ?, 'Plan Anual SST aprobado (se actualiza solo con cada versión aprobada).', ?, 'company', 'active', ?, ?) returning id",
                        [$p->company_id, self::category($p->company_id), $code, 'Plan Anual SST '.$p->year, $p->approved_by, $p->approved_by, $p->approved_by])->id;
                DB::update("update document_versions set status = 'obsolete', obsoleted_at = now() where document_id = ? and status = 'current' and deleted_at is null", [$doc]);
                $n = (int) DB::selectOne('select coalesce(max(version_number), 0) + 1 as n from document_versions where document_id = ?', [$doc])->n;
                $dv = DB::selectOne("insert into document_versions (company_id, document_id, version_number, version_label, file_id, sha256, change_summary, status, approved_by, approved_at, effective_on, created_by, updated_by)
                                     values (?, ?, ?, ?, ?, ?, ?, 'current', ?, ?, (now() at time zone 'America/Lima')::date, ?, ?) returning id",
                    [$p->company_id, $doc, $n, 'Plan '.$p->year.' v'.$p->version_number, $fid, hash('sha256', $bytes), "Versión {$p->version_number} del Plan Anual SST {$p->year} aprobada.",
                        $p->approved_by, $p->approved_at, $p->approved_by, $p->approved_by])->id;
                DB::update("update documents set current_version_id = ?, status = 'active', updated_at = now() where id = ?", [$dv, $doc]);
                DB::update('update annual_plans set file_id = ?, document_version_id = ? where id = ?', [$fid, $dv, $planId]);
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** RN-PLA-08: informe de cumplimiento del plan cerrado. */
    public static function closure(string $planId): void
    {
        try {
            DB::transaction(function () use ($planId) {
                [$name, $bytes, $p] = self::pdf($planId, 'reports.annual_plan_closure');
                $fid = self::store($p->company_id, $name, $bytes, $planId, $p->closed_by);
                DB::update('update annual_plans set closure_report_file_id = ? where id = ?', [$fid, $planId]);
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function store(string $company, string $name, string $bytes, string $planId, ?string $by): string
    {
        $fid = (string) Str::uuid7();
        $disk = config('escudo.files_disk', 'local');
        $path = "company/{$company}/".date('Y/m')."/{$fid}.pdf";
        Storage::disk($disk)->put($path, $bytes);
        DB::insert("insert into files (id, company_id, bucket, object_key, original_name, mime_type, size_bytes, sha256, module_code, entity_type, entity_id, status, exif_stripped, uploaded_by)
                    values (?, ?, ?, ?, ?, 'application/pdf', ?, decode(?, 'hex'), 'PLA', 'annual_plans', ?, 'available', false, ?)",
            [$fid, $company, $disk, $path, $name, strlen($bytes), hash('sha256', $bytes), $planId, $by]);

        return $fid;
    }

    /** Categoría documental: la que ya trate del Plan Anual; si no hay, se crea «Plan Anual SST». */
    private static function category(string $company): string
    {
        return DB::selectOne("select id from document_categories where deleted_at is null and (code ilike '%plan%anual%' or name ilike '%plan anual%') order by sort_order nulls last limit 1")?->id
            ?? DB::selectOne("insert into document_categories (company_id, code, name, requires_approval_by, sort_order) values (?, 'PLAN-ANUAL', 'Plan Anual SST', 'sst', 91) returning id", [$company])->id;
    }
}

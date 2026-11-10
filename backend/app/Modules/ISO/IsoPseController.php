<?php

namespace App\Modules\ISO;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sistema integrado ISO (ISO) y paquetes sectoriales (PSE):
 *  POST /iso/assess {standard}       evalúa cada cláusula con los datos reales de los módulos y deja la evidencia
 *  GET  /pse/packs                   paquetes disponibles y cuáles tiene la empresa
 *  POST /pse/packs/{id}/apply        aplica un paquete: crea las capacitaciones del sector y activa sus plantillas
 */
class IsoPseController extends Controller
{
    /** Evidencia por módulo: [consulta que cuenta registros que valen como evidencia, qué se cuenta]. */
    private const EVIDENCE = [
        'LBA' => ["select count(*) as n from baseline_assessments where deleted_at is null and status = 'approved'", 'línea base aprobada'],
        'MRL' => ['select count(*) as n from requirement_evaluations where deleted_at is null', 'evaluaciones de requisitos legales'],
        'POL' => ["select count(*) as n from sst_policies where deleted_at is null and status = 'current'", 'política vigente'],
        'COM' => ["select count(*) as n from committees where deleted_at is null and status = 'active'", 'comité o supervisor activo'],
        'IPC' => ['select count(*) as n from iperc_matrices where deleted_at is null and current_version_id is not null', 'matrices IPERC aprobadas'],
        'PLA' => ["select count(*) as n from annual_plans where deleted_at is null and status in ('approved','current')", 'plan anual aprobado'],
        'MOC' => ['select count(*) as n from change_requests where deleted_at is null', 'cambios gestionados'],
        'CAP' => ["select count(*) as n from training_sessions where deleted_at is null and status = 'held'", 'capacitaciones realizadas'],
        'DOC' => ["select count(*) as n from document_versions where deleted_at is null and status = 'current'", 'documentos vigentes'],
        'NOT' => ['select count(*) as n from notifications', 'avisos enviados'],
        'PTR' => ["select count(*) as n from work_permits where deleted_at is null and status = 'closed'", 'permisos de alto riesgo cerrados'],
        'PET' => ["select count(*) as n from procedure_versions where deleted_at is null and status = 'current'", 'procedimientos vigentes'],
        'CTR' => ["select count(*) as n from contractor_accreditations where deleted_at is null and status = 'accredited'", 'contratistas acreditados'],
        'SIM' => ["select count(*) as n from drills where deleted_at is null and status in ('executed','evaluated')", 'simulacros realizados'],
        'EPP' => ['select count(*) as n from ppe_deliveries where deleted_at is null', 'entregas de EPP'],
        'EST' => ['select count(*) as n from monthly_statistics where deleted_at is null', 'meses con estadísticas'],
        'AUI' => ["select count(*) as n from audits where deleted_at is null and status in ('completed','closed')", 'auditorías completadas'],
        'MAG' => ['select count(*) as n from measurements where deleted_at is null', 'mediciones de agentes'],
        'RVD' => ["select count(*) as n from management_reviews where deleted_at is null and status in ('completed','closed')", 'revisiones por la dirección'],
        'ACR' => ["select count(*) as n from corrective_actions where deleted_at is null and status = 'verified'", 'acciones verificadas'],
        'INV' => ["select count(*) as n from investigations where deleted_at is null and status = 'closed'", 'investigaciones cerradas'],
        'ACC' => ["select count(*) as n from sst_events where deleted_at is null and status <> 'discarded'", 'eventos registrados'],
    ];

    public function assess(Request $request): JsonResponse
    {
        $this->can('iso.iso_evidences.create');
        $code = (string) $request->input('standard', 'ISO45001');
        $std = DB::selectOne('select id, code, name from iso_standards where (code = ? or code ilike ?) and deleted_at is null limit 1', [$code, '%'.preg_replace('/\D/', '', $code).'%'])
            ?? DB::selectOne('select id, code, name from iso_standards where deleted_at is null order by code limit 1');
        if (! $std) {
            throw ApiProblem::notFound('No hay normas ISO cargadas en el catálogo.');
        }
        $company = app(CompanyContext::class)->companyId;
        $out = [];
        foreach (DB::select('select id, clause_code, title, mapped_modules from iso_clauses where iso_standard_id = ? and deleted_at is null order by sort_order', [$std->id]) as $c) {
            $mods = array_filter(explode(',', trim((string) $c->mapped_modules, '{}')));
            $found = [];
            foreach ($mods as $m) {
                if (isset(self::EVIDENCE[$m])) {
                    $n = (int) (DB::selectOne(self::EVIDENCE[$m][0])->n ?? 0);
                    if ($n > 0) {
                        $found[] = "{$m}: {$n} ".self::EVIDENCE[$m][1];
                    }
                }
            }
            $status = $found === [] ? 'not_started' : (count($found) >= max(1, (int) ceil(count($mods) / 2)) ? 'implemented' : 'in_progress');
            $desc = $found ? 'Evidencia en EscudoSST: '.implode('; ', $found).'.' : 'Sin evidencia todavía en los módulos '.implode(', ', $mods).'.';
            $ev = DB::selectOne('select id, status from iso_evidences where iso_clause_id = ? and deleted_at is null and source_type is null limit 1', [$c->id]);
            if ($ev) {
                // Una cláusula verificada o marcada «no aplica» por la persona no se pisa.
                if (! in_array($ev->status, ['verified', 'not_applicable'], true)) {
                    DB::update('update iso_evidences set status = ?, description = ?, last_reviewed_on = current_date, updated_by = ? where id = ?', [$status, $desc, (string) auth()->id(), $ev->id]);
                }
            } else {
                DB::insert('insert into iso_evidences (company_id, iso_clause_id, description, status, last_reviewed_on, created_by, updated_by) values (?, ?, ?, ?, current_date, ?, ?)',
                    [$company, $c->id, $desc, $status, (string) auth()->id(), (string) auth()->id()]);
            }
            $out[] = ['clause' => $c->clause_code, 'title' => $c->title, 'status' => $ev && in_array($ev->status, ['verified', 'not_applicable'], true) ? $ev->status : $status, 'evidence' => $desc];
        }

        return response()->json(['data' => ['standard' => $std->name, 'clauses' => $out,
            'implemented' => count(array_filter($out, fn ($o) => in_array($o['status'], ['implemented', 'verified'], true))), 'total' => count($out)]]);
    }

    public function packs(): JsonResponse
    {
        $this->can('pse.company_sector_packs.read');

        return response()->json(['data' => DB::select(
            "select p.id, p.code, p.name, p.sector, p.description, p.version_number,
                    (select count(*) from sector_pack_items i where i.sector_pack_id = p.id and i.deleted_at is null) as items,
                    (select c.applied_at from company_sector_packs c where c.sector_pack_id = p.id and c.status = 'active' and c.deleted_at is null limit 1) as applied_at
               from sector_packs p where p.deleted_at is null and p.status = 'published' order by p.name"
        )]);
    }

    public function apply(string $id, RecordsService $records): JsonResponse
    {
        $this->can('pse.company_sector_packs.create');
        $p = Str::isUuid($id) ? DB::selectOne('select * from sector_packs where id = ? and deleted_at is null', [$id]) : null;
        if (! $p) {
            throw ApiProblem::notFound('El paquete no existe.');
        }
        if (DB::selectOne("select 1 as ok from company_sector_packs where sector_pack_id = ? and status = 'active' and deleted_at is null", [$id])) {
            throw new ApiProblem(409, 'conflict', 'Ya aplicado', 'Tu empresa ya tiene este paquete.');
        }
        $applied = [];
        $trainingsRes = Registry::resource('CAP', 'trainings');
        foreach (DB::select('select item_type, code, name, payload from sector_pack_items where sector_pack_id = ? and deleted_at is null order by sort_order', [$id]) as $it) {
            $pl = json_decode($it->payload ?? '{}', true) ?: [];
            if ($it->item_type === 'training_topic') {
                $topic = (string) ($pl['topic'] ?? $it->name);
                if (DB::selectOne('select 1 as ok from trainings where title = ? and deleted_at is null', [$topic])) {
                    continue;
                }
                $records->create($trainingsRes, ['title' => $topic, 'training_type' => in_array($pl['training_type'] ?? '', ['induction', 'specific', 'general', 'refresher'], true) ? $pl['training_type'] : 'specific',
                    'modality' => 'in_person', 'duration_minutes' => 60]);
                $applied[] = ['type' => 'Capacitación', 'name' => $topic];
            } else {
                $label = ['inspection_template' => 'Plantilla de inspección', 'hazard' => 'Peligros del catálogo', 'permit_type' => 'Tipo de permiso'][$it->item_type] ?? $it->item_type;
                $applied[] = ['type' => $label, 'name' => $it->name ?: json_encode($pl, JSON_UNESCAPED_UNICODE)];
            }
        }
        DB::insert("insert into company_sector_packs (company_id, sector_pack_id, pack_version, applied_at, applied_by, items_applied, status, created_by, updated_by)
                    values (?, ?, ?, now(), ?, ?::jsonb, 'active', ?, ?)",
            [app(CompanyContext::class)->companyId, $id, (int) $p->version_number, (string) auth()->id(), json_encode($applied, JSON_UNESCAPED_UNICODE), (string) auth()->id(), (string) auth()->id()]);

        return response()->json(['data' => ['pack' => $p->name, 'applied' => $applied]]);
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no puede hacer esto.');
        }
    }
}

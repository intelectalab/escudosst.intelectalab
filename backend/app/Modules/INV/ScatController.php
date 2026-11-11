<?php

namespace App\Modules\INV;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Método SCAT e informe de la investigación (M-INV SCR-INV-04 y SCR-INV-06):
 *  GET /inv/scat/catalog                  catálogo SCAT activo (contacto, actos, condiciones, factores, NAC), de la tabla global
 *                                         scat_catalog_items que edita el dueño de la plataforma (respaldo: ScatCatalog)
 *  GET /inv/investigations/{id}/scat      lo elegido en el asistente (contacto y causas con código SCAT)
 *  PUT /inv/investigations/{id}/scat      guarda el asistente {contact[], immediate[{code,note}], basic[{code,note,parent}],
 *                                         nac[{code,pec,note,parent}]}: crea, actualiza o quita causas con código SCAT
 *                                         (las causas con acción correctiva no se quitan)
 *  GET /inv/investigations/{id}/report    datos del informe imprimible: evento, equipo, método, causas, conclusiones,
 *                                         acciones y firmas
 * Las causas se escriben con el motor de registros (mismas reglas, permisos y bitácora que la lista «Causas»).
 */
class ScatController extends Controller
{
    private const LOCKED = ['approved', 'actions_in_progress', 'closed'];

    public function __construct(private RecordsService $records) {}

    public function catalog(): JsonResponse
    {
        return response()->json(['data' => ScatCatalog::toArray()]);
    }

    public function show(string $id): JsonResponse
    {
        $inv = $this->investigation($id);

        return response()->json(['data' => $this->state($inv)]);
    }

    public function save(Request $request, string $id): JsonResponse
    {
        $inv = $this->investigation($id);
        if (in_array($inv->status, self::LOCKED, true)) {
            throw new ApiProblem(409, 'conflict', 'Investigación aprobada', 'La investigación ya está aprobada: el análisis SCAT no se cambia. Los nuevos hallazgos van como adenda.');
        }
        // Códigos desactivados en el catálogo: solo valen si la investigación ya los tenía (se conservan tal cual).
        $had = array_column(DB::select('select scat_code from investigation_causes where investigation_id = ? and scat_code is not null and deleted_at is null', [$inv->id]), 'scat_code');
        $d = $request->validate([
            'contact' => ['required', 'array', 'min:1'],
            'contact.*' => ['string', 'in:'.implode(',', array_unique([...ScatCatalog::contactCodes(), ...$this->contact($inv)]))],
            'immediate' => ['required', 'array', 'min:1'],
            'immediate.*.code' => ['required', 'string'],
            'immediate.*.note' => ['nullable', 'string', 'max:500'],
            'basic' => ['required', 'array', 'min:1'],
            'basic.*.code' => ['required', 'string'],
            'basic.*.note' => ['nullable', 'string', 'max:500'],
            'basic.*.parent' => ['nullable', 'string'],
            'nac' => ['present', 'array'],
            'nac.*.code' => ['required', 'string'],
            'nac.*.pec' => ['required', 'in:P,E,C'],
            'nac.*.note' => ['nullable', 'string', 'max:500'],
            'nac.*.parent' => ['nullable', 'string'],
        ], [
            'contact.required' => 'Elige al menos un tipo de contacto o evento.',
            'contact.min' => 'Elige al menos un tipo de contacto o evento.',
            'immediate.required' => 'Elige al menos una causa inmediata (acto o condición).',
            'immediate.min' => 'Elige al menos una causa inmediata (acto o condición).',
            'basic.required' => 'Elige al menos una causa básica (factor personal o del trabajo).',
            'basic.min' => 'Elige al menos una causa básica (factor personal o del trabajo).',
        ]);

        // Lista deseada: código SCAT → [nivel, categoría, descripción, código del padre].
        $want = [];
        foreach (['immediate' => 'immediate', 'basic' => 'basic'] as $key => $level) {
            foreach ($d[$key] as $i => $c) {
                $cls = ScatCatalog::classify($c['code']);
                if (! $cls || $cls[0] !== $level || (! $cls[3] && ! in_array($c['code'], $had, true))) {
                    $this->fail("{$key}.{$i}.code", 'Ese código no es del catálogo SCAT para este paso.');
                }
                $want[$c['code']] = [$cls[0], $cls[1], $this->text($cls[2], $c['note'] ?? null), $key === 'basic' ? ($c['parent'] ?? null) : null];
            }
        }
        foreach ($d['nac'] as $i => $c) {
            $code = $c['code'].'-'.$c['pec'];
            $cls = ScatCatalog::classify($code);
            if (! $cls || (! $cls[3] && ! in_array($code, $had, true))) {
                $this->fail("nac.{$i}.code", 'Ese elemento de control no es del catálogo SCAT.');
            }
            $want[$code] = [$cls[0], $cls[1], $this->text($cls[2], $c['note'] ?? null), $c['parent'] ?? null];
        }
        foreach ($want as $code => $w) {
            if ($w[3] !== null && (! isset($want[$w[3]]) || $want[$w[3]][0] !== ($w[0] === 'basic' ? 'immediate' : 'basic'))) {
                $this->fail('basic', "La causa {$code} debe explicar a una causa elegida en el paso anterior.");
            }
        }

        $res = Registry::resource('INV', 'investigation_causes');
        $this->records->authorize($res, 'create');
        $existing = [];
        foreach (DB::select('select id, scat_code, corrective_action_id from investigation_causes where investigation_id = ? and scat_code is not null and deleted_at is null', [$inv->id]) as $c) {
            $existing[$c->scat_code] = $c;
        }

        $kept = [];
        DB::transaction(function () use ($inv, $d, $want, $existing, $res, &$kept) {
            DB::update('update investigations set scat_contact = ?::jsonb, updated_by = ? where id = ?',
                [json_encode(array_values(array_unique($d['contact']))), (string) auth()->id(), $inv->id]);
            if ($inv->status === 'open' && in_array('inv.investigations.update', Permissions::effective(), true)) {
                $this->records->update(Registry::resource('INV', 'investigations'), $inv->id, ['status' => 'analysis']);
            }
            // Primero inmediatas, luego básicas y al final las NAC, para enlazar cada una con su padre.
            $ids = [];
            $order = 0;
            foreach (['immediate', 'basic', 'lack_of_control'] as $level) {
                foreach ($want as $code => [$lv, $cat, $desc, $parent]) {
                    if ($lv !== $level) {
                        continue;
                    }
                    $values = ['investigation_id' => $inv->id, 'cause_level' => $lv, 'cause_category' => $cat, 'scat_code' => $code, 'description' => $desc,
                        'parent_cause_id' => $parent ? ($ids[$parent] ?? null) : null, 'sort_order' => ++$order];
                    $ids[$code] = isset($existing[$code])
                        ? $this->records->update($res, $existing[$code]->id, $values)['id']
                        : $this->records->create($res, $values)['id'];
                }
            }
            foreach ($existing as $code => $c) {
                if (isset($want[$code])) {
                    continue;
                }
                if ($c->corrective_action_id) {
                    $kept[] = $code;   // Ya tiene acción correctiva: se conserva para no perder la trazabilidad.

                    continue;
                }
                $this->records->delete($res, $c->id);
            }
        });

        $out = $this->state($this->investigation($id));
        $out['kept'] = $kept;

        return response()->json(['data' => $out]);
    }

    public function report(string $id): JsonResponse
    {
        $inv = $this->investigation($id);
        $co = DB::selectOne('select legal_name, trade_name, ruc from companies where id = ?', [$inv->company_id]);
        $event = DB::selectOne(
            'select e.code, e.event_type, e.occurred_at, e.reported_at, e.exact_location, e.description, e.activity_at_time, e.severity, e.lost_days, e.injured_count,
                    e.material_damage, e.immediate_actions, s.name as site, a.name as area
               from sst_events e left join sites s on s.id = e.site_id left join areas a on a.id = e.area_id where e.id = ?', [$inv->event_id]);
        // Equipo con su firma (la de la ficha o la pedida por M-FIR) y si está pendiente.
        $team = array_map(function ($m) {
            $img = $m->signature_id ? DB::selectOne("select mime_type, encode(image, 'base64') as image from signature_strokes where signature_id = ?", [$m->signature_id]) : null;

            return (object) ['id' => $m->id, 'name' => $m->name, 'role' => $m->role, 'signed_at' => $m->signed_at, 'method' => $m->method,
                'pending' => ! $m->signature_id && $m->pending_request_id !== null,
                'image' => $img ? 'data:'.$img->mime_type.';base64,'.str_replace(["\n", "\r"], '', $img->image) : null];
        }, TeamSignaturesController::members($inv->id));
        $causes = DB::select(
            'select c.id, c.parent_cause_id, c.cause_level, c.cause_category, c.scat_code, c.description, a.code as action_code
               from investigation_causes c left join corrective_actions a on a.id = c.corrective_action_id
              where c.investigation_id = ? and c.deleted_at is null order by c.sort_order, c.created_at', [$inv->id]);
        $actions = DB::select(
            "select a.code, a.title, a.due_on, a.status, a.priority, coalesce(u.full_name, w.full_name) as responsible
               from corrective_actions a left join users u on u.id = a.responsible_user_id left join workers w on w.id = a.responsible_worker_id
              where a.deleted_at is null and (a.id in (select corrective_action_id from investigation_causes where investigation_id = ? and deleted_at is null and corrective_action_id is not null)
                    or (a.source_type = 'investigation' and a.source_id = ?))
              order by a.due_on, a.code", [$inv->id, $inv->id]);
        $signatures = DB::select(
            "select s.signer_name, s.signed_at, s.method, k.mime_type, encode(k.image, 'base64') as image
               from signatures s left join signature_strokes k on k.signature_id = s.id
              where s.subject_type = 'investigations' and s.subject_id = ? order by s.signed_at", [$inv->id]);
        foreach ($signatures as $s) {
            $s->image = $s->image ? 'data:'.$s->mime_type.';base64,'.str_replace(["\n", "\r"], '', $s->image) : null;
            unset($s->mime_type);
        }
        $approver = $inv->approved_by ? DB::selectOne('select full_name from users where id = ?', [$inv->approved_by])?->full_name : null;

        return response()->json(['data' => [
            'company' => ['name' => $co->trade_name ?: $co->legal_name, 'legal_name' => $co->legal_name, 'ruc' => trim((string) $co->ruc)],
            'investigation' => [
                'id' => $inv->id, 'code' => $inv->code, 'method' => $inv->method, 'status' => $inv->status, 'started_on' => $inv->started_on, 'due_on' => $inv->due_on,
                'summary' => $inv->summary, 'immediate_causes' => $inv->immediate_causes, 'basic_causes' => $inv->basic_causes, 'conclusions' => $inv->conclusions,
                'lessons_learned' => $inv->lessons_learned, 'approved_at' => $inv->approved_at, 'approved_by' => $approver,
                'scat_contact' => array_map(fn ($c) => ['code' => $c, 'label' => ScatCatalog::label($c)], $this->contact($inv)),
            ],
            'event' => $event,
            'team' => $team,
            'causes' => $causes,
            'actions' => $actions,
            'signatures' => $signatures,
        ]]);
    }

    private function state(object $inv): array
    {
        $causes = DB::select(
            'select c.id, c.parent_cause_id, c.cause_level, c.cause_category, c.scat_code, c.description, c.corrective_action_id, a.code as action_code, a.status as action_status
               from investigation_causes c left join corrective_actions a on a.id = c.corrective_action_id
              where c.investigation_id = ? and c.scat_code is not null and c.deleted_at is null order by c.sort_order, c.created_at', [$inv->id]);
        $codes = [];
        foreach ($causes as $c) {
            $codes[$c->id] = $c->scat_code;
        }
        foreach ($causes as $c) {
            $c->parent_code = $c->parent_cause_id ? ($codes[$c->parent_cause_id] ?? null) : null;
            // Lo que la persona agregó después de la descripción del catálogo.
            $base = ScatCatalog::classify($c->scat_code)[2] ?? '';
            $c->note = str_starts_with($c->description, $base) ? trim(ltrim(mb_substr($c->description, mb_strlen($base)), ' :—-')) : $c->description;
        }

        return [
            'investigation' => ['id' => $inv->id, 'code' => $inv->code, 'method' => $inv->method, 'status' => $inv->status],
            'contact' => $this->contact($inv),
            'causes' => $causes,
            'editable' => ! in_array($inv->status, self::LOCKED, true) && in_array('inv.investigation_causes.create', Permissions::effective(), true),
        ];
    }

    private function contact(object $inv): array
    {
        $v = is_string($inv->scat_contact ?? null) ? json_decode($inv->scat_contact, true) : ($inv->scat_contact ?? []);

        return array_values(array_filter((array) $v, 'is_string'));
    }

    private function text(string $base, ?string $note): string
    {
        $note = trim((string) $note);

        return $note === '' ? $base : $base.': '.$note;
    }

    private function investigation(string $id): object
    {
        $inv = Str::isUuid($id) ? DB::selectOne('select * from investigations where id = ? and deleted_at is null', [$id]) : null;

        return $inv ?? throw ApiProblem::notFound('La investigación no existe o es de otra empresa.');
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}

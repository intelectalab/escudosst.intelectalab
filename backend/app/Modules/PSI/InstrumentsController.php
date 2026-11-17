<?php

namespace App\Modules\PSI;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cuestionario psicosocial de la empresa con versiones (M-PSI RN-PSI-04):
 *  GET  /psi/instruments                 versiones (la vigente es la que usan las encuestas nuevas) y, sin ninguna, el cuestionario base
 *  POST /psi/instruments                 nueva versión en borrador, copia de la vigente (o del cuestionario base)
 *  PUT  /psi/instruments/{id}            edita el borrador: {title, questions: {dimensions: [{name, items: [{text}]}]}, cutoffs: {medium, high}}
 *  POST /psi/instruments/{id}/publish    la publica: queda vigente y la anterior obsoleta (las encuestas ya creadas conservan sus preguntas)
 */
class InstrumentsController extends Controller
{
    public function index(): JsonResponse
    {
        $this->can('psi.psychosocial_surveys.read');
        $rows = DB::select('select i.id, i.version_number, i.title, i.status, i.questions, i.cutoffs, i.change_summary, i.published_at, u.full_name as published_by
                              from psychosocial_instruments i left join users u on u.id = i.published_by where i.deleted_at is null order by i.version_number desc');
        foreach ($rows as $r) {
            $r->questions = json_decode((string) $r->questions, true);
            $r->cutoffs = json_decode((string) $r->cutoffs, true);
        }

        return response()->json(['data' => ['versions' => $rows, 'base' => PsychosocialSurvey::defaultQuestions(), 'base_cutoffs' => PsychosocialSurvey::CUTOFFS,
            'can_edit' => in_array('psi.psychosocial_surveys.update', Permissions::effective(), true)]]);
    }

    public function store(): JsonResponse
    {
        $this->can('psi.psychosocial_surveys.update');
        if (DB::selectOne("select 1 as ok from psychosocial_instruments where status = 'draft' and deleted_at is null")) {
            throw new ApiProblem(409, 'conflict', 'Ya hay un borrador', 'Ya existe una versión en borrador del cuestionario: edítala o publícala.');
        }
        $cur = DB::selectOne("select title, questions, cutoffs from psychosocial_instruments where status = 'current' and deleted_at is null limit 1");
        $n = (int) DB::selectOne('select coalesce(max(version_number), 0) + 1 as n from psychosocial_instruments where deleted_at is null')->n;
        $uid = (string) auth()->id();
        $id = DB::selectOne('insert into psychosocial_instruments (company_id, version_number, title, questions, cutoffs, created_by, updated_by) values (?, ?, ?, ?::jsonb, ?::jsonb, ?, ?) returning id',
            [app(CompanyContext::class)->companyId, $n, $cur->title ?? 'Cuestionario psicosocial', $cur->questions ?? json_encode(PsychosocialSurvey::defaultQuestions(), JSON_UNESCAPED_UNICODE),
                $cur->cutoffs ?? json_encode(PsychosocialSurvey::CUTOFFS), $uid, $uid])->id;

        return response()->json(['data' => ['id' => $id, 'version_number' => $n]], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->can('psi.psychosocial_surveys.update');
        $i = $this->draft($id);
        $d = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'change_summary' => ['nullable', 'string', 'max:1000'],
            'questions.dimensions' => ['required', 'array', 'min:1', 'max:20'], 'questions.dimensions.*.name' => ['required', 'string', 'max:150'],
            'questions.dimensions.*.items' => ['required', 'array', 'min:1', 'max:20'], 'questions.dimensions.*.items.*.text' => ['required', 'string', 'max:400'],
            'cutoffs.medium' => ['required', 'numeric', 'between:0,100'], 'cutoffs.high' => ['required', 'numeric', 'between:0,100', 'gt:cutoffs.medium'],
        ], ['questions.dimensions.*.items.required' => 'Cada dimensión necesita al menos una pregunta.', 'cutoffs.high.gt' => 'El corte de riesgo alto debe ser mayor que el de riesgo medio.'],
            ['questions.dimensions.*.name' => 'nombre de la dimensión', 'questions.dimensions.*.items.*.text' => 'pregunta']);
        $dims = [];
        foreach (array_values($d['questions']['dimensions']) as $k => $dim) {
            $code = 'd'.($k + 1);
            $dims[] = ['code' => $code, 'name' => trim($dim['name']),
                'items' => array_map(fn ($it, $j) => ['id' => "{$code}_".($j + 1), 'text' => trim($it['text'])], array_values($dim['items']), array_keys(array_values($dim['items'])))];
        }
        DB::update('update psychosocial_instruments set title = ?, change_summary = ?, questions = ?::jsonb, cutoffs = ?::jsonb, updated_at = now(), updated_by = ? where id = ?', [
            trim($d['title']), $d['change_summary'] ?? null, json_encode(['scale' => PsychosocialSurvey::SCALE, 'dimensions' => $dims], JSON_UNESCAPED_UNICODE),
            json_encode(['medium' => (float) $d['cutoffs']['medium'], 'high' => (float) $d['cutoffs']['high']]), (string) auth()->id(), $i->id]);

        return response()->json(['data' => ['id' => $i->id]]);
    }

    public function publish(string $id): JsonResponse
    {
        $this->can('psi.psychosocial_surveys.update');
        $i = $this->draft($id);
        DB::update("update psychosocial_instruments set status = 'obsolete', updated_at = now() where status = 'current' and deleted_at is null");
        DB::update("update psychosocial_instruments set status = 'current', published_at = now(), published_by = ?, updated_at = now() where id = ?", [(string) auth()->id(), $i->id]);

        return response()->json(['data' => ['id' => $i->id, 'status' => 'current']]);
    }

    private function draft(string $id): object
    {
        $i = Str::isUuid($id) ? DB::selectOne('select * from psychosocial_instruments where id = ? and deleted_at is null', [$id]) : null;
        if (! $i) {
            throw ApiProblem::notFound('Esa versión del cuestionario no existe.');
        }
        if ($i->status !== 'draft') {
            throw new ApiProblem(409, 'conflict', 'Versión publicada', 'Solo se edita o publica un borrador. Para cambiar el cuestionario crea una nueva versión.');
        }

        return $i;
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('Tu rol no gestiona el cuestionario psicosocial.');
        }
    }
}

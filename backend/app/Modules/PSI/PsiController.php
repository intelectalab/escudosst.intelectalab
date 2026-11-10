<?php

namespace App\Modules\PSI;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Core\Tenancy\DbContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\Rules\WorkerRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Encuesta psicosocial anónima (M-PSI):
 *  GET  /psi/surveys/{id}/link                         enlace público y avance (con sesión)
 *  GET  /public/psi/{company}/{survey}/{sig}           preguntas de la encuesta (sin sesión; enlace firmado)
 *  POST /public/psi/{company}/{survey}/{sig}           responder: {document, answers{item: 0..4}}
 */
class PsiController extends Controller
{
    public function link(string $id): JsonResponse
    {
        if (! in_array('psi.psychosocial_surveys.read', Permissions::effective(), true)) {
            throw ApiProblem::forbidden();
        }
        $s = Str::isUuid($id) ? DB::selectOne('select id, status from psychosocial_surveys where id = ? and deleted_at is null', [$id]) : null;
        if (! $s) {
            throw ApiProblem::notFound('La encuesta no existe o es de otra empresa.');
        }
        $company = app(CompanyContext::class)->companyId;
        $n = (int) DB::selectOne('select count(*) as n from psychosocial_responses where survey_id = ? and deleted_at is null', [$id])->n;
        $workers = (int) DB::selectOne("select count(*) as n from workers where deleted_at is null and status in ('active','on_leave')")->n;

        return response()->json(['data' => [
            'url' => PsychosocialSurvey::publicUrl($company, $id), 'status' => $s->status, 'responses' => $n, 'workers' => $workers,
            'min_group' => (int) LegalParams::number('psychosocial_min_responses_report', 5),
        ]]);
    }

    public function show(string $company, string $survey, string $sig): JsonResponse
    {
        return $this->inCompany($company, $survey, $sig, function (object $s) {
            $c = DB::selectOne('select coalesce(trade_name, legal_name) as name from companies where id = ?', [$s->company_id]);
            $open = $this->isOpen($s);

            return response()->json(['data' => [
                'title' => $s->title, 'company' => $c?->name, 'open' => $open, 'status' => $s->status, 'ends_on' => $s->ends_on,
                'questions' => $open ? json_decode((string) $s->questions, true) : null,
            ]]);
        });
    }

    public function answer(Request $request, string $company, string $survey, string $sig): JsonResponse
    {
        $data = $request->validate([
            'document' => ['required', 'string', 'max:20'],
            'answers' => ['required', 'array'],
        ], [], ['document' => 'documento', 'answers' => 'respuestas']);

        return $this->inCompany($company, $survey, $sig, function (object $s) use ($data) {
            if (! $this->isOpen($s)) {
                throw new ApiProblem(409, 'conflict', 'La encuesta no está abierta', 'Esta encuesta ya cerró o todavía no empieza.');
            }
            $q = json_decode((string) $s->questions, true);
            $answers = [];
            foreach ($q['dimensions'] as $d) {
                foreach ($d['items'] as $it) {
                    $v = $data['answers'][$it['id']] ?? null;
                    if (! is_numeric($v) || (int) $v < 0 || (int) $v > 4) {
                        throw new ApiProblem(422, 'validation_failed', 'Faltan respuestas', 'Responde todas las preguntas antes de enviar.',
                            ['errors' => ['answers' => ['Responde todas las preguntas.']]]);
                    }
                    $answers[$it['id']] = (int) $v;
                }
            }
            $doc = strtoupper((string) preg_replace('/[\s.\-]/', '', $data['document']));
            $w = null;
            foreach (['DNI', 'CE', 'PTP', 'PASSPORT', 'OTHER'] as $type) {
                $w = DB::selectOne("select id, current_site_id, current_area_id from workers
                                     where deleted_at is null and status in ('active','on_leave') and document_number_hash = decode(?, 'hex')",
                    [WorkerRules::documentHash($type, $doc)]);
                if ($w) {
                    break;
                }
            }
            if (! $w) {
                throw new ApiProblem(422, 'validation_failed', 'No encontramos ese documento',
                    'Ese documento no corresponde a un trabajador activo de la empresa. Revísalo o consulta con el área de SST.',
                    ['errors' => ['document' => ['Documento no encontrado entre los trabajadores.']]]);
            }
            $token = PsychosocialSurvey::respondentHash($s->id, $w->id);
            if (DB::selectOne("select 1 as ok from psychosocial_responses where survey_id = ? and respondent_token_hash = decode(?, 'hex')", [$s->id, $token])) {
                throw new ApiProblem(409, 'conflict', 'Ya respondiste', 'Ya registramos tu respuesta a esta encuesta. Gracias por participar.');
            }
            // Sin worker_id (RN-PSI-01): solo sede y área para los resultados agregados.
            DB::insert("insert into psychosocial_responses (company_id, survey_id, respondent_token_hash, site_id, area_id, answers)
                        values (?, ?, decode(?, 'hex'), ?, ?, ?::jsonb)",
                [$s->company_id, $s->id, $token, $w->current_site_id, $w->current_area_id, json_encode($answers)]);

            return response()->json(['data' => ['ok' => true]], 201);
        });
    }

    private function isOpen(object $s): bool
    {
        $today = now('America/Lima')->toDateString();

        return $s->status === 'open' && (! $s->starts_on || $s->starts_on <= $today) && (! $s->ends_on || $s->ends_on >= $today);
    }

    /** Valida la firma del enlace y ejecuta con el contexto RLS de esa empresa (transacción propia). */
    private function inCompany(string $company, string $survey, string $sig, callable $fn): JsonResponse
    {
        if (! Str::isUuid($company) || ! Str::isUuid($survey) || ! hash_equals(PsychosocialSurvey::signature($company, $survey), $sig)) {
            throw ApiProblem::notFound('El enlace de la encuesta no es válido.');
        }

        return DB::transaction(function () use ($company, $survey, $fn) {
            DbContext::setCompany($company);
            $s = DB::selectOne('select * from psychosocial_surveys where id = ? and deleted_at is null', [$survey]);
            if (! $s) {
                throw ApiProblem::notFound('El enlace de la encuesta no es válido.');
            }

            return $fn($s);
        });
    }
}

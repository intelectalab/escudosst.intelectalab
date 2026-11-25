<?php

namespace App\Modules\IAA;

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
 * IA-04 «Redacción de actas» (docs/17 §1, M-COM SCR-COM-07 «redactar acta»):
 *  POST /iaa/minutes/draft {meeting_id, notes?, agreements?: [texto | {description, responsible_position?, due_on?}]}
 *
 * Entrada mínima (§3.3): agenda, notas del secretario, asistentes solo por cargo (cargo en el comité, representación y puesto;
 * sin nombres) y acuerdos dictados; todo el texto libre pasa por el redactor (RN-IAA-03) y se rechaza si trae datos de salud.
 * En ai_requests no se guarda el texto (§3.4): solo su hash y los campos enviados.
 *  - Sin proveedor de IA (modo biblioteca): el borrador se arma al momento con la plantilla de acta de EscudoSST → 201.
 *  - Con proveedor encendido: la solicitud queda «queued» y la redacta el trabajador de la cola (AiRequestJob) → 202; el estado
 *    se consulta en GET /iaa/requests/{id}.
 * El borrador queda como ai_suggestions «pending» (RN-IAA-01): al aceptarlo (POST /iaa/suggestions/{id}/accept, con el texto y los
 * acuerdos corregidos) se guarda en el acta de la sesión y se crean los acuerdos con el motor de registros (si hubo quórum, RN-COM-08).
 */
class MinutesController extends Controller
{
    private const TYPE = ['ordinary' => 'ordinaria', 'extraordinary' => 'extraordinaria', 'installation' => 'de instalación'];

    private const MODALITY = ['in_person' => 'presencial', 'virtual' => 'virtual', 'mixed' => 'mixta'];

    private const ROLE = ['president' => 'Presidente', 'secretary' => 'Secretario', 'member' => 'Miembro', 'observer' => 'Observador'];

    private const REPRESENTATION = ['employer' => 'representante del empleador', 'workers' => 'representante de los trabajadores', 'supervisor' => 'Supervisor SST'];

    public function draft(Request $request): JsonResponse
    {
        Usage::guard();
        $d = $request->validate([
            'meeting_id' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:8000'],
            'agreements' => ['nullable', 'array', 'max:30'],
        ], ['meeting_id.required' => 'Elige la sesión del comité.']);
        self::needs('COM', 'el Comité SST');
        // Alcance por sede/área de la membresía (docs/05 §2): la sesión debe estar en su alcance.
        \App\Core\Auth\Scope::assertRecord('committee_meetings', $d['meeting_id'], write: true);
        $m = DB::selectOne('select * from committee_meetings where id = ? and deleted_at is null', [$d['meeting_id']]);
        if (! $m) {
            throw ApiProblem::notFound('La sesión no existe o es de otra empresa.');
        }
        if (in_array($m->status, ['minutes_signed', 'cancelled'], true)) {
            throw new ApiProblem(409, 'conflict', 'Acta cerrada', $m->status === 'cancelled' ? 'La sesión está cancelada.' : 'El acta ya está firmada: no se redacta de nuevo.');
        }
        $notes = trim((string) ($d['notes'] ?? ''));
        $given = self::agreementsFrom((array) ($d['agreements'] ?? []));
        self::noHealth($notes.' '.implode(' ', array_column($given, 'description')).' '.implode(' ', array_filter(array_column($given, 'responsible_position'))));

        $input = $this->input($m, $notes, $given);
        $text = self::inputText($input);
        $ctx = app(CompanyContext::class);
        $uid = (string) auth()->id();
        $fields = '{meeting_type,held_on,location,modality,attendees_by_position,quorum_reached,agenda,notes,agreements}';
        $summary = 'Borrador de acta'.($m->number ? ' N.° '.$m->number : '').' del Comité SST';

        if (AiProvider::enabled()) {
            $req = DB::selectOne(
                "insert into ai_requests (company_id, user_id, feature, module_code, subject_type, subject_id, provider, model, prompt_template_code, prompt_version,
                    input_redacted, contains_medical_data, input_summary, input_hash, input_fields, status, created_by, updated_by)
                 values (?, ?, 'minutes_draft', 'COM', 'committee_meetings', ?, 'anthropic', ?, 'minutes_draft', ?, true, false, ?, ?, ?::text[], 'queued', ?, ?) returning id",
                [$ctx->companyId, $uid, $m->id, AiProvider::model(), PromptTemplates::version('minutes_draft'), $summary, hash('sha256', $text), $fields, $uid, $uid])->id;
            AiRequestJob::dispatch($req, $ctx->companyId, $uid, 'minutes_draft', $text, ['meeting_id' => $m->id, 'quorum_reached' => $input['quorum_reached']])->afterCommit();

            return response()->json(['data' => ['request_id' => $req, 'status' => 'queued',
                'message' => 'El asistente está redactando el acta. Te avisaremos cuando el borrador esté listo.']], 202);
        }

        $started = microtime(true);
        $draft = self::libraryDraft($input);
        $req = DB::selectOne(
            "insert into ai_requests (company_id, user_id, feature, module_code, subject_type, subject_id, provider, model, prompt_template_code, prompt_version,
                input_redacted, contains_medical_data, input_summary, input_hash, input_fields, latency_ms, status, completed_at, created_by, updated_by)
             values (?, ?, 'minutes_draft', 'COM', 'committee_meetings', ?, 'escudosst_library', 'plantilla', 'minutes_draft_library', '1', true, false, ?, ?, ?::text[], ?, 'completed', now(), ?, ?) returning id",
            [$ctx->companyId, $uid, $m->id, $summary, hash('sha256', $text), $fields, (int) ((microtime(true) - $started) * 1000), $uid, $uid])->id;
        $s = AiRequestJob::suggestion($ctx->companyId, $uid, $req, 'minutes_draft', 'committee_meetings', $m->id, $draft + ['quorum_reached' => $input['quorum_reached']],
            'Borrador armado con la plantilla de acta de EscudoSST a partir de la agenda, tus notas, los asistentes por cargo y los acuerdos. Revísalo y corrígelo antes de guardarlo.');

        return response()->json(['data' => ['request_id' => $req, 'status' => 'completed', 'suggestion' => self::present($s)]], 201);
    }

    /** Entrada mínima y seudonimizada del caso (docs/17 §3.3). */
    private function input(object $m, string $notes, array $given): array
    {
        $c = DB::selectOne('select committee_type from committees where id = ?', [$m->committee_id]);
        $present = is_string($m->attendance) ? (json_decode($m->attendance, true) ?: []) : [];
        $present = array_map(fn ($x) => is_array($x) ? (string) ($x['id'] ?? $x['member_id'] ?? '') : (string) $x, $present);
        $attendees = [];
        foreach (DB::select("select cm.id, cm.worker_id, cm.representation, cm.member_type, cm.role_in_committee, p.name as position
                               from committee_members cm join workers w on w.id = cm.worker_id left join positions p on p.id = w.current_position_id
                              where cm.committee_id = ? and cm.deleted_at is null and cm.status = 'active'
                              order by case cm.role_in_committee when 'president' then 0 when 'secretary' then 1 else 2 end, cm.representation", [$m->committee_id]) as $mem) {
            if ($present && ! in_array((string) $mem->id, $present, true) && ! in_array((string) $mem->worker_id, $present, true)) {
                continue;
            }
            // Solo el cargo: nunca el nombre ni el documento (docs/17 §1 IA-04 «asistentes por cargo»).
            $attendees[] = implode(' · ', array_filter([self::ROLE[$mem->role_in_committee] ?? 'Miembro', self::REPRESENTATION[$mem->representation] ?? null,
                $mem->position ? Redactor::redact((string) $mem->position)[0] : null, $mem->member_type === 'alternate' ? 'suplente' : 'titular']));
        }
        if ($given === []) {
            foreach (DB::select("select a.description, a.due_on, p.name as position from meeting_agreements a left join workers w on w.id = a.responsible_worker_id
                                   left join positions p on p.id = w.current_position_id where a.meeting_id = ? and a.deleted_at is null order by a.number nulls last, a.created_at", [$m->id]) as $a) {
                $given[] = ['description' => $a->description, 'responsible_position' => $a->position, 'due_on' => $a->due_on, 'existing' => true];
            }
        }
        $agenda = array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/^\s*(?:\d{1,2}[\.\)\-]|[•\-–])\s*/u', '', $l)),
            preg_split('/\n/', trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/li)\s*\/?>/i', "\n", (string) $m->agenda)), ENT_QUOTES | ENT_HTML5, 'UTF-8')))), fn ($l) => $l !== ''));
        $at = $m->held_at ?? $m->scheduled_at;

        return [
            'committee_type' => $c?->committee_type,
            'meeting_type' => $m->meeting_type,
            'number' => $m->number ? (int) $m->number : null,
            'held_on' => $at ? date('d/m/Y', strtotime($at)) : null,
            'held_time' => $at ? (new \DateTime($at))->setTimezone(new \DateTimeZone('America/Lima'))->format('H:i') : null,
            'location' => $m->location ? Redactor::redact((string) $m->location)[0] : null,
            'modality' => $m->modality,
            'attendees' => $attendees,
            'quorum_reached' => $m->quorum_reached === null ? null : (bool) $m->quorum_reached,
            'agenda' => array_map(fn ($l) => Redactor::redact($l)[0], $agenda),
            'notes' => $notes !== '' ? Redactor::redact($notes)[0] : '',
            'agreements' => array_map(fn ($a) => ['description' => Redactor::redact($a['description'])[0],
                'responsible_position' => $a['responsible_position'] ? Redactor::redact($a['responsible_position'])[0] : null,
                'due_on' => $a['due_on']] + (! empty($a['existing']) ? ['existing' => true] : []), $given),
        ];
    }

    /** Lo que se envía al proveedor: solo la entrada mínima, como texto. */
    public static function inputText(array $in): string
    {
        $lines = ['Tipo de órgano: '.($in['committee_type'] === 'supervisor' ? 'Supervisor de SST' : 'Comité de SST'),
            'Sesión: '.(self::TYPE[$in['meeting_type']] ?? 'ordinaria').($in['number'] ? ' N.° '.$in['number'] : ''),
            'Fecha: '.($in['held_on'] ?? 'sin fecha').($in['held_time'] ? ' '.$in['held_time'] : ''),
            'Lugar: '.($in['location'] ?? 'no indicado').' · modalidad '.(self::MODALITY[$in['modality']] ?? 'presencial'),
            'Quórum: '.($in['quorum_reached'] === null ? 'no se tomó asistencia' : ($in['quorum_reached'] ? 'sí hubo quórum' : 'no hubo quórum')),
            'Asistentes por cargo:', ...array_map(fn ($a) => '- '.$a, $in['attendees'] ?: ['(sin asistencia registrada)']),
            'Agenda:', ...array_map(fn ($a, $i) => ($i + 1).'. '.$a, $in['agenda'], array_keys($in['agenda'])),
            'Notas del secretario:', $in['notes'] !== '' ? $in['notes'] : '(sin notas)',
            'Acuerdos dictados:', ...array_map(fn ($a, $i) => ($i + 1).'. '.$a['description'].($a['responsible_position'] ? ' · responsable: '.$a['responsible_position'] : '')
                .($a['due_on'] ? ' · fecha: '.$a['due_on'] : ''), $in['agreements'], array_keys($in['agreements']))];

        return implode("\n", $lines);
    }

    /** Modo biblioteca (sin clave de IA): borrador con la plantilla de acta y la lista de acuerdos estructurados. */
    public static function libraryDraft(array $in): array
    {
        $organ = $in['committee_type'] === 'supervisor' ? 'del Supervisor de Seguridad y Salud en el Trabajo' : 'del Comité de Seguridad y Salud en el Trabajo';
        $type = self::TYPE[$in['meeting_type']] ?? 'ordinaria';
        $quorum = $in['quorum_reached'];
        $agreements = $quorum === false ? [] : array_values($in['agreements']);
        $p = [];
        $p[] = mb_strtoupper('Acta de sesión '.$type.($in['number'] ? ' N.° '.str_pad((string) $in['number'], 2, '0', STR_PAD_LEFT) : '').' '.$organ);
        $p[] = 'Siendo las '.($in['held_time'] ?? '…').' horas del '.($in['held_on'] ?? '…').', en '.($in['location'] ?? '…').' (modalidad '.(self::MODALITY[$in['modality']] ?? 'presencial')
            .'), se reunieron en sesión '.$type.' los integrantes '.$organ.'.';
        $p[] = 'Asistentes (por cargo):';
        foreach ($in['attendees'] ?: ['(registrar la asistencia de la sesión)'] as $a) {
            $p[] = '- '.$a;
        }
        $p[] = $quorum === null ? 'Quórum: pendiente de verificar con la asistencia.'
            : ($quorum ? 'Verificado el quórum reglamentario, se dio inicio a la sesión.' : 'No se alcanzó el quórum reglamentario: la sesión queda registrada sin quórum y no se adoptan acuerdos.');
        $p[] = 'Agenda:';
        foreach ($in['agenda'] ?: ['(sin agenda registrada)'] as $i => $a) {
            $p[] = ($i + 1).'. '.$a;
        }
        $p[] = 'Desarrollo de la sesión:';
        foreach (array_values(array_filter(array_map('trim', preg_split('/\n+/', $in['notes'])))) ?: ['(escribir lo tratado en cada punto de la agenda)'] as $n) {
            $p[] = $n;
        }
        if ($quorum !== false) {
            $p[] = 'Acuerdos:';
            foreach ($agreements ?: [['description' => '(sin acuerdos registrados)', 'responsible_position' => null, 'due_on' => null]] as $i => $a) {
                $p[] = ($i + 1).'. '.$a['description'].($a['responsible_position'] ? ' Responsable: '.$a['responsible_position'].'.' : '')
                    .($a['due_on'] ? ' Fecha: '.date('d/m/Y', strtotime($a['due_on'])).'.' : '');
            }
        }
        $p[] = 'Sin otro punto que tratar, se levantó la sesión y firman los asistentes en señal de conformidad.';

        return ['minutes_text' => implode("\n", $p), 'agreements' => $agreements];
    }

    /** Acuerdos dictados: texto libre («… responsable: cargo … 15/12/2026») o {description, responsible_position, due_on}. */
    public static function agreementsFrom(array $list): array
    {
        $out = [];
        foreach (array_slice($list, 0, 30) as $a) {
            if (is_array($a)) {
                $desc = trim((string) ($a['description'] ?? ''));
                $resp = trim((string) ($a['responsible_position'] ?? '')) ?: null;
                $due = isset($a['due_on']) && is_string($a['due_on']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['due_on']) ? $a['due_on'] : null;
            } else {
                $t = trim((string) $a);
                $resp = preg_match('/responsable\s*:\s*([^\n;|—–·]+?)(?=\s*(?:[;|—–·,]|\bfecha\b|\d{1,2}\/\d{1,2}\/\d{4}|\d{4}-\d{2}-\d{2}|$))/iu', $t, $rm) ? trim($rm[1], " .-") : null;
                $due = null;
                if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $t, $dm) && checkdate((int) $dm[2], (int) $dm[1], (int) $dm[3])) {
                    $due = sprintf('%04d-%02d-%02d', $dm[3], $dm[2], $dm[1]);
                } elseif (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $t, $dm) && checkdate((int) $dm[2], (int) $dm[3], (int) $dm[1])) {
                    $due = $dm[0];
                }
                $desc = (string) preg_replace(['/responsable\s*:\s*[^\n;|—–·]+/iu', '/\bfecha\s*:?\s*/iu', '/\d{1,2}\/\d{1,2}\/\d{4}|\d{4}-\d{2}-\d{2}/', '/^[\s.\-–—;|,·]+|[\s.\-–—;|,·]+$/u'], '', $t);
                $desc = (string) preg_replace('/^[\s.\-–—;|,·]+|[\s.\-–—;|,·]+$/u', '', $desc);
            }
            if ($desc !== '') {
                $out[] = ['description' => mb_substr($desc, 0, 1000), 'responsible_position' => $resp ? mb_substr($resp, 0, 150) : null, 'due_on' => $due];
            }
        }

        return $out;
    }

    /**
     * Al aceptar: el texto (corregido por la persona) queda como acta en borrador de la sesión y los acuerdos nuevos se registran
     * con su responsable por cargo y fecha (si hubo quórum). Todo con el motor de registros: mismas reglas, bitácora y permisos.
     */
    public static function apply(object $s, array $payload, array $edit): array
    {
        self::needs('COM', 'el Comité SST');
        $records = app(RecordsService::class);
        $res = Registry::resource('COM', 'committee_meetings');
        $records->authorize($res, 'update');
        $m = DB::selectOne('select id, status, quorum_reached from committee_meetings where id = ? and deleted_at is null', [$s->target_id]);
        if (! $m || in_array($m->status, ['minutes_signed', 'cancelled'], true)) {
            throw new ApiProblem(409, 'conflict', 'Acta cerrada', 'La sesión ya no admite cambios en el acta (firmada o cancelada).');
        }
        $text = is_string($edit['minutes_text'] ?? null) && trim($edit['minutes_text']) !== '' ? trim($edit['minutes_text']) : (string) $payload['minutes_text'];
        $agreements = array_key_exists('agreements', $edit) && is_array($edit['agreements']) ? self::agreementsFrom($edit['agreements']) : array_values(array_filter(
            (array) ($payload['agreements'] ?? []), fn ($a) => empty($a['existing'])));
        $html = implode('', array_map(fn ($l) => '<p>'.htmlspecialchars($l, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>', array_values(array_filter(array_map('trim', preg_split('/\n/', $text)), fn ($l) => $l !== ''))));
        $records->update($res, $m->id, ['minutes_html' => $html] + ($m->status === 'held' ? ['status' => 'minutes_draft'] : []));

        $created = [];
        if ($m->quorum_reached && $agreements && in_array($m->status, ['held', 'minutes_draft'], true)) {
            $resA = Registry::resource('COM', 'meeting_agreements');
            $records->authorize($resA, 'create');
            $have = array_map('mb_strtolower', array_column(DB::select('select description from meeting_agreements where meeting_id = ? and deleted_at is null', [$m->id]), 'description'));
            foreach ($agreements as $a) {
                $desc = $a['description'].($a['responsible_position'] && empty($a['responsible_worker_id']) ? ' (Responsable: '.$a['responsible_position'].')' : '');
                if (in_array(mb_strtolower($desc), $have, true) || in_array(mb_strtolower($a['description']), $have, true)) {
                    continue;
                }
                $created[] = $records->create($resA, array_filter(['meeting_id' => $m->id, 'description' => mb_substr($desc, 0, 1000), 'due_on' => $a['due_on'] ?? null,
                    'responsible_worker_id' => isset($a['responsible_worker_id']) && Str::isUuid((string) $a['responsible_worker_id']) ? $a['responsible_worker_id'] : null], fn ($v) => $v !== null))['id'];
            }
        }
        $edited = $text !== (string) $payload['minutes_text'] || array_key_exists('agreements', $edit);

        return ['status' => $edited ? 'partially_accepted' : 'accepted', 'meeting_id' => $m->id, 'agreements' => count($created), 'agreement_ids' => $created];
    }

    public static function present(object $s): array
    {
        return ['id' => $s->id, 'type' => $s->suggestion_type, 'target_id' => $s->target_id, 'status' => $s->status, 'rationale' => $s->rationale,
            'confidence' => $s->confidence === null ? null : (float) $s->confidence, 'payload' => json_decode($s->payload, true)];
    }

    private static function needs(string $module, string $name): void
    {
        if (! DB::selectOne("select 1 as ok from feature_flags where module_code = ? and mode = 'enabled' and deleted_at is null", [$module])) {
            throw ApiProblem::forbidden("Tu plan no tiene {$name} habilitado.");
        }
    }

    private static function noHealth(string $text): void
    {
        if (preg_match('/\b(diagn[oó]stico|hipertensi[oó]n|diabetes|embarazo|gestante|VIH|c[aá]ncer|depresi[oó]n|descanso m[eé]dico)\b/iu', $text)) {
            $msg = 'No escribas datos de salud de personas en el acta: el asistente no los procesa.';
            throw new ApiProblem(422, 'validation_failed', 'Revisa el texto', $msg, ['errors' => ['notes' => [$msg]]]);
        }
    }
}

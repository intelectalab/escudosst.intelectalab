<?php

namespace App\Modules\CAP;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Support\Xlsx;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\REC\RecordsService;
use App\Modules\REC\Registry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Capacitaciones (M-CAP):
 *  POST /cap/attendances/{id}/sign      SCR-CAP-05: firma del participante en la pantalla (trazo) que valida su asistencia (RN-CAP-01)
 *  GET  /cap/expirations?days=          SCR-CAP-09: certificados vencidos y por vencer (último por trabajador y tema) de trabajadores activos
 *  POST /cap/expirations/retrain        programación masiva de reentrenamiento: crea la sesión y convoca a los trabajadores
 *  GET  /cap/reports.xlsx?year=         SCR-CAP-14: por tema, sesiones, participantes, aprobados, desaprobados y horas-hombre por modalidad;
 *                                       permanencia en el aula por curso, cumplimiento de la matriz por área y por cargo y certificados del año
 *  GET  /cap/report?year=               SCR-CAP-14: el mismo reporte para imprimir o guardar en PDF (/imprimir/reporte-capacitacion)
 *  GET  /cap/verification               SCR-CAP-13: verificación pública activada para la empresa, temas verificables, texto de la página,
 *                                       límite de consultas por minuto y dirección de la página pública de la empresa
 *  PUT  /cap/verification {enabled?, page_text?, rate_limit?}  activa o desactiva la verificación pública (RN-CAP-12), fija el texto de la
 *                                       página y el límite propio de la empresa (certificate_verify_rate_limit, RN-CAP-14)
 *  GET  /cap/sessions/{id}/trainer-check RN-CAP-16: si el capacitador no tiene disponibilidad registrada en ese horario, se advierte
 */
class CapToolsController extends Controller
{
    public function sign(Request $request, string $id): JsonResponse
    {
        $this->can('cap.training_attendances.update');
        $d = $request->validate(['image' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:400000'], 'signer_name' => ['nullable', 'string', 'max:150']],
            ['image.required' => 'Falta la firma.']);
        $a = Str::isUuid($id) ? DB::selectOne(
            "select a.*, s.status as session_status, w.full_name from training_attendances a join training_sessions s on s.id = a.session_id
               left join workers w on w.id = a.worker_id where a.id = ? and a.deleted_at is null", [$id]) : null;
        if (! $a) {
            throw ApiProblem::notFound('La asistencia no existe.');
        }
        if ($a->signature_id) {
            throw new ApiProblem(409, 'conflict', 'Ya firmó', 'Esta asistencia ya tiene su firma.');
        }
        if (in_array($a->session_status, ['held', 'cancelled'], true)) {
            throw new ApiProblem(409, 'conflict', 'Sesión cerrada', 'La sesión ya se cerró: sus asistencias no cambian.');
        }
        $png = base64_decode(substr($d['image'], strlen('data:image/png;base64,')), true);
        if (! $png || ! @imagecreatefromstring($png)) {
            throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', 'La firma no es una imagen válida.', ['errors' => ['image' => ['La firma no es una imagen válida.']]]);
        }
        $company = app(CompanyContext::class)->companyId;
        $fid = (string) Str::uuid7();
        $disk = config('escudo.files_disk', 'local');
        $path = "company/{$company}/".date('Y/m')."/{$fid}.png";
        Storage::disk($disk)->put($path, $png);
        DB::insert("insert into files (id, company_id, bucket, object_key, original_name, mime_type, size_bytes, sha256, module_code, entity_type, entity_id, status, exif_stripped, uploaded_by)
                    values (?, ?, ?, ?, 'firma.png', 'image/png', ?, decode(?, 'hex'), 'CAP', 'training_attendances', ?, 'available', true, ?)",
            [$fid, $company, $disk, $path, strlen($png), hash('sha256', $png), $a->id, (string) auth()->id()]);
        $row = DB::selectOne('select id, company_id, session_id, worker_id, contractor_worker_id from training_attendances where id = ?', [$a->id]);
        $hash = hash('sha256', json_encode($row));
        $sig = DB::selectOne(
            "insert into signatures (company_id, subject_type, subject_id, signer_user_id, signer_worker_id, signer_name, method, stroke_file_id, document_hash, evidence_hash, evidence, ip, user_agent, signed_at)
             values (?, 'training_attendances', ?, ?, ?, ?, 'stroke', ?, decode(?, 'hex'), decode(?, 'hex'), ?::jsonb, ?::inet, ?, now()) returning id",
            [$company, $a->id, (string) auth()->id(), $a->worker_id, trim((string) ($d['signer_name'] ?? '')) ?: ($a->full_name ?? 'Participante'), $fid, $hash,
                hash('sha256', $hash.'|'.$fid.'|'.now()->toIso8601String()), json_encode(['channel' => 'assisted_screen', 'assisted_by' => (string) auth()->id()]),
                $request->ip(), mb_substr((string) $request->userAgent(), 0, 300)])->id;
        DB::update("update training_attendances set signature_id = ?, attended = true, check_in_method = 'signature', check_in_at = coalesce(check_in_at, now()), updated_at = now(), updated_by = ? where id = ?",
            [$sig, (string) auth()->id(), $a->id]);

        return response()->json(['data' => ['signature_id' => $sig]], 201);
    }

    public function expirations(Request $request): JsonResponse
    {
        $this->can('cap.training_certificates.read');
        $days = (int) ($request->query('days') ?: LegalParams::number('training_expiry_notice_days', 30));

        return response()->json(['data' => ['days' => $days, 'rows' => DB::select(
            "select distinct on (c.worker_id, c.training_id) c.id, c.worker_id, w.full_name, ps.name as position, ar.name as area, s.name as site, c.training_id, t.title as training,
                    c.expires_on, (c.expires_on < current_date) as expired
               from training_certificates c join workers w on w.id = c.worker_id and w.status = 'active' and w.deleted_at is null
               join trainings t on t.id = c.training_id and t.deleted_at is null
               left join positions ps on ps.id = w.current_position_id left join areas ar on ar.id = w.current_area_id left join sites s on s.id = w.current_site_id
              where c.deleted_at is null and c.status in ('validated', 'expired') and c.expires_on is not null
                and not exists (select 1 from training_certificates n where n.worker_id = c.worker_id and n.training_id = c.training_id and n.deleted_at is null
                                  and n.status = 'validated' and (n.expires_on is null or n.expires_on > c.expires_on))
                and c.expires_on <= current_date + ?::int
              order by c.worker_id, c.training_id, c.expires_on desc", [$days])]]);
    }

    public function retrain(Request $request, RecordsService $records): JsonResponse
    {
        $this->can('cap.training_sessions.create');
        $d = $request->validate(['training_id' => ['required', 'uuid'], 'worker_ids' => ['required', 'array', 'min:1', 'max:300'], 'worker_ids.*' => ['uuid'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'site_id' => ['nullable', 'uuid'], 'location' => ['nullable', 'string', 'max:200']],
            ['worker_ids.required' => 'Elige a quiénes reentrenar.', 'ends_at.after' => 'La hora de fin debe ser posterior al inicio.']);
        $session = $records->create(Registry::resource('CAP', 'training_sessions'), array_filter([
            'training_id' => $d['training_id'], 'starts_at' => $d['starts_at'], 'ends_at' => $d['ends_at'], 'site_id' => $d['site_id'] ?? null, 'location' => $d['location'] ?? null, 'status' => 'scheduled',
        ], fn ($x) => $x !== null));
        $res = Registry::resource('CAP', 'training_attendances');
        $added = 0;
        $errors = [];
        foreach (array_unique($d['worker_ids']) as $w) {
            try {
                $records->create($res, ['session_id' => $session['id'], 'worker_id' => $w, 'attended' => false]);
                $added++;
            } catch (\Throwable $e) {
                $errors[] = (DB::selectOne('select full_name from workers where id = ?', [$w])?->full_name ?? 'Trabajador').': '
                    .($e instanceof ApiProblem ? (collect($e->extra['errors'] ?? [])->flatten()->first() ?? ($e->detail ?? $e->title)) : 'no se pudo convocar');
            }
        }

        return response()->json(['data' => ['session_id' => $session['id'], 'invited' => $added, 'errors' => $errors]], 201);
    }

    public function report(Request $request): Response
    {
        $this->can('cap.trainings.read');
        $year = (int) ($request->query('year') ?: now('America/Lima')->year);
        $d = self::reportData($year);
        $c = $d['company'];
        $out = [["REPORTE DE CAPACITACIONES {$year}"], ["{$c['name']} · RUC {$c['ruc']}"], ['Generado: '.now('America/Lima')->format('d/m/Y H:i')], [],
            ['Tema', 'Modalidad', 'Sesiones realizadas', 'Participantes', 'Aprobados (certificados)', 'Desaprobados o sin certificado', 'Horas-hombre presenciales', 'Horas-hombre en el aula']];
        $mod = ['in_person' => 'Presencial', 'virtual' => 'Virtual', 'blended' => 'Mixta'];
        foreach ($d['topics'] as $r) {
            $out[] = [$r['title'], $mod[$r['modality']] ?? $r['modality'], $r['sessions'], $r['participants'], $r['approved'], $r['failed'], $r['hours_in_person'], $r['hours_aula']];
        }
        $out[] = [];
        $out[] = ['PERMANENCIA EN EL AULA VIRTUAL'];
        $out[] = ['Curso', 'Matriculados', 'Completaron', 'Aprobaron', 'Permanencia promedio (min)', 'Permanencia total (horas)'];
        foreach ($d['permanence'] as $r) {
            $out[] = [$r['course'], $r['enrolled'], $r['completed'], $r['passed'], $r['avg_minutes'], $r['total_hours']];
        }
        foreach (['by_area' => 'CUMPLIMIENTO DE LA MATRIZ POR ÁREA', 'by_position' => 'CUMPLIMIENTO DE LA MATRIZ POR CARGO'] as $k => $t) {
            $out[] = [];
            $out[] = [$t];
            $out[] = [$k === 'by_area' ? 'Área' : 'Cargo', 'Trabajadores', 'Temas exigidos', 'Vigentes', 'Por vencer', 'Vencidos', 'Faltantes', 'Cumplimiento (%)'];
            foreach ($d['compliance'][$k] as $r) {
                $out[] = [$r['name'], $r['workers'], $r['required'], $r['valid'], $r['expiring'], $r['expired'], $r['missing'], $r['pct']];
            }
        }
        $out[] = [];
        $out[] = ['CERTIFICADOS DEL AÑO'];
        $out[] = ['Emitidos', 'Validados', 'Por validar', 'Anulados'];
        $out[] = [$d['certificates']['issued'], $d['certificates']['validated'], $d['certificates']['pending'], $d['certificates']['voided']];

        return response(Xlsx::build('Capacitaciones', $out, 5), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="reporte-capacitaciones-'.$year.'.xlsx"',
        ]);
    }

    public function printable(Request $request): JsonResponse
    {
        $this->can('cap.trainings.read');
        $year = (int) ($request->query('year') ?: now('America/Lima')->year);

        return response()->json(['data' => self::reportData($year)]);
    }

    /** SCR-CAP-14 / §11: datos del reporte anual de capacitación (los mismos para Excel y para imprimir). */
    public static function reportData(int $year): array
    {
        $rows = DB::select(
            "select t.title, t.modality,
                    (select count(*) from training_sessions s where s.training_id = t.id and s.status = 'held' and s.deleted_at is null and extract(year from s.starts_at) = ?) as sessions,
                    (select count(distinct a.worker_id) from training_attendances a join training_sessions s on s.id = a.session_id and s.status = 'held' and s.deleted_at is null
                      where s.training_id = t.id and a.attended and a.deleted_at is null and extract(year from s.starts_at) = ?) as participants,
                    (select count(*) from training_certificates c where c.training_id = t.id and c.deleted_at is null and c.status in ('validated', 'expired') and extract(year from c.issued_on) = ?) as approved,
                    (select coalesce(sum(coalesce(a.hours, extract(epoch from (s.ends_at - s.starts_at)) / 3600)), 0) from training_attendances a
                       join training_sessions s on s.id = a.session_id and s.status = 'held' and s.deleted_at is null
                      where s.training_id = t.id and a.attended and a.signature_id is not null and a.deleted_at is null and extract(year from s.starts_at) = ?) as hours_in_person,
                    (select coalesce(sum(c.hours), 0) from training_certificates c where c.training_id = t.id and c.course_id is not null and c.deleted_at is null
                       and c.status in ('validated', 'expired') and extract(year from c.issued_on) = ?) as hours_aula
               from trainings t where t.deleted_at is null order by t.title", [$year, $year, $year, $year, $year]);
        $topics = [];
        foreach ($rows as $r) {
            if ((int) $r->sessions === 0 && (int) $r->approved === 0) {
                continue;
            }
            $topics[] = ['title' => $r->title, 'modality' => $r->modality, 'sessions' => (int) $r->sessions, 'participants' => (int) $r->participants, 'approved' => (int) $r->approved,
                'failed' => max(0, (int) $r->participants - (int) $r->approved), 'hours_in_person' => round((float) $r->hours_in_person, 1), 'hours_aula' => round((float) $r->hours_aula, 1)];
        }
        // RN-CAP-04: permanencia real en el aula (tiempo activo, no la ventana abierta), por curso, de las matrículas del año.
        $permanence = array_map(fn ($r) => ['course' => $r->title, 'enrolled' => (int) $r->enrolled, 'completed' => (int) $r->completed, 'passed' => (int) $r->passed,
            'avg_minutes' => round((float) $r->avg_seconds / 60, 1), 'total_hours' => round((float) $r->total_seconds / 3600, 1)], DB::select(
            "select c.title, count(*) as enrolled, count(*) filter (where e.status = 'completed') as completed, count(*) filter (where e.passed) as passed,
                    coalesce(avg(e.active_seconds_total) filter (where e.active_seconds_total > 0), 0) as avg_seconds, coalesce(sum(e.active_seconds_total), 0) as total_seconds
               from course_enrollments e join courses c on c.id = e.course_id
              where e.deleted_at is null and e.status <> 'cancelled' and extract(year from e.enrolled_at) = ?
              group by c.title order by c.title", [$year]));
        // Cumplimiento de la matriz por área y por cargo (RN-CAP-05), con los trabajadores activos del alcance del usuario.
        $soon = (int) LegalParams::number('training_expiry_notice_days', 30);
        $items = DB::select(
            "select coalesce(a.name, 'Sin área') as area, coalesce(p.name, 'Sin cargo') as position, w.id as worker_id,
                    case when c.id is not null and (c.expires_on is null or c.expires_on > current_date + ?::int) then 'valid'
                         when c.id is not null and c.expires_on >= current_date then 'expiring'
                         when x.id is not null then 'expired' else 'missing' end as state
               from workers w
               join position_training_requirements q on q.position_id = w.current_position_id and q.deleted_at is null and coalesce(q.is_active, true)
                and q.training_id is not null and q.requirement_level <> 'suggested' and (q.effective_to is null or q.effective_to >= current_date)
               join trainings t on t.id = q.training_id and t.deleted_at is null
               left join positions p on p.id = w.current_position_id left join areas a on a.id = w.current_area_id
               left join lateral (select id, expires_on from training_certificates where worker_id = w.id and training_id = t.id and deleted_at is null and status = 'validated'
                                    order by expires_on desc nulls first limit 1) c on true
               left join lateral (select id from training_certificates where worker_id = w.id and training_id = t.id and deleted_at is null and status in ('validated', 'expired') limit 1) x on true
              where w.deleted_at is null and w.status = 'active'".\App\Core\Auth\Scope::andSql('w.current_site_id', 'w.current_area_id'), [$soon]);
        $group = function (string $key) use ($items): array {
            $g = [];
            foreach ($items as $r) {
                $k = $r->{$key};
                $g[$k] ??= ['name' => $k, 'workers' => [], 'required' => 0, 'valid' => 0, 'expiring' => 0, 'expired' => 0, 'missing' => 0];
                $g[$k]['workers'][$r->worker_id] = true;
                $g[$k]['required']++;
                $g[$k][$r->state]++;
            }
            ksort($g);

            return array_values(array_map(fn ($x) => ['workers' => count($x['workers']), 'pct' => $x['required'] ? (int) round(($x['valid'] + $x['expiring']) * 100 / $x['required']) : null] + $x, $g));
        };
        $cert = DB::selectOne("select count(*) as issued, count(*) filter (where status in ('validated', 'expired')) as validated, count(*) filter (where status = 'pending_validation') as pending,
                                      count(*) filter (where status = 'voided') as voided
                                 from training_certificates where deleted_at is null and extract(year from issued_on) = ?", [$year]);
        $co = DB::selectOne('select legal_name, trade_name, ruc from companies where id = ?', [app(CompanyContext::class)->companyId]);

        return ['year' => $year, 'company' => ['name' => $co->trade_name ?: $co->legal_name, 'legal_name' => $co->legal_name, 'ruc' => trim((string) $co->ruc)],
            'topics' => $topics, 'permanence' => $permanence,
            'compliance' => ['by_area' => $group('area'), 'by_position' => $group('position')],
            'certificates' => ['issued' => (int) $cert->issued, 'validated' => (int) $cert->validated, 'pending' => (int) $cert->pending, 'voided' => (int) $cert->voided]];
    }

    public function verification(): JsonResponse
    {
        $this->can('cap.trainings.read');

        $company = app(CompanyContext::class)->companyId;

        return response()->json(['data' => ['enabled' => LegalParams::flag('certificate_public_verification', false),
            'trainings' => DB::select('select id, title from trainings where deleted_at is null and public_verification_enabled order by title'),
            'page_text' => LegalParams::text('certificate_verification_page_text', ''),
            'rate_limit' => (int) LegalParams::number('certificate_verify_rate_limit', 30),
            'page_url' => rtrim((string) config('escudo.frontend_url'), '/').'/verificar/empresa/'.$company,
            'can_edit' => in_array('cap.trainings.update', Permissions::effective(), true)]]);
    }

    public function setVerification(Request $request): JsonResponse
    {
        $this->can('cap.trainings.update');
        $d = $request->validate(['enabled' => ['sometimes', 'boolean'], 'page_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'rate_limit' => ['sometimes', 'integer', 'min:1', 'max:600']],
            ['rate_limit.min' => 'El límite debe ser de al menos 1 consulta por minuto.', 'rate_limit.max' => 'El límite no puede pasar de 600 consultas por minuto.',
                'page_text.max' => 'El texto de la página admite hasta 2000 caracteres.']);
        if (array_key_exists('page_text', $d)) {
            LegalParams::setForCompany('certificate_verification_page_text', trim((string) $d['page_text']));
        }
        if (isset($d['rate_limit'])) {
            LegalParams::setForCompany('certificate_verify_rate_limit', (string) (int) $d['rate_limit']);
        }
        if (! array_key_exists('enabled', $d)) {
            return $this->verification();
        }
        $on = (bool) $d['enabled'];
        LegalParams::setForCompany('certificate_public_verification', $on ? 'true' : 'false');
        // Los certificados de los temas verificables siguen la decisión de la empresa.
        DB::update('update training_certificates c set public_verification_enabled = ? from trainings t where t.id = c.training_id and t.public_verification_enabled and c.deleted_at is null',
            [$on ? 'true' : 'false']);
        if (! $on) {
            DB::update('update training_certificates set public_verification_enabled = false where training_id is not null and deleted_at is null');
        }

        return response()->json(['data' => ['enabled' => $on]]);
    }

    public function trainerCheck(string $id): JsonResponse
    {
        $this->can('cap.trainings.read');
        $s = Str::isUuid($id) ? DB::selectOne('select instructor_user_id, starts_at, ends_at from training_sessions where id = ? and deleted_at is null', [$id]) : null;
        if (! $s) {
            throw ApiProblem::notFound('La sesión no existe.');
        }

        return response()->json(['data' => ['warning' => self::trainerWarning($s->instructor_user_id, $s->starts_at, $s->ends_at)]]);
    }

    /** RN-CAP-16: texto de advertencia si el capacitador no tiene disponibilidad en ese horario (o lo tiene bloqueado); null si está disponible. */
    public static function trainerWarning(?string $trainer, ?string $starts, ?string $ends): ?string
    {
        if (! $trainer || ! $starts || ! $ends) {
            return null;
        }
        $from = (new \DateTimeImmutable($starts))->setTimezone(new \DateTimeZone('America/Lima'));
        $to = (new \DateTimeImmutable($ends))->setTimezone(new \DateTimeZone('America/Lima'));
        $day = $from->format('Y-m-d');
        $blocked = DB::selectOne("select reason from trainer_availability where trainer_user_id = ? and deleted_at is null and kind = 'blocked'
                                    and date_from <= ?::date and coalesce(date_to, date_from) >= ?::date limit 1", [$trainer, $day, $day]);
        if ($blocked) {
            return 'El capacitador tiene ese día bloqueado'.($blocked->reason ? " ({$blocked->reason})" : '').'.';
        }
        $ok = DB::selectOne("select 1 as ok from trainer_availability where trainer_user_id = ? and deleted_at is null
                               and ((kind = 'weekly' and weekday = ? and start_time <= ?::time and end_time >= ?::time)
                                 or (kind = 'extra' and date_from <= ?::date and coalesce(date_to, date_from) >= ?::date
                                     and (start_time is null or start_time <= ?::time) and (end_time is null or end_time >= ?::time)))",
            [$trainer, (int) $from->format('N'), $from->format('H:i'), $to->format('H:i'), $day, $day, $from->format('H:i'), $to->format('H:i')]);
        if ($ok) {
            return null;
        }
        $any = DB::selectOne('select 1 as ok from trainer_availability where trainer_user_id = ? and deleted_at is null limit 1', [$trainer]);

        return $any ? 'El capacitador no tiene disponibilidad registrada en ese horario.' : null;
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden();
        }
    }
}

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
 *  GET  /cap/reports.xlsx?year=         SCR-CAP-14: por tema, sesiones, participantes, aprobados, desaprobados y horas-hombre por modalidad
 *  GET  /cap/verification               SCR-CAP-13: verificación pública activada para la empresa y temas verificables
 *  PUT  /cap/verification {enabled}     activa o desactiva la verificación pública de la empresa (RN-CAP-12)
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
        $c = DB::selectOne('select legal_name, ruc from companies where id = ?', [app(CompanyContext::class)->companyId]);
        $out = [["REPORTE DE CAPACITACIONES {$year}"], ["{$c->legal_name} · RUC ".trim((string) $c->ruc)], ['Generado: '.now('America/Lima')->format('d/m/Y H:i')], [],
            ['Tema', 'Modalidad', 'Sesiones realizadas', 'Participantes', 'Aprobados (certificados)', 'Desaprobados o sin certificado', 'Horas-hombre presenciales', 'Horas-hombre en el aula']];
        $mod = ['in_person' => 'Presencial', 'virtual' => 'Virtual', 'blended' => 'Mixta'];
        foreach ($rows as $r) {
            if ((int) $r->sessions === 0 && (int) $r->approved === 0) {
                continue;
            }
            $out[] = [$r->title, $mod[$r->modality] ?? $r->modality, (int) $r->sessions, (int) $r->participants, (int) $r->approved, max(0, (int) $r->participants - (int) $r->approved),
                round((float) $r->hours_in_person, 1), round((float) $r->hours_aula, 1)];
        }

        return response(Xlsx::build('Capacitaciones', $out, 5), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="reporte-capacitaciones-'.$year.'.xlsx"',
        ]);
    }

    public function verification(): JsonResponse
    {
        $this->can('cap.trainings.read');

        return response()->json(['data' => ['enabled' => LegalParams::flag('certificate_public_verification', false),
            'trainings' => DB::select('select id, title from trainings where deleted_at is null and public_verification_enabled order by title'),
            'can_edit' => in_array('cap.trainings.update', Permissions::effective(), true)]]);
    }

    public function setVerification(Request $request): JsonResponse
    {
        $this->can('cap.trainings.update');
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
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

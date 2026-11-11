<?php

namespace App\Modules\AUL;

use App\Core\Http\ApiProblem;
use App\Core\Support\LegalParams;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Conexión opcional con el Moodle de la empresa (M-AUL SCR-AUL-19, docs/09 §11). Una conexión por empresa.
 * El token del servicio web se guarda cifrado (lms_connections.token_enc) y nunca vuelve al navegador.
 *  GET  /aul/admin/moodle                       conexión (sin token: «configurado · termina en …») y cursos con su curso de Moodle
 *  PUT  /aul/admin/moodle                       {base_url, token?, sync_enrollments, sync_grades, enabled} (token vacío = se conserva)
 *  POST /aul/admin/moodle/test                  probar conexión (core_webservice_get_site_info)
 *  PUT  /aul/admin/moodle/courses/{id}          {external_course_id|null} enlazar o desenlazar un curso con su id en Moodle
 *  POST /aul/admin/moodle/courses/{id}/push     enviar matrículas a Moodle (busca o crea a la persona por su correo)
 *  POST /aul/admin/moodle/courses/{id}/pull     traer notas finales de Moodle a las matrículas
 * A Moodle solo van nombre y correo del participante (RN-AUL-36). Tras «lms_sync_failure_threshold» fallas seguidas la
 * conexión queda en «error» y se avisa al administrador de la cuenta y al Jefe SST (correo y app).
 */
class MoodleController extends Controller
{
    public function show(): JsonResponse
    {
        $c = $this->conn();
        $courses = DB::select(
            "select c.id, c.code, c.title, c.status, c.external_course_id, (c.lms_connection_id is not null and c.lms_connection_id = ?) as linked,
                    (select count(*) from course_enrollments e where e.course_id = c.id and e.deleted_at is null and e.status <> 'cancelled' and e.worker_id is not null) as enrolled,
                    (select count(*) from course_enrollments e where e.course_id = c.id and e.deleted_at is null and e.external_enrollment_id is not null) as synced
               from courses c where c.deleted_at is null and c.company_id is not null and c.status <> 'archived' order by c.code", [$c?->id]);

        return response()->json(['data' => [
            'connection' => $c ? [
                'id' => $c->id, 'base_url' => $c->base_url, 'status' => $c->status, 'sync_enrollments' => (bool) $c->sync_enrollments, 'sync_grades' => (bool) $c->sync_grades,
                'token_set' => $this->tokenHint($c), 'last_tested_at' => $c->last_tested_at, 'last_sync_at' => $c->last_sync_at, 'last_sync_status' => $c->last_sync_status,
                'last_error' => $c->last_error, 'site_name' => json_decode($c->settings ?: '{}', true)['site_name'] ?? null,
            ] : null,
            'courses' => array_map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'title' => $r->title, 'status' => $r->status,
                'external_course_id' => $r->linked ? $r->external_course_id : null, 'enrolled' => (int) $r->enrolled, 'synced' => (int) $r->synced], $courses),
        ]]);
    }

    public function save(Request $request): JsonResponse
    {
        $d = $request->validate([
            'base_url' => ['required', 'string', 'max:300', 'regex:#^https://[^\s/]+#i'],
            'token' => ['nullable', 'string', 'max:200'],
            'sync_enrollments' => ['boolean'], 'sync_grades' => ['boolean'], 'enabled' => ['boolean'],
        ], ['base_url.regex' => 'La dirección de Moodle debe empezar con https://', 'base_url.required' => 'Escribe la dirección de tu Moodle.']);
        $c = $this->conn();
        $token = trim((string) ($d['token'] ?? ''));
        if (! $c && $token === '') {
            $this->fail('token', 'Pega el token del servicio web de Moodle.');
        }
        $url = rtrim(trim($d['base_url']), '/');
        $status = ($d['enabled'] ?? true) ? ($c && $c->status === 'active' && $token === '' && $c->base_url === $url ? 'active' : 'pending') : 'disabled';
        $me = (string) auth()->id();
        if ($c) {
            DB::update('update lms_connections set base_url = ?, token_enc = coalesce(?, token_enc), sync_enrollments = ?, sync_grades = ?, status = ?, updated_at = now(), updated_by = ? where id = ?',
                [$url, $token !== '' ? Crypt::encryptString($token) : null, ($d['sync_enrollments'] ?? true) ? 'true' : 'false', ($d['sync_grades'] ?? true) ? 'true' : 'false', $status, $me, $c->id]);
        } else {
            DB::insert(
                "insert into lms_connections (company_id, provider, name, base_url, credentials_secret_ref, token_enc, sync_direction, sync_enrollments, sync_grades, status, created_by, updated_by)
                 values (?, 'moodle', 'Moodle', ?, 'app-encrypted://lms_connections/token_enc', ?, 'both', ?, ?, ?, ?, ?)",
                [$this->company(), $url, Crypt::encryptString($token), ($d['sync_enrollments'] ?? true) ? 'true' : 'false', ($d['sync_grades'] ?? true) ? 'true' : 'false', $status, $me, $me]);
        }
        // En la bitácora: qué cambió, nunca el token.
        $this->audit('lms_connection_saved', (string) $this->conn()?->id, ['base_url' => $url, 'token_changed' => $token !== '', 'status' => $status]);

        return $this->show();
    }

    public function test(): JsonResponse
    {
        $c = $this->conn() ?? throw ApiProblem::notFound('Primero guarda la dirección y el token de Moodle.');
        try {
            $info = $this->client($c)->siteInfo();
            $needed = ['core_user_get_users_by_field', 'core_user_create_users', 'enrol_manual_enrol_users', 'gradereport_user_get_grade_items'];
            $missing = $info['functions'] ? array_values(array_diff($needed, $info['functions'])) : [];
            $settings = json_decode($c->settings ?: '{}', true) ?: [];
            $settings['site_name'] = $info['sitename'];
            $settings['fail_count'] = 0;
            DB::update("update lms_connections set status = case when status = 'disabled' then status else 'active' end, last_tested_at = now(), last_error = null, settings = ?::jsonb,
                               updated_at = now() where id = ?", [json_encode($settings, JSON_UNESCAPED_UNICODE), $c->id]);
            $msg = "Conexión correcta con «{$info['sitename']}»".($info['release'] ? " (Moodle {$info['release']})" : '').'.';
            if ($missing) {
                $msg .= ' Al servicio web le faltan estas funciones: '.implode(', ', $missing).'.';
            }

            return response()->json(['data' => ['ok' => true, 'message' => $msg, 'missing' => $missing]]);
        } catch (Throwable $e) {
            DB::update("update lms_connections set last_tested_at = now(), last_error = ?, status = case when status = 'disabled' then status else 'error' end, updated_at = now() where id = ?",
                [mb_substr($e->getMessage(), 0, 300), $c->id]);

            return response()->json(['data' => ['ok' => false, 'message' => mb_substr($e->getMessage(), 0, 300), 'missing' => []]]);
        }
    }

    public function link(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['external_course_id' => ['nullable', 'regex:/^\d{1,10}$/']], ['external_course_id.regex' => 'El id del curso en Moodle es un número (lo ves en la dirección: course/view.php?id=…).']);
        $c = $this->conn() ?? throw ApiProblem::notFound('Primero configura la conexión con Moodle.');
        $course = $this->course($id);
        $ext = $d['external_course_id'] ?? null;
        DB::update('update courses set lms_connection_id = ?, external_course_id = ?, updated_at = now(), updated_by = ? where id = ?',
            [$ext ? $c->id : null, $ext, (string) auth()->id(), $course->id]);

        return response()->json(['data' => ['id' => $course->id, 'external_course_id' => $ext]]);
    }

    public function push(string $id): JsonResponse
    {
        [$c, $course] = $this->ready($id, 'sync_enrollments', 'El envío de matrículas está desactivado en la conexión.');
        $client = $this->client($c);
        $rows = DB::select(
            "select e.id, e.external_enrollment_id, w.first_name, w.paternal_last_name, w.maternal_last_name, w.personal_email, u.email as user_email
               from course_enrollments e join workers w on w.id = e.worker_id left join users u on u.id = w.user_id and u.deleted_at is null
              where e.course_id = ? and e.deleted_at is null and e.status <> 'cancelled' and e.worker_id is not null order by e.enrolled_at", [$course->id]);
        $done = $skipped = 0;
        $errors = [];
        foreach ($rows as $r) {
            if ($r->external_enrollment_id) {
                continue;   // ya enviada (idempotencia por external_enrollment_id, RN-AUL-23)
            }
            $email = $r->user_email ?: $this->plain($r->personal_email);
            if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;

                continue;
            }
            try {
                $uid = $client->userId($email, (string) $r->first_name, trim($r->paternal_last_name.' '.$r->maternal_last_name));
                $client->enrol([['userid' => $uid, 'courseid' => (int) $course->external_course_id]], (int) (json_decode($c->settings ?: '{}', true)['student_role_id'] ?? 5));
                DB::update('update course_enrollments set external_enrollment_id = ?, last_synced_at = now(), updated_at = now() where id = ?', ["{$uid}:{$course->external_course_id}", $r->id]);
                $done++;
            } catch (Throwable $e) {
                $errors[] = mb_substr($e->getMessage(), 0, 200);
                if (count($errors) >= 3) {
                    break;   // Moodle no responde bien: no se insiste con el resto
                }
            }
        }
        $this->finish($c, $errors, $done);

        return response()->json(['data' => ['sent' => $done, 'skipped_without_email' => $skipped, 'errors' => $errors]]);
    }

    public function pull(string $id): JsonResponse
    {
        [$c, $course] = $this->ready($id, 'sync_grades', 'La lectura de notas está desactivada en la conexión.');
        $client = $this->client($c);
        $rows = DB::select("select id, external_enrollment_id from course_enrollments where course_id = ? and deleted_at is null and external_enrollment_id is not null", [$course->id]);
        $updated = $pending = 0;
        $errors = [];
        foreach ($rows as $r) {
            [$uid] = explode(':', (string) $r->external_enrollment_id) + [null];
            if (! ctype_digit((string) $uid)) {
                continue;
            }
            try {
                $g = $client->courseGrade((int) $course->external_course_id, (int) $uid);
                if (! $g) {
                    $pending++;

                    continue;
                }
                DB::update('update course_enrollments set final_score = ?, final_score_label = ?, last_synced_at = now(), updated_at = now() where id = ?', [$g[0], mb_substr($g[1], 0, 60), $r->id]);
                $updated++;
            } catch (Throwable $e) {
                $errors[] = mb_substr($e->getMessage(), 0, 200);
                if (count($errors) >= 3) {
                    break;
                }
            }
        }
        $this->finish($c, $errors, $updated);

        return response()->json(['data' => ['updated' => $updated, 'without_grade' => $pending, 'errors' => $errors]]);
    }

    // ---------------------------------------------------------------- apoyo

    private function conn(): ?object
    {
        return DB::selectOne("select * from lms_connections where deleted_at is null and provider = 'moodle' order by created_at limit 1");
    }

    private function client(object $c): MoodleClient
    {
        try {
            $token = Crypt::decryptString((string) $c->token_enc);
        } catch (Throwable) {
            throw new ApiProblem(409, 'conflict', 'Falta el token', 'Vuelve a pegar el token del servicio web de Moodle.');
        }

        return new MoodleClient($c->base_url, $token);
    }

    /** @return array{0: object, 1: object} */
    private function ready(string $id, string $flag, string $off): array
    {
        $c = $this->conn() ?? throw ApiProblem::notFound('Primero configura la conexión con Moodle.');
        if ($c->status === 'disabled') {
            throw new ApiProblem(409, 'conflict', 'Conexión desactivada', 'La conexión con Moodle está desactivada.');
        }
        if (! $c->{$flag}) {
            throw new ApiProblem(409, 'conflict', 'Opción desactivada', $off);
        }
        $course = $this->course($id);
        if (! $course->external_course_id || $course->lms_connection_id !== $c->id) {
            $this->fail('external_course_id', 'Enlaza el curso con su id en Moodle primero.');
        }

        return [$c, $course];
    }

    private function course(string $id): object
    {
        return DB::selectOne('select id, code, title, external_course_id, lms_connection_id from courses where id = ? and deleted_at is null and company_id is not null', [$id])
            ?? throw ApiProblem::notFound('El curso no existe.');
    }

    /** Resultado de la sincronización; tras varias fallas seguidas la conexión pasa a «error» y se avisa. */
    private function finish(object $c, array $errors, int $ok): void
    {
        $settings = json_decode($c->settings ?: '{}', true) ?: [];
        $fails = $errors && ! $ok ? (int) ($settings['fail_count'] ?? 0) + 1 : 0;
        $settings['fail_count'] = $fails;
        $threshold = (int) LegalParams::number('lms_sync_failure_threshold', 3);
        $status = $fails >= $threshold ? 'error' : ($c->status === 'error' && ! $errors ? 'active' : $c->status);
        DB::update('update lms_connections set last_sync_at = now(), last_sync_status = ?, last_error = ?, settings = ?::jsonb, status = ?, updated_at = now() where id = ?',
            [$errors ? ($ok ? 'partial' : 'failed') : 'ok', $errors[0] ?? null, json_encode($settings, JSON_UNESCAPED_UNICODE), $status, $c->id]);
        if ($fails >= $threshold) {
            foreach (DB::select("select distinct m.user_id from memberships m join roles r on r.id = m.role_id
                                   where m.company_id = ? and m.status = 'active' and m.deleted_at is null and coalesce(r.base_role_code, r.code) in ('account_admin', 'sst_manager')", [$this->company()]) as $u) {
                DB::insert(
                    "insert into notifications (company_id, event_code, subject_type, subject_id, recipient_user_id, title, body, link, priority, dedupe_key)
                     values (?, 'aul.lms_sync_failed', 'lms_connections', ?, ?, 'Falla la sincronización con Moodle', ?, '/m/aul', 'high', ?) on conflict do nothing",
                    [$this->company(), $c->id, $u->user_id, "La conexión con Moodle falló {$fails} veces seguidas: revisa la dirección y el token.",
                        'aul.lms_sync_failed:'.$c->id.':'.date('Y-m-d').':'.$u->user_id]
                );
            }
        }
    }

    private function tokenHint(object $c): ?string
    {
        try {
            $t = $c->token_enc ? Crypt::decryptString($c->token_enc) : '';
        } catch (Throwable) {
            return null;
        }

        return $t !== '' ? 'configurado · termina en '.mb_substr($t, -4) : null;
    }

    private function plain(?string $v): ?string
    {
        if (! $v) {
            return null;
        }
        try {
            return Crypt::decryptString($v);
        } catch (Throwable) {
            return $v;
        }
    }

    private function audit(string $action, string $id, array $changes): void
    {
        $ctx = app(CompanyContext::class);
        DB::insert(
            "insert into audit_logs (company_id, actor_user_id, actor_role, actor_type, action, module_code, entity_type, entity_id, changes, ip, user_agent)
             values (?, ?, ?, 'user', ?, 'AUL', 'lms_connections', ?, ?::jsonb, ?::inet, ?)",
            [$ctx->companyId, (string) auth()->id(), $ctx->roleCode, $action, $id, json_encode($changes, JSON_UNESCAPED_UNICODE), request()->ip(), mb_substr((string) request()->userAgent(), 0, 300)]
        );
    }

    private function company(): string
    {
        return (string) app(CompanyContext::class)->companyId;
    }

    private function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }
}

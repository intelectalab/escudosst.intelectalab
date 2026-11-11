<?php

namespace App\Modules\AUL;

use Illuminate\Support\Facades\DB;

/**
 * Tiempo de permanencia real (M-AUL «Medición de permanencia», RN-AUL-07/08/09):
 *  - El reproductor envía un latido cada `aul_heartbeat_seconds` (30 por defecto) mientras hay interacción y la
 *    pestaña está visible; cada latido aporta como máximo ese tiempo.
 *  - Si pasan `aul_idle_minutes` (5 por defecto) sin latidos, la sesión se cierra por inactividad y ese tiempo no suma.
 *  - Una sola sesión abierta por matrícula: otra pestaña o dispositivo cierra la anterior como «sesión reemplazada».
 *  - Un latido fuera de secuencia no suma y marca la sesión para revisar (posible automatización).
 * Los dos valores son parámetros de la empresa (tabla legal_parameters), no constantes de ley.
 */
final class Heartbeats
{
    public static function beatSeconds(): int
    {
        return self::param('aul_heartbeat_seconds', 30, 10, 300);
    }

    public static function idleSeconds(): int
    {
        return self::param('aul_idle_minutes', 5, 1, 120) * 60;
    }

    /** @param array{client_session_id: string, seq: int, lesson_id?: ?string, interactions?: ?int, video_seconds?: ?int, visible?: bool, ended?: bool, device_type?: ?string} $d */
    public static function beat(object $e, array $d): array
    {
        $beat = self::beatSeconds();
        $idle = self::idleSeconds();
        $now = time();
        $lesson = $d['lesson_id'] ?? null;
        $open = DB::select('select * from learning_activity_sessions where enrollment_id = ? and ended_at is null and deleted_at is null order by started_at desc for update', [$e->id]);
        $current = null;
        foreach ($open as $s) {
            $stale = $now - strtotime($s->last_heartbeat_at) > $idle;
            if (self::base($s->client_session_id) !== $d['client_session_id']) {
                self::close($s->id, $stale ? 'idle_timeout' : 'superseded');   // RN-AUL-08
            } elseif ($stale) {
                self::close($s->id, 'idle_timeout');                            // RN-AUL-07: lo inactivo no suma
            } elseif ($s->lesson_id !== $lesson) {
                self::close($s->id, 'closed');                                  // cambió de actividad: una sesión por actividad
            } else {
                $current = $s;
            }
        }
        $credited = 0;
        $accepted = true;
        if ($current) {
            if ((int) $d['seq'] <= (int) $current->last_heartbeat_seq) {
                $accepted = false;   // RN-AUL-09: fuera de secuencia (repetido o reenviado)
                DB::update('update learning_activity_sessions set is_flagged = true, updated_at = now() where id = ?', [$current->id]);
            } else {
                $active = ($d['visible'] ?? true) && ((int) ($d['interactions'] ?? 0) > 0 || (int) ($d['video_seconds'] ?? 0) > 0);
                $credited = $active ? max(0, min($now - strtotime($current->last_heartbeat_at), $beat)) : 0;
                $video = min((int) ($d['video_seconds'] ?? 0), $beat);
                DB::update('update learning_activity_sessions set last_heartbeat_at = now(), last_heartbeat_seq = ?, active_seconds = active_seconds + ?, video_seconds = video_seconds + ?,
                              interactions_count = interactions_count + ?, heartbeats_count = heartbeats_count + 1, updated_at = now() where id = ?',
                    [(int) $d['seq'], $credited, $video, (int) ($d['interactions'] ?? 0), $current->id]);
            }
        } else {
            // Una sesión nueva por actividad o tras inactividad: el identificador del cliente se numera (#2, #3…).
            $used = (int) DB::selectOne("select count(*) as n from learning_activity_sessions where enrollment_id = ? and deleted_at is null and (client_session_id = ? or client_session_id like ?)",
                [$e->id, $d['client_session_id'], str_replace(['%', '_'], ['\%', '\_'], $d['client_session_id']).'#%'])->n;
            $clientId = $used ? $d['client_session_id'].'#'.($used + 1) : $d['client_session_id'];
            $current = DB::selectOne(
                'insert into learning_activity_sessions (company_id, enrollment_id, lesson_id, client_session_id, started_at, last_heartbeat_at, last_heartbeat_seq, heartbeats_count,
                    interactions_count, device_type, user_agent, ip_hash, created_by, updated_by)
                 values (?, ?, ?, ?, now(), now(), ?, 1, ?, ?, ?, decode(?, \'hex\'), ?, ?) returning id',
                [$e->company_id, $e->id, $lesson, $clientId, (int) $d['seq'], (int) ($d['interactions'] ?? 0), $d['device_type'] ?? null,
                    mb_substr((string) request()->userAgent(), 0, 300), hash('sha256', (string) request()->ip()), (string) auth()->id(), (string) auth()->id()]
            );
        }
        if (! empty($d['ended'])) {
            self::close($current->id, 'closed');
        }
        $total = (int) DB::selectOne("update course_enrollments set active_seconds_total = active_seconds_total + ?, last_activity_at = now(), started_at = coalesce(started_at, now()),
                                        status = case when status = 'enrolled' then 'in_progress' else status end where id = ? returning active_seconds_total", [$credited, $e->id])->active_seconds_total;
        // Con todo lo obligatorio hecho, la permanencia que faltaba puede completar el curso (RN-AUL-10).
        $fresh = DB::selectOne('select * from course_enrollments where id = ?', [$e->id]);
        if ($credited > 0 && $fresh->status === 'in_progress' && (float) $fresh->progress_pct >= 100) {
            Progress::mark($fresh, null);
        }

        return ['accepted' => $accepted, 'credited_seconds' => $credited, 'active_seconds_total' => $total, 'session_id' => $current->id, 'heartbeat_seconds' => $beat];
    }

    private static function base(string $clientId): string
    {
        return preg_replace('/#\d+$/', '', $clientId);
    }

    private static function close(string $id, string $reason): void
    {
        DB::update('update learning_activity_sessions set ended_at = greatest(last_heartbeat_at, started_at), end_reason = ?, updated_at = now() where id = ?', [$reason, $id]);
    }

    private static function param(string $key, int $default, int $min, int $max): int
    {
        $v = DB::selectOne(
            'select value from legal_parameters where key = ? and (company_id is null or company_id = nullif(current_setting(\'app.company_id\', true), \'\')::uuid)
                and valid_from <= current_date and (valid_to is null or valid_to >= current_date) and deleted_at is null order by company_id nulls last, valid_from desc limit 1',
            [$key]
        );

        return is_numeric($v->value ?? null) ? max($min, min($max, (int) $v->value)) : $default;
    }
}

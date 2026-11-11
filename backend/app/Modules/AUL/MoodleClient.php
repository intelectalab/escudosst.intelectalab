<?php

namespace App\Modules\AUL;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * LmsProvider de Moodle (docs/09 §11, RN-AUL-22): servicios web REST de Moodle con token de servicio.
 *  {base}/webservice/rest/server.php?wstoken=…&wsfunction=…&moodlewsrestformat=json
 * Funciones usadas: core_webservice_get_site_info (probar), core_user_get_users_by_field y core_user_create_users
 * (buscar o crear a la persona por su correo), enrol_manual_enrol_users (matricular) y gradereport_user_get_grade_items
 * (leer notas). Moodle responde 200 aun con error: se revisa «exception». El token nunca se registra.
 */
final class MoodleClient
{
    public function __construct(private string $baseUrl, private string $token) {}

    /** @return array<string, mixed> */
    public function call(string $function, array $params = []): mixed
    {
        try {
            $r = Http::asForm()->timeout(30)->acceptJson()->post(rtrim($this->baseUrl, '/').'/webservice/rest/server.php', [
                'wstoken' => $this->token, 'wsfunction' => $function, 'moodlewsrestformat' => 'json',
            ] + $params);
        } catch (\Throwable $e) {
            throw new RuntimeException('No se pudo conectar con Moodle: '.$this->redact($e->getMessage()));
        }
        if (! $r->successful()) {
            throw new RuntimeException("Moodle respondió {$r->status()} en {$function}.");
        }
        $json = $r->json();
        if (is_array($json) && isset($json['exception'])) {
            throw new RuntimeException('Moodle: '.$this->redact((string) ($json['message'] ?? $json['errorcode'] ?? 'error')).' ('.$function.')');
        }
        if ($json === null && ! in_array(trim($r->body()), ['', 'null'], true)) {
            throw new RuntimeException("Respuesta no válida de Moodle en {$function}: ¿la dirección es la del sitio Moodle?");
        }

        return $json;
    }

    /** @return array{sitename: string, release: ?string, functions: list<string>} */
    public function siteInfo(): array
    {
        $i = (array) $this->call('core_webservice_get_site_info');

        return ['sitename' => (string) ($i['sitename'] ?? ''), 'release' => $i['release'] ?? null,
            'functions' => array_values(array_map(fn ($f) => (string) ($f['name'] ?? ''), (array) ($i['functions'] ?? [])))];
    }

    /** Busca a la persona por su correo; si no existe la crea (contraseña la genera Moodle y se la envía). */
    public function userId(string $email, string $first, string $last): int
    {
        $found = (array) $this->call('core_user_get_users_by_field', ['field' => 'email', 'values' => [$email]]);
        if (isset($found[0]['id'])) {
            return (int) $found[0]['id'];
        }
        $made = (array) $this->call('core_user_create_users', ['users' => [[
            'username' => mb_strtolower($email), 'email' => $email, 'firstname' => $first ?: '-', 'lastname' => $last ?: '-', 'auth' => 'manual', 'createpassword' => 1,
        ]]]);
        if (! isset($made[0]['id'])) {
            throw new RuntimeException('Moodle no devolvió el usuario creado.');
        }

        return (int) $made[0]['id'];
    }

    /** @param list<array{userid: int, courseid: int}> $items */
    public function enrol(array $items, int $roleId = 5): void
    {
        if ($items) {
            $this->call('enrol_manual_enrol_users', ['enrolments' => array_map(fn ($i) => ['roleid' => $roleId, 'userid' => $i['userid'], 'courseid' => $i['courseid']], $items)]);
        }
    }

    /** Nota final del curso: [nota en %, texto como la muestra Moodle] o null si aún no tiene. */
    public function courseGrade(int $courseId, int $userId): ?array
    {
        $r = (array) $this->call('gradereport_user_get_grade_items', ['courseid' => $courseId, 'userid' => $userId]);
        foreach ((array) ($r['usergrades'][0]['gradeitems'] ?? []) as $item) {
            if (($item['itemtype'] ?? '') !== 'course') {
                continue;
            }
            $raw = $item['graderaw'] ?? null;
            if ($raw === null || $raw === '') {
                return null;
            }
            $min = (float) ($item['grademin'] ?? 0);
            $max = (float) ($item['grademax'] ?? 100);
            $pct = $max > $min ? round(((float) $raw - $min) * 100 / ($max - $min), 2) : (float) $raw;

            return [max(0, min(100, $pct)), (string) ($item['gradeformatted'] ?? $raw)];
        }

        return null;
    }

    private function redact(string $msg): string
    {
        return mb_substr($this->token !== '' ? str_replace($this->token, '***', $msg) : $msg, 0, 300);
    }
}

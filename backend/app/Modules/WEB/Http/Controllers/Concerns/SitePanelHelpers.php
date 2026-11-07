<?php

namespace App\Modules\WEB\Http\Controllers\Concerns;

use App\Core\Http\ApiProblem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Apoyo común de las pantallas del sitio en el panel: conexión, errores por campo, textos y medios de la biblioteca. */
trait SitePanelHelpers
{
    protected const MESSAGES = [
        'required' => 'Completa este campo.',
        'email' => 'Escribe un correo válido.',
        'max' => 'Es muy largo.',
        'min' => 'Es muy corto.',
        'in' => 'Elige una opción de la lista.',
        'regex' => 'Solo minúsculas, números y guiones.',
        'uuid' => 'Elige una opción de la lista.',
        'date' => 'Escribe una fecha válida.',
        'integer' => 'Escribe un número entero.',
        'between' => 'Revisa el número: está fuera del rango permitido.',
        'url' => 'El enlace no es válido.',
        'starts_with' => 'El enlace debe empezar con https://.',
        'after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        'after' => 'La fecha final debe ser posterior a la inicial.',
        'accepted' => 'Acepta la política de privacidad para continuar.',
    ];

    protected function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    protected function fail(string $field, string $msg): never
    {
        throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => [$field => [$msg]]]);
    }

    protected function conflict(string $msg): never
    {
        throw new ApiProblem(409, 'conflict', 'No se puede hacer', $msg);
    }

    protected function clean(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    protected function row(string $table, string $id, string $missing): object
    {
        $r = Str::isUuid($id) ? $this->db()->selectOne("select * from {$table} where id = ? and deleted_at is null", [$id]) : null;

        return $r ?? throw ApiProblem::notFound($missing);
    }

    /** Medio de la biblioteca (site_media) del tipo indicado; null si no se eligió. */
    protected function media(?string $mediaId, string $field, array $kinds = ['image'], string $msg = 'Elige una imagen de la biblioteca de medios.'): ?string
    {
        if (! $mediaId) {
            return null;
        }
        $ok = Str::isUuid($mediaId) && $this->db()->selectOne(
            'select 1 from site_media where id = ? and kind = any(?::text[]) and deleted_at is null', [$mediaId, '{'.implode(',', $kinds).'}']
        );

        return $ok ? $mediaId : $this->fail($field, $msg);
    }

    /** Frase bloqueada por los lineamientos de contenido (RN-WEB-58) dentro del texto, o null. */
    protected function blockedTerm(string $text): ?string
    {
        return $this->db()->selectOne(
            'select web_blocked_term(?, coalesce((select content_blocked_terms from site_settings where singleton), \'[]\'::jsonb)) as t', [$text]
        )->t;
    }

    protected function assertAllowedText(string $field, string ...$texts): void
    {
        if ($term = $this->blockedTerm(implode(' ', array_filter($texts)))) {
            $this->fail($field, "No se puede publicar «{$term}»: es una afirmación que no podemos respaldar (Sitio web › Lineamientos de contenido).");
        }
    }
}

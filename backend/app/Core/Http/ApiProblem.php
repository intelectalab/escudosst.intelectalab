<?php

namespace App\Core\Http;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Error de la API en formato application/problem+json (docs/08-api.md §7).
 * Los textos van en español (Perú).
 */
class ApiProblem extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra  Campos adicionales (module, required_plan, errors...)
     */
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly array $extra = [],
    ) {
        parent::__construct($title);
    }

    public static function unauthenticated(string $detail = 'Inicia sesión para continuar.'): self
    {
        return new self(401, 'unauthenticated', 'No autenticado', $detail);
    }

    public static function forbidden(string $detail = 'No tienes permiso para realizar esta acción.'): self
    {
        return new self(403, 'forbidden', 'Acceso denegado', $detail);
    }

    public static function notFound(string $detail = 'El recurso no existe.'): self
    {
        return new self(404, 'not_found', 'No encontrado', $detail);
    }

    public static function badRequest(string $detail): self
    {
        return new self(400, 'bad_request', 'Petición inválida', $detail);
    }

    /** Transición de estado no permitida (docs/08 §6 y §7, docs/02 §11): 409 con el código estable invalid_transition. */
    public static function invalidTransition(string $detail): self
    {
        return new self(409, 'invalid_transition', 'No se puede hacer este cambio', $detail);
    }

    public function toResponse(?string $traceId = null): JsonResponse
    {
        return self::make($this->status, $this->type, $this->title, $this->detail, $this->extra, $traceId);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function make(int $status, string $type, string $title, ?string $detail = null, array $extra = [], ?string $traceId = null): JsonResponse
    {
        $body = array_filter([
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], fn ($v) => $v !== null) + $extra;

        if ($traceId !== null) {
            $body['trace_id'] = $traceId;
        }

        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']);
    }
}

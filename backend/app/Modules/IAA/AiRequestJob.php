<?php

namespace App\Modules\IAA;

use App\Core\Tenancy\CompanyContext;
use App\Core\Tenancy\DbContext;
use App\Modules\NOT\Notify;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Solicitud al proveedor de IA en segundo plano (docs/17 §2 y §3.6: «llamadas solo desde workers»):
 * ai_requests queued → running → completed / failed. Corre con la empresa y el usuario que la pidieron fijados en la conexión
 * (RLS); recibe la entrada ya redactada (sin nombres, documentos, teléfonos, correos ni datos de salud). La respuesta se valida
 * contra el esquema del caso (RN-IAA-04): si no lo cumple se descarta y la solicitud queda «failed». Al terminar avisa en la
 * campana «Sugerencia lista» (M-IAA §10). La sugerencia queda pendiente: nunca se aplica sola (RN-IAA-01).
 *
 * Casos: controls, causes, talk (consultas rápidas) y minutes_draft (IA-04, borrador de acta).
 */
class AiRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    /** @param array{meeting_id?: string, quorum_reached?: ?bool} $meta */
    public function __construct(public string $requestId, public string $companyId, public string $userId, public string $code, public string $input, public array $meta = []) {}

    public function handle(): void
    {
        $prevCtx = app()->bound(CompanyContext::class) ? app(CompanyContext::class) : null;
        if (! $prevCtx || $prevCtx->companyId !== $this->companyId) {
            app()->instance(CompanyContext::class, new CompanyContext($this->companyId, '', 'sst_manager', '', ''));
        }
        try {
            $go = $this->inContext(fn () => DB::update("update ai_requests set status = 'running', updated_at = now() where id = ? and status = 'queued'", [$this->requestId]));
            if (! $go) {
                return;   // ya la atendió otro trabajador
            }
            $started = microtime(true);
            try {
                $out = $this->code === 'minutes_draft' ? AiProvider::minutes($this->input) : AiProvider::suggest($this->code, $this->input);
            } catch (\Throwable $e) {
                Log::warning('Asistente IA: error del proveedor', ['request' => $this->requestId, 'error' => $e->getMessage()]);
                $out = null;
            }
            $ms = (int) ((microtime(true) - $started) * 1000);
            $usage = AiProvider::$lastUsage;
            if ($out === null) {
                $this->inContext(fn () => DB::update("update ai_requests set status = 'failed', error_code = 'invalid_response', latency_ms = ?, input_tokens = ?, output_tokens = ?,
                    completed_at = now(), updated_at = now() where id = ?", [$ms, $usage['in'] ?? null, $usage['out'] ?? null, $this->requestId]));
                $this->notify('El asistente no pudo responder', 'La respuesta de la IA no tenía el formato esperado y se descartó. Vuelve a intentarlo.');

                return;
            }
            $this->inContext(function () use ($out, $ms, $usage) {
                if ($this->code === 'minutes_draft') {
                    self::suggestion($this->companyId, $this->userId, $this->requestId, 'minutes_draft', 'committee_meetings', $this->meta['meeting_id'] ?? null,
                        $out + ['quorum_reached' => $this->meta['quorum_reached'] ?? null],
                        'Borrador de acta redactado con IA a partir de la agenda, las notas, los asistentes por cargo y los acuerdos. Revísalo y corrígelo antes de guardarlo en el acta.');
                } else {
                    self::suggestion($this->companyId, $this->userId, $this->requestId, $this->code, 'free_text', null, ['items' => $out],
                        'Sugerencias generadas con IA (la biblioteca aún no tiene este tema). Revísalas y adáptalas: son propuestas.');
                }
                DB::update("update ai_requests set status = 'completed', latency_ms = ?, input_tokens = ?, output_tokens = ?, completed_at = now(), updated_at = now() where id = ?",
                    [$ms, $usage['in'] ?? null, $usage['out'] ?? null, $this->requestId]);
            });
            $this->notify('Sugerencia lista', $this->code === 'minutes_draft' ? 'El borrador del acta está listo para revisar.' : 'Las sugerencias del asistente están listas para revisar.');
        } finally {
            if ($prevCtx) {
                app()->instance(CompanyContext::class, $prevCtx);
            } else {
                app()->forgetInstance(CompanyContext::class);
            }
        }
    }

    /** Guarda la propuesta como ai_suggestions «pending» (RN-IAA-01). */
    public static function suggestion(string $company, string $user, string $reqId, string $type, string $targetType, ?string $targetId, array $payload, ?string $rationale): object
    {
        return DB::selectOne(
            "insert into ai_suggestions (company_id, ai_request_id, suggestion_type, target_type, target_id, payload, rationale, status, expires_at, created_by, updated_by)
             values (?, ?, ?, ?, ?, ?::jsonb, ?, 'pending', now() + interval '30 days', ?, ?) returning *",
            [$company, $reqId, $type, $targetType, $targetId, json_encode($payload, JSON_UNESCAPED_UNICODE), $rationale, $user, $user]);
    }

    private function inContext(callable $fn): mixed
    {
        return DB::transaction(function () use ($fn) {
            DbContext::setCompany($this->companyId, local: true);
            DbContext::setUser($this->userId, local: true);

            return $fn();
        });
    }

    private function notify(string $title, string $body): void
    {
        try {
            $link = $this->code === 'minutes_draft' && ! empty($this->meta['meeting_id']) ? '/m/com' : '/m/iaa?t=history';
            $this->inContext(fn () => Notify::users([$this->userId], 'iaa.suggestion_ready', 'IAA', $this->requestId, $title, $body, $link, 'normal',
                'iaa.suggestion_ready:'.$this->requestId, $this->companyId, true));
        } catch (\Throwable) {
        }
    }
}

<?php

namespace App\Modules\SUN;

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
 * Generación del expediente SUNAFIL en segundo plano (docs/08 §12): queued → building → ready / ready_with_gaps / failed.
 * Corre con la empresa y el usuario que la pidieron fijados en la conexión (RLS) y al terminar avisa a quien la pidió.
 * Si falla, el expediente queda «failed» con el motivo en error_message (se puede volver a armar).
 */
class BuildDossierJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $dossierId, public string $companyId, public string $userId) {}

    public function handle(): void
    {
        $prevCtx = app()->bound(CompanyContext::class) ? app(CompanyContext::class) : null;
        if (! $prevCtx || $prevCtx->companyId !== $this->companyId) {
            // Fuera de una petición (trabajador de la cola): la empresa para los parámetros legales y los avisos.
            app()->instance(CompanyContext::class, new CompanyContext($this->companyId, '', 'sst_manager', '', ''));
        }
        try {
            $this->inContext(fn () => DB::update("update inspection_dossiers set status = 'building', error_message = null where id = ? and status in ('queued', 'building', 'failed')", [$this->dossierId]));
            try {
                $res = $this->inContext(fn () => DossierBuilder::build($this->dossierId, $this->userId));
            } catch (\Throwable $e) {
                Log::error('No se pudo armar el expediente SUNAFIL', ['dossier' => $this->dossierId, 'error' => $e->getMessage()]);
                $this->inContext(fn () => DB::update("update inspection_dossiers set status = 'failed', error_message = ? where id = ?",
                    ['No se pudo armar el expediente. Vuelve a intentarlo; si se repite, avisa a soporte.', $this->dossierId]));
                $this->notify('Expediente con error', 'No se pudo armar el expediente. Vuelve a intentarlo.');

                return;
            }
            $this->notify('Expediente listo', $res['gaps'] === 0 ? 'El expediente está completo: todos los puntos están incluidos.'
                : "{$res['gaps']} de {$res['total']} puntos con brechas: ábrelo para resolverlos.");
        } finally {
            if ($prevCtx) {
                app()->instance(CompanyContext::class, $prevCtx);
            } else {
                app()->forgetInstance(CompanyContext::class);
            }
        }
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
            $this->inContext(fn () => Notify::users([$this->userId], 'sun.dossier_built', 'SUN', $this->dossierId, $title, $body,
                '/m/sun?t=inspection_dossiers&open='.$this->dossierId, 'normal', 'sun.dossier_built:'.$this->dossierId.':'.date('Y-m-d H:i:s'), $this->companyId, true));
        } catch (\Throwable) {
        }
    }
}

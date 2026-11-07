<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\SUS\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Copias de seguridad en «Salud del sistema» (docs/15 §9 y §10).
 *  GET  /internal/backups   estado por base, configuración y últimas copias
 *  POST /internal/backups   hace una copia ahora (solo el Dueño); corre después de responder
 */
class BackupsController extends Controller
{
    public function index(BackupService $backups): JsonResponse
    {
        return response()->json(['data' => $backups->status()]);
    }

    public function store(Request $request, BackupService $backups, AccountsService $accounts): JsonResponse
    {
        $actor = $request->user()->id;
        $accounts->audit($actor, 'backup_requested', 'backup_runs', null, []);
        dispatch(fn () => app(BackupService::class)->runAll('manual', $actor))->afterResponse();

        return response()->json(['data' => ['started' => true]], 202);
    }
}

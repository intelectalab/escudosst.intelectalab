<?php

namespace App\Modules\SUS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use App\Modules\SUS\Services\BackupRestoreTestService;
use App\Modules\SUS\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Copias de seguridad en «Salud del sistema» (docs/15 §9 y §10).
 *  GET  /internal/backups   estado por base, configuración y últimas copias
 *  POST /internal/backups   hace una copia ahora (solo el Dueño); corre después de responder
 *  POST /internal/backups/restore-test   prueba de restauración ahora (solo el Dueño); corre después de responder
 */
class BackupsController extends Controller
{
    public function index(BackupService $backups): JsonResponse
    {
        return response()->json(['data' => $backups->status() + ['restore' => app(BackupRestoreTestService::class)->status()]]);
    }

    public function store(Request $request, BackupService $backups, AccountsService $accounts): JsonResponse
    {
        $actor = $request->user()->id;
        $accounts->audit($actor, 'backup_requested', 'backup_runs', null, []);
        dispatch(fn () => app(BackupService::class)->runAll('manual', $actor))->afterResponse();

        return response()->json(['data' => ['started' => true]], 202);
    }

    public function restoreTest(Request $request, AccountsService $accounts): JsonResponse
    {
        $data = $request->validate(['target' => ['nullable', 'in:main,medical,all']]);
        $target = $data['target'] ?? 'all';
        $actor = $request->user()->id;
        $accounts->audit($actor, 'backup_restore_test_requested', 'backup_restore_tests', null, ['target' => $target]);
        dispatch(function () use ($target, $actor) {
            $svc = app(BackupRestoreTestService::class);
            $target === 'all' ? $svc->runAll('manual', $actor) : $svc->run($target, 'manual', $actor);
        })->afterResponse();

        return response()->json(['data' => ['started' => true]], 202);
    }
}

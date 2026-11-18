<?php

// Vigilante: si falta la copia de seguridad la hace en ese momento; si está al día no hace nada.

use App\Modules\SUS\Services\BackupService;
use App\Modules\SUS\Services\SystemWatch;
use Illuminate\Support\Facades\Mail;

it('hace la copia que falta y no repite si está al día', function () {
    Mail::fake();
    \Illuminate\Support\Facades\Cache::flush();
    $b = Mockery::mock(BackupService::class);
    $b->shouldReceive('status')->once()->andReturn(['targets' => [
        ['target' => 'main', 'label' => 'Base principal', 'overdue' => true],
        ['target' => 'medical', 'label' => 'Base médica', 'overdue' => false],
    ]]);
    $b->shouldReceive('run')->once()->with('main', 'schedule')->andReturn('id');
    $b->shouldReceive('prune')->once()->andReturn(0);
    expect((new SystemWatch($b))->run()['backup'])->toBe('recovered');

    $ok = Mockery::mock(BackupService::class);
    $ok->shouldReceive('status')->once()->andReturn(['targets' => [['target' => 'main', 'label' => 'Base principal', 'overdue' => false]]]);
    $ok->shouldNotReceive('run');
    $r = (new SystemWatch($ok))->run();
    expect($r['backup'])->toBe('ok')->and($r['disk_used_pct'])->toBeGreaterThan(0);
});

<?php

// M-SIM SCR-SIM-02 / RN-SIM-01 / RN-SIM-08: cronómetro (alarma → evacuación completa) comparado con la meta de la sede y asistencia.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code = 'SIM' and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

it('registra la ejecución con el tiempo de evacuación, la meta de la sede y quiénes no participaron', function () {
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'SIM-'.random_int(1000, 99999), 'name' => 'Sede simulacro'], $this->h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update('update sites set evacuation_target_seconds = 300 where id = ?', [$site]);
    DB::select("select set_config('app.company_id', '', false)");
    $ids = [];
    foreach (['Rosa', 'Pedro'] as $n) {
        $ids[] = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(74000000, 74999999), 'first_name' => $n, 'paternal_last_name' => 'Simulacro'], $this->h)
            ->assertCreated()->json('data.id');
    }
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update('update workers set current_site_id = ? where id = any(?::uuid[])', [$site, '{'.implode(',', $ids).'}']);
    DB::select("select set_config('app.company_id', '', false)");
    $drill = $this->postJson('/api/v1/records/SIM/drills', ['title' => 'Sismo RR', 'drill_type' => 'earthquake', 'site_id' => $site, 'scheduled_at' => '2031-03-10T10:00:00-05:00'], $this->h)
        ->assertCreated()->json('data.id');

    $g = $this->getJson("/api/v1/sim/drills/{$drill}/run", $this->h)->assertOk()->json('data');
    expect($g['target_seconds'])->toBe(300)->and($g['workers'])->toHaveCount(2);

    $this->postJson("/api/v1/sim/drills/{$drill}/run", ['alarm_time' => '2031-03-10T10:00:00-05:00', 'evacuation_complete_time' => '2031-03-10T09:59:00-05:00'], $this->h)->assertStatus(422);
    $r = $this->postJson("/api/v1/sim/drills/{$drill}/run", ['alarm_time' => '2031-03-10T10:00:00-05:00', 'evacuation_complete_time' => '2031-03-10T10:04:30-05:00',
        'attendees' => [['worker_id' => $ids[0], 'role' => 'brigadist']]], $this->h)->assertOk()->json('data');
    expect($r['drill']['evacuation_time_seconds'])->toBe(270)->and($r['met'])->toBeTrue()->and($r['drill']['status'])->toBe('executed')
        ->and($r['not_participated'])->toHaveCount(1)->and($r['not_participated'][0])->toContain('Pedro');
});

it('evalúa el simulacro ejecutado por criterios y observaciones; lo no cumplido pasa a su acción correctiva (SCR-SIM-03, RN-SIM-06)', function () {
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'SIM-'.random_int(1000, 99999), 'name' => 'Sede evaluación'], $this->h)->assertCreated()->json('data.id');
    companyParamForTests(HUAYLAS_ID, 'drill_evaluation_criteria', json_encode(['Se dio la alarma a tiempo', 'Rutas de evacuación libres']), 'json');
    $drill = $this->postJson('/api/v1/records/SIM/drills', ['title' => 'Incendio EV', 'drill_type' => 'fire', 'site_id' => $site, 'scheduled_at' => '2031-03-10T10:00:00-05:00'], $this->h)
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/sim/drills/{$drill}/evaluation", ['observations' => 'Antes de ejecutar'], $this->h)->assertStatus(409);
    $g = $this->getJson("/api/v1/sim/drills/{$drill}/run", $this->h)->assertOk()->json('data');
    expect($g['criteria'])->toBe(['Se dio la alarma a tiempo', 'Rutas de evacuación libres'])->and($g['evaluation'])->toBeNull();

    $this->postJson("/api/v1/sim/drills/{$drill}/run", ['alarm_time' => '2031-03-10T10:00:00-05:00', 'evacuation_complete_time' => '2031-03-10T10:06:00-05:00'], $this->h)->assertOk();
    $e = $this->postJson("/api/v1/sim/drills/{$drill}/evaluation", ['criteria' => [
        ['name' => 'Se dio la alarma a tiempo', 'met' => true],
        ['name' => 'Rutas de evacuación libres', 'met' => false, 'note' => 'salida B con cajas'],
    ], 'observations' => 'Falta señalizar el punto de reunión'], $this->h)->assertOk()->json('data.evaluation');
    expect($e['not_met'])->toBe(['Rutas de evacuación libres (salida B con cajas)']);

    $report = $this->post('/api/v1/files', ['file' => \Illuminate\Http\UploadedFile::fake()->create('informe.pdf', 20, 'application/pdf'), 'module' => 'SIM'], $this->h + ['Accept' => 'application/json'])->json('data.id');
    $this->patchJson("/api/v1/records/SIM/drills/{$drill}", ['status' => 'evaluated', 'result' => 'needs_improvement', 'report_file_id' => $report], $this->h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $desc = DB::selectOne("select description from corrective_actions where source_type = 'drill' and source_id = ?", [$drill])->description;
    expect($desc)->toContain('salida B con cajas')->toContain('Falta señalizar el punto de reunión')->not->toContain('Se dio la alarma a tiempo');
    $this->postJson("/api/v1/sim/drills/{$drill}/evaluation", ['observations' => 'Otra'], $this->h)->assertStatus(409);
    // Informe de simulacro para imprimir.
    $rep = $this->getJson("/api/v1/sim/drills/{$drill}/report", $this->h)->assertOk()->json('data');
    expect($rep['details']['result'])->toBe('needs_improvement')->and($rep['company']['ruc'])->not->toBe('')->and($rep['evaluation']['observations'])->toBe('Falta señalizar el punto de reunión');
});

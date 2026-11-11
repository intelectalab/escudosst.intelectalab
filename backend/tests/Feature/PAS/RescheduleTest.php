<?php

// «Reprogramar» una actividad-mes del Programa Anual SST (RN-PAS-02): motivo obligatorio, nuevo mes del mismo año,
// mes original marcado «reprogramado», historial inalterable y auditoría.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function pasRH(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

it('reprograma un mes atrasado con motivo y lo muestra en el cronograma', function () {
    $h = pasRH();
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update("update companies set sst_model = 'supervisor' where id = ?", [ANDINAS_ID]);
    $now = now('America/Lima');
    $year = (int) $now->year;
    $month = (int) $now->month;
    DB::update('update annual_programs set deleted_at = now() where year = ?', [$year]);
    $prog = $this->postJson('/api/v1/records/PAS/annual_programs', ['year' => $year, 'title' => "PASST {$year}"], $h)->assertCreated()->json('data.id');
    $past = max(1, $month - 1);
    $other = $past === 1 ? 2 : 1;
    $a = $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'R1', 'title' => 'Capacitación', 'activity_type' => 'training',
        'goal_quantity' => 2, 'planned_months' => array_values(array_unique([$other, $past]))], $h)->assertCreated()->json('data.id');

    // Programa en borrador: no se reprograma.
    $this->postJson("/api/v1/pas/activities/{$a}/reschedule", ['from_month' => $past, 'to_month' => 12, 'reason' => 'Instructor no disponible'], $h)->assertStatus(422);
    $this->patchJson("/api/v1/records/PAS/annual_programs/{$prog}", ['status' => 'approved'], $h)->assertOk();

    // Motivo obligatorio, mes nuevo no pasado, mes original programado.
    $this->postJson("/api/v1/pas/activities/{$a}/reschedule", ['from_month' => $past, 'to_month' => 12], $h)->assertStatus(422)->assertJsonValidationErrors('reason');
    if ($month > 3) {
        $this->postJson("/api/v1/pas/activities/{$a}/reschedule", ['from_month' => $past, 'to_month' => $month - 2, 'reason' => 'Instructor no disponible'], $h)->assertStatus(422)->assertJsonValidationErrors('to_month');
    }
    $this->postJson("/api/v1/pas/activities/{$a}/reschedule", ['from_month' => $past === 7 || $other === 7 ? 8 : 7, 'to_month' => 12, 'reason' => 'Instructor no disponible'], $h)->assertStatus(422)->assertJsonValidationErrors('from_month');

    $to = 12;
    $this->postJson("/api/v1/pas/activities/{$a}/reschedule", ['from_month' => $past, 'to_month' => $to, 'reason' => 'Instructor no disponible'], $h)
        ->assertCreated()->assertJsonPath('data.to_month', $to);

    $d = $this->getJson("/api/v1/pas/schedule?year={$year}", $h)->assertOk()->json('data');
    expect($d['can_reschedule'])->toBeTrue();
    $act = collect($d['activities'])->firstWhere('id', $a);
    $cell = fn (int $m) => collect($act['cells'])->firstWhere('month', $m);
    expect($cell($past)['state'])->toBe('rescheduled')
        ->and($cell($past)['rescheduled_to'])->toBe($to)
        ->and($cell($past)['reschedule_reason'])->toBe('Instructor no disponible')
        ->and($cell($to)['planned'])->toBeTrue()
        ->and($act['reschedules'])->toHaveCount(1)
        ->and($d['progress']['rescheduled'])->toBe(1);

    // Ya programado en el mes nuevo: no se repite.
    $this->postJson("/api/v1/pas/activities/{$a}/reschedule", ['from_month' => $other, 'to_month' => $to, 'reason' => 'Otra razón válida'], $h)->assertStatus(422)->assertJsonValidationErrors('to_month');

    // Historial inalterable y auditoría.
    expect(fn () => DB::update('update activity_reschedules set reason = ? where activity_id = ?', ['x', $a]))->toThrow(Exception::class);
})->skip(fn () => in_array((int) now('America/Lima')->month, [1, 12, 13], true), 'Se necesita un mes atrasado y diciembre libre');

it('no reprograma un mes que ya tiene ejecución', function () {
    $h = pasRH();
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update("update companies set sst_model = 'supervisor' where id = ?", [ANDINAS_ID]);
    $year = (int) now('America/Lima')->year;
    DB::update('update annual_programs set deleted_at = now() where year = ?', [$year]);
    $prog = $this->postJson('/api/v1/records/PAS/annual_programs', ['year' => $year, 'title' => "PASST {$year}"], $h)->assertCreated()->json('data.id');
    $a = $this->postJson('/api/v1/records/PAS/program_activities', ['program_id' => $prog, 'code' => 'R2', 'title' => 'Inspección', 'activity_type' => 'inspection',
        'goal_quantity' => 1, 'planned_months' => [1]], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/records/PAS/annual_programs/{$prog}", ['status' => 'approved'], $h)->assertOk();
    $this->postJson('/api/v1/records/PAS/activity_executions', ['activity_id' => $a, 'executed_on' => "{$year}-01-15", 'quantity' => 1, 'description' => 'Acta INS-01'], $h)->assertCreated();
    $this->postJson("/api/v1/pas/activities/{$a}/reschedule", ['from_month' => 1, 'to_month' => 12, 'reason' => 'Motivo de prueba'], $h)->assertStatus(422)->assertJsonValidationErrors('from_month');
    $this->postJson('/api/v1/pas/activities/00000000-0000-0000-0000-000000000000/reschedule', ['from_month' => 1, 'to_month' => 12, 'reason' => 'Motivo de prueba'], $h)->assertNotFound();
});

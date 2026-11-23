<?php

// M-CAP: matriz por cargo versionada y consultable a una fecha (RN-CAP-06), sesión fuera de horario marcada (RN-CAP-17) e
// importación de la matriz desde Excel o CSV con revisión antes de guardar (SCR-CAP-07).

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

function cmvAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

function cmvBase(): array
{
    return cmvAs(function () {
        $n = random_int(10000, 99999);
        $pos = DB::selectOne('insert into positions (company_id, code, name) values (?, ?, ?) returning id', [HUAYLAS_ID, "SOL{$n}", "Soldador {$n}"])->id;
        $tr = DB::selectOne("insert into trainings (company_id, code, title, training_type) values (?, ?, ?, 'specific') returning id", [HUAYLAS_ID, "TAL{$n}", "Trabajo en altura {$n}"])->id;

        return compact('n', 'pos', 'tr');
    });
}

it('un cambio en la matriz guarda la versión anterior y se puede ver a una fecha pasada', function () {
    $b = cmvBase();
    // La fila existe desde hace 10 días como obligatoria.
    $id = cmvAs(fn () => DB::selectOne("insert into position_training_requirements (company_id, position_id, training_id, requirement_level, renewal_months, effective_from, created_at)
                                         values (?, ?, ?, 'mandatory', 12, current_date - 10, now() - interval '10 days') returning id", [HUAYLAS_ID, $b['pos'], $b['tr']])->id);
    $this->patchJson("/api/v1/records/CAP/position_training_requirements/{$id}", ['requirement_level' => 'suggested'], $this->h)->assertOk();

    $rows = cmvAs(fn () => DB::select('select requirement_level, effective_from, effective_to, deleted_at is not null as archived from position_training_requirements
                                        where position_id = ? and training_id = ? order by effective_from', [$b['pos'], $b['tr']]));
    expect($rows)->toHaveCount(2)->and($rows[0]->requirement_level)->toBe('mandatory')->and($rows[0]->archived)->toBeTrue()->and($rows[0]->effective_to)->not->toBeNull()
        ->and($rows[1]->requirement_level)->toBe('suggested')->and($rows[1]->archived)->toBeFalse();

    $cell = fn (?string $asOf) => collect($this->getJson('/api/v1/cap/matrix'.($asOf ? "?as_of={$asOf}" : ''), $this->h)->assertOk()->json('data.cells'))
        ->first(fn ($c) => $c['position_id'] === $b['pos'] && $c['training_id'] === $b['tr']);
    expect($cell(null)['level'])->toBe('suggested')->and($cell(now('America/Lima')->subDays(3)->format('Y-m-d'))['level'])->toBe('mandatory');
    $this->getJson('/api/v1/cap/matrix?as_of=2999-01-01', $this->h)->assertStatus(422);
});

it('marca la sesión que cae fuera de los turnos de la sede', function () {
    [$site, $tr] = cmvAs(function () {
        $site = DB::selectOne("insert into sites (company_id, code, name) values (?, ?, 'Sede turnos') returning id", [HUAYLAS_ID, 'ST'.random_int(1000, 99999)])->id;
        DB::insert("insert into work_shifts (company_id, site_id, code, name, start_time, end_time, hours_per_day, status) values (?, ?, ?, 'Día', '07:00', '17:00', 10, 'active')", [HUAYLAS_ID, $site, 'D'.random_int(100, 99999)]);
        $tr = DB::selectOne("insert into trainings (company_id, title, training_type) values (?, 'Charla de turno', 'specific') returning id", [HUAYLAS_ID])->id;

        return [$site, $tr];
    });
    $mk = function (string $s, string $e) use ($tr, $site) {
        $r = $this->postJson('/api/v1/records/CAP/training_sessions', ['training_id' => $tr, 'site_id' => $site, 'starts_at' => $s, 'ends_at' => $e], $this->h);
        expect($r->status())->toBe(201, (string) $r->getContent());

        return $r->json('data.id');
    };
    $in = $mk('2026-12-01T09:00:00-05:00', '2026-12-01T11:00:00-05:00');
    $out = $mk('2026-12-01T19:00:00-05:00', '2026-12-01T21:00:00-05:00');
    $flag = fn ($id) => cmvAs(fn () => DB::selectOne('select out_of_hours from training_sessions where id = ?', [$id])->out_of_hours);
    expect($flag($in))->toBeFalse()->and($flag($out))->toBeTrue();
    // Al moverla al horario del turno, deja de estar marcada.
    $this->patchJson("/api/v1/records/CAP/training_sessions/{$out}", ['starts_at' => '2026-12-01T14:00:00-05:00', 'ends_at' => '2026-12-01T16:00:00-05:00'], $this->h)->assertOk();
    expect($flag($out))->toBeFalse();
});

it('importa la matriz desde un CSV: revisa primero y guarda después', function () {
    $b = cmvBase();
    $csv = "Cargo,Tema,Nivel,Renovación (meses),Plazo desde el ingreso (días)\nSOL{$b['n']},TAL{$b['n']},obligatorio,12,30\nNoExiste,TAL{$b['n']},obligatorio,,\nSOL{$b['n']},TAL{$b['n']},quizás,,\n";
    $file = fn () => UploadedFile::fake()->createWithContent('matriz.csv', $csv);
    $r0 = $this->post('/api/v1/cap/matrix/import', ['file' => $file()], $this->h);
    expect($r0->status())->toBe(200, (string) $r0->getContent());
    $rev = $r0->json('data');
    expect($rev['applied'])->toBeFalse()->and($rev['saved'])->toBe(0)->and($rev['rows'][0]['action'])->toBe('create')
        ->and($rev['rows'][1]['action'])->toBe('error')->and($rev['rows'][2]['action'])->toBe('error');
    expect(cmvAs(fn () => DB::selectOne('select count(*) as n from position_training_requirements where position_id = ? and deleted_at is null', [$b['pos']])->n))->toBe(0);

    $ok = $this->post('/api/v1/cap/matrix/import', ['file' => $file(), 'apply' => '1'], $this->h)->assertOk()->json('data');
    expect($ok['saved'])->toBe(1);
    $row = cmvAs(fn () => DB::selectOne('select requirement_level, renewal_months, due_within_days from position_training_requirements where position_id = ? and deleted_at is null', [$b['pos']]));
    expect($row->requirement_level)->toBe('mandatory')->and($row->renewal_months)->toBe(12)->and($row->due_within_days)->toBe(30);

    $this->get('/api/v1/cap/matrix/template.xlsx', $this->h)->assertOk();
});

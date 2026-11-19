<?php

// M-PCA: varios puestos objetivo por tema (§5 target_positions, SCR-PCA-02/03, RN-PCA-02/03/07) y temas mínimos y del sector
// propuestos al crear el programa (flujo 1).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('PCA', 'CAP', 'FOT') and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

function pctAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

it('un tema va dirigido a varios puestos: cumplimiento, matriz por puesto y requisitos del fotocheck', function () {
    $s = pctAs(function () {
        $n = random_int(10000, 99999);
        $pos = fn ($c) => DB::selectOne('insert into positions (company_id, code, name) values (?, ?, ?) returning id', [HUAYLAS_ID, "{$c}{$n}", "{$c} {$n}"])->id;
        $a = $pos('SOLD');
        $b = $pos('ARM');
        $w = fn ($p) => DB::selectOne("insert into workers (company_id, document_number, document_number_hash, first_name, paternal_last_name, status, current_position_id)
                                          values (?, ?, decode(md5(random()::text), 'hex'), 'Trabajador', 'PCA', 'active', ?) returning id", [HUAYLAS_ID, (string) random_int(10000000, 99999999), $p])->id;
        $wa = $w($a);
        $w($a);
        $w($b);
        $year = 2041 + random_int(0, 58);
        $prog = DB::selectOne("insert into training_programs (company_id, year, title, status) values (?, ?, 'PCA puestos', 'draft') returning id", [HUAYLAS_ID, $year])->id;
        $tr = DB::selectOne("insert into trainings (company_id, title, training_type, status) values (?, 'Trabajos en caliente', 'specific', 'scheduled') returning id", [HUAYLAS_ID])->id;
        $ses = DB::selectOne("insert into training_sessions (company_id, training_id, starts_at, status) values (?, ?, now(), 'held') returning id", [HUAYLAS_ID, $tr])->id;
        DB::insert('insert into training_attendances (company_id, session_id, worker_id, attended) values (?, ?, ?, true)', [HUAYLAS_ID, $ses, $wa]);

        return compact('a', 'b', 'prog', 'tr');
    });

    $item = $this->postJson('/api/v1/records/PCA/training_program_items', ['training_program_id' => $s['prog'], 'topic' => 'Trabajos en caliente', 'training_type' => 'specific',
        'is_mandatory_legal' => true, 'target_positions' => [$s['a'], $s['b']], 'training_id' => $s['tr'], 'planned_month' => 3], $this->h)->assertCreated()->json('data.id');
    $row = $this->getJson("/api/v1/records/PCA/training_program_items/{$item}", $this->h)->assertOk()->json('data');
    expect($row['target_positions'])->toEqualCanonicalizing([$s['a'], $s['b']])->and(collect($row['target_positions__labels'])->pluck('id'))->toContain($s['a']);

    // Un puesto que no existe se rechaza.
    $this->postJson('/api/v1/records/PCA/training_program_items', ['training_program_id' => $s['prog'], 'topic' => 'Otro', 'training_type' => 'specific',
        'target_positions' => ['01900000-0000-7000-8000-0000000000ff']], $this->h)->assertStatus(422)->assertJsonValidationErrors('target_positions');

    // RN-PCA-03 y SCR-PCA-03: objetivo = trabajadores de los dos puestos; cumplimiento por puesto en la matriz.
    $it = collect($this->getJson("/api/v1/pca/overview?program_id={$s['prog']}", $this->h)->assertOk()->json('data.items'))->firstWhere('id', $item);
    expect($it['target'])->toBe(3)->and($it['done'])->toBe(1)
        ->and($it['by_position'][$s['a']])->toMatchArray(['target' => 2, 'done' => 1, 'pct' => 50])
        ->and($it['by_position'][$s['b']])->toMatchArray(['target' => 1, 'done' => 0, 'pct' => 0]);

    // RN-PCA-07: al aprobarse, un requisito del fotocheck por cada puesto objetivo.
    $n = pctAs(fn () => \App\Modules\REC\Rules\ProgramRules::syncCredentialRequirements($s['prog']));
    $types = pctAs(fn () => (int) DB::selectOne("select count(*) as n from credential_types where deleted_at is null and coalesce(is_active, true) and applies_to in ('workers', 'both')")->n);
    $got = pctAs(fn () => DB::select('select distinct position_id from credential_requirements where training_program_item_id = ? and deleted_at is null', [$item]));
    if ($types > 0) {
        expect(collect($got)->pluck('position_id')->all())->toEqualCanonicalizing([$s['a'], $s['b']])->and($n)->toBe($types * 2);
    }
});

it('propone los temas mínimos y los agrega al programa en borrador', function () {
    $prog = pctAs(fn () => DB::selectOne("insert into training_programs (company_id, year, title, status) values (?, ?, 'PCA propuesto', 'draft') returning id",
        [HUAYLAS_ID, 2041 + random_int(0, 58)])->id);
    $t = $this->getJson("/api/v1/pca/programs/{$prog}/suggested-topics", $this->h)->assertOk()->json('data');
    $min = collect($t['topics'])->where('source', 'Tema mínimo');
    expect($t['editable'])->toBeTrue()->and($min->pluck('topic')->all())->toContain('Inducción en seguridad y salud en el trabajo', 'IPERC del puesto', 'Uso de EPP', 'Respuesta a emergencias', 'Reglamento Interno de SST (RISST)');

    $r = $this->postJson("/api/v1/pca/programs/{$prog}/suggested-topics", ['keys' => ['min:induccion', 'min:epp']], $this->h)->assertCreated()->json('data');
    expect($r['added'])->toBe(2)->and(collect($r['topics'])->firstWhere('key', 'min:epp')['present'])->toBeTrue();
    // No se duplican.
    expect($this->postJson("/api/v1/pca/programs/{$prog}/suggested-topics", ['keys' => ['min:epp']], $this->h)->assertCreated()->json('data.added'))->toBe(0);
    $items = pctAs(fn () => DB::select('select topic, training_type, is_mandatory_legal from training_program_items where training_program_id = ? and deleted_at is null order by topic', [$prog]));
    expect($items)->toHaveCount(2)->and($items[0]->is_mandatory_legal)->toBeTrue()->and(collect($items)->firstWhere('topic', 'Inducción en seguridad y salud en el trabajo')->training_type)->toBe('induction');

    // RN-PCA-02: obligatorios sin puesto ni público, el programa no se envía.
    $this->patchJson("/api/v1/records/PCA/training_programs/{$prog}", ['status' => 'committee_review'], $this->h)->assertStatus(422)->assertJsonValidationErrors('status');

    // Aprobado, ya no se agregan (nueva versión).
    pctAs(fn () => DB::update("update training_programs set status = 'approved' where id = ?", [$prog]));
    $this->postJson("/api/v1/pca/programs/{$prog}/suggested-topics", ['keys' => ['min:risst']], $this->h)->assertStatus(409);
});

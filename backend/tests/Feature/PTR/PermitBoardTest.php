<?php

// M-PTR SCR-PTR-01 (tablero de permisos activos por sede con su mapa), RN-PTR-06 con RN-FIR-03 (el permiso con todas sus firmas
// igual se suspende y se reanuda) y §11 (permisos en los que participó el trabajador, en su parte del expediente SUN).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->me = DB::connection('platform')->selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    pbAs(fn () => DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('PTR', 'SUN') and deleted_at is null", [HUAYLAS_ID]));
    $this->site = pbAs(fn () => DB::selectOne("insert into sites (company_id, code, name, latitude, longitude) values (?, ?, 'Sede del tablero', -12.046374, -77.042793) returning id",
        [HUAYLAS_ID, 'PB-'.substr(uniqid(), -6)])->id);
});

afterEach(function () {
    app('auth')->forgetGuards();
    \App\Core\Auth\Permissions::flush();
});

function pbAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $r = $fn();
    DB::select("select set_config('app.company_id', '', false)");

    return $r;
}

function pbPermit(string $site, string $status, ?string $leader = null, string $from = 'now()'): string
{
    return pbAs(function () use ($site, $status, $leader, $from) {
        $type = DB::selectOne("insert into permit_types (company_id, code, name, category, validity_hours, requires_ats, requires_gas_test, checklist, approval_chain)
                               values (?, ?, 'Trabajo en altura PB', 'work_at_height', 8, false, false, '[]'::jsonb, '[]'::jsonb) returning id", [HUAYLAS_ID, 'PB-'.substr(uniqid(), -6)])->id;

        return DB::selectOne("insert into work_permits (company_id, code, permit_type_id, site_id, work_description, valid_from, valid_until, status, executor_leader_worker_id, crew, activated_at,
                                    suspended_reason, closed_at, area_left_safe)
                              values (?, ?, ?, ?, 'Cambio de techo', {$from} - interval '1 minute', {$from} + interval '6 hours', ?, ?, '[]'::jsonb, now(),
                                      case when ?::text = 'suspended' then 'Prueba' end, case when ?::text = 'closed' then now() end, case when ?::text = 'closed' then true end) returning id",
            [HUAYLAS_ID, 'PTR-PB-'.substr(uniqid(), -6), $type, $site, $status, $leader, $status, $status, $status])->id;
    });
}

it('el tablero agrupa los permisos en curso por sede con estado, tipo, vencimiento y coordenadas', function () {
    $a = pbPermit($this->site, 'active');
    $s = pbPermit($this->site, 'suspended');
    $c = pbPermit($this->site, 'closed');
    $d = $this->getJson('/api/v1/ptr/board', $this->h)->assertOk()->json('data');
    $site = collect($d['sites'])->firstWhere('site_id', $this->site);
    expect($site)->not->toBeNull()->and($site['lat'])->toEqual(-12.046374)->and($site['by_status'])->toBe(['active' => 1, 'suspended' => 1])
        ->and($site['by_type'])->toBe(['Trabajo en altura PB' => 2])
        ->and(collect($site['permits'])->pluck('id')->all())->toContain($a, $s)->not->toContain($c)
        ->and($d['totals']['active'])->toBeGreaterThanOrEqual(1);
});

it('un permiso con todas sus firmas se puede suspender y reanudar, pero no editar', function () {
    $p = pbPermit($this->site, 'active');
    pbAs(fn () => DB::insert("insert into signature_requests (company_id, subject_type, subject_id, document_hash, signer_user_id, role_in_document, status, signed_at)
                              values (?, 'work_permits', ?, decode(md5('pb'), 'hex'), ?, 'approver', 'signed', now())", [HUAYLAS_ID, $p, $this->me]));
    $this->patchJson("/api/v1/records/PTR/work_permits/{$p}", ['work_description' => 'Otra tarea'], $this->h)->assertStatus(409);
    $this->postJson("/api/v1/ptr/work-permits/{$p}/suspend", ['reason' => 'Viento fuerte'], $this->h)->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->patchJson("/api/v1/records/PTR/work_permits/{$p}", ['status' => 'active'], $this->h)->assertOk()->assertJsonPath('data.status', 'active');
    $this->patchJson("/api/v1/records/PTR/work_permits/{$p}", ['status' => 'suspended', 'suspended_reason' => 'Lluvia'], $this->h)->assertOk()->assertJsonPath('data.suspended_reason', 'Lluvia');
});

it('la parte del trabajador en el expediente SUN cuenta los permisos en que participó', function () {
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '83830001', 'first_name' => 'Ejecutor', 'paternal_last_name' => 'Altura'], $this->h)->assertCreated()->json('data.id');
    $idle = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '83830002', 'first_name' => 'Sin', 'paternal_last_name' => 'Permisos'], $this->h)->assertCreated()->json('data.id');
    pbPermit($this->site, 'closed', $w);
    $crew = pbPermit($this->site, 'active');
    pbAs(fn () => DB::update("update work_permits set crew = ?::jsonb where id = ?", [json_encode([['worker_id' => $w, 'role' => 'watcher']]), $crew]));
    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Expediente PTR', 'period_from' => now()->startOfYear()->toDateString(), 'period_to' => now()->endOfYear()->toDateString()], $this->h)
        ->assertCreated()->json('data.id');
    $items = collect($this->postJson("/api/v1/sun/dossiers/{$d}/build", ['worker_ids' => [$w, $idle]], $this->h)->assertOk()->json('data.items'))->where('code', 'W8');
    $mine = $items->firstWhere('worker_id', $w);
    $other = $items->firstWhere('worker_id', $idle);
    expect($mine['title'])->toContain('(2)')->and($mine['status'])->toBe('included')->and($other['status'])->toBe('not_applicable');
});

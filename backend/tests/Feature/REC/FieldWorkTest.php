<?php

// Trabajo en campo: charla con foto de grupo y firma asistida uno tras otro (M-CH5 SCR-CH5-02), entrega rápida de EPP por DNI o
// fotocheck con firma en el momento (M-EPP SCR-EPP-03), cierre del ATS con observaciones e incidente (M-ATS SCR-ATS-04) y
// ubicación GPS de la inspección (M-INS SCR-INS-03).

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

const FIELD_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

function fieldSite($t): string
{
    return $t->postJson('/api/v1/records/TEN/sites', ['code' => 'CF-'.random_int(1000, 9999), 'name' => 'Obra de campo'], $t->h)->assertCreated()->json('data.id');
}

it('registra la charla con foto de grupo y cada asistente firma asistido; con la primera firma queda realizada', function () {
    $h = $this->h;
    $site = fieldSite($this);
    $area = $this->postJson('/api/v1/records/TEN/areas', ['site_id' => $site, 'code' => 'CHA-'.random_int(100, 999), 'name' => 'Encofrado'], $h)->assertCreated()->json('data.id');
    $workers = [];
    foreach (['Luis', 'Ana'] as $n) {
        $workers[] = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 49999999), 'first_name' => $n, 'paternal_last_name' => 'Charla',
            'current_site_id' => $site, 'current_area_id' => $area], $h)->assertCreated()->json('data.id');
    }
    $photo = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->image('grupo.jpg', 40, 30), 'module' => 'CH5'], $h + ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');
    $base = ['topic' => 'Orden y limpieza', 'talk_on' => now()->toDateString(), 'site_id' => $site, 'area_id' => $area, 'worker_ids' => $workers];

    $this->postJson('/api/v1/ch5/quick', $base + ['photo_file_id' => '01900000-0000-7000-8000-0000000000ff'], $h)->assertStatus(422)->assertJsonValidationErrors('photo_file_id');
    $talk = $this->postJson('/api/v1/ch5/quick', $base + ['photo_file_id' => $photo], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select photo_file_id, status from safety_talks where id = ?', [$talk]))->toMatchArray(['photo_file_id' => $photo, 'status' => 'scheduled']);

    $list = $this->getJson("/api/v1/ch5/talks/{$talk}/attendances", $h)->assertOk()->json('data.attendances');
    expect($list)->toHaveCount(2)->and($list[0]['signed'])->toBeFalse();
    $att = $list[0]['id'];

    $this->postJson("/api/v1/ch5/attendances/{$att}/sign", ['image' => FIELD_PNG], $h)->assertStatus(422)->assertJsonValidationErrors('assisted');
    $this->postJson("/api/v1/ch5/attendances/{$att}/sign", ['assisted' => true], $h)->assertStatus(422)->assertJsonValidationErrors('pin');
    $this->postJson("/api/v1/ch5/attendances/{$att}/sign", ['assisted' => true, 'dni' => '00000000', 'pin' => '1234'], $h)->assertStatus(422)->assertJsonValidationErrors('dni');
    $sig = $this->postJson("/api/v1/ch5/attendances/{$att}/sign", ['assisted' => true, 'image' => FIELD_PNG], $h)->assertCreated()->json('data.signature_id');
    $this->postJson("/api/v1/ch5/attendances/{$att}/sign", ['assisted' => true, 'image' => FIELD_PNG], $h)->assertStatus(409);

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $s = DB::selectOne('select method, signer_worker_id, evidence from signatures where id = ?', [$sig]);
    expect($s->method)->toBe('assisted_in_person')->and($s->signer_worker_id)->toBe($list[0]['worker_id'])
        ->and(json_decode($s->evidence, true))->toMatchArray(['identity' => 'stroke']);
    expect(DB::selectOne('select status from safety_talks where id = ?', [$talk])->status)->toBe('held');
    expect((int) DB::selectOne('select count(*) as n from signature_strokes where signature_id = ?', [$sig])->n)->toBe(1);
    expect($this->getJson("/api/v1/ch5/talks/{$talk}/attendances", $h)->json('data.attendances.1.signed'))->toBeTrue();
});

it('busca al trabajador por DNI con sus EPP exigidos y su estado, y entrega con la firma en el momento', function () {
    $h = $this->h;
    $site = fieldSite($this);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $pos = DB::selectOne("insert into positions (company_id, code, name, status) values (?, ?, 'Soldador de campo', 'active') returning id", [HUAYLAS_ID, 'PFW-'.random_int(1000, 9999)])->id;
    $glove = DB::selectOne("insert into ppe_items (company_id, code, name, category, size_options, useful_life_days) values (?, ?, 'Guantes de cuero', 'hand', '{S,M,L}', 90) returning id",
        [HUAYLAS_ID, 'EFW-'.random_int(1000, 9999)])->id;
    $mask = DB::selectOne("insert into ppe_items (company_id, code, name, category) values (?, ?, 'Careta de soldar', 'eye_face') returning id", [HUAYLAS_ID, 'EFW-'.random_int(1000, 9999)])->id;
    foreach ([$glove, $mask] as $it) {
        DB::insert('insert into ppe_position_requirements (company_id, position_id, ppe_item_id, is_mandatory) values (?, ?, ?, true)', [HUAYLAS_ID, $pos, $it]);
        DB::insert("insert into ppe_stock_movements (company_id, ppe_item_id, site_id, movement_type, quantity, moved_at, reference) values (?, ?, ?, 'in', 10, now(), 'Ingreso de prueba')", [HUAYLAS_ID, $it, $site]);
    }
    $dni = (string) random_int(40000000, 49999999);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => $dni, 'first_name' => 'Rosa', 'paternal_last_name' => 'Campo', 'current_position_id' => $pos,
        'current_site_id' => $site], $h)->assertCreated()->json('data.id');

    $this->getJson('/api/v1/epp/quick/lookup?code=11111111', $h)->assertNotFound();
    $look = $this->getJson('/api/v1/epp/quick/lookup?code='.$dni, $h)->assertOk()->json('data');
    expect($look['worker']['id'])->toBe($w);
    $items = collect($look['items'])->keyBy('ppe_item_id');
    expect($items[$glove]['state'])->toBe('missing')->and($items[$glove]['sizes'])->toBe(['S', 'M', 'L'])->and((float) $items[$glove]['stock'])->toBe(10.0);

    $body = ['worker_id' => $w, 'delivery_reason' => 'initial', 'items' => [['ppe_item_id' => $glove], ['ppe_item_id' => $mask]], 'assisted' => true, 'image' => FIELD_PNG];
    $this->postJson('/api/v1/epp/quick/deliver', $body, $h)->assertStatus(422)->assertJsonPath('errors.items.0', fn ($m) => str_contains($m, 'talla'));
    $body['items'][0]['size'] = 'M';
    $noSign = $body;
    unset($noSign['image']);
    $this->postJson('/api/v1/epp/quick/deliver', $noSign, $h)->assertStatus(422);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect((int) DB::selectOne('select count(*) as n from ppe_deliveries where worker_id = ?', [$w])->n)->toBe(0);   // sin firma no queda nada a medias

    $ids = $this->postJson('/api/v1/epp/quick/deliver', $body, $h)->assertCreated()->json('data.ids');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $rows = DB::select('select status, signature_id, size from ppe_deliveries where id = any(?::uuid[]) order by size nulls last', ['{'.implode(',', $ids).'}']);
    expect($rows)->toHaveCount(2)->and($rows[0]->status)->toBe('delivered')->and($rows[0]->signature_id)->not->toBeNull()->and($rows[0]->size)->toBe('M');
    expect((float) \App\Modules\REC\Rules\PpeCommitteeRules::stock($glove, $site))->toBe(9.0);
    $items = collect($this->getJson('/api/v1/epp/quick/lookup?code='.$dni, $h)->json('data.items'))->keyBy('ppe_item_id');
    expect($items[$glove]['state'])->toBe('ok')->and($items[$mask]['state'])->toBe('ok');
});

it('cierra el ATS con hora de término y observaciones, y exige enlazar el evento si hubo un incidente', function () {
    $h = $this->h;
    $site = fieldSite($this);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $ats = DB::selectOne("insert into ats_forms (company_id, code, task, site_id, performed_on, status, started_at) values (?, ?, 'Soldadura en altura', ?, current_date, 'in_execution', now() - interval '2 hours') returning id",
        [HUAYLAS_ID, 'ATS-T-'.random_int(10000, 99999), $site])->id;
    $event = DB::selectOne('select id from sst_events where deleted_at is null limit 1')?->id;

    $this->patchJson("/api/v1/records/ATS/ats_forms/{$ats}", ['status' => 'closed', 'task' => 'Otra'], $h)->assertStatus(409);
    $this->patchJson("/api/v1/records/ATS/ats_forms/{$ats}", ['status' => 'closed', 'closed_at' => now()->addHour()->toIso8601String()], $h)->assertStatus(422)->assertJsonValidationErrors('closed_at');
    $this->patchJson("/api/v1/records/ATS/ats_forms/{$ats}", ['status' => 'closed', 'closed_at' => now()->subHours(3)->toIso8601String()], $h)->assertStatus(422)->assertJsonValidationErrors('closed_at');
    $this->patchJson("/api/v1/records/ATS/ats_forms/{$ats}", ['status' => 'closed', 'incident_occurred' => true], $h)->assertStatus(422)->assertJsonValidationErrors('incident_event_id');
    $end = now()->subMinutes(20);
    $close = ['status' => 'closed', 'closed_at' => $end->toIso8601String(), 'closing_notes' => 'Se reforzó la línea de vida.'] + ($event ? ['incident_event_id' => $event] : []);
    $this->patchJson("/api/v1/records/ATS/ats_forms/{$ats}", $close, $h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $row = DB::selectOne('select status, closed_at, closing_notes, incident_occurred, incident_event_id from ats_forms where id = ?', [$ats]);
    expect($row->status)->toBe('closed')->and($row->closing_notes)->toBe('Se reforzó la línea de vida.')->and($row->incident_occurred)->toBe((bool) $event)
        ->and($row->incident_event_id)->toBe($event)->and(abs(strtotime($row->closed_at) - $end->timestamp))->toBeLessThan(2);
    $this->patchJson("/api/v1/records/ATS/ats_forms/{$ats}", ['closing_notes' => 'cambio'], $h)->assertStatus(409);
});

it('guarda la ubicación GPS de la inspección la primera vez e ignora una inválida', function () {
    $site = fieldSite($this);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $id = DB::selectOne("insert into inspections (company_id, code, title, inspection_type, site_id, status) values (?, ?, 'Inspección de campo', 'planned', ?, 'in_progress') returning id",
        [HUAYLAS_ID, 'INS-T-'.random_int(10000, 99999), $site])->id;
    $svc = app(\App\Modules\INS\Services\InspectionService::class);
    $svc->place($id, ['lat' => 200, 'lng' => -77]);
    expect(DB::selectOne('select latitude from inspections where id = ?', [$id])->latitude)->toBeNull();
    $svc->place($id, ['lat' => -9.5278, 'lng' => -77.5278, 'accuracy_m' => 12.34]);
    $svc->place($id, ['lat' => -12.0, 'lng' => -77.0]);
    $r = DB::selectOne('select latitude, longitude, location_accuracy_m from inspections where id = ?', [$id]);
    expect((float) $r->latitude)->toBe(-9.5278)->and((float) $r->longitude)->toBe(-77.5278)->and((float) $r->location_accuracy_m)->toBe(12.3);
});

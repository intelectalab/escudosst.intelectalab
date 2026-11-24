<?php

// M-PSE: la plataforma carga «PETS modelo» y «formato de registro» en un paquete; al instalarlo quedan como PETS y documento de
// la empresa en borrador (sin duplicar al reinstalar) y una edición del paquete llega como actualización en una versión nueva.
// M-API §12: avisos worker.created, training.certificate_issued y credential.status_changed.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled', company_enabled = true where company_id = ? and module_code in ('PSE', 'PET', 'DOC', 'API') and deleted_at is null", [HUAYLAS_ID]);
    \App\Core\Auth\Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->admin = authHeaders(tokenFor(ADMIN_EMAIL));
    $this->pack = DB::connection('platform')->selectOne("select id from sector_packs where status = 'published' and deleted_at is null order by name limit 1")->id;
    $this->sfx = strtoupper(substr(md5(uniqid()), 0, 6));
});

afterEach(function () {
    DB::connection('platform')->update("update sector_pack_items set deleted_at = now() where code like ? and deleted_at is null", ['%-'.$this->sfx]);
});

it('la plataforma carga un PETS modelo y un formato de registro; al instalarlos quedan en borrador y la edición llega como actualización', function () {
    $base = "/api/v1/internal/catalogs/sector-packs/{$this->pack}/items";
    $this->postJson($base, ['item_type' => 'pets_model', 'code' => 'PETS-X-'.$this->sfx, 'name' => 'Trabajo de prueba'], $this->admin)->assertStatus(422)->assertJsonValidationErrors('steps');
    $this->postJson($base, ['item_type' => 'pets_model', 'code' => 'pets minúsculas', 'name' => 'Trabajo de prueba', 'steps' => [['step' => 'Uno']]], $this->admin)->assertStatus(422)->assertJsonValidationErrors('code');
    $this->postJson($base, ['item_type' => 'pets_model', 'code' => 'PETS-X-'.$this->sfx, 'name' => 'Trabajo de prueba'], authHeaders(tokenFor(CORPORATIVO_EMAIL)))->assertStatus(403);
    $this->app['auth']->forgetGuards();

    $pets = $this->postJson($base, ['item_type' => 'pets_model', 'code' => 'PETS-X-'.$this->sfx, 'name' => 'Trabajo de prueba en altura', 'is_critical_task' => true,
        'steps' => [['step' => 'Inspeccionar el arnés', 'risk' => 'Caída', 'control' => 'Arnés certificado'], ['step' => ' ']], 'required_ppe' => ['Arnés', 'Casco']], $this->admin)
        ->assertCreated()->json('data.id');
    $fmt = $this->postJson($base, ['item_type' => 'record_format', 'code' => 'FOR-X-'.$this->sfx, 'name' => 'Formato de prueba', 'content_html' => '<p>Campos del formato</p>'], $this->admin)
        ->assertCreated()->json('data.id');
    $this->postJson($base, ['item_type' => 'record_format', 'code' => 'FOR-X-'.$this->sfx, 'name' => 'Otro', 'content_html' => '<p>x</p>'], $this->admin)->assertStatus(409);
    $listed = collect($this->getJson('/api/v1/internal/catalogs/sector-packs', $this->admin)->assertOk()->json('data'))->firstWhere('id', $this->pack)['items'];
    expect(collect($listed)->firstWhere('id', $pets)['payload']['steps'])->toHaveCount(1);

    $this->app['auth']->forgetGuards();
    $prev = collect($this->getJson("/api/v1/pse/packs/{$this->pack}/preview", $this->h)->assertOk()->json('data.items'));
    expect($prev->firstWhere('id', $pets)['action'])->toBe('install')->and($prev->firstWhere('id', $fmt)['type_label'])->toBe('Formato de registro');
    $this->postJson("/api/v1/pse/packs/{$this->pack}/install", ['items' => [$pets, $fmt]], $this->h)->assertCreated();

    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $p = DB::selectOne("select p.id, p.title, p.procedure_type, p.is_critical_task, v.status, v.steps, v.required_ppe from procedures p join procedure_versions v on v.procedure_id = p.id
                         where p.company_id = ? and p.code = ? and p.deleted_at is null", [HUAYLAS_ID, 'PETS-X-'.$this->sfx]);
    expect($p->title)->toBe('Trabajo de prueba en altura')->and($p->procedure_type)->toBe('pets')->and($p->is_critical_task)->toBeTrue()->and($p->status)->toBe('draft')
        ->and(json_decode($p->steps, true))->toEqual([['step' => 'Inspeccionar el arnés', 'risk' => 'Caída', 'control' => 'Arnés certificado']])
        ->and(json_decode($p->required_ppe, true))->toBe(['Arnés', 'Casco']);
    $d = DB::selectOne("select d.status, c.code as category, v.content_html, v.status as vstatus from documents d join document_categories c on c.id = d.category_id
                         join document_versions v on v.document_id = d.id where d.company_id = ? and d.code = ?", [HUAYLAS_ID, 'FOR-X-'.$this->sfx]);
    expect($d->status)->toBe('draft')->and($d->category)->toBe('REGISTER')->and($d->content_html)->toBe('<p>Campos del formato</p>')->and($d->vstatus)->toBe('draft');

    // RN-PSE-02: reinstalar no duplica.
    $this->postJson("/api/v1/pse/packs/{$this->pack}/install", ['items' => [$pets, $fmt]], $this->h)->assertCreated();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect((int) DB::selectOne('select count(*) as n from procedures where company_id = ? and code = ? and deleted_at is null', [HUAYLAS_ID, 'PETS-X-'.$this->sfx])->n)->toBe(1);

    // La plataforma edita el formato: la empresa lo ve como cambio y, al aplicarlo, queda una versión 2 en borrador.
    $this->app['auth']->forgetGuards();
    $this->patchJson("/api/v1/internal/catalogs/sector-pack-items/{$fmt}", ['item_type' => 'record_format', 'code' => 'FOR-X-'.$this->sfx, 'name' => 'Formato de prueba',
        'content_html' => '<p>Campos nuevos</p>'], $this->admin)->assertOk();
    $this->app['auth']->forgetGuards();
    $up = collect($this->getJson("/api/v1/pse/packs/{$this->pack}/updates", $this->h)->assertOk()->json('data.items'))->firstWhere('id', $fmt);
    expect($up['change'])->toBe('changed')->and($up['to_version'])->toBe(2);
    $this->postJson("/api/v1/pse/packs/{$this->pack}/updates", ['items' => [$fmt]], $this->h)->assertSuccessful();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $v2 = DB::selectOne("select v.version_number, v.content_html, v.status from document_versions v join documents d on d.id = v.document_id where d.company_id = ? and d.code = ? order by v.version_number desc limit 1",
        [HUAYLAS_ID, 'FOR-X-'.$this->sfx]);
    expect($v2->version_number)->toBe(2)->and($v2->content_html)->toBe('<p>Campos nuevos</p>')->and($v2->status)->toBe('draft');

    // Quitar el ítem del paquete no borra lo instalado (RN-PSE-06).
    $this->app['auth']->forgetGuards();
    $this->deleteJson("/api/v1/internal/catalogs/sector-pack-items/{$pets}", [], $this->admin)->assertNoContent();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect((int) DB::selectOne('select count(*) as n from procedures where company_id = ? and code = ? and deleted_at is null', [HUAYLAS_ID, 'PETS-X-'.$this->sfx])->n)->toBe(1);
});

it('encola los avisos de trabajador creado, certificado emitido y cambio de estado del fotocheck', function () {
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $hook = DB::selectOne("insert into webhooks (company_id, name, target_url, event_codes, secret, is_active) values (?, 'ERP', 'https://erp.example.com/hook',
                            '{worker.created,training.certificate_issued,credential.status_changed}', 'x', true) returning id", [HUAYLAS_ID])->id;
    $count = fn (string $ev) => (int) DB::selectOne('select count(*) as n from webhook_deliveries where webhook_id = ? and event_code = ?', [$hook, $ev])->n;

    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(40000000, 49999999), 'first_name' => 'Aviso', 'paternal_last_name' => 'Webhook'], $this->h)
        ->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect($count('worker.created'))->toBe(1);
    expect(json_decode(DB::selectOne("select request_body from webhook_deliveries where webhook_id = ? and event_code = 'worker.created'", [$hook])->request_body, true))
        ->toMatchArray(['event' => 'worker.created', 'id' => $w, 'table' => 'workers']);

    // Emitido = validado: un certificado pendiente de validación no avisa; al validarse sí.
    $cert = (string) Str::uuid7();
    \App\Modules\API\Webhooks::queue('training_certificates', 'create', $cert, ['status' => 'pending_validation']);
    expect($count('training.certificate_issued'))->toBe(0);
    \App\Modules\API\Webhooks::queue('training_certificates', 'update', $cert, ['status' => 'validated']);
    expect($count('training.certificate_issued'))->toBe(1);

    // Cualquier cambio de estado del fotocheck, con el estado nuevo; un cambio sin estado no avisa.
    $cred = (string) Str::uuid7();
    \App\Modules\API\Webhooks::queue('credentials', 'update', $cred, ['notes' => 'x']);
    \App\Modules\API\Webhooks::queue('credentials', 'update', $cred, ['status' => 'blocked']);
    expect($count('credential.status_changed'))->toBe(1);
    expect(json_decode(DB::selectOne("select request_body from webhook_deliveries where webhook_id = ? and event_code = 'credential.status_changed'", [$hook])->request_body, true)['data'])
        ->toBe(['status' => 'blocked']);
    expect(\App\Modules\API\Webhooks::codes())->toContain('worker.created', 'training.certificate_issued', 'credential.status_changed', 'inspection.completed');
});

<?php

// Gestión documental: bandeja de aprobación (SCR-DOC-04, RN-DOC-02/03) y campaña de cargos (SCR-DOC-05, RN-DOC-05).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function docH(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

/** El usuario Corporativo también trabaja en Andinas con el rol indicado (otra persona que aprueba). */
function docSecond(string $role): array
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $uid = DB::selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $roleId = DB::selectOne('select id from roles where account_id is null and code = ?', [$role])->id;
    DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [ANDINAS_ID, $uid, $roleId]);
    DB::select("select set_config('app.company_id', '', false)");

    return authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID);
}

/** Documento con una versión enviada a revisión por el Jefe SST. */
function docInReview(object $t, string $category, string $code): array
{
    $h = docH();
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $cat = DB::selectOne('select id from document_categories where company_id is null and code = ?', [$category])->id;
    $doc = $t->postJson('/api/v1/records/DOC/documents', ['category_id' => $cat, 'code' => $code, 'title' => "Documento {$code}"], $h)->assertCreated()->json('data.id');
    $v = $t->postJson('/api/v1/records/DOC/document_versions', ['document_id' => $doc, 'content_html' => '<p>Contenido</p>', 'change_summary' => 'Primera emisión', 'requires_ack' => true], $h)
        ->assertCreated()->json('data.id');
    $t->patchJson("/api/v1/records/DOC/document_versions/{$v}", ['status' => 'in_review'], $h)->assertOk();

    return [$doc, $v];
}

it('lista las versiones en revisión; quien la elaboró no la aprueba; otra persona la aprueba con su contraseña y queda vigente', function () {
    [$doc, $v] = docInReview($this, 'PETS', 'PETS-T1');
    $row = collect($this->getJson('/api/v1/doc/inbox', docH())->assertOk()->json('data'))->firstWhere('id', $v);
    expect($row)->toMatchArray(['document_code' => 'PETS-T1', 'requires_approval_by' => 'sst', 'can_approve' => false])
        ->and($row['cannot_reason'])->toContain('Tú elaboraste');
    $this->postJson("/api/v1/doc/versions/{$v}/approve", ['password' => DEMO_PASSWORD], docH())->assertForbidden();

    $h2 = docSecond('sst_manager');
    expect(collect($this->getJson('/api/v1/doc/inbox', $h2)->json('data'))->firstWhere('id', $v)['can_approve'])->toBeTrue();
    $this->postJson("/api/v1/doc/versions/{$v}/approve", ['password' => 'incorrecta'], $h2)->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson("/api/v1/doc/versions/{$v}/approve", ['password' => DEMO_PASSWORD], $h2)->assertOk()->assertJsonPath('data.status', 'current');

    $this->getJson("/api/v1/records/DOC/documents/{$doc}", $h2)->assertJsonPath('data.current_version_id', $v);
    $this->postJson("/api/v1/doc/versions/{$v}/approve", ['password' => DEMO_PASSWORD], $h2)->assertStatus(409);
    $sheet = $this->getJson("/api/v1/doc/documents/{$doc}/sheet", $h2)->assertOk();
    expect($sheet->json('data.versions.0.status'))->toBe('current')->and($sheet->json('data.versions.0.approved_by_name'))->not->toBeNull();
    expect(DB::selectOne("select count(*) as n from audit_logs where entity_id = ? and action = 'approve'", [$v])->n)->toBe(1);
});

it('una categoría del comité no la aprueba el Jefe SST; se devuelve con comentario y se avisa a quien la envió', function () {
    [, $v] = docInReview($this, 'MINUTES', 'ACTA-T1');
    $h2 = docSecond('sst_manager');
    $this->postJson("/api/v1/doc/versions/{$v}/approve", ['password' => DEMO_PASSWORD], $h2)->assertForbidden()
        ->assertJsonPath('detail', 'Esta categoría la aprueba el Comité SST (un miembro del comité).');
    $this->postJson("/api/v1/doc/versions/{$v}/return", ['reason' => ''], $h2)->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/doc/versions/{$v}/return", ['reason' => 'Falta la firma del secretario'], $h2)->assertOk()->assertJsonPath('data.status', 'draft');
    $this->getJson("/api/v1/records/DOC/document_versions/{$v}", docH())->assertJsonPath('data.review_comments', 'Falta la firma del secretario');
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where subject_id = ? and event_code = 'doc.version_returned'", [$v])->n)->toBe(1);
});

it('el comité aprueba los documentos de su categoría', function () {
    [, $v] = docInReview($this, 'MINUTES', 'ACTA-T2');
    $h2 = docSecond('committee_member');
    $this->postJson("/api/v1/doc/versions/{$v}/approve", ['password' => DEMO_PASSWORD], $h2)->assertOk()->assertJsonPath('data.status', 'current');
});

it('campaña de cargos: pide la lectura por sede, muestra recibidos vs. esperados y limita los recordatorios', function () {
    [$doc, $v] = docInReview($this, 'PETS', 'PETS-T2');
    $h = docH();
    $this->postJson("/api/v1/doc/documents/{$doc}/campaign", [], $h)->assertStatus(409);   // sin versión vigente
    $this->postJson("/api/v1/doc/versions/{$v}/approve", ['password' => DEMO_PASSWORD], docSecond('sst_manager'))->assertOk();

    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'DOC-S', 'name' => 'Sede cargos'], $h)->json('data.id');
    $w = [];
    foreach (['70990011', '70990012', '70990013'] as $k => $dni) {
        $w[] = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => $dni, 'first_name' => "Ana{$k}", 'paternal_last_name' => 'Cargo'], $h)->assertCreated()->json('data.id');
    }
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update('update workers set current_site_id = ? where id = any(?::uuid[])', [$site, '{'.$w[0].','.$w[1].'}']);

    $res = $this->postJson("/api/v1/doc/documents/{$doc}/campaign", ['site_id' => $site], $h)->assertCreated();
    expect($res->json('data'))->toMatchArray(['requested' => 2, 'new' => 2, 'already_acknowledged' => 0])
        ->and($res->json('data.acks'))->toMatchArray(['expected' => 2, 'received' => 0, 'campaign' => true]);
    // Repetir la campaña no duplica los avisos.
    $this->postJson("/api/v1/doc/documents/{$doc}/campaign", ['site_id' => $site], $h)->assertCreated()->assertJsonPath('data.new', 0);

    $this->postJson('/api/v1/records/DOC/document_acknowledgements', ['document_version_id' => $v, 'worker_id' => $w[0], 'method' => 'in_person_assisted'], $h)->assertCreated();
    $acks = $this->getJson("/api/v1/doc/documents/{$doc}/sheet", $h)->assertOk()->json('data.acks');
    expect($acks)->toMatchArray(['expected' => 2, 'received' => 1, 'pct' => 50])->and(count($acks['pending']))->toBe(1)->and($acks['pending'][0]['id'])->toBe($w[1]);

    $this->postJson("/api/v1/doc/documents/{$doc}/remind", [], $h)->assertOk()->assertJsonPath('data.sent', 1);
    $this->postJson("/api/v1/doc/documents/{$doc}/remind", [], $h)->assertStatus(429);
});

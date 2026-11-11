<?php

// Aprobar una versión desde la bandeja deja la firma del aprobador (M-FIR) sobre la huella del contenido,
// que sigue «Íntegra» cuando la versión pasa luego a obsoleta.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function docApprovalH(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

function dsApprover(): array
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $uid = DB::selectOne('select id from users where email = ?', [CORPORATIVO_EMAIL])->id;
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    if (! DB::selectOne('select 1 as ok from memberships where company_id = ? and user_id = ?', [ANDINAS_ID, $uid])) {
        $roleId = DB::selectOne("select id from roles where account_id is null and code = 'sst_manager'")->id;
        DB::insert("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now())", [ANDINAS_ID, $uid, $roleId]);
    }
    DB::select("select set_config('app.company_id', '', false)");

    return authHeaders(tokenFor(CORPORATIVO_EMAIL), ANDINAS_ID);
}

function dsVersion(object $t, string $doc, string $html): string
{
    $h = docApprovalH();
    $v = $t->postJson('/api/v1/records/DOC/document_versions', ['document_id' => $doc, 'content_html' => $html, 'change_summary' => 'Emisión'], $h)->assertCreated()->json('data.id');
    $t->patchJson("/api/v1/records/DOC/document_versions/{$v}", ['status' => 'in_review'], $h)->assertOk();

    return $v;
}

it('al aprobar se crea la firma del aprobador y sigue íntegra cuando la versión queda obsoleta', function () {
    $h = docApprovalH();
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $cat = DB::selectOne("select id from document_categories where company_id is null and code = 'PETS'")->id;
    $doc = $this->postJson('/api/v1/records/DOC/documents', ['category_id' => $cat, 'code' => 'PETS-FIR', 'title' => 'Documento firmado'], $h)->assertCreated()->json('data.id');
    $v1 = dsVersion($this, $doc, '<p>Versión 1</p>');
    $h2 = dsApprover();
    $sig = $this->postJson("/api/v1/doc/versions/{$v1}/approve", ['password' => DEMO_PASSWORD], $h2)->assertOk()->json('data.signature_id');
    expect($sig)->not->toBeNull();

    app('auth')->forgetGuards();
    $check = $this->getJson("/api/v1/fir/verify/document_versions/{$v1}", $h)->assertOk()->json('data');
    expect($check)->toHaveCount(1)->and($check[0])->toMatchArray(['result' => 'intact', 'method' => 'pin', 'role_in_document' => 'approver', 'signature_id' => $sig]);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne('select signature_request_id from document_versions where id = ?', [$v1])->signature_request_id)->not->toBeNull()
        ->and(DB::selectOne("select status from signature_requests where subject_id = ? and role_in_document = 'approver'", [$v1])->status)->toBe('signed');

    // Una nueva versión aprobada deja obsoleta la anterior: su firma sigue íntegra.
    $v2 = dsVersion($this, $doc, '<p>Versión 2</p>');
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/doc/versions/{$v2}/approve", ['password' => DEMO_PASSWORD], $h2)->assertOk();
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/records/DOC/document_versions/{$v1}", $h)->assertJsonPath('data.status', 'obsolete');
    $this->getJson("/api/v1/fir/verify/document_versions/{$v1}", $h)->assertOk()->assertJsonPath('data.0.result', 'intact');

    // Si el contenido firmado cambia, la verificación lo detecta.
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update("update document_versions set content_html = '<p>Alterado</p>' where id = ?", [$v1]);
    $this->getJson("/api/v1/fir/verify/document_versions/{$v1}", $h)->assertOk()->assertJsonPath('data.0.result', 'altered');
});

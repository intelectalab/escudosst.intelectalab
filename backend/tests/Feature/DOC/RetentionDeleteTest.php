<?php

// RN-DOC-08: un documento que llegó a publicarse no se borra durante el plazo de conservación de su categoría; un borrador sí.

use Illuminate\Support\Facades\DB;

it('borra un borrador pero no un documento publicado dentro de su plazo de conservación', function () {
    $GLOBALS['sus_tokens'] = [];
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $cat = DB::selectOne("select id from document_categories where company_id is null and code = 'PETS'")->id;
    $code = 'RET-'.substr(md5(uniqid()), 0, 6);

    $draft = $this->postJson('/api/v1/records/DOC/documents', ['category_id' => $cat, 'code' => $code.'A', 'title' => 'Borrador'], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/DOC/document_versions', ['document_id' => $draft, 'content_html' => '<p>Texto</p>', 'change_summary' => 'Primera'], $h)->assertCreated();
    $this->deleteJson("/api/v1/records/DOC/documents/{$draft}", [], $h)->assertNoContent();

    $doc = $this->postJson('/api/v1/records/DOC/documents', ['category_id' => $cat, 'code' => $code.'B', 'title' => 'Publicado'], $h)->assertCreated()->json('data.id');
    $v = $this->postJson('/api/v1/records/DOC/document_versions', ['document_id' => $doc, 'content_html' => '<p>Texto</p>', 'change_summary' => 'Primera'], $h)->assertCreated()->json('data.id');
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update("update document_versions set status = 'current', approved_at = now() where id = ?", [$v]);

    $r = $this->deleteJson("/api/v1/records/DOC/documents/{$doc}", [], $h)->assertStatus(409);
    expect($r->json('detail'))->toContain('se conserva hasta');
    $this->deleteJson("/api/v1/records/DOC/document_versions/{$v}", [], $h)->assertStatus(409);
});

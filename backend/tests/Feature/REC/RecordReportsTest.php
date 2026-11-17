<?php

// Informes imprimibles de registros (§11 de las fichas): revisión por la dirección, constancia de firma, actas de convocatoria e
// instalación del comité, entregas de EPP por trabajador e historial y cargos de un documento.

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

const RR_USER = '01900000-0000-7000-8000-000000000303';

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    rrAs(fn () => DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('RVD', 'FIR', 'COM', 'EPP', 'DOC') and deleted_at is null", [HUAYLAS_ID]));
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

function rrAs(callable $fn): mixed
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    try {
        return $fn();
    } finally {
        DB::select("select set_config('app.company_id', '', false)");
    }
}

it('arma el informe de revisión por la dirección y la constancia de su firma', function () {
    $rev = rrAs(fn () => DB::selectOne("insert into management_reviews (company_id, year, title, review_on, conclusions, inputs, participants, status)
        values (?, 2031, 'Revisión RR', current_date, 'Se cumplió el plan.', '{\"accidentes\": 1}'::jsonb, '[\"Gerente general\", \"Jefe SST\"]'::jsonb, 'in_progress') returning id", [HUAYLAS_ID])->id);
    rrAs(fn () => DB::insert("insert into management_review_outputs (company_id, review_id, output_type, description, responsible_user_id, due_on, status)
        values (?, ?, 'resource', 'Comprar arneses', ?, current_date + 30, 'open')", [HUAYLAS_ID, $rev, RR_USER]));

    $r = $this->getJson("/api/v1/reports/management_reviews/{$rev}", $this->h)->assertOk();
    expect($r->json('data.review.title'))->toBe('Revisión RR')
        ->and($r->json('data.review.inputs'))->toBe(['accidentes' => 1])
        ->and($r->json('data.review.participants'))->toBe(['Gerente general', 'Jefe SST'])
        ->and($r->json('data.outputs.0.description'))->toBe('Comprar arneses');

    $req = $this->postJson('/api/v1/fir/requests', ['subject_table' => 'management_reviews', 'subject_id' => $rev, 'signer_user_id' => RR_USER, 'role_in_document' => 'approver'], $this->h)
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/fir/requests/{$req}/sign", ['password' => DEMO_PASSWORD], $this->h)->assertOk();
    $sig = rrAs(fn () => DB::selectOne('select id from signatures where signature_request_id = ?', [$req])->id);

    $c = $this->getJson("/api/v1/reports/signatures/{$sig}", $this->h)->assertOk();
    expect($c->json('data.signature.document_hash'))->toHaveLength(64)
        ->and($c->json('data.signature.evidence_hash'))->toHaveLength(64)
        ->and($c->json('data.signature.signed_at_lima'))->toMatch('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}$/')
        ->and($c->json('data.signature.subject.table'))->toBe('management_reviews');
    expect($this->getJson("/api/v1/reports/management_reviews/{$rev}", $this->h)->json('data.signatures'))->toHaveCount(1);
});

it('arma las actas de convocatoria e instalación del comité', function () {
    $com = rrAs(fn () => DB::selectOne("insert into committees (company_id, committee_type, name, installation_on, period_start_on, period_end_on, members_required, status)
        values (?, 'committee', 'Comité RR', current_date, current_date, current_date + 730, 4, 'active') returning id", [HUAYLAS_ID])->id);
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(67000000, 67999999), 'first_name' => 'Luis', 'paternal_last_name' => 'Reportes'], $this->h)
        ->assertCreated()->json('data.id');
    rrAs(fn () => DB::insert("insert into committee_members (company_id, committee_id, worker_id, representation, member_type, role_in_committee, starts_on, status)
        values (?, ?, ?, 'workers', 'titular', 'president', current_date, 'active')", [HUAYLAS_ID, $com, $w]));
    $m = $this->getJson("/api/v1/reports/committees/{$com}", $this->h)->assertOk();
    expect($m->json('data.committee.name'))->toBe('Comité RR')->and($m->json('data.members.0.role_in_committee'))->toBe('president')
        ->and($m->json('data.members.0.document'))->toStartWith('****');

    $el = rrAs(fn () => DB::selectOne("insert into elections (company_id, committee_id, title, period_label, status, convened_at, seats_titular, seats_alternate)
        values (?, ?, 'Elección RR', '2031-2032', 'convened', now(), 2, 2) returning id", [HUAYLAS_ID, $com])->id);
    $e = $this->getJson("/api/v1/reports/elections/{$el}/call", $this->h)->assertOk();
    expect($e->json('data.election.title'))->toBe('Elección RR')->and($e->json('data.committee.name'))->toBe('Comité RR');
});

it('arma las entregas de EPP del trabajador y el historial con cargos del documento', function () {
    $w = $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(67000000, 67999999), 'first_name' => 'Luis', 'paternal_last_name' => 'Reportes'], $this->h)
        ->assertCreated()->json('data.id');
    $item = rrAs(fn () => DB::selectOne("insert into ppe_items (company_id, code, name, category, unit) values (?, ?, 'Casco RR', 'head', 'unit') returning id", [HUAYLAS_ID, 'RR-CASCO-'.random_int(1000, 999999)])->id);
    rrAs(fn () => DB::insert("insert into ppe_deliveries (company_id, worker_id, ppe_item_id, quantity, delivered_at, delivery_reason, status) values (?, ?, ?, 1, now(), 'initial', 'delivered')",
        [HUAYLAS_ID, $w, $item]));
    $p = $this->getJson("/api/v1/reports/ppe_deliveries/worker/{$w}", $this->h)->assertOk();
    expect(collect($p->json('data.deliveries'))->pluck('item'))->toContain('Casco RR');

    $cat = rrAs(fn () => DB::selectOne("insert into document_categories (company_id, code, name) values (?, ?, 'Categoría RR') returning id", [HUAYLAS_ID, 'RR-C-'.random_int(1000, 999999)])->id);
    $doc = rrAs(fn () => DB::selectOne("insert into documents (company_id, category_id, code, title, status) values (?, ?, ?, 'Documento RR', 'active') returning id",
        [HUAYLAS_ID, $cat, 'RR-DOC-'.random_int(1000, 999999)])->id);
    $ver = rrAs(fn () => DB::selectOne("insert into document_versions (company_id, document_id, version_number, version_label, change_summary, status, approved_by, approved_at)
        values (?, ?, 1, 'v1', 'Primera versión', 'current', ?, now()) returning id", [HUAYLAS_ID, $doc, RR_USER])->id);
    rrAs(fn () => DB::update('update documents set current_version_id = ? where id = ?', [$ver, $doc]));
    rrAs(fn () => DB::insert("insert into document_acknowledgements (company_id, document_version_id, worker_id, method, acknowledged_at) values (?, ?, ?, 'app_signature', now())",
        [HUAYLAS_ID, $ver, $w]));
    $d = $this->getJson("/api/v1/reports/documents/{$doc}", $this->h)->assertOk();
    expect($d->json('data.versions.0.approved_by'))->not->toBeNull()->and($d->json('data.acknowledgements'))->toHaveCount(1)
        ->and($d->json('data.document.current_version.version_label'))->toBe('v1');

    $this->getJson('/api/v1/reports/documents/01900000-0000-7000-8000-000000009999', $this->h)->assertNotFound();
});

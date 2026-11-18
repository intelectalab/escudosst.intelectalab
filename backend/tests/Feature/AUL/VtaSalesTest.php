<?php

// M-VTA: cupón validado en el servidor (RN-VTA-11), pago manual con evidencia (RN-VTA-14), pedido vencido (RN-VTA-12),
// reembolso total que cancela la matrícula y anula el certificado (RN-VTA-18), cupos corporativos (RN-VTA-15),
// panel, reporte y campaña con consentimiento (RN-VTA-22).

use App\Core\Auth\Permissions;
use App\Core\Tenancy\DbContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->flags = $db->select("select id, mode, source, company_enabled from feature_flags where company_id = ? and module_code in ('VTA', 'AUL')", [HUAYLAS_ID]);
    $db->update("update feature_flags set mode = 'enabled', company_enabled = true where company_id = ? and module_code in ('VTA', 'AUL')", [HUAYLAS_ID]);
    Permissions::flush();
    DB::transaction(function () {
        DbContext::setCompany(HUAYLAS_ID);
        $this->course = DB::selectOne("insert into courses (company_id, code, title, hours, status, published_at) values (?, ?, 'Espacios confinados', 4, 'published', now()) returning id",
            [HUAYLAS_ID, 'VS-'.random_int(1000, 9999)])->id;
        $this->offer = DB::selectOne("insert into course_offers (company_id, course_id, slug, title, list_price, is_public, status, allows_company_purchase)
                                      values (?, ?, ?, 'Espacios confinados', 100, true, 'published', true) returning id", [HUAYLAS_ID, $this->course, 'conf-'.random_int(1000, 9999)])->id;
        DB::insert("insert into coupons (company_id, code, discount_type, discount_value, applies_to, max_redemptions_per_buyer, is_active)
                    values (?, 'PRUEBA20', 'percent', 20, 'all', 1, true)", [HUAYLAS_ID]);
        DbContext::setCompany(null);
    });
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    foreach ($this->flags as $f) {
        $db->update('update feature_flags set mode = ?, source = ?, company_enabled = ? where id = ?', [$f->mode, $f->source, $f->company_enabled ? 'true' : 'false', $f->id]);
    }
    app('auth')->forgetGuards();
    Permissions::flush();
});

function vtaQuery(string $sql, array $b = []): array
{
    return DB::transaction(function () use ($sql, $b) {
        DbContext::setCompany(HUAYLAS_ID);
        $r = DB::select($sql, $b);
        DbContext::setCompany(null);

        return $r;
    });
}

function vtaToken(string $userId): string
{
    $plain = \Illuminate\Support\Str::random(40);
    $id = DB::selectOne("insert into personal_access_tokens (id, tokenable_type, tokenable_id, name, token, abilities, created_at, updated_at)
                         values (gen_random_uuid(), ?, ?, 'e2e-vp', ?, '[\"*\"]', now(), now()) returning id", [\App\Models\User::class, $userId, hash('sha256', $plain)])->id;

    return $id.'|'.$plain;
}

function vtaPerson(array $extra = []): array
{
    return array_merge(['buyer_type' => 'person', 'document_type' => 'DNI', 'document_number' => '72727272', 'first_name' => 'Ana', 'last_name' => 'Ríos', 'email' => 'ana.vs@example.com'], $extra);
}

it('aplica el cupón, exige la evidencia del pago, lo cuenta al pagar y lo rechaza al mismo comprador', function () {
    $this->postJson('/api/v1/vta/sell', vtaPerson(['course_offer_id' => $this->offer, 'coupon_code' => 'NOEXISTE']), $this->h)->assertStatus(422)->assertJsonValidationErrors('coupon_code');
    $o = $this->postJson('/api/v1/vta/sell', vtaPerson(['course_offer_id' => $this->offer, 'coupon_code' => 'prueba20']), $this->h)->assertCreated()->json('data');
    expect($o['total'])->toEqual(80)->and($o['discount'])->toEqual(20);

    $this->postJson("/api/v1/vta/orders/{$o['id']}/pay", ['method' => 'yape', 'reference' => 'VS-0001'], $this->h)->assertStatus(422)->assertJsonValidationErrors('evidence_file_id');
    $this->postJson("/api/v1/vta/orders/{$o['id']}/pay", ['method' => 'yape', 'reference' => 'VS-0001', 'evidence_file_id' => evidenceFile($this->h, 'VTA')], $this->h)->assertOk();
    $row = vtaQuery('select o.manual_payment_method, o.manual_payment_evidence_file_id, c.redemptions_count from orders o join coupons c on c.id = o.coupon_id where o.id = ?', [$o['id']])[0];
    expect($row->manual_payment_method)->toBe('yape')->and($row->manual_payment_evidence_file_id)->not->toBeNull()->and($row->redemptions_count)->toBe(1);

    // Máximo un uso por comprador.
    $this->postJson('/api/v1/vta/sell', vtaPerson(['course_offer_id' => $this->offer, 'coupon_code' => 'PRUEBA20']), $this->h)->assertStatus(422)->assertJsonValidationErrors('coupon_code');
});

it('un pedido vencido no se cobra y el reembolso total cancela la matrícula y anula el certificado', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $o = $this->postJson('/api/v1/vta/sell', vtaPerson(['course_offer_id' => $this->offer, 'document_number' => '73737373', 'email' => 'beto.vs@example.com']), $this->h)->json('data');
    DB::transaction(function () use ($o) {
        DbContext::setCompany(HUAYLAS_ID);
        DB::update("update orders set expires_at = now() - interval '1 minute' where id = ?", [$o['id']]);
        expect(\App\Modules\AUL\VtaSales::expire(DB::connection()))->toBe(1);
        DbContext::setCompany(null);
    });
    $this->postJson("/api/v1/vta/orders/{$o['id']}/pay", ['method' => 'cash', 'evidence_file_id' => evidenceFile($this->h, 'VTA')], $this->h)->assertStatus(409);

    $o = $this->postJson('/api/v1/vta/sell', vtaPerson(['course_offer_id' => $this->offer, 'document_number' => '73737373', 'email' => 'beto.vs@example.com']), $this->h)->json('data');
    $this->postJson("/api/v1/vta/orders/{$o['id']}/pay", ['method' => 'transfer', 'reference' => 'VS-0002', 'evidence_file_id' => evidenceFile($this->h, 'VTA')], $this->h)->assertOk();
    $enr = vtaQuery('select e.id, e.external_learner_id from course_enrollments e join order_items i on i.id = e.order_item_id where i.order_id = ?', [$o['id']])[0];
    DB::transaction(function () use ($enr) {
        DbContext::setCompany(HUAYLAS_ID);
        DB::insert("insert into training_certificates (company_id, course_id, course_enrollment_id, external_learner_id, certificate_code, verification_code, holder_name, course_title, issued_on, status)
                    values (?, ?, ?, ?, 'VS-2026-000001', 'VSVER0000001', 'Beto', 'Espacios confinados', current_date, 'pending_validation')", [HUAYLAS_ID, $this->course, $enr->id, $enr->external_learner_id]);
        DbContext::setCompany(null);
    });

    $this->postJson("/api/v1/vta/orders/{$o['id']}/refund", ['amount' => 150, 'reason' => 'Pidió la devolución'], $this->h)->assertStatus(422)->assertJsonValidationErrors('amount');
    $this->postJson("/api/v1/vta/orders/{$o['id']}/refund", ['amount' => 30, 'reason' => 'Descuento acordado'], $this->h)->assertOk()->assertJsonPath('data.status', 'partially_refunded');
    $this->postJson("/api/v1/vta/orders/{$o['id']}/refund", ['amount' => 70, 'reason' => 'Pidió la devolución'], $this->h)->assertOk()
        ->assertJsonPath('data.status', 'refunded')->assertJsonPath('data.enrollments_cancelled', 1)->assertJsonPath('data.certificates_voided', 1);
    $r = vtaQuery("select e.status as e, c.status as c, o.refunded_total from course_enrollments e join training_certificates c on c.course_enrollment_id = e.id
                     join order_items i on i.id = e.order_item_id join orders o on o.id = i.order_id where e.id = ?", [$enr->id])[0];
    expect($r->e)->toBe('cancelled')->and($r->c)->toBe('voided')->and((float) $r->refunded_total)->toEqual(100.0);
    // M-VTA §10: el comprador recibe el aviso de cada reembolso confirmado.
    \Illuminate\Support\Facades\Mail::assertSent(\App\Modules\WEB\Mail\SiteRequestMail::class, fn ($m) => $m->hasTo('beto.vs@example.com') && str_contains($m->title, 'Reembolso'));
});

it('asigna cupos hasta el máximo y solo libera a quien no inició el curso', function () {
    $o = $this->postJson('/api/v1/vta/sell', ['course_offer_id' => $this->offer, 'buyer_type' => 'company', 'document_type' => 'RUC', 'document_number' => '20545454541',
        'legal_name' => 'Cliente VS SAC', 'ruc' => '20545454541', 'email' => 'compras.vs@example.com', 'seats' => 2], $this->h)->assertCreated()->json('data');
    $this->postJson("/api/v1/vta/orders/{$o['id']}/pay", ['method' => 'transfer', 'reference' => 'VS-0003', 'evidence_file_id' => evidenceFile($this->h, 'VTA')], $this->h)->assertOk();
    $lic = vtaQuery('select course_license_id as id from order_items where order_id = ?', [$o['id']])[0]->id;
    $p = fn ($n) => ['document_type' => 'DNI', 'document_number' => "7474747{$n}", 'first_name' => "P{$n}", 'last_name' => 'Cupo', 'email' => "p{$n}.vs@example.com"];

    $a1 = $this->postJson("/api/v1/vta/licenses/{$lic}/assignments", $p(1), $this->h)->assertCreated()->json('data');
    $this->postJson("/api/v1/vta/licenses/{$lic}/assignments", $p(1), $this->h)->assertStatus(409);
    $this->postJson("/api/v1/vta/licenses/{$lic}/assignments", $p(2), $this->h)->assertCreated();
    $this->postJson("/api/v1/vta/licenses/{$lic}/assignments", $p(3), $this->h)->assertStatus(409);
    expect($this->getJson("/api/v1/vta/licenses/{$lic}/assignments", $this->h)->assertOk()->json('data.license.used'))->toBe(2);

    // Inició el curso: no se libera.
    DB::transaction(function () use ($a1) {
        DbContext::setCompany(HUAYLAS_ID);
        DB::update("update course_enrollments set started_at = now(), status = 'in_progress' where id = ?", [$a1['enrollment_id']]);
        DbContext::setCompany(null);
    });
    $this->postJson("/api/v1/vta/license-assignments/{$a1['assignment_id']}/release", ['reason' => 'Cambio de personal'], $this->h)->assertStatus(409);
    $a2 = vtaQuery("select id from license_assignments where course_license_id = ? and id <> ?", [$lic, $a1['assignment_id']])[0]->id;
    $this->postJson("/api/v1/vta/license-assignments/{$a2}/release", ['reason' => 'Cambio de personal'], $this->h)->assertOk();
    $this->postJson("/api/v1/vta/licenses/{$lic}/assignments", $p(3), $this->h)->assertCreated();
});

it('muestra el panel, exporta el reporte y la campaña excluye a quien no aceptó publicidad o ya tiene el curso', function () {
    $o = $this->postJson('/api/v1/vta/sell', vtaPerson(['course_offer_id' => $this->offer, 'document_number' => '75757575', 'email' => 'caro.vs@example.com']), $this->h)->json('data');
    $this->postJson("/api/v1/vta/orders/{$o['id']}/pay", ['method' => 'plin', 'reference' => 'VS-0004', 'evidence_file_id' => evidenceFile($this->h, 'VTA')], $this->h)->assertOk();
    $d = $this->getJson('/api/v1/vta/dashboard', $this->h)->assertOk()->json('data');
    expect($d['paid'])->toBeGreaterThanOrEqual(1)->and($d['sales'])->toBeGreaterThanOrEqual(100);
    $this->get('/api/v1/vta/reports.xlsx', $this->h)->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    // Base: dos aprobados del curso base; uno con publicidad aceptada, otro sin ella.
    $base = vtaQuery("insert into courses (company_id, code, title, status, published_at) values (?, ?, 'Curso base', 'published', now()) returning id", [HUAYLAS_ID, 'VB-'.random_int(1000, 9999)])[0]->id;
    foreach ([['76767676', true], ['77777777', false]] as [$doc, $mk]) {
        DB::transaction(function () use ($doc, $mk, $base) {
            DbContext::setCompany(HUAYLAS_ID);
            $l = DB::selectOne("insert into external_learners (company_id, document_type, document_number, document_number_hash, document_last4, first_name, last_name, email, data_consent_at, consent_text_version, marketing_consent, marketing_consent_at, source)
                                values (?, 'DNI', 'x', decode(md5(?), 'hex'), right(?, 4), 'Base', 'Persona', ?, now(), 'v1', ?, case when ? then now() end, 'manual') returning id",
                [HUAYLAS_ID, $doc, $doc, "b{$doc}@example.com", $mk ? 'true' : 'false', $mk ? 'true' : 'false'])->id;
            DB::insert("insert into course_enrollments (company_id, course_id, external_learner_id, source, status, passed, completed_at) values (?, ?, ?, 'manual', 'completed', true, now())", [HUAYLAS_ID, $base, $l]);
            DbContext::setCompany(null);
        });
    }
    $body = ['course_offer_id' => $this->offer, 'audience' => 'passed_course', 'base_course_id' => $base];
    $this->postJson('/api/v1/vta/campaigns', $body, $this->h)->assertOk()->assertJsonPath('data.base', 2)->assertJsonPath('data.without_consent', 1)->assertJsonPath('data.reach', 1);
    $this->postJson('/api/v1/vta/campaigns', $body + ['send' => true], $this->h)->assertCreated()->assertJsonPath('data.reach', 1);
    expect(vtaQuery("select count(*) as n from course_recommendations where reason_code = 'campaign' and course_offer_id = ?", [$this->offer])[0]->n)->toBe(1);
});

it('al pagar crea el portal del comprador y del representante, que solo ve y libera sus cupos', function () {
    $n = random_int(100000, 999999);
    $o = $this->postJson('/api/v1/vta/sell', vtaPerson(['course_offer_id' => $this->offer, 'document_number' => '78787878', 'email' => "luis.vp{$n}@example.com"]), $this->h)->json('data');
    $this->postJson("/api/v1/vta/orders/{$o['id']}/pay", ['method' => 'yape', 'reference' => 'VP-0001', 'evidence_file_id' => evidenceFile($this->h, 'VTA')], $this->h)->assertOk();
    $c = $this->postJson('/api/v1/vta/sell', ['course_offer_id' => $this->offer, 'buyer_type' => 'company', 'document_type' => 'RUC', 'document_number' => '20565656561',
        'legal_name' => 'Compradora VP SAC', 'ruc' => '20565656561', 'email' => "rep.vp{$n}@example.com", 'seats' => 2], $this->h)->json('data');
    $this->postJson("/api/v1/vta/orders/{$c['id']}/pay", ['method' => 'transfer', 'reference' => 'VP-0002', 'evidence_file_id' => evidenceFile($this->h, 'VTA')], $this->h)->assertOk();

    $luis = DB::connection('platform')->selectOne('select id, status from users where email = ?', ["luis.vp{$n}@example.com"]);
    $rep = DB::connection('platform')->selectOne('select id from users where email = ?', ["rep.vp{$n}@example.com"]);
    expect($luis->status)->toBe('invited')->and($rep)->not->toBeNull();
    $enr = vtaQuery('select e.user_id from course_enrollments e join order_items i on i.id = e.order_item_id where i.order_id = ?', [$o['id']])[0];
    expect($enr->user_id)->toBe($luis->id);

    // El comprador ve su pedido y cambia su consentimiento de publicidad.
    app('auth')->forgetGuards();
    Permissions::flush();
    $hl = authHeaders(vtaToken($luis->id), HUAYLAS_ID);
    $p = $this->getJson('/api/v1/vta/portal', $hl)->assertOk()->json('data');
    expect($p['orders'])->toHaveCount(1)->and($p['licenses'])->toBe([]);
    $this->putJson('/api/v1/vta/portal/consents', ['marketing_consent' => true], $hl)->assertOk();
    expect($this->getJson('/api/v1/aul/my', $hl)->assertOk()->json('data'))->toHaveCount(1);

    // El representante asigna y libera solo sus cupos; el comprador no ve los cupos ajenos.
    app('auth')->forgetGuards();
    Permissions::flush();
    $hr = authHeaders(vtaToken($rep->id), HUAYLAS_ID);
    $lic = $this->getJson('/api/v1/vta/portal', $hr)->assertOk()->json('data.licenses.0.id');
    $a = $this->postJson("/api/v1/vta/licenses/{$lic}/assignments", ['document_type' => 'DNI', 'document_number' => '79797979', 'first_name' => 'Mia', 'last_name' => 'Cupo', 'email' => "mia.vp{$n}@example.com"], $hr)
        ->assertCreated()->json('data');
    $this->postJson("/api/v1/vta/license-assignments/{$a['assignment_id']}/release", ['reason' => 'Ya no trabaja'], $hr)->assertOk();
    app('auth')->forgetGuards();
    Permissions::flush();
    $this->getJson("/api/v1/vta/licenses/{$lic}/assignments", $hl)->assertNotFound();
    DB::delete("delete from personal_access_tokens where name = 'e2e-vp'");
});

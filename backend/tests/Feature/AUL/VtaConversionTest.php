<?php

// M-VTA RN-VTA-23 y §11: la compra de cupos de una empresa (sin matrícula) convierte la recomendación del curso hecha a esa empresa,
// dentro del periodo de atribución; reporte de conversión por pedido, campaña y empresa compradora (JSON y Excel).

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
        $this->course = DB::selectOne("insert into courses (company_id, code, title, hours, status, published_at) values (?, ?, 'Trabajo en altura', 4, 'published', now()) returning id",
            [HUAYLAS_ID, 'VC-'.random_int(1000, 9999)])->id;
        $this->offer = DB::selectOne("insert into course_offers (company_id, course_id, slug, title, list_price, is_public, status, allows_company_purchase)
                                      values (?, ?, ?, 'Trabajo en altura', 100, true, 'published', true) returning id", [HUAYLAS_ID, $this->course, 'alt-'.random_int(1000, 9999)])->id;
        DbContext::setCompany(null);
    });
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->me = DB::selectOne('select id from app_auth_lookup_user(?)', [CORPORATIVO_EMAIL])->id;
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

function vtcQuery(string $sql, array $b = []): array
{
    return DB::transaction(function () use ($sql, $b) {
        DbContext::setCompany(HUAYLAS_ID);
        $r = DB::select($sql, $b);
        DbContext::setCompany(null);

        return $r;
    });
}

it('atribuye la compra de cupos de la empresa a su recomendación vista y la reporta por pedido, campaña y empresa', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $ruc = '2057'.random_int(1000000, 9999999);
    // Recomendación vista hace 2 días (a la empresa) y otra a otra empresa que no compra.
    [$seen, $other] = vtcQuery("with a as (
            insert into course_recommendations (company_id, course_id, course_offer_id, buyer_company_ruc, buyer_company_name, source, recommended_by, target_scope, reason_code, channel, status, sent_at, viewed_at, dedupe_key)
            values (?, ?, ?, ?, 'Compradora VC SAC', 'manual', ?, 'buyer_company', 'campaign', 'email', 'viewed', now() - interval '3 days', now() - interval '2 days', ?),
                   (?, ?, ?, '20999999991', 'Otra SAC', 'manual', ?, 'buyer_company', 'campaign', 'email', 'sent', now() - interval '3 days', null, ?)
            returning id, buyer_company_ruc) select id from a order by buyer_company_ruc = ? desc",
        [HUAYLAS_ID, $this->course, $this->offer, $ruc, $this->me, 'vc:'.$ruc, HUAYLAS_ID, $this->course, $this->offer, $this->me, 'vc-other:'.$ruc, $ruc]);

    $o = $this->postJson('/api/v1/vta/sell', ['course_offer_id' => $this->offer, 'buyer_type' => 'company', 'document_type' => 'RUC', 'document_number' => $ruc,
        'legal_name' => 'Compradora VC SAC', 'ruc' => $ruc, 'email' => 'compras.vc@example.com', 'seats' => 3], $this->h)->assertCreated()->json('data');
    $this->postJson("/api/v1/vta/orders/{$o['id']}/pay", ['method' => 'transfer', 'reference' => 'VC-'.random_int(1000, 9999), 'evidence_file_id' => evidenceFile($this->h, 'VTA')], $this->h)->assertOk();

    $r = vtcQuery('select id, status, converted_order_id, converted_enrollment_id from course_recommendations where id in (?, ?) order by id = ? desc', [$seen->id, $other->id, $seen->id]);
    expect($r[0]->status)->toBe('converted')->and($r[0]->converted_order_id)->toBe($o['id'])->and($r[0]->converted_enrollment_id)->toBeNull()
        ->and($r[1]->status)->toBe('sent');

    $d = $this->getJson('/api/v1/vta/conversion?from='.now('America/Lima')->subDays(10)->toDateString().'&to='.now('America/Lima')->toDateString(), $this->h)->assertOk()->json('data');
    $order = collect($d['orders'])->firstWhere('id', $o['id']);
    expect($order)->not->toBeNull()->and($order['buyer_ruc'])->toBe($ruc)->and($order['campaign'])->toContain('Trabajo en altura')->and((float) $order['amount'])->toBeGreaterThan(0);
    $camp = collect($d['campaigns'])->firstWhere('offer', 'Trabajo en altura');
    expect($camp['sent'])->toBe(2)->and($camp['converted'])->toBe(1)->and($camp['orders'])->toBe(1)->and($camp['conversion_pct'])->toEqual(50);
    $co = collect($d['companies'])->firstWhere('ruc', $ruc);
    expect($co['converted'])->toBe(1)->and($co['orders'])->toBe(1)->and((float) $co['amount'])->toEqual((float) $order['amount']);

    $this->get('/api/v1/vta/conversion.xlsx', $this->h)->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $this->getJson('/api/v1/vta/conversion?from=2026-05-01&to=2026-04-01', $this->h)->assertStatus(422)->assertJsonValidationErrors('to');
});

it('no atribuye la compra de cupos si la vista es anterior al periodo de atribución', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $ruc = '2058'.random_int(1000000, 9999999);
    $param = vtcQuery("insert into legal_parameters (company_id, key, value, value_type, description, valid_from, to_confirm)
                       values (?, 'recommendation_attribution_days', '5', 'integer', 'prueba', date '2020-01-01', false) returning id", [HUAYLAS_ID])[0]->id;
    try {
        $old = vtcQuery("insert into course_recommendations (company_id, course_id, buyer_company_ruc, source, recommended_by, target_scope, reason_code, channel, status, sent_at, clicked_at, dedupe_key)
                         values (?, ?, ?, 'manual', ?, 'buyer_company', 'manual', 'email', 'clicked', now() - interval '20 days', now() - interval '10 days', ?) returning id",
            [HUAYLAS_ID, $this->course, $ruc, $this->me, 'vc-old:'.$ruc])[0]->id;
        $o = $this->postJson('/api/v1/vta/sell', ['course_offer_id' => $this->offer, 'buyer_type' => 'company', 'document_type' => 'RUC', 'document_number' => $ruc,
            'legal_name' => 'Tardía SAC', 'ruc' => $ruc, 'email' => 'compras.vt@example.com', 'seats' => 1], $this->h)->assertCreated()->json('data');
        $this->postJson("/api/v1/vta/orders/{$o['id']}/pay", ['method' => 'cash', 'evidence_file_id' => evidenceFile($this->h, 'VTA')], $this->h)->assertOk();
        expect(vtcQuery('select status from course_recommendations where id = ?', [$old])[0]->status)->toBe('clicked');
    } finally {
        vtcQuery('update legal_parameters set deleted_at = now() where id = ?', [$param]);
    }
});

<?php

// Partners y referidos (SCR-WEB-35/46, RN-WEB-46 a 48): la solicitud crea el interesado y queda pendiente; el Dueño
// aprueba (genera el código) o rechaza con motivo; el registro con ?ref= crea el referido salvo con el RUC del partner.

use App\Modules\WEB\Http\Controllers\PartnersController;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    config(['mail.default' => 'array']);
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

function partnerApply(array $extra = []): void
{
    test()->postJson('/api/v1/public/partners', $extra + [
        'partner_type' => 'consultancy', 'full_name' => 'Rosa Consultora', 'email' => 'rosa@consultora.test', 'company_name' => 'Consultora Rosa SAC',
        'ruc' => '20100047218', 'website' => 'https://consultora.test', 'privacy_consent' => true,
    ])->assertCreated();
}

it('la solicitud valida el RUC, crea el interesado «partner» y no se duplica', function () {
    $this->postJson('/api/v1/public/partners', ['partner_type' => 'consultancy', 'full_name' => 'Rosa', 'email' => 'x@y.test', 'company_name' => 'XY Consultores', 'ruc' => '20100047219', 'privacy_consent' => true])
        ->assertStatus(422)->assertJsonValidationErrors('ruc');
    partnerApply();
    partnerApply();
    $db = DB::connection('platform');
    expect($db->selectOne("select source from marketing_leads where email = 'rosa@consultora.test'")->source)->toBe('partner');
    expect($db->selectOne("select count(*) as n from partner_applications a join marketing_leads l on l.id = a.lead_id where l.email = 'rosa@consultora.test'")->n)->toBe(1);
});

it('el Dueño aprueba con código, el registro con ?ref= crea el referido y el mismo RUC no cuenta', function () {
    partnerApply();
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $app = collect($this->getJson('/api/v1/internal/site/partners', $h)->assertOk()->json('data.applications'))->firstWhere('email', 'rosa@consultora.test');
    $this->postJson("/api/v1/internal/site/partners/{$app['id']}/review", ['approve' => false], $h)->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/internal/site/partners/{$app['id']}/review", ['approve' => true, 'code' => 'rosa-sst'], $h)->assertOk()->assertJsonPath('data.referral_code', 'ROSA-SST');
    $this->postJson("/api/v1/internal/site/partners/{$app['id']}/review", ['approve' => true], $h)->assertStatus(422);
    $this->getJson('/api/v1/public/partners/ref/rosa-sst')->assertJsonPath('data.valid', true);

    // Registro autoservicio con ?ref= (la llamada que hace POST /public/trial después de crear la cuenta).
    $cos = DB::connection('platform')->select('select account_id, ruc from companies where deleted_at is null order by created_at limit 2');
    PartnersController::attribute('ROSA-SST', $cos[0]->account_id, '20100047218', null);   // RUC del partner: no se atribuye
    PartnersController::attribute('ROSA-SST', $cos[1]->account_id, trim($cos[1]->ruc), null);
    PartnersController::attribute('OTRO-CODIGO', $cos[0]->account_id, trim($cos[0]->ruc), null);   // código inexistente
    $refs = $this->getJson('/api/v1/internal/site/partners', $h)->json('data.referrals');
    expect($refs)->toHaveCount(1)->and($refs[0]['status'])->toBe('trial');

    $code = collect($this->getJson('/api/v1/internal/site/partners', $h)->json('data.applications'))->firstWhere('email', 'rosa@consultora.test');
    expect($code['referrals'])->toBe(1)->and($code['reward_rule_json'])->toBe([]);
    $this->patchJson("/api/v1/internal/site/partners/codes/{$code['code_id']}", ['is_active' => true, 'reward_type' => 'discount'], $h)->assertStatus(422);
    $this->patchJson("/api/v1/internal/site/partners/codes/{$code['code_id']}", ['is_active' => false, 'reward_type' => 'discount', 'reward_value' => '10 %'], $h)->assertNoContent();
    $this->getJson('/api/v1/public/partners/ref/ROSA-SST')->assertJsonPath('data.valid', false);
    $this->patchJson("/api/v1/internal/site/partners/referrals/{$refs[0]['id']}", ['status' => 'customer'], $h)->assertNoContent();
    expect(DB::connection('platform')->selectOne('select converted_at from referrals where id = ?', [$refs[0]['id']])->converted_at)->not->toBeNull();
});

it('Soporte ve las solicitudes pero no las aprueba', function () {
    partnerApply();
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $id = $this->getJson('/api/v1/internal/site/partners', $h)->json('data.applications.0.id');
    $admin = DB::connection('platform')->selectOne('select id from users where email = ?', [ADMIN_EMAIL])->id;
    DB::connection('pgsql')->select("select set_config('app.user_id', ?, false)", [$admin]);
    DB::connection('pgsql')->update("update users set platform_role = 'support' where id = ?", [$admin]);
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/internal/site/partners', $h)->assertOk();
    $this->postJson("/api/v1/internal/site/partners/{$id}/review", ['approve' => true], $h)->assertForbidden();
});

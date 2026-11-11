<?php

// Matriz de requisitos legales (M-MRL SCR-MRL-01/03): catálogo de normas globales y propias, y «Agregar desde el catálogo».

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function mrlH(string $email = PROFESIONAL_EMAIL, string $company = ANDINAS_ID): array
{
    return authHeaders(tokenFor($email), $company);
}

function mrlMe(): string
{
    return DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
}

it('lista el catálogo global con filtros por sector y tema', function () {
    $all = $this->getJson('/api/v1/mrl/catalog', mrlH())->assertOk();
    $ley = collect($all->json('data'))->firstWhere('number', '29783');
    expect($ley)->not->toBeNull()->and($ley['is_company'])->toBeFalse()->and($ley['requirements_count'])->toBeGreaterThan(3)
        ->and($all->json('meta.sectors'))->toContain('construccion')->and($all->json('meta.can_add'))->toBeTrue();

    $cons = collect($this->getJson('/api/v1/mrl/catalog?sector=construccion', mrlH())->assertOk()->json('data'))->pluck('number');
    expect($cons)->toContain('011-2019-TR')->not->toContain('024-2016-EM');
    $ergo = collect($this->getJson('/api/v1/mrl/catalog?topic=ergonomia', mrlH())->json('data'))->pluck('number')->all();
    expect($ergo)->toBe(['375-2008-TR']);
    expect(collect($this->getJson('/api/v1/mrl/catalog?q=miner', mrlH())->json('data'))->pluck('number'))->toContain('024-2016-EM');
});

it('previsualiza y agrega los requisitos elegidos con responsable y próxima evaluación, sin duplicar', function () {
    $ley = collect($this->getJson('/api/v1/mrl/catalog?q=29783', mrlH())->json('data'))->firstWhere('number', '29783');
    $prev = $this->getJson("/api/v1/mrl/catalog/requirements?norm_ids={$ley['id']}", mrlH())->assertOk();
    $reqs = $prev->json('data.0.requirements');
    expect($reqs)->not->toBeEmpty()->and($reqs[0]['added'])->toBeFalse();

    $this->postJson('/api/v1/mrl/catalog/add', ['items' => [['template_id' => $reqs[0]['id']]]], mrlH())->assertStatus(422)->assertJsonValidationErrors('responsible_user_id');
    $this->postJson('/api/v1/mrl/catalog/add', ['items' => [['template_id' => $reqs[0]['id']]], 'responsible_user_id' => mrlMe(), 'next_evaluation_on' => '2020-01-01'], mrlH())
        ->assertStatus(422)->assertJsonValidationErrors('next_evaluation_on');

    $r = $this->postJson('/api/v1/mrl/catalog/add', [
        'items' => [['template_id' => $reqs[0]['id']], ['template_id' => $reqs[1]['id']], ['legal_norm_id' => $ley['id'], 'article' => 'Art. 99', 'requirement' => 'Requisito propio de la empresa']],
        'responsible_user_id' => mrlMe(),
    ], mrlH())->assertCreated();
    expect($r->json('data.created'))->toBe(3)->and($r->json('data.skipped'))->toBe(0);
    $id = $r->json('data.ids.0');
    $row = $this->getJson("/api/v1/records/MRL/legal_requirements/{$id}", mrlH())->assertOk();
    expect($row->json('data.responsible_user_id'))->toBe(mrlMe())->and($row->json('data.status'))->toBe('active')
        ->and(substr((string) $row->json('data.next_evaluation_on'), 0, 4))->toBeGreaterThanOrEqual(date('Y'));

    // Los ya agregados se marcan y no se duplican.
    expect($this->getJson("/api/v1/mrl/catalog/requirements?norm_ids={$ley['id']}", mrlH())->json('data.0.requirements.0.added'))->toBeTrue();
    $again = $this->postJson('/api/v1/mrl/catalog/add', ['items' => [['template_id' => $reqs[0]['id']]], 'responsible_user_id' => mrlMe()], mrlH())->assertCreated();
    expect($again->json('data.created'))->toBe(0)->and($again->json('data.skipped'))->toBe(1);
    expect(collect($this->getJson('/api/v1/mrl/catalog?q=29783', mrlH())->json('data'))->firstWhere('number', '29783')['added_count'])->toBe(3);
});

it('la empresa registra normas propias que solo ella ve y no edita las globales', function () {
    $this->postJson('/api/v1/mrl/catalog/norms', ['norm_type' => 'ordinance', 'number' => ''], mrlH())->assertStatus(422)->assertJsonValidationErrors('number');
    $id = $this->postJson('/api/v1/mrl/catalog/norms', [
        'norm_type' => 'ordinance', 'number' => 'OM 123-2026-MDL', 'title' => 'Ordenanza de seguridad en edificaciones', 'sectors' => ['construccion'], 'topics' => ['gestion'],
    ], mrlH())->assertCreated()->json('data.id');
    $this->postJson('/api/v1/mrl/catalog/norms', ['norm_type' => 'ordinance', 'number' => 'om 123-2026-mdl', 'title' => 'Repetida'], mrlH())->assertStatus(422);

    $mine = collect($this->getJson('/api/v1/mrl/catalog?origin=company', mrlH())->json('data'));
    expect($mine->pluck('id')->all())->toBe([$id])->and($mine[0]['is_company'])->toBeTrue()->and($mine[0]['sectors'])->toBe(['construccion']);
    // Otra empresa no la ve.
    expect(collect($this->getJson('/api/v1/mrl/catalog', mrlH(CORPORATIVO_EMAIL, HUAYLAS_ID))->json('data'))->pluck('id'))->not->toContain($id);

    $this->patchJson("/api/v1/mrl/catalog/norms/{$id}", ['norm_type' => 'ordinance', 'number' => 'OM 123-2026-MDL', 'title' => 'Ordenanza actualizada'], mrlH())->assertOk();
    expect(collect($this->getJson('/api/v1/mrl/catalog?origin=company', mrlH())->json('data'))[0]['title'])->toBe('Ordenanza actualizada');

    $global = collect($this->getJson('/api/v1/mrl/catalog?q=29783', mrlH())->json('data'))->firstWhere('number', '29783');
    $this->patchJson("/api/v1/mrl/catalog/norms/{$global['id']}", ['norm_type' => 'law', 'number' => '29783', 'title' => 'Cambiada'], mrlH())->assertStatus(403);

    // Requisito propio sobre la norma propia.
    $this->postJson('/api/v1/mrl/catalog/add', ['items' => [['legal_norm_id' => $id, 'requirement' => 'Licencia de funcionamiento con ITSE vigente']], 'responsible_user_id' => mrlMe()], mrlH())
        ->assertCreated()->assertJsonPath('data.created', 1);
});

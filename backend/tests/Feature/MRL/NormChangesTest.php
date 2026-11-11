<?php

// Novedades normativas (M-MRL RN-MRL-05, criterio 2, SCR-MRL-05): si una norma del catálogo global pasa a «modificada»,
// los requisitos vinculados de la empresa quedan «requiere revisión» y se avisa al Jefe SST; él lo marca «Revisado».

use App\Modules\MRL\NormChanges;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->delete("delete from legal_norms where company_id is null and number in ('777-2026-TR', '778-2026-TR')");
    $this->started = DB::connection('platform')->selectOne("select now() - interval '1 second' as t")->t;
});

afterEach(function () {
    // La plataforma no entra en la transacción de la prueba: se deshace la de la empresa y se borra la norma creada.
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->delete('delete from legal_norms where company_id is null and created_at >= ?', [$this->started]);
});

function ncPlatform(string $method, string $uri, array $data = [])
{
    app('auth')->forgetGuards();

    return test()->json($method, '/api/v1/internal/catalogs'.$uri, $data, authHeaders(tokenFor(ADMIN_EMAIL)));
}

it('al modificar una norma global en la plataforma se dispara la revisión; un cambio sin contenido no', function () {
    $norm = ['norm_type' => 'supreme_decree', 'number' => '778-2026-TR', 'title' => 'Norma de prueba de la plataforma', 'scope' => 'general', 'status' => 'current'];
    $nid = ncPlatform('POST', '/legal-norms', $norm)->assertCreated()->json('data.id');
    ncPlatform('PATCH', "/legal-norms/{$nid}", ['issuing_entity' => 'MTPE'] + $norm)->assertOk()->assertJsonMissingPath('data.flagged');
    ncPlatform('PATCH', "/legal-norms/{$nid}", ['status' => 'amended'] + $norm)->assertOk()->assertJsonPath('data.flagged.requirements', 0);
    expect(NormChanges::reason(['status' => 'current', 'title' => 'A'], ['status' => 'repealed', 'title' => 'A']))->toBe('La norma fue derogada.')
        ->and(NormChanges::reason(['title' => 'A', 'summary' => null], ['title' => 'A', 'summary' => '']))->toBeNull()
        ->and(NormChanges::reason(['title' => 'A'], ['title' => 'B']))->toContain('título');
});

it('marca los requisitos vinculados como «requiere revisión», avisa al Jefe SST y se revisan', function () {
    $norm = ['norm_type' => 'supreme_decree', 'number' => '777-2026-TR', 'title' => 'Norma de prueba de novedades', 'scope' => 'general', 'status' => 'current'];
    $nid = ncPlatform('POST', '/legal-norms', $norm)->assertCreated()->json('data.id');

    app('auth')->forgetGuards();
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $me = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    $req = $this->postJson('/api/v1/mrl/catalog/add', ['items' => [['legal_norm_id' => $nid, 'article' => 'Art. 1', 'requirement' => 'Cumplir la norma de prueba']],
        'responsible_user_id' => $me], $h)->assertCreated()->json('data.ids.0');

    // Lo que hace la plataforma al publicar la norma modificada (aquí sin tocar la norma, que la transacción de la prueba tiene referenciada).
    $flag = app(NormChanges::class)->flag($nid, 'La norma fue modificada.');
    expect($flag['requirements'])->toBeGreaterThanOrEqual(1)->and($flag['notified'])->toBeGreaterThanOrEqual(1);

    $news = $this->getJson('/api/v1/mrl/news', $h)->assertOk()->json('data');
    $row = collect($news['needs_review'])->firstWhere('id', $req);
    expect($row)->not->toBeNull()->and($row['review_reason'])->toBe('La norma fue modificada.')->and($news['can_review'])->toBeTrue()
        ->and(collect($news['recent_norms'])->firstWhere('id', $nid)['review_count'])->toBe(1);
    expect(collect($this->getJson('/api/v1/mrl/catalog?q=777-2026', $h)->json('data'))->firstWhere('id', $nid)['review_count'])->toBe(1);
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne("select count(*) as n from notifications where event_code = 'mrl.norm_changed' and subject_id = ? and recipient_user_id = ?", [$nid, $me])->n)->toBe(1);

    $this->postJson("/api/v1/mrl/requirements/{$req}/reviewed", ['note' => 'Sin cambios para la empresa'], $h)->assertOk()->assertJsonPath('data.needs_review', false);
    $this->postJson("/api/v1/mrl/requirements/{$req}/reviewed", [], $h)->assertStatus(409);
    expect(collect($this->getJson('/api/v1/mrl/news', $h)->json('data.needs_review'))->firstWhere('id', $req))->toBeNull();
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    expect(DB::selectOne('select reviewed_by from legal_requirements where id = ?', [$req])->reviewed_by)->toBe($me);
});

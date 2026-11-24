<?php

// M-HOM RN-HOM-01/02 y §5: un solo cálculo (VendorScoring) para «Calificar» de SST, la edición de la evaluación y la
// autoevaluación del portal del contratista: puntajes de 0 a 5 por criterio, total ponderado de 0 a 100 y calificación
// A (≥ vendor_rating_a_min_score), B (aprueba sin llegar a A) o C (bajo el mínimo de la plantilla).

use App\Core\Auth\Permissions;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    DB::update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('HOM', 'CTR') and deleted_at is null", [HUAYLAS_ID]);
    DB::select("select set_config('app.company_id', '', false)");
});

function homAs(string $email): array
{
    app('auth')->forgetGuards();
    Permissions::flush();
    app()->forgetInstance(\App\Core\Tenancy\CompanyContext::class);

    return authHeaders(tokenFor($email), HUAYLAS_ID);
}

/** Plantilla SST 60 % / Calidad 40 % con mínimo 70. */
function homTemplate(array $h): string
{
    return test()->postJson('/api/v1/records/HOM/vendor_evaluation_templates', ['code' => 'HS-'.random_int(1000, 99999), 'name' => 'Plantilla puntaje', 'passing_score' => 70,
        'criteria' => [['name' => 'SST', 'weight' => 60], ['name' => 'Calidad', 'weight' => 40]]], $h)->assertCreated()->json('data.id');
}

function homEval(array $h, string $tpl, ?string $contractor = null): string
{
    return test()->postJson('/api/v1/records/HOM/vendor_evaluations', array_filter(['template_id' => $tpl, 'vendor_name' => 'Proveedor HS', 'evaluated_on' => now()->toDateString(),
        'contractor_id' => $contractor]), $h)->assertCreated()->json('data.id');
}

function homRow(string $id): object
{
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $r = DB::selectOne('select total_score, rating, scores from vendor_evaluations where id = ?', [$id]);
    DB::select("select set_config('app.company_id', '', false)");

    return $r;
}

it('«Calificar», la edición y la autoevaluación del portal dan el mismo total y la misma calificación', function () {
    $h = homAs(CORPORATIVO_EMAIL);
    $tpl = homTemplate($h);
    // Contratista con su administrador en el portal.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $ctr = DB::selectOne("insert into contractors (company_id, ruc, legal_name) values (?, '20100070970', 'Proveedor Portal SAC') returning id", [HUAYLAS_ID])->id;
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [ESENCIAL_EMAIL])->id;
    $role = DB::selectOne("select id from roles where account_id is null and code = 'contractor_admin'")->id;
    $m = DB::selectOne("insert into memberships (company_id, user_id, role_id, status, accepted_at) values (?, ?, ?, 'active', now()) returning id", [HUAYLAS_ID, $uid, $role])->id;
    DB::insert("insert into membership_scopes (company_id, membership_id, scope_type, contractor_id) values (?, ?, 'contractor', ?)", [HUAYLAS_ID, $m, $ctr]);
    DB::select("select set_config('app.company_id', '', false)");

    $e1 = homEval($h, $tpl);
    $e2 = homEval($h, $tpl);
    $e3 = homEval($h, $tpl, $ctr);

    // 4/5 × 60 + 3/5 × 40 = 72: aprueba (≥ 70) sin llegar a A (85) → B.
    $this->putJson("/api/v1/hom/evaluations/{$e1}/scores", ['scores' => ['SST' => 4, 'Calidad' => 3]], $h)->assertOk()
        ->assertJsonPath('data.total_score', 72)->assertJsonPath('data.rating', 'conditionally_approved');
    $r2 = $this->patchJson("/api/v1/records/HOM/vendor_evaluations/{$e2}", ['scores' => ['SST' => ['score' => 4], 'Calidad' => ['score' => 3]]], $h)->assertOk()->json('data');
    expect((float) $r2['total_score'])->toEqual(72)->and($r2['rating'])->toBe('conditionally_approved');

    $portal = homAs(ESENCIAL_EMAIL);
    $this->postJson("/api/v1/ctr/portal/homologations/{$e3}", ['scores' => ['SST' => ['score' => 4, 'comment' => 'Tenemos SCTR'], 'Calidad' => ['score' => 3]]], $portal)->assertOk();

    foreach ([$e1, $e2, $e3] as $id) {
        $row = homRow($id);
        expect((float) $row->total_score)->toEqual(72)->and($row->rating)->toBe('conditionally_approved');
        // Una sola forma de «scores»: {criterio: {score, …}}.
        expect(json_decode($row->scores, true)['SST']['score'])->toEqual(4);
    }
    expect(json_decode(homRow($e3)->scores, true)['SST'])->toMatchArray(['comment' => 'Tenemos SCTR', 'by' => 'proveedor']);

    // SST valida la autoevaluación y la vuelve a puntuar: conserva el comentario del proveedor.
    $h = homAs(CORPORATIVO_EMAIL);
    $this->putJson("/api/v1/hom/evaluations/{$e3}/scores", ['scores' => ['SST' => 5, 'Calidad' => 4]], $h)->assertOk()->assertJsonPath('data.rating', 'approved');
    expect(json_decode(homRow($e3)->scores, true)['SST'])->toMatchArray(['score' => 5, 'comment' => 'Tenemos SCTR', 'by' => 'sst']);

    // Puntajes fuera de 0 a 5: se rechazan en las tres vías.
    $this->putJson("/api/v1/hom/evaluations/{$e1}/scores", ['scores' => ['SST' => 6, 'Calidad' => 3]], $h)->assertStatus(422)->assertJsonValidationErrors('scores');
    $this->putJson("/api/v1/hom/evaluations/{$e1}/scores", ['scores' => ['SST' => -1, 'Calidad' => 3]], $h)->assertStatus(422);
    $this->patchJson("/api/v1/records/HOM/vendor_evaluations/{$e2}", ['scores' => ['SST' => ['score' => 90], 'Calidad' => ['score' => 40]]], $h)->assertStatus(422)->assertJsonValidationErrors('scores');
    $e4 = homEval($h, $tpl, $ctr);
    $this->postJson("/api/v1/ctr/portal/homologations/{$e4}", ['scores' => ['SST' => ['score' => 90], 'Calidad' => ['score' => 3]]], homAs(ESENCIAL_EMAIL))->assertStatus(422);
    expect(homRow($e4)->total_score)->toBeNull();
});

it('califica A, B o C según el mínimo de la plantilla y el umbral de A (parámetro)', function () {
    $h = homAs(CORPORATIVO_EMAIL);
    $tpl = homTemplate($h);
    $e = homEval($h, $tpl);
    $score = fn (int $sst, int $cal) => $this->putJson("/api/v1/hom/evaluations/{$e}/scores", ['scores' => ['SST' => $sst, 'Calidad' => $cal]], $h)->assertOk()->json('data');

    expect($score(5, 4))->toMatchArray(['total_score' => 92, 'rating' => 'approved'])             // A: ≥ 85
        ->and($score(5, 2))->toMatchArray(['total_score' => 76, 'rating' => 'conditionally_approved'])   // B: 70 a < 85
        ->and($score(3, 4))->toMatchArray(['total_score' => 68, 'rating' => 'rejected']);          // C: < 70

    // El umbral de A es un parámetro (a confirmar): con 95, el 92 pasa a B.
    companyParamForTests(HUAYLAS_ID, 'vendor_rating_a_min_score', '95', 'decimal');
    DB::select("select set_config('app.company_id', '', false)");
    expect($score(5, 4)['rating'])->toBe('conditionally_approved');
    // Sin criterios calificados completos: se pide calificarlos todos.
    $this->putJson("/api/v1/hom/evaluations/{$e}/scores", ['scores' => ['SST' => 5]], $h)->assertStatus(422);
});

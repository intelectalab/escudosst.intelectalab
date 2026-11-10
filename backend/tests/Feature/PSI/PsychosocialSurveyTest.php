<?php

// Encuesta psicosocial anónima (M-PSI): enlace firmado, una respuesta por trabajador sin guardar quién es,
// resultados solo al cerrar y sin desagregar grupos pequeños.

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function psiAnswers(array $questions, int $value): array
{
    $a = [];
    foreach ($questions['dimensions'] as $d) {
        foreach ($d['items'] as $it) {
            $a[$it['id']] = $value;
        }
    }

    return $a;
}

it('recibe respuestas anónimas por el enlace y calcula resultados al cerrar', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $site = $this->postJson('/api/v1/records/TEN/sites', ['code' => 'PSI-S', 'name' => 'Sede PSI'], $h)->json('data.id');
    $area = $this->postJson('/api/v1/records/TEN/areas', ['code' => 'PSI-A', 'name' => 'Operaciones PSI', 'site_id' => $site], $h)->assertCreated()->json('data.id');
    foreach (range(1, 6) as $i) {
        $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '6060600'.$i, 'first_name' => "Tra{$i}", 'paternal_last_name' => 'Psi', 'current_area_id' => $area], $h)->assertCreated();
    }
    $id = $this->postJson('/api/v1/records/PSI/psychosocial_surveys', ['title' => 'Clima psicosocial 2026', 'instrument' => 'custom'], $h)->assertCreated()->json('data.id');
    $link = $this->getJson("/api/v1/psi/surveys/{$id}/link", $h)->assertOk()->json('data');
    $path = '/api/v1/public/psi/'.substr($link['url'], strpos($link['url'], '/encuesta/') + 10);

    // En borrador no se puede responder; firma falsa → no existe.
    $this->getJson($path)->assertOk()->assertJsonPath('data.open', false)->assertJsonPath('data.questions', null);
    $this->getJson(substr($path, 0, -4).'0000')->assertNotFound();

    $this->patchJson("/api/v1/records/PSI/psychosocial_surveys/{$id}", ['status' => 'open'], $h)->assertOk();
    $q = $this->getJson($path)->assertOk()->assertJsonPath('data.open', true)->json('data.questions');
    expect($q['dimensions'])->toHaveCount(6);

    // Incompleta, documento ajeno, respuesta válida y doble respuesta.
    $this->postJson($path, ['document' => '60606001', 'answers' => ['demands_1' => 2]])->assertStatus(422);
    $this->postJson($path, ['document' => '99999999', 'answers' => psiAnswers($q, 2)])->assertStatus(422)->assertJsonPath('errors.document.0', 'Documento no encontrado entre los trabajadores.');
    foreach (range(1, 5) as $i) {
        $this->postJson($path, ['document' => '6060600'.$i, 'answers' => psiAnswers($q, 4)])->assertCreated();
    }
    $this->postJson($path, ['document' => '60606001', 'answers' => psiAnswers($q, 0)])->assertStatus(409);

    // Ninguna respuesta guarda quién respondió, y no se crean respuestas desde el panel.
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::table('psychosocial_responses')->where('survey_id', $id)->count())->toBe(5)
        ->and(DB::table('psychosocial_responses')->where('survey_id', $id)->whereNotNull('worker_id')->count())->toBe(0);
    $this->postJson('/api/v1/records/PSI/psychosocial_responses', ['survey_id' => $id, 'answers' => []], $h)->assertStatus(409);

    // Resultados solo al cerrar: área con 5 respuestas visible y en riesgo alto.
    expect($this->getJson("/api/v1/records/PSI/psychosocial_surveys/{$id}", $h)->json('data.results'))->toBeNull();
    $this->patchJson("/api/v1/records/PSI/psychosocial_surveys/{$id}", ['status' => 'closed'], $h)->assertOk();
    $r = $this->getJson("/api/v1/records/PSI/psychosocial_surveys/{$id}", $h)->json('data.results');
    $r = is_string($r) ? json_decode($r, true) : $r;
    expect($r['responses'])->toBe(5)->and($r['dimensions'][0]['level'])->toBe('high')->and($r['areas'][0]['area'])->toBe('Operaciones PSI');
    $this->postJson($path, ['document' => '60606006', 'answers' => psiAnswers($q, 1)])->assertStatus(409);
});

it('no desagrega grupos con menos respuestas que el mínimo (RN-PSI-02)', function () {
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $this->postJson('/api/v1/records/TRB/workers', ['document_number' => '61616161', 'first_name' => 'Uno', 'paternal_last_name' => 'Solo'], $h)->assertCreated();
    $id = $this->postJson('/api/v1/records/PSI/psychosocial_surveys', ['title' => 'Pequeña', 'instrument' => 'custom'], $h)->json('data.id');
    $this->patchJson("/api/v1/records/PSI/psychosocial_surveys/{$id}", ['status' => 'open'], $h)->assertOk();
    $url = $this->getJson("/api/v1/psi/surveys/{$id}/link", $h)->json('data.url');
    $path = '/api/v1/public/psi/'.substr($url, strpos($url, '/encuesta/') + 10);
    $q = $this->getJson($path)->json('data.questions');
    $this->postJson($path, ['document' => '61616161', 'answers' => psiAnswers($q, 3)])->assertCreated();
    $this->patchJson("/api/v1/records/PSI/psychosocial_surveys/{$id}", ['status' => 'closed'], $h)->assertOk();
    $r = $this->getJson("/api/v1/records/PSI/psychosocial_surveys/{$id}", $h)->json('data.results');
    $r = is_string($r) ? json_decode($r, true) : $r;
    expect($r['shown'])->toBeFalse()->and($r['dimensions'])->toBe([])->and($r['areas'])->toBe([])->and($r['hidden_responses'])->toBe(1);
});

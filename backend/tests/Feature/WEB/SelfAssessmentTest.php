<?php

// Autodiagnóstico Ley 29783 (SCR-WEB-29/42, RN-WEB-37 a 39): un solo cuestionario publicado; la versión publicada no
// se edita; el puntaje por sección y el nivel salen de las respuestas; quien responde entra a «Interesados» y recibe
// su resultado por correo.

use App\Modules\WEB\Mail\SiteRequestMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    Mail::fake();
    \Illuminate\Support\Facades\Storage::fake('local');
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

function publishDraft(): string
{
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $draft = collect(test()->getJson('/api/v1/internal/site/self-assessment', $h)->json('data.versions'))->firstWhere('status', 'draft');
    test()->postJson("/api/v1/internal/site/self-assessment/{$draft['id']}/publish", [], $h)->assertOk();

    return $draft['id'];
}

it('publica el cuestionario, calcula el puntaje por sección y registra al interesado', function () {
    expect($this->getJson('/api/v1/public/tools/self-assessment')->json('data'))->toBeNull();   // aún en borrador
    $id = publishDraft();

    $q = $this->getJson('/api/v1/public/tools/self-assessment')->assertOk()->json('data');
    expect($q['to_validate'])->toBeTrue()->and(json_encode($q))->not->toContain('weight');
    $ids = collect($q['sections'])->flatMap(fn ($s) => collect($s['questions'])->pluck('id'))->all();

    $answers = array_fill_keys($ids, 'yes');
    $answers[$ids[0]] = 'no';
    $base = ['questionnaire_id' => $id, 'full_name' => 'Rosa Prueba', 'email' => 'rosa.auto@ejemplo.test', 'company_name' => 'Obras Prueba', 'privacy_consent' => true];

    $this->postJson('/api/v1/public/tools/self-assessment', $base + ['answers' => array_slice($answers, 1, null, true)])->assertStatus(422)->assertJsonValidationErrors('answers');
    $r = $this->postJson('/api/v1/public/tools/self-assessment', $base + ['answers' => $answers])->assertOk()->json('data');
    expect((float) $r['score_total'])->toBeGreaterThan(80)->and($r['level'])->toBe('high')
        ->and($r['pending'])->toHaveKey('Política y organización');
    expect(DB::connection('platform')->selectOne("select source from marketing_leads where email = 'rosa.auto@ejemplo.test'")->source)->toBe('self_assessment');
    Mail::assertSent(SiteRequestMail::class, fn ($m) => $m->hasTo('rosa.auto@ejemplo.test'));

    // Informe PDF: se descarga solo con el enlace firmado.
    expect($r['report_url'])->not->toBeNull();
    $pdf = $this->get($r['report_url'])->assertOk();
    expect($pdf->streamedContent())->toStartWith('%PDF');
    $this->get(preg_replace('/signature=[^&]+/', 'signature=falsa', $r['report_url']))->assertForbidden();
    Mail::assertSent(SiteRequestMail::class, fn ($m) => $m->buttonUrl === $r['report_url']);
});

it('la versión publicada no se edita: se crea una nueva y al publicarla la anterior se archiva', function () {
    $h = authHeaders(tokenFor(ADMIN_EMAIL));
    $id = publishDraft();
    $this->putJson("/api/v1/internal/site/self-assessment/{$id}/questions", ['questions' => [['section' => 'X', 'question_text' => 'Una pregunta nueva de prueba', 'weight' => 1, 'answer_type' => 'yes_no']]], $h)->assertStatus(422);

    $new = $this->postJson("/api/v1/internal/site/self-assessment/{$id}/copy", [], $h)->assertCreated()->json('data.id');
    $v = $this->getJson("/api/v1/internal/site/self-assessment/{$new}", $h)->json('data');
    expect($v['version'])->toBe(2)->and($v['questions'])->toHaveCount(9);

    $this->putJson("/api/v1/internal/site/self-assessment/{$new}/questions", ['questions' => [
        ['section' => 'Política', 'question_text' => '¿Tiene política de SST firmada?', 'weight' => 2, 'answer_type' => 'yes_no'],
        ['section' => 'Riesgos', 'question_text' => '¿Tiene matriz IPERC actualizada?', 'weight' => 1, 'answer_type' => 'yes_partial_no'],
    ]], $h)->assertNoContent();
    $this->postJson("/api/v1/internal/site/self-assessment/{$new}/publish", [], $h)->assertOk();

    $versions = collect($this->getJson('/api/v1/internal/site/self-assessment', $h)->json('data.versions'));
    expect($versions->firstWhere('id', $id)['status'])->toBe('archived')->and($versions->firstWhere('id', $new)['status'])->toBe('published');
    expect(collect($this->getJson('/api/v1/public/tools/self-assessment')->json('data.sections'))->pluck('section')->all())->toBe(['Política', 'Riesgos']);
});

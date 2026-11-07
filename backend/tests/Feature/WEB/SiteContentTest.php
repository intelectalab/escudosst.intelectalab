<?php

// Preguntas frecuentes, testimonios, otros productos (SCR-WEB-11 y 23) e interesados (SCR-WEB-39 y 40).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

function site(string $method, string $uri, array $data = [])
{
    return test()->json($method, '/api/v1/internal/site/'.$uri, $data, authHeaders(tokenFor(ADMIN_EMAIL)));
}

it('las preguntas frecuentes se ven en el sitio solo cuando están publicadas', function () {
    $id = site('POST', 'faqs', ['topic' => 'product', 'question' => '¿Necesito instalar algo?', 'answer' => 'No, funciona en el navegador.', 'status' => 'draft'])
        ->assertCreated()->json('data.id');
    expect(collect($this->getJson('/api/v1/public/faqs')->json('data'))->pluck('question'))->not->toContain('¿Necesito instalar algo?');

    site('PATCH', "faqs/{$id}", ['topic' => 'product', 'question' => '¿Necesito instalar algo?', 'answer' => 'No, funciona en el navegador.', 'status' => 'published'])->assertNoContent();
    expect(collect($this->getJson('/api/v1/public/faqs')->json('data'))->pluck('question'))->toContain('¿Necesito instalar algo?');

    site('DELETE', "faqs/{$id}")->assertNoContent();
    expect(collect($this->getJson('/api/v1/public/faqs')->json('data'))->pluck('question'))->not->toContain('¿Necesito instalar algo?');
});

it('un testimonio solo se publica con consentimiento o como ejemplo', function () {
    $base = ['author_name' => 'Rosa P.', 'author_role' => 'Jefa SST', 'company_name' => 'Agro Sur', 'quote' => 'Ahora tenemos todo ordenado para SUNAFIL.', 'rating' => 5];
    $id = site('POST', 'testimonials', $base + ['status' => 'pending'])->assertCreated()->json('data.id');

    site('PATCH', "testimonials/{$id}", $base + ['status' => 'approved'])->assertStatus(422)->assertJsonValidationErrors('status');
    site('PATCH', "testimonials/{$id}", $base + ['status' => 'approved', 'publication_consent' => true])->assertStatus(422)->assertJsonValidationErrors('consent_evidence');
    site('PATCH', "testimonials/{$id}", $base + ['status' => 'approved', 'publication_consent' => true, 'consent_evidence' => 'Correo del 05/11/2026'])->assertNoContent();
    expect(collect($this->getJson('/api/v1/public/testimonials')->json('data'))->pluck('author_name'))->toContain('Rosa P.');

    // Retira el consentimiento: deja de publicarse al momento.
    site('PATCH', "testimonials/{$id}", $base + ['status' => 'pending', 'publication_consent' => false])->assertNoContent();
    expect(collect($this->getJson('/api/v1/public/testimonials')->json('data'))->pluck('author_name'))->not->toContain('Rosa P.');
});

it('otros productos: enlace https y visibles en el sitio', function () {
    site('POST', 'products', ['name' => 'Producto QA', 'url' => 'http://inseguro.test'])->assertStatus(422)->assertJsonValidationErrors('url');
    $id = site('POST', 'products', ['name' => 'Producto QA', 'description' => 'Prueba', 'url' => 'https://qa.test', 'is_visible' => true])->assertCreated()->json('data.id');
    expect(collect($this->getJson('/api/v1/public/site')->json('data.products'))->pluck('name'))->toContain('Producto QA');

    site('PATCH', "products/{$id}", ['name' => 'Producto QA', 'is_visible' => false])->assertNoContent();
    expect(collect($this->getJson('/api/v1/public/site')->json('data.products'))->pluck('name'))->not->toContain('Producto QA');
});

it('la bandeja de interesados agrupa por correo, cambia estado y registra el retiro del consentimiento', function () {
    $db = DB::connection('platform');
    $lead = $db->selectOne(
        "insert into marketing_leads (email, full_name, company_name, source, privacy_consent, marketing_consent, marketing_consent_at)
         values ('lead.qa@prueba.test', 'Lead QA', 'Empresa QA', 'demo', true, true, now()) returning id"
    )->id;
    $db->insert("insert into demo_requests (request_type, full_name, company_name, email, privacy_consent) values ('quote', 'Lead QA', 'Empresa QA', 'lead.qa@prueba.test', true)");

    $row = collect(site('GET', 'leads')->assertOk()->json('data'))->firstWhere('id', $lead);
    expect($row['requests'])->toBe(1);
    expect(site('GET', "leads/{$lead}")->json('data.timeline.0.detail'))->toBe('quote');

    $csv = $this->get('/api/v1/internal/site/leads/export', authHeaders(tokenFor(ADMIN_EMAIL)))->streamedContent();
    expect($csv)->toContain('lead.qa@prueba.test');

    site('PATCH', "leads/{$lead}", ['status' => 'discarded'])->assertStatus(422)->assertJsonValidationErrors('discard_reason');
    site('PATCH', "leads/{$lead}", ['status' => 'contacted', 'notes' => 'Llamar el lunes', 'withdraw_marketing' => true])->assertNoContent();
    $l = $db->selectOne('select status, marketing_consent, marketing_consent_withdrawn_at from marketing_leads where id = ?', [$lead]);
    expect($l->status)->toBe('contacted')->and($l->marketing_consent)->toBeFalse()->and($l->marketing_consent_withdrawn_at)->not->toBeNull();
    expect($this->get('/api/v1/internal/site/leads/export', authHeaders(tokenFor(ADMIN_EMAIL)))->streamedContent())->not->toContain('lead.qa@prueba.test');
});

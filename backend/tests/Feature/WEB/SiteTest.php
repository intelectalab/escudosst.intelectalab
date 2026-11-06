<?php

// Sitio web, Parte 1 (M-WEB): datos públicos y ajustes del dueño (SCR-WEB-21).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $this->snapshot = (array) DB::connection('platform')->selectOne('select * from site_settings where singleton');
});

afterEach(function () {
    $row = $this->snapshot;
    unset($row['id'], $row['created_at'], $row['updated_at']);
    DB::connection('platform')->table('site_settings')->where('singleton', true)->update($row);
});

function siteSettings(array $overrides = []): array
{
    return $overrides + [
        'trade_name' => 'EscudoSST',
        'legal_name' => 'GRUPO DE SOLUCIONES CREATIVAS SAC',
        'ruc' => '20607431117',
        'primary_domain' => 'escudosst.com',
        'whatsapp_number' => '51987654321',
        'whatsapp_default_message' => 'Hola, quiero conocer EscudoSST',
        'social_links' => ['linkedin' => 'https://www.linkedin.com/company/escudosst'],
        'announcement_bar' => ['text' => 'Nuevo: módulo de inspecciones', 'link' => '/producto', 'active' => true, 'until' => null],
    ];
}

it('el sitio público responde sin sesión y solo con lo publicado', function () {
    $site = $this->getJson('/api/v1/public/site')->assertOk()->json('data');
    expect($site['settings']['legal_name'])->toBe('GRUPO DE SOLUCIONES CREATIVAS SAC')
        ->and($site['settings'])->not->toHaveKey('analytics_ids')
        ->and($site['products'])->not->toBeEmpty();

    $pricing = $this->getJson('/api/v1/public/pricing')->assertOk()->json('data');
    expect(collect($pricing['plans'])->pluck('code')->all())->toContain('E', 'P', 'C')
        ->and($pricing['plans'][0]['price_label'])->toBe('Solicitar cotización')   // sin precio = cotización (RN-SUS-01)
        ->and($pricing['annual_discount_pct'])->toEqual(0);                          // sin descuento anual decidido (DA-01)

    // El precio es mensual: el anual es 12 × mensual menos el descuento anual.
    expect(\App\Modules\SUS\Support\Pricing::forPeriod(100.0, 'monthly', 10))->toBe(100.0)
        ->and(\App\Modules\SUS\Support\Pricing::forPeriod(100.0, 'annual', 0))->toBe(1200.0)
        ->and(\App\Modules\SUS\Support\Pricing::forPeriod(100.0, 'annual', 15))->toBe(1020.0)
        ->and(\App\Modules\SUS\Support\Pricing::forPeriod(null, 'annual', 0))->toBeNull();

    $modules = collect($this->getJson('/api/v1/public/modules')->assertOk()->json('data'))->keyBy('code');
    expect($modules['IPC']['from_plan'])->toBe('E');
});

it('el dueño guarda el WhatsApp y la barra de anuncio, y se ven en el sitio', function () {
    $this->getJson('/api/v1/internal/site/settings', authHeaders(tokenFor(ESENCIAL_EMAIL)))->assertStatus(403);
    $admin = authHeaders(tokenFor(ADMIN_EMAIL));

    $this->patchJson('/api/v1/internal/site/settings', siteSettings(['whatsapp_number' => '+51 987 654 321']), $admin)
        ->assertStatus(422)->assertJsonPath('errors.whatsapp_number.0', 'Escribe solo números con el código de país, sin + ni espacios (por ejemplo: 51987654321).');
    $this->patchJson('/api/v1/internal/site/settings', siteSettings(['announcement_bar' => ['text' => 'Prueba gratis 14 días', 'active' => true]]), $admin)
        ->assertStatus(422);

    // Sin redes sociales también se guarda (objeto vacío).
    $this->patchJson('/api/v1/internal/site/settings', siteSettings(['social_links' => ['facebook' => null]]), $admin)->assertNoContent();
    $this->patchJson('/api/v1/internal/site/settings', siteSettings(), $admin)->assertNoContent();
    $public = $this->getJson('/api/v1/public/site')->json('data.settings');
    expect($public['whatsapp_number'])->toBe('51987654321')
        ->and($public['announcement_bar']['text'])->toBe('Nuevo: módulo de inspecciones');

    // Barra vencida: el sitio ya no la muestra.
    $this->patchJson('/api/v1/internal/site/settings', siteSettings(['announcement_bar' => ['text' => 'Aviso viejo', 'active' => true, 'until' => '2020-01-01']]), $admin)->assertNoContent();
    expect($this->getJson('/api/v1/public/site')->json('data.settings.announcement_bar'))->toBeNull();
});

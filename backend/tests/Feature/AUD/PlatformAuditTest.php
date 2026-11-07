<?php

// SCR-AUD-01 · Bitácora de la plataforma (docs/modulos/M-AUD.md).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    // Un evento conocido para filtrar (la bitácora es solo INSERT: queda, como en producción).
    $this->marker = 'prueba-bitacora-'.bin2hex(random_bytes(4));
    DB::connection('platform')->insert(
        "insert into audit_logs (company_id, actor_user_id, actor_type, action, module_code, entity_type, changes, reason)
         values (null, null, 'system', 'subscription_reminder', 'SUS', 'subscriptions', ?::jsonb, ?)",
        [json_encode(['company' => 'Empresa de prueba', 'marker' => $this->marker]), 'Evento de prueba']
    );
});

function audit(array $query = [])
{
    return test()->getJson('/api/v1/internal/audit?'.http_build_query($query), authHeaders(tokenFor(ADMIN_EMAIL)));
}

it('solo el equipo de la plataforma ve la bitácora', function () {
    $this->getJson('/api/v1/internal/audit', authHeaders(tokenFor(PROFESIONAL_EMAIL)))->assertStatus(403);
});

it('lista eventos con filtros, resumen y paginación', function () {
    $res = audit()->assertOk();
    expect($res->json('stats.total'))->toBeGreaterThan(0)
        ->and($res->json('meta.per_page'))->toBe(30)
        ->and(collect($res->json('facets.actors'))->pluck('id'))->toContain('system');

    // Búsqueda por texto dentro de los cambios.
    $found = audit(['q' => $this->marker])->assertOk();
    expect($found->json('meta.total'))->toBe(1)
        ->and($found->json('data.0.actor'))->toBeNull()
        ->and($found->json('data.0.changes.marker'))->toBe($this->marker);

    // Filtro por acción y por actor «sistema».
    expect(collect(audit(['action' => 'subscription_reminder', 'actor' => 'system'])->json('data'))->pluck('action')->unique()->all())
        ->toBe(['subscription_reminder']);

    // Rango de fechas inválido.
    audit(['from' => '2026-11-10', 'to' => '2026-11-01'])->assertStatus(422);
});

it('verifica que la cadena de hash de la bitácora está intacta', function () {
    $this->getJson('/api/v1/internal/audit/verify', authHeaders(tokenFor(ADMIN_EMAIL)))
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.problems', []);
});

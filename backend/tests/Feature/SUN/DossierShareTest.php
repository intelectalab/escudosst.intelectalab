<?php

// Modo SUNAFIL (M-SUN SCR-SUN-05/06/08): PDF único foliado, enlace del inspector con máximo de vistas y descargas,
// aprobación de gerencia antes de activarse e historial de accesos (Fecha, Desde, Acción).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
});

function sunH(): array
{
    return authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
}

function sunBuiltDossier(): string
{
    $d = test()->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Expediente foliado', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'], sunH())
        ->assertCreated()->json('data.id');
    test()->postJson("/api/v1/sun/dossiers/{$d}/build", [], sunH())->assertOk();

    return $d;
}

function sunToken(string $url): array
{
    return array_slice(explode('/', $url), -2);
}

it('arma el PDF único con índice y folios correlativos', function () {
    $d = $this->postJson('/api/v1/records/SUN/inspection_dossiers', ['title' => 'Sin armar', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31'], sunH())->json('data.id');
    $this->getJson("/api/v1/sun/dossiers/{$d}/print", sunH())->assertStatus(409);

    $d = sunBuiltDossier();
    $p = $this->getJson("/api/v1/sun/dossiers/{$d}/print", sunH())->assertOk();
    $items = $p->json('data.items');
    expect($items)->not->toBeEmpty()
        ->and($items[0]['number'])->toBe(1)
        ->and($items[0]['folio'])->toBe($p->json('data.index_pages') + 1)
        ->and(end($items)['folio'])->toBe($p->json('data.total_folios'))
        ->and($p->json('data.summary.total'))->toBe(count($items))
        ->and($p->json('data.company.ruc'))->not->toBe('');
    // Ningún folio se repite.
    expect(collect($items)->pluck('folio')->unique()->count())->toBe(count($items));
});

it('limita vistas y descargas del enlace y guarda el historial de accesos', function () {
    $d = sunBuiltDossier();
    $this->postJson("/api/v1/sun/dossiers/{$d}/share", ['days' => 3, 'allow_download' => true, 'max_downloads' => 99], sunH())->assertStatus(422)->assertJsonValidationErrors('max_downloads');
    $s = $this->postJson("/api/v1/sun/dossiers/{$d}/share", ['days' => 3, 'max_views' => 3, 'allow_download' => true, 'max_downloads' => 1, 'recipient_name' => 'Inspectora'], sunH())
        ->assertCreated()->assertJsonPath('data.status', 'active')->json('data');
    [$company, $token] = sunToken($s['url']);

    $this->getJson("/api/v1/public/sun/{$company}/{$token}")->assertOk()->assertJsonPath('data.allow_download', true)->assertJsonPath('data.downloads_left', 1);
    $zip = $this->get("/api/v1/public/sun/{$company}/{$token}/download")->assertOk();
    expect($zip->headers->get('content-type'))->toContain('zip');
    $this->get("/api/v1/public/sun/{$company}/{$token}/download", ['Accept' => 'application/json'])->assertStatus(410);
    $this->getJson("/api/v1/public/sun/{$company}/{$token}")->assertOk()->assertJsonPath('data.allow_download', false)->assertJsonPath('data.views_left', 1);

    // Un enlace sin descarga no entrega el ZIP.
    $s2 = $this->postJson("/api/v1/sun/dossiers/{$d}/share", ['days' => 1], sunH())->assertCreated()->json('data');
    [, $token2] = sunToken($s2['url']);
    $this->get("/api/v1/public/sun/{$company}/{$token2}/download", ['Accept' => 'application/json'])->assertStatus(403);

    $links = collect($this->getJson("/api/v1/sun/dossiers/{$d}/share-links", sunH())->assertOk()->json('data'));
    $l = $links->firstWhere('id', $s['id']);
    expect($l['view_count'])->toBe(2)->and($l['download_count'])->toBe(1)->and($l['recipient_name'])->toBe('Inspectora');
    $actions = collect($l['accesses'])->pluck('action')->all();
    expect($actions)->toContain('created', 'view', 'download', 'denied')
        ->and(collect($l['accesses'])->firstWhere('action', 'view')['from'])->toContain('.x.x');
    expect(collect($links->firstWhere('id', $s2['id'])['accesses'])->pluck('action')->all())->toContain('denied');
});

it('el enlace con aprobación no funciona hasta que gerencia lo aprueba', function () {
    $d = sunBuiltDossier();
    $s = $this->postJson("/api/v1/sun/dossiers/{$d}/share", ['days' => 2, 'requires_approval' => true], sunH())->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data');
    [$company, $token] = sunToken($s['url']);
    $this->getJson("/api/v1/public/sun/{$company}/{$token}")->assertStatus(410);
    // El Jefe SST no aprueba.
    $this->postJson("/api/v1/sun/share-links/{$s['id']}/approve", [], sunH())->assertStatus(403);

    // El mismo usuario con rol de gerencia: no aprueba lo que él pidió.
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    $mgmt = DB::selectOne("select id from roles where code = 'management' and account_id is null")->id;
    DB::update('update memberships set role_id = ? where user_id = ? and company_id = ?', [$mgmt, $uid, ANDINAS_ID]);
    $GLOBALS['sus_tokens'] = [];
    $this->postJson("/api/v1/sun/share-links/{$s['id']}/approve", [], sunH())->assertStatus(403);

    // Pedido por otra persona: gerencia lo aprueba y empieza a contar su vigencia.
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    DB::update('update dossier_share_links set created_by = null where id = ?', [$s['id']]);
    $this->postJson("/api/v1/sun/share-links/{$s['id']}/approve", [], sunH())->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson("/api/v1/sun/share-links/{$s['id']}/approve", [], sunH())->assertStatus(409);
    $this->getJson("/api/v1/public/sun/{$company}/{$token}")->assertOk();

    $l = collect($this->getJson("/api/v1/sun/dossiers/{$d}/share-links", sunH())->assertOk()->json('data'))->firstWhere('id', $s['id']);
    expect($l['status'])->toBe('active')->and($l['approved_at'])->not->toBeNull()
        ->and(collect($l['accesses'])->pluck('action')->all())->toContain('created', 'denied', 'approved', 'view');
});

it('gerencia puede rechazar un enlace pendiente y el inspector no entra', function () {
    $d = sunBuiltDossier();
    $s = $this->postJson("/api/v1/sun/dossiers/{$d}/share", ['days' => 2, 'requires_approval' => true], sunH())->json('data');
    DB::select("select set_config('app.company_id', ?, false)", [ANDINAS_ID]);
    $uid = DB::selectOne('select id from app_auth_lookup_user(?)', [PROFESIONAL_EMAIL])->id;
    DB::update("update memberships set role_id = (select id from roles where code = 'management' and account_id is null) where user_id = ? and company_id = ?", [$uid, ANDINAS_ID]);
    DB::update('update dossier_share_links set created_by = null where id = ?', [$s['id']]);
    $GLOBALS['sus_tokens'] = [];
    $this->postJson("/api/v1/sun/share-links/{$s['id']}/reject", ['reason' => 'No corresponde aún'], sunH())->assertOk()->assertJsonPath('data.status', 'rejected');
    [$company, $token] = sunToken($s['url']);
    $this->getJson("/api/v1/public/sun/{$company}/{$token}")->assertStatus(410)->assertJsonPath('detail', 'La empresa no aprobó este enlace. Pide uno nuevo a la empresa.');
});

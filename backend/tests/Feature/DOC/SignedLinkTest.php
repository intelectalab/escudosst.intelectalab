<?php

// RN-DOC-07 / criterio 5: enlace de descarga firmado que deja de funcionar al pasar signed_url_ttl (los archivos médicos viven en otra base y no tienen enlace).

it('da un enlace temporal que abre el archivo sin sesión y deja de funcionar al vencer', function () {
    $GLOBALS['sus_tokens'] = [];
    $h = authHeaders(tokenFor(PROFESIONAL_EMAIL), ANDINAS_ID);
    $file = evidenceFile($h, 'DOC');
    $r = $this->postJson("/api/v1/files/{$file}/link", [], $h)->assertOk()->json('data');
    expect($r['minutes'])->toBeGreaterThan(0)->and($r['url'])->toContain('signature=');

    $path = parse_url($r['url'], PHP_URL_PATH).'?'.parse_url($r['url'], PHP_URL_QUERY);
    $this->get($path)->assertOk();
    $this->get(preg_replace('/signature=[^&]+/', 'signature=alterada', $path), ['Accept' => 'application/json'])->assertStatus(403);
    $this->travel($r['minutes'] + 1)->minutes();
    $this->get($path, ['Accept' => 'application/json'])->assertStatus(403);
    $this->travelBack();
});

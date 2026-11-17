<?php

// M-WEB: los datos personales de los contactos de la web se borran al pasar su plazo; los convertidos se conservan.

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('anonimiza el contacto vencido y conserva el vigente', function () {
    $db = DB::connection('platform');
    $n = random_int(100000, 999999);
    $old = $db->selectOne("insert into marketing_leads (email, full_name, phone, source, last_source, status, last_activity_at, created_at)
                           values (?, 'Viejo Contacto', '999111222', 'contact', 'contact', 'new', now() - interval '5 years', now() - interval '5 years') returning id", ["viejo{$n}@example.com"])->id;
    $new = $db->selectOne("insert into marketing_leads (email, full_name, source, last_source, status, last_activity_at) values (?, 'Nuevo Contacto', 'contact', 'contact', 'new', now()) returning id", ["nuevo{$n}@example.com"])->id;
    Artisan::call('escudo:web-retention');
    $o = $db->selectOne('select email, full_name, phone, deleted_at from marketing_leads where id = ?', [$old]);
    expect($o->full_name)->toBeNull()->and($o->phone)->toBeNull()->and($o->email)->toEndWith('@anonimo.invalid')->and($o->deleted_at)->not->toBeNull();
    expect($db->selectOne('select full_name from marketing_leads where id = ?', [$new])->full_name)->toBe('Nuevo Contacto');
    $this->getJson('/api/v1/public/blog-posts')->assertOk()->assertJsonStructure(['data']);
});

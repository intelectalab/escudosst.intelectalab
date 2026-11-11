<?php

// Catálogo público de cursos (VTA · SCR-VTA-12/15/16): lista y ficha sin sesión, solicitud de compra que deja un pedido
// pendiente y se paga con «Registrar pago»; catálogo no disponible si la empresa no tiene VTA.

use App\Core\Auth\Permissions;
use App\Core\Tenancy\DbContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    $db = DB::connection('platform');
    $this->flags = $db->select("select id, mode, source from feature_flags where company_id = ? and module_code in ('VTA', 'AUL')", [HUAYLAS_ID]);
    $db->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('VTA', 'AUL')", [HUAYLAS_ID]);
    Permissions::flush();

    // Curso publicado con dos secciones y tres ofertas: pública (aforo 3), privada (solo por enlace) y borrador.
    DB::transaction(function () {
        DbContext::setCompany(HUAYLAS_ID);
        $course = DB::selectOne("insert into courses (company_id, code, title, summary, category, hours, status, published_at)
                                 values (?, 'PC-ALT', 'Trabajo en altura', 'Arnés, anclajes y rescate', 'Alto riesgo', 8, 'published', now()) returning id", [HUAYLAS_ID])->id;
        DB::insert("insert into course_sections (company_id, course_id, section_number, title, sort_order) values (?, ?, 1, 'Normativa', 1), (?, ?, 2, 'Equipos de protección', 2)",
            [HUAYLAS_ID, $course, HUAYLAS_ID, $course]);
        $offer = 'insert into course_offers (company_id, course_id, slug, title, list_price, sale_price, seats_total, is_public, status, min_company_seats)
                  values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) returning id';
        $this->offer = DB::selectOne($offer, [HUAYLAS_ID, $course, 'altura-pc', 'Trabajo en altura', 150, 118, 3, 'true', 'published', 2])->id;
        DB::selectOne($offer, [HUAYLAS_ID, $course, 'altura-privado', 'Altura (enlace privado)', 100, null, null, 'false', 'published', null]);
        DB::selectOne($offer, [HUAYLAS_ID, $course, 'altura-borrador', 'Altura (borrador)', 100, null, null, 'true', 'draft', null]);
        DbContext::setCompany(null);
    });
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    $db = DB::connection('platform');
    foreach ($this->flags as $f) {
        $db->update('update feature_flags set mode = ?, source = ? where id = ?', [$f->mode, $f->source, $f->id]);
    }
    $db->update("update companies set catalog_slug = null where id = ? and catalog_slug = 'huaylas-cursos-pc'", [HUAYLAS_ID]);
    Permissions::flush();
});

function buyer(array $extra = []): array
{
    return array_merge(['buyer_type' => 'person', 'document_type' => 'DNI', 'document_number' => '71717171', 'first_name' => 'Luz', 'last_name' => 'Quispe',
        'email' => 'luz.pc@example.com', 'phone' => '987654321', 'privacy_consent' => true], $extra);
}

it('lista solo las ofertas públicas y vigentes; la ficha trae el temario y la oferta privada se abre por su enlace', function () {
    $list = $this->getJson('/api/v1/public/courses/'.HUAYLAS_ID)->assertOk()->json('data');
    expect(array_column($list['offers'], 'slug'))->toBe(['altura-pc'])
        ->and($list['offers'][0]['price'])->toEqual(118)
        ->and($list['offers'][0]['seats_left'])->toBe(3)
        ->and($list['offers'][0]['hours'])->toEqual(8);

    $this->getJson('/api/v1/public/courses/'.HUAYLAS_ID.'/altura-pc')->assertOk()
        ->assertJsonPath('data.offer.sections.1.title', 'Equipos de protección')
        ->assertJsonPath('data.offer.min_company_seats', 2)
        ->assertJsonPath('data.payment_instructions', null);
    $this->getJson('/api/v1/public/courses/'.HUAYLAS_ID.'/altura-privado')->assertOk();
    $this->getJson('/api/v1/public/courses/'.HUAYLAS_ID.'/altura-borrador')->assertNotFound();
    $this->getJson('/api/v1/public/courses/no-existe-esta')->assertNotFound();

    // Con su dirección propia. El catálogo se ubica con el rol de plataforma, que no ve la transacción de la prueba:
    // la dirección se fija con ese rol (y se quita al terminar) antes de que la prueba toque la fila de la empresa.
    DB::connection('platform')->update("update companies set catalog_slug = 'huaylas-cursos-pc' where id = ?", [HUAYLAS_ID]);
    $this->getJson('/api/v1/public/courses/huaylas-cursos-pc')->assertOk()->assertJsonPath('data.company.catalog', 'huaylas-cursos-pc');

    // La empresa cambia la dirección de su catálogo.
    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    expect($this->getJson('/api/v1/vta/catalog', $h)->json('data.url'))->toEndWith('/cursos/huaylas-cursos-pc');
    $this->putJson('/api/v1/vta/catalog', ['slug' => 'Con Espacios'], $h)->assertStatus(422);
    $this->putJson('/api/v1/vta/catalog', ['slug' => 'huaylas-cursos-nuevo'], $h)->assertOk()->assertJsonPath('data.slug', 'huaylas-cursos-nuevo');
    expect($this->getJson('/api/v1/vta/catalog', $h)->json('data.url'))->toEndWith('/cursos/huaylas-cursos-nuevo');
});

it('sin VTA activo el catálogo responde «no disponible» y no muestra cursos', function () {
    DB::connection('platform')->update("update feature_flags set mode = 'locked' where company_id = ? and module_code = 'VTA'", [HUAYLAS_ID]);
    $this->getJson('/api/v1/public/courses/'.HUAYLAS_ID)->assertNotFound()->assertJsonPath('type', 'catalog_unavailable')->assertJsonMissingPath('data');
    $this->postJson('/api/v1/public/courses/'.HUAYLAS_ID.'/altura-pc/order', buyer())->assertNotFound();
});

it('la solicitud de compra deja un pedido pendiente que la empresa paga con «Registrar pago»', function () {
    $url = '/api/v1/public/courses/'.HUAYLAS_ID.'/altura-pc/order';
    $this->postJson($url, buyer(['privacy_consent' => false]))->assertStatus(422)->assertJsonValidationErrors('privacy_consent');
    $this->postJson($url, buyer(['website' => 'http://spam.example']))->assertStatus(422);

    $key = (string) Str::uuid();
    $o = $this->postJson($url, buyer(['idempotency_key' => $key, 'marketing_consent' => true]))->assertCreated()->json('data');
    expect($o['total'])->toEqual(118)->and($o['status'])->toBe('pending')->and($o['payment_instructions'])->toBeNull();
    // Reintento con la misma clave: el mismo pedido, no otro.
    $this->postJson($url, buyer(['idempotency_key' => $key]))->assertOk()->assertJsonPath('data.order_number', $o['order_number']);

    $h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
    $row = DB::transaction(function () use ($o) {
        DbContext::setCompany(HUAYLAS_ID);

        return DB::selectOne('select o.id, o.source, o.status, l.marketing_consent from orders o join external_learners l on l.id = o.external_learner_id where o.order_number = ?', [$o['order_number']]);
    });
    expect($row->source)->toBe('public_catalog')->and($row->status)->toBe('pending')->and($row->marketing_consent)->toBeTrue();
    $this->postJson("/api/v1/vta/orders/{$row->id}/pay", ['method' => 'yape', 'reference' => 'PC-0099887'], $h)->assertOk()->assertJsonPath('data.delivered.0.type', 'enrollment');
});

it('cupos para empresa: mínimo de la oferta y aforo que cuenta los pedidos pendientes', function () {
    $url = '/api/v1/public/courses/'.HUAYLAS_ID.'/altura-pc/order';
    $company = ['buyer_type' => 'company', 'legal_name' => 'Cliente PC SAC', 'ruc' => '20512345671', 'email' => 'compras.pc@example.com', 'privacy_consent' => true];
    $this->postJson($url, $company + ['seats' => 1])->assertStatus(422)->assertJsonValidationErrors('seats');
    $this->postJson($url, $company + ['seats' => 2])->assertCreated()->assertJsonPath('data.total', 236);
    // Quedan 1 de 3 (2 reservados por el pedido pendiente).
    $this->getJson('/api/v1/public/courses/'.HUAYLAS_ID)->assertJsonPath('data.offers.0.seats_left', 1);
    $this->postJson($url, $company + ['seats' => 2])->assertStatus(422)->assertJsonValidationErrors('seats');
    $this->postJson($url, buyer())->assertCreated();
    $this->postJson($url, buyer(['document_number' => '72727272', 'email' => 'otro.pc@example.com']))->assertStatus(422)->assertJsonValidationErrors('seats');
});

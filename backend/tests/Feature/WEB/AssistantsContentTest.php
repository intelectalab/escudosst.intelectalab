<?php

// Contenido de los asistentes al día con el producto (migraciones 2026_11_11_000031..33): el asistente del sitio
// responde preguntas de módulos con las preguntas frecuentes publicadas y nunca da precios en ese contenido; la ayuda
// por pantalla nombra el botón principal de cada módulo; y la biblioteca del Asistente IA cubre peligros de los sectores.

use App\Modules\IAA\AssistantController;
use App\Modules\WEB\Services\ChatbotAssistant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Cache::flush();
    DB::connection('platform')->beginTransaction();
});

afterEach(function () {
    DB::connection('pgsql')->rollBack(0);
    DB::connection('platform')->rollBack(0);
});

dataset('preguntas de módulos', [
    ['¿Tienen portal para contratistas?', 'portal'],
    ['¿Cómo funciona la portería con QR?', 'Abrir portería'],
    ['¿Se puede hacer la elección del comité de forma electrónica?', 'voto es secreto'],
    ['¿Puedo vender cursos?', 'número de operación'],
    ['¿Funciona sin internet?', 'Próximamente'],
    ['¿Emiten factura electrónica?', 'pendientes'],
    ['¿Tiene matriz IPERC?', 'controles por jerarquía'],
    ['¿Se integra con Moodle?', 'Moodle'],
]);

it('responde la pregunta de un módulo con el contenido publicado y sin precios', function (string $q, string $expected) {
    $r = app(ChatbotAssistant::class)->answer($q);
    expect($r['reply'])->toContain($expected)
        ->and($r['kind'])->toBeIn(['assistant:faq', 'guided'])
        ->and($r['reply'])->not->toMatch('/S\/\s?\d|\d+(\.\d{2})?\s?(soles|al mes)/iu');
})->with('preguntas de módulos');

it('las preguntas frecuentes y respuestas guiadas del producto no tienen precios', function () {
    $db = DB::connection('platform');
    $texts = array_merge(
        array_map(fn ($r) => $r->answer, $db->select("select answer from site_faqs where status = 'published' and deleted_at is null")),
        array_map(fn ($r) => $r->answer, $db->select("select answer from chatbot_intents where status = 'published' and deleted_at is null")),
    );
    expect(count($texts))->toBeGreaterThanOrEqual(35);
    foreach ($texts as $t) {
        expect($t)->not->toMatch('/S\/\s?\d|US\$|\$\s?\d|\d+\s?soles/iu');
    }
});

it('la ayuda de cada módulo nombra su botón principal y el asistente la muestra paso a paso', function () {
    $db = DB::connection('platform');
    $buttons = [
        'como-usar-inspecciones' => 'Nueva inspección', 'como-usar-epp' => 'Registrar entrega', 'como-usar-accidentes-incidentes-y-enfermedades' => 'Registrar evento',
        'como-usar-petar-y-permisos-de-alto-riesgo' => 'Solicitar PETAR', 'como-usar-trabajadores-y-legajo' => 'Cargar desde Excel', 'como-usar-diagnostico-de-linea-base' => 'Nuevo diagnóstico',
        'como-usar-modo-inspeccion-sunafil' => 'Generar expediente', 'como-usar-ingreso-de-terceros-por-qr' => 'Abrir portería (escanear QR)', 'como-usar-acciones-correctivas' => 'Nueva acción',
    ];
    foreach ($buttons as $slug => $label) {
        $body = $db->selectOne("select body from help_articles where slug = ? and status = 'published' and deleted_at is null", [$slug])?->body;
        expect($body)->not->toBeNull()->toContain("«{$label}»");
    }
    expect((int) $db->selectOne("select count(*) as n from help_articles where slug like 'como-usar-%' and status = 'published' and deleted_at is null and body like '%## Cómo se usa%'")->n)->toBeGreaterThanOrEqual(58);

    $r = app(ChatbotAssistant::class)->answer('cómo registro una entrega de EPP');
    expect($r['card']['type'])->toBe('guide')->and($r['card']['href'])->toBe('/ayuda/como-usar-epp')
        ->and(implode(' ', $r['card']['steps']))->toContain('Registrar entrega');
});

it('la biblioteca del Asistente IA cubre peligros y eventos de los sectores', function (string $feature, string $text, string $topic) {
    [$items, , $matched] = app(AssistantController::class)->answer($feature, $text);
    expect($matched)->toBe($topic)->and($items)->not->toBeEmpty();
})->with([
    ['controls', 'fumigación del cultivo con plaguicidas', 'Plaguicidas'],
    ['controls', 'soldadura en la nave de producción', 'Trabajos en caliente'],
    ['controls', 'excavación de zanja para tuberías', 'Aberturas, zanjas y excavaciones'],
    ['controls', 'enfermera que atiende pacientes con fluidos', 'Agentes biológicos'],
    ['causes', 'el trabajador se cayó del andamio', 'Caída de altura'],
    ['talk', 'uso del extintor', 'Prevención de incendios y uso del extintor'],
]);

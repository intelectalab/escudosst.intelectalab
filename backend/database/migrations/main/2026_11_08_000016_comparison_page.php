<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Página «EscudoSST vs Excel y papel» (SCR-WEB-31) con su contenido inicial, publicada. Se edita después en
 * Sitio web › Páginas. Solo se crea si aún no existe (no pisa cambios hechos en el panel). Sin precios ni cifras.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('platform');   // site_pages tiene RLS: escribe el rol de la plataforma
        if ($db->selectOne("select 1 as x from site_pages where slug = 'comparativa' and deleted_at is null")) {
            return;
        }
        $rows = [
            ['IPERC', 'Matrices en Excel que cada área actualiza por su lado; es difícil saber cuál es la versión vigente.',
                'Una matriz por área o puesto, con su historial de cambios y la versión vigente siempre a la vista.'],
            ['Capacitaciones', 'Listas de asistencia en papel que hay que escanear y archivar; cuesta probar quién asistió.',
                'La asistencia queda registrada con firma y fecha, junto a cada trabajador.'],
            ['Entrega de EPP', 'Cargos de entrega en papel; no se sabe a tiempo quién necesita reposición.',
                'Cada entrega queda registrada por trabajador, con su firma, y se ve qué equipos toca reponer.'],
            ['Inspecciones', 'Hallazgos en hojas o en fotos del celular que se pierden; el seguimiento depende de la memoria.',
                'Hallazgos con fotos, responsable y fecha límite, y aviso cuando una acción queda pendiente.'],
            ['Expediente para SUNAFIL', 'Antes de una inspección hay que buscar y ordenar papeles de varias carpetas.',
                'Los registros obligatorios ordenados en un solo expediente que se puede revisar y descargar.'],
            ['Alertas de vencimiento', 'Fechas de exámenes médicos, SCTR y capacitaciones anotadas en calendarios o en Excel.',
                'Avisos por correo antes de que algo venza, para actuar a tiempo.'],
        ];
        $blocks = [
            ['type' => 'hero', 'eyebrow' => 'Comparativa', 'title' => 'EscudoSST vs Excel y papel',
                'text' => 'Las mismas obligaciones de la Ley 29783, con menos papeles sueltos y todo en un solo lugar.'],
            ['type' => 'compare', 'title' => 'Tarea por tarea', 'manual_label' => 'Con Excel y papel', 'escudo_label' => 'Con EscudoSST',
                'items' => array_map(fn ($r) => ['task' => $r[0], 'manual' => $r[1], 'escudo' => $r[2]], $rows)],
            ['type' => 'cta', 'title' => 'Pruébalo con tu propia empresa', 'text' => 'Crea tu cuenta de prueba y registra tus primeros datos.', 'button' => 'trial'],
        ];
        $db->transaction(function () use ($db, $blocks) {
            $id = $db->selectOne(
            "insert into site_pages (slug, page_type, title, summary, blocks, meta_title, meta_description)
             values ('comparativa', 'comparison', 'EscudoSST vs Excel y papel', ?, ?::jsonb, ?, ?) returning id",
            [
                'Cómo cambia la gestión de SST al pasar de Excel y papel a EscudoSST, tarea por tarea.',
                json_encode($blocks, JSON_UNESCAPED_UNICODE),
                'EscudoSST vs Excel y papel · Gestión de SST',
                'Compara, tarea por tarea, cómo se lleva la SST con Excel y papel y cómo se hace con EscudoSST: IPERC, capacitaciones, EPP, inspecciones y más.',
            ]
            )->id;
            $db->statement('select web_publish_page(?)', [$id]);
            if ($db->selectOne('select status from site_pages where id = ?', [$id])->status !== 'published') {
                throw new RuntimeException('No se pudo publicar la comparativa.');
            }
        });
    }

    public function down(): void
    {
        // El contenido se edita en el panel: no se borra al revertir.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Textos de PRUEBA para los documentos legales del sitio (M-WEB, SCR-WEB-15 y 24).
 *
 * La documentación los deja «Por redactar y validar con abogado». Para que el panel y el sitio se puedan probar,
 * se carga en el borrador v1.0 de cada documento una estructura por secciones (la del prototipo) con un texto de
 * prueba marcado «[Texto de prueba]». El panel no deja publicar mientras quede esa marca.
 * Solo reemplaza borradores que siguen con el texto vacío original: nunca toca un texto ya editado o publicado.
 */
return new class extends Migration
{
    private const MARK = '[Texto de prueba]';

    private const SECTIONS = [
        'terms' => ['Objeto y aceptación', 'Definiciones', 'Registro y cuenta', 'Planes, pagos y facturación', 'Uso aceptable', 'Datos de la empresa cliente', 'Propiedad intelectual', 'Disponibilidad y soporte', 'Limitación de responsabilidad', 'Suspensión y terminación', 'Modificaciones', 'Ley aplicable y jurisdicción', 'Contacto'],
        'privacy' => ['Titular del banco de datos', 'Datos personales que tratamos', 'Finalidades del tratamiento', 'Consentimiento y base legal', 'Datos sensibles de salud', 'Encargados de tratamiento', 'Flujo transfronterizo', 'Plazo de conservación', 'Medidas de seguridad', 'Derechos de los titulares (ARCO)', 'Cambios a esta política'],
        'cookies' => ['Qué son las cookies', 'Cookies necesarias', 'Cookies de analítica', 'Cookies de marketing', 'Lista de cookies utilizadas', 'Cómo cambiar tus preferencias', 'Cambios a esta política'],
        'dpa' => ['Partes', 'Objeto del encargo', 'Instrucciones del responsable', 'Confidencialidad', 'Medidas de seguridad', 'Subencargados', 'Asistencia en el ejercicio de derechos', 'Notificación de incidentes', 'Devolución y supresión de datos', 'Auditoría y verificación'],
        'sla' => ['Alcance', 'Disponibilidad comprometida', 'Mantenimientos programados', 'Niveles de severidad', 'Canales de soporte', 'Créditos de servicio', 'Exclusiones', 'Reportes de servicio'],
        'complaints_info' => ['Qué es el Libro de Reclamaciones', 'Diferencia entre reclamo y queja', 'Cómo presentar una hoja', 'Plazo de respuesta', 'Seguimiento de tu hoja', 'Responsable de atenderla'],
    ];

    public function up(): void
    {
        $db = DB::connection('platform');
        foreach (self::SECTIONS as $type => $sections) {
            $md = '> '.self::MARK.' Este documento es una estructura de ejemplo para probar el sistema. '
                ."Reemplaza cada sección con el texto redactado y validado por el abogado antes de publicarlo.\n\n";
            foreach ($sections as $i => $title) {
                $md .= '## '.($i + 1).'. '.$title."\n\n"
                    .self::MARK.' Aquí va el texto de «'.$title.'» redactado por el abogado. '
                    ."Escribe en párrafos cortos y claros; puedes usar listas empezando cada línea con un guion.\n\n";
            }
            $db->update(
                "update legal_document_versions v set content = ?, summary_of_changes = 'Estructura de prueba'
                   from legal_documents d
                  where d.id = v.legal_document_id and d.doc_type = ? and v.status = 'draft'
                    and v.content = 'Por redactar y validar con abogado'",
                [rtrim($md), $type]
            );
        }
    }

    public function down(): void
    {
        DB::connection('platform')->update(
            "update legal_document_versions set content = 'Por redactar y validar con abogado' where status = 'draft' and content like '> [Texto de prueba]%'"
        );
    }
};

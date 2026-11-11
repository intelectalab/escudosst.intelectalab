<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Asistente del sitio (M-WEB SCR-WEB-59..62): preguntas frecuentes publicadas y respuestas guiadas con lo que
 * EscudoSST hace hoy (10/11/2026). El asistente responde solo con contenido publicado: estas preguntas salen también en
 * /preguntas-frecuentes. Nunca se escriben precios: los planes se ven en /planes y la cotización en /cotizacion.
 * Lo pendiente de proveedor (pagos en línea, facturación electrónica, firma digital certificada) se dice como pendiente,
 * y la app sin conexión como «Próximamente». El equipo las edita en Panel › Sitio web (Preguntas frecuentes y Asistente).
 * Solo inserta lo que no existe (por pregunta o por nombre): no pisa lo que el equipo ya editó.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('platform');   // site_faqs y chatbot_intents tienen RLS solo para el rol de la plataforma
        $order = 100;
        foreach ($this->faqs() as [$topic, $question, $answer]) {
            $order += 10;
            if ($db->selectOne('select 1 as ok from site_faqs where lower(question) = lower(?) and deleted_at is null', [$question])) {
                continue;
            }
            $db->insert("insert into site_faqs (topic, question, answer, sort_order, status) values (?, ?, ?, ?, 'published')", [$topic, $question, $answer, $order]);
        }
        $order = 50;
        foreach ($this->intents() as [$name, $phrases, $answer, $ctaType, $ctaUrl]) {
            $order++;
            if ($db->selectOne('select 1 as ok from chatbot_intents where name = ? and deleted_at is null', [$name])) {
                continue;
            }
            $db->insert("insert into chatbot_intents (name, trigger_phrases_json, answer, cta_type, cta_url, sort_order, status) values (?, ?::jsonb, ?, ?, ?, ?, 'published')",
                [$name, json_encode($phrases, JSON_UNESCAPED_UNICODE), $answer, $ctaType, $ctaUrl, $order]);
        }
        Cache::forget('chatbot:index');
    }

    public function down(): void
    {
        // Borrado lógico (el rol de la plataforma no borra físicamente); solo lo que el equipo no editó.
        $db = DB::connection('platform');
        foreach ($this->faqs() as [, $question]) {
            $db->update('update site_faqs set deleted_at = now() where question = ? and updated_by is null and deleted_at is null', [$question]);
        }
        foreach ($this->intents() as [$name]) {
            $db->update('update chatbot_intents set deleted_at = now() where name = ? and updated_by is null and deleted_at is null', [$name]);
        }
        Cache::forget('chatbot:index');
    }

    /** Respuestas guiadas: solo frases muy específicas (tienen prioridad sobre todo lo demás). */
    private function intents(): array
    {
        return [
            ['Pagos en línea y facturación electrónica', ['facturación electrónica', 'facturacion electronica', 'factura electrónica', 'factura electronica', 'boleta electrónica', 'boleta electronica', 'pago en línea', 'pago en linea', 'pagos en línea', 'pagos en linea', 'pagar con tarjeta', 'pago con tarjeta'],
                'Los pagos en línea con tarjeta y la emisión de comprobantes electrónicos desde EscudoSST están pendientes de la conexión con el proveedor: todavía no funcionan. Hoy, en la venta de cursos, el pago se registra a mano con el número de operación (Yape, Plin, transferencia, depósito o efectivo). Para tu suscripción, pide una cotización y el equipo te indica cómo pagar.',
                'link', '/cotizacion'],
            ['Firma digital certificada', ['firma digital', 'certificado digital', 'dnie', 'firma con certificado'],
                'EscudoSST ya trae firma electrónica simple con evidencia: se pide la firma, la persona firma o rechaza desde sus pendientes, y cada firma tiene código de verificación y huella del documento. La firma digital con certificado (por ejemplo con DNIe) está pendiente de la conexión con el proveedor: todavía no está disponible.',
                'link', '/producto'],
            ['App sin conexión', ['sin internet', 'sin conexión', 'sin conexion', 'offline', 'sin señal', 'sin senal', 'zona sin cobertura'],
                'La app móvil sin conexión es un complemento que aparece como «Próximamente»: todavía no se vende ni se activa en las cuentas. Hoy EscudoSST se usa desde el navegador de la computadora o del celular, con internet.',
                'link', '/complementos'],
        ];
    }

    /** [tema, pregunta, respuesta]. Temas: general, plans, security, data_protection, product, billing, support. */
    private function faqs(): array
    {
        return [
            ['product', '¿Qué módulos incluye?', 'El menú se ordena en grupos: Planificación (línea base, política y objetivos, matriz legal, plan y programa anual, programa de capacitación, RISST, comité o supervisor, presupuesto), Riesgos (IPERC, mapa de riesgos, monitoreo de agentes, riesgos ergonómicos y psicosociales, PETS, gestión del cambio), Operación en campo (inspecciones, actos y condiciones, charlas de 5 minutos, ATS, PETAR, observación de conducta, EPP, equipos de emergencia, simulacros y brigadas), Personas y salud (capacitaciones, aula virtual, fotocheck con QR, EMO, vigilancia médica, descansos médicos, grupos vulnerables, venta de cursos), Eventos y mejora (accidentes, investigación, acciones correctivas, auditorías, estadísticas, revisión por la dirección, registros obligatorios), Contratistas (acreditación, SCTR, ingreso por QR, homologación) y Modo SUNAFIL. Cada plan incluye una parte: compáralos en la página de planes.'],
            ['product', '¿Qué complementos hay?', 'Puedes agregar a tu plan: Asistente IA, Paquetes sectoriales, Sistema integrado ISO, Medio ambiente (SSOMA), Gestión del cambio y Homologación de proveedores. La app móvil sin conexión aparece como «Próximamente»: todavía no se vende. Los detalles están en la página de complementos.'],
            ['product', '¿Cómo empiezo a usar el sistema?', 'Al entrar por primera vez verás «Primeros pasos»: completas los datos de la empresa (sector y nivel de riesgo), creas tus sedes, áreas y puestos, cargas a tus trabajadores desde Excel y haces el diagnóstico de línea base. Cada paso tiene su botón y una barra de avance.'],
            ['product', '¿Puedo cargar a mis trabajadores desde Excel?', 'Sí. En Trabajadores y legajo pulsa «Cargar desde Excel»: descargas la plantilla, completas una fila por trabajador y la subes (hasta 5 MB y 2000 trabajadores). Eliges el modo (solo altas, altas y actualización, o cesar), el sistema revisa cada fila antes de guardar y las filas con error se pueden descargar para corregirlas.'],
            ['product', '¿Cómo me ayuda ante una inspección de SUNAFIL?', 'El Modo Inspección SUNAFIL muestra un autodiagnóstico con puntaje y las brechas a cerrar primero. Si llega un requerimiento, el asistente «Nuevo expediente» te pide la orden de inspección, la comparecencia, el periodo, los trabajadores inspeccionados y las obligaciones, y arma el expediente con los datos del sistema. Lo descargas en ZIP o lo compartes con el inspector con un enlace temporal (vigencia, límite de visitas y descargas, y aprobación de gerencia si la quieres).'],
            ['product', '¿Tiene matriz IPERC?', 'Sí. Puedes tener una matriz por sede, área, proceso o puesto, con identificación del peligro, evaluación inicial, controles por jerarquía y riesgo residual. Cada matriz guarda sus versiones aprobadas, tiene mapa de calor y se descarga en PDF o Excel. Con el complemento Asistente IA puedes pedir filas sugeridas para un puesto.'],
            ['product', '¿Cómo se registra un accidente?', 'En Accidentes, incidentes y enfermedades pulsa «Registrar evento»: un asistente por pasos te pide qué pasó (tipo de evento, fecha, sede y lugar), la persona afectada, la lesión y los testigos, y la notificación. Te muestra el plazo para notificar a la autoridad según los parámetros legales vigentes y, si el tipo lo exige, abre la investigación.'],
            ['product', '¿Genera los registros obligatorios del SG-SST?', 'Sí. En Registros obligatorios eliges el registro (accidentes de trabajo, enfermedades ocupacionales, incidentes peligrosos e incidentes, exámenes médicos ocupacionales, monitoreo de agentes, inspecciones internas, estadísticas, equipos de seguridad o emergencia, inducción y capacitación, y auditorías) y el periodo; ves la vista previa con los datos del sistema y lo descargas en Excel o PDF. Queda un historial de lo exportado.'],
            ['product', '¿Cómo funciona el PETAR?', 'En PETAR y permisos de alto riesgo pulsa «Solicitar PETAR»: eliges el tipo de permiso, el lugar, la tarea y el horario, y agregas a los ejecutantes y al vigía. El sistema revisa a cada ejecutante (inducción, EMO apto vigente, SCTR, capacitación y EPP del puesto) y pide responder las verificaciones previas en el lugar: un «No» impide aprobar hasta corregirlo. Luego el permiso se aprueba, se activa, se puede suspender y se cierra dejando el área segura.'],
            ['product', '¿Tiene portal para contratistas?', 'Sí. Desde Acreditación de contratistas invitas al contratista y le llega un correo para crear su contraseña. En su portal sube sus documentos, agrega a sus trabajadores (o los carga desde Excel), registra sus pólizas SCTR y solicita la acreditación. Tú revisas cada documento y lo apruebas u observas; el nivel de riesgo del servicio define qué requisitos se le exigen.'],
            ['product', '¿Cómo funciona la portería con QR?', 'En Ingreso de terceros por QR pulsa «Abrir portería (escanear QR)»: la cámara lee el fotocheck o se escribe el DNI, y el sistema dice al instante si la persona está habilitada. Si no lo está, se puede permitir por excepción con un motivo (se avisa al Jefe SST). También registra visitantes con su inducción de visita, muestra quiénes están dentro y registra las salidas.'],
            ['product', '¿Se puede hacer la elección del comité de forma electrónica?', 'Sí. En Comité o Supervisor SST, pestaña «Elección electrónica»: convocas la elección, se arma el padrón con los trabajadores activos, se inscriben y habilitan los candidatos, abres la votación y cada trabajador vota desde la app o con su enlace de votación. El voto es secreto, no se puede cambiar y deja comprobante. Al cerrar se hace el escrutinio y se genera el acta. Las reuniones y actas del comité también se llevan ahí, con quórum y firmas.'],
            ['product', '¿Tiene aula virtual para capacitar?', 'Sí. El Aula virtual SST permite crear cursos con secciones de texto, video, documento, enlace y evaluación; bancos de preguntas (también desde Excel); escalas de nota; matrícula por persona, puesto o área; control del tiempo de permanencia; recomendaciones de cursos y permisos para capacitadores. Si ya usas Moodle, se puede conectar de forma opcional.'],
            ['product', '¿Puedo vender cursos?', 'Sí, con el módulo Venta de cursos y pagos: pones un curso a la venta, creas pedidos para una persona o para una empresa (cupos) y usas cupones. Hoy el pago se registra a mano con el número de operación (Yape, Plin, transferencia, depósito o efectivo). Los pagos en línea con tarjeta y los comprobantes electrónicos están pendientes de la conexión con el proveedor.'],
            ['data_protection', '¿Los resultados médicos están cifrados?', 'Sí. Los resultados de los exámenes médicos, el detalle de los descansos médicos y las condiciones de salud de los grupos vulnerables se guardan cifrados en una base médica separada. Solo los roles médicos los ven, y para abrirlos se confirma la contraseña. Cada acceso queda registrado en «Accesos a datos sensibles» de la bitácora. Los demás roles ven solo la aptitud (apto, apto con restricciones, no apto).'],
            ['product', '¿Funciona sin internet?', 'Todavía no. La app móvil sin conexión es un complemento que aparece como «Próximamente» y aún no se vende. Hoy EscudoSST se usa desde el navegador de la computadora o del celular, con internet.'],
            ['security', '¿Hacen copias de seguridad de mis datos?', 'Sí. Se hacen copias de seguridad cifradas de forma automática y se pueden guardar también en un almacenamiento externo, fuera del servidor principal. El detalle de cómo cuidamos los datos está en el centro de confianza.'],
            ['security', '¿Tiene verificación en dos pasos?', 'Sí. Al ingresar, además de la contraseña, se pide un código: de tu app de autenticación (Google o Microsoft Authenticator) o, si no la tienes a la mano, un código por correo. Si no puedes con ninguno, usas uno de tus códigos de recuperación (cada uno sirve una sola vez).'],
            ['security', '¿Puedo ingresar con mi DNI?', 'Sí. En la página de inicio de sesión puedes escribir tu correo de trabajo o tu DNI, y luego tu contraseña y la verificación en dos pasos.'],
            ['product', '¿Tiene un buscador general?', 'Sí. Desde la barra superior puedes buscar un trabajador, un módulo o un registro y abrirlo directamente.'],
            ['support', '¿Hay ayuda dentro del sistema?', 'Sí. En cada pantalla el botón «Ayuda» muestra los artículos de esa pantalla, un buscador de ayuda y videos cuando los hay. Si no encuentras la respuesta, «Escribir al equipo» envía tu consulta al equipo de EscudoSST y la respuesta te llega en «Mis consultas».'],
            ['product', '¿Puedo dar permisos distintos a cada persona?', 'Sí. En Usuarios, roles y permisos invitas a cada persona con su rol y, si quieres, la limitas a una sede. Cada rol ve y hace solo lo que le corresponde, y puedes suspender o reactivar el acceso. Todos los cambios quedan en la bitácora de la empresa.'],
            ['product', '¿Tienen marca blanca para consultoras?', 'Sí, en el plan Consultora. Tienes un panel con todas tus empresas cliente (cumplimiento, alertas y próximas visitas), invitas empresas y configuras tu marca blanca: nombre de tu consultora, logo, colores, pie de página en los documentos y la opción de mostrar «Con tecnología EscudoSST».'],
            ['product', '¿Envía avisos por WhatsApp?', 'EscudoSST se conecta con WhatsApp oficial (la API de WhatsApp Business) para enviar alertas críticas, el resumen diario, enlaces de votación y respuestas a reportes a quienes lo aceptaron. Mientras esa conexión no esté activa, los avisos llegan a la campana de la app y por correo.'],
            ['product', '¿Se integra con Moodle?', 'Sí, de forma opcional. Desde el Aula virtual se configura la conexión con tu Moodle, se prueba, se enlazan los cursos, se envían las matrículas y se traen las notas.'],
            ['billing', '¿Emiten comprobantes electrónicos?', 'La emisión de facturas y boletas electrónicas desde EscudoSST está pendiente de la conexión con el proveedor y todavía no funciona. Para tu suscripción, pide una cotización y el equipo te indica cómo se factura.'],
            ['product', '¿Tiene firma electrónica de registros?', 'Sí. Los registros, actas y cargos se firman con firma electrónica simple con evidencia: se pide la firma, la persona firma o rechaza desde sus pendientes y cada firma tiene un código para verificarla. También se puede tomar la firma del trabajador presente. La firma digital con certificado está pendiente de proveedor.'],
            ['product', '¿Tiene contenido por sector?', 'Sí, con el complemento Paquetes sectoriales: construcción, minería, agroindustria, manufactura, retail y comercio, oficinas y servicios, y clínicas y salud. Cada paquete trae peligros, plantillas, permisos y temas de capacitación del sector, y se aplica con un botón.'],
            ['product', '¿Controla la entrega de EPP?', 'Sí. En EPP tienes el catálogo, el EPP exigido por puesto, el stock y las entregas: con «Registrar entrega» queda quién recibió qué y cuándo, y el sistema avisa lo que hay que reponer en los próximos 30 días.'],
            ['product', '¿Controla capacitaciones y certificados?', 'Sí. En Capacitaciones e inducciones programas la capacitación, registras sesiones, asistencias y evaluaciones, y emites los certificados (se pueden verificar en línea). La matriz por cargo muestra qué capacitaciones exige cada puesto y cuánto se cumple.'],
            ['product', '¿Controla los exámenes médicos ocupacionales?', 'Sí. En EMOs y aptitud programas los exámenes, registras la aptitud de cada trabajador y ves las aptitudes por vencer en 30 días y los aptos con restricciones. Los resultados médicos solo los ve el personal médico.'],
            ['product', '¿Calcula los índices de accidentabilidad?', 'Sí. En Estadísticas SST registras las horas-hombre de cada mes y el sistema calcula el índice de frecuencia (IF), el de gravedad (IG) y el de accidentabilidad (IA), por mes y acumulado del año, con los accidentes con baja y los días perdidos.'],
            ['product', '¿Tiene asistente con inteligencia artificial?', 'Sí, el complemento Asistente IA: sugiere filas del IPERC para un puesto, guía la investigación de un accidente con preguntas y causas propuestas, y responde consultas rápidas (controles para un peligro, guía de causas y guion de charla de 5 minutos). Responde con una biblioteca preparada por el equipo de EscudoSST; cada sugerencia la acepta o descarta una persona. No se escriben datos de salud.'],
            ['product', '¿Tiene fotocheck con QR?', 'Sí. En Fotocheck SST con QR eliges al trabajador y el tipo de fotocheck, el sistema revisa que cumpla los requisitos y lo emites e imprimes. El QR se lee en la portería para validar el ingreso.'],
            ['product', '¿Qué es el diagnóstico de línea base?', 'Es la evaluación inicial del sistema de gestión SST. En Diagnóstico de línea base pulsa «Nuevo diagnóstico»: respondes la lista de verificación (cumple, parcial, no cumple, no aplica) con su nota de evidencia, ves el puntaje y al aprobarlo se generan las brechas a cerrar.'],
        ];
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Autoayuda por pantalla (SCR-WEB-34) al día con la aplicación (11/11/2026): cada «Cómo usar <módulo>» describe las
 * tarjetas de indicadores, pestañas, asistentes por pasos, botones y la ficha de cada registro tal como se ven hoy
 * (frontend/app/(app)/m/[code]/page.tsx, components/modules/*, lib/moduleScreens.ts y lib/moduleKpis.ts).
 * Actualiza el texto por slug (los slugs no cambian; el de Charlas de 5 minutos se crea si faltara). También pone al
 * día «Cómo ingresar» (correo o DNI). El equipo los sigue editando en Panel › Sitio web › Centro de ayuda.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    private const LISTA = 'Arriba de cada lista tienes el buscador («Buscar por código, nombre o texto…») y los filtros; «Exportar» descarga la lista filtrada en CSV para Excel y, si marcas filas, «Exportar seleccionados».';

    public function up(): void
    {
        // help_* tienen RLS solo para el rol de la plataforma: se escribe con esa conexión.
        $db = DB::connection('platform');
        $cat = $db->selectOne("select id from help_categories where slug = 'uso-de-los-modulos'")?->id;
        foreach ($this->articles() as $slug => $body) {
            $body = str_replace('{LISTA}', self::LISTA, $body);
            $n = $db->update('update help_articles set body = ?, updated_at = now() where slug = ? and deleted_at is null', [$body, $slug]);
            if ($n === 0 && $cat && $slug === 'como-usar-charlas-de-5-minutos') {
                $db->insert("insert into help_articles (category_id, title, slug, body, screen_code, status, published_at, sort_order) values (?, 'Cómo usar Charlas de 5 minutos', ?, ?, 'SCR-CH5-01', 'published', now(), 35)",
                    [$cat, $slug, $body]);
            }
        }
        $db->update("update help_articles set body = ?, updated_at = now() where slug = 'como-ingresar-a-escudosst' and deleted_at is null", [<<<'TXT'
Para entrar necesitas tu correo de trabajo o tu DNI, tu contraseña y la verificación en dos pasos.

## Pasos

- Abre la página de inicio de sesión y escribe tu correo o tu DNI, y tu contraseña.
- Escribe el código de 6 dígitos de tu app de autenticación (Google o Microsoft Authenticator).
- Si no tienes la app a la mano, elige «Código por correo»: te llega un código a tu correo.
- Si no puedes con ninguno, escribe uno de tus códigos de recuperación (cada uno sirve una sola vez).
- Si trabajas con varias empresas, elige con cuál quieres entrar.

## La primera vez

Al entrar por primera vez configuras tu app de autenticación y guardas tus códigos de recuperación en un lugar seguro.

## Si no te llega el código por correo

Revisa la carpeta de correo no deseado y espera un minuto antes de pedir otro.
TXT]);
        Cache::forget('chatbot:index');
    }

    public function down(): void
    {
        // El texto anterior está en 2026_11_10_000023: no se restaura (el equipo puede haberlo editado después).
    }

    /** @return array<string, string> slug => cuerpo (Markdown). */
    private function articles(): array
    {
        return [
            'como-usar-empresas-sedes-y-areas' => <<<'TXT'
La base de todo: cómo está organizada la empresa. Sin sedes, áreas y puestos, los demás módulos no saben a quién aplicar el IPERC, el EPP, los exámenes o las capacitaciones. Está en Configuración.

Lo usa: Administrador de la cuenta y Jefe SST.

## Cómo se usa

- En la pestaña «Sedes» pulsa «Nueva sede» (planta, obra, oficina o tienda).
- En «Áreas» pulsa «Nueva área» y elige su sede (las áreas pueden tener sub-áreas).
- En «Puestos» pulsa «Nuevo puesto»; marca si es un puesto crítico (alto riesgo).
- En «Turnos» pulsa «Nuevo turno» para calcular las horas-hombre.
- Si recién empiezas, la guía «Primeros pasos» del inicio te lleva por estos pasos en orden.

## Botones importantes

- Nueva sede · Nueva área · Nuevo puesto · Nuevo turno
- Exportar: descarga la lista en CSV para Excel.
- Archivar (dentro de la ficha): no deja desactivar una sede que tiene trabajadores activos.

## Ficha de cada registro

Al tocar una fila se abre su ficha con las pestañas Resumen, Detalle e Historial (quién cambió qué y cuándo). {LISTA}

## Se conecta con

Trabajadores, IPERC, EPP, EMO, Capacitaciones, Estadísticas (horas-hombre).
TXT,
            'como-usar-usuarios-roles-y-permisos' => <<<'TXT'
Controla quién entra al sistema y qué puede hacer. Cada persona tiene un rol y, si quieres, un alcance limitado a una sede. Cada rol ve y hace solo lo que le corresponde. Está en Configuración.

Lo usa: Administrador de la cuenta.

## Cómo se usa

- En la pestaña «Usuarios» pulsa «Invitar usuario»: correo, nombre completo y rol.
- A la persona le llega un correo para crear su contraseña y configurar su verificación en dos pasos.
- Con «Editar acceso» cambias su rol o su alcance; con «Suspender» le quitas el acceso y con «Reactivar» se lo devuelves.
- Si la invitación no llegó, usa «Reenviar».
- En la pestaña «Roles y permisos» ves qué puede hacer cada rol.

## Lo que ves en la pantalla

La lista muestra a cada persona con su correo, alcance, si tiene doble factor, su último acceso y su estado. Si tu empresa trabaja con una consultora, arriba aparece su acceso y el botón «Quitar acceso a la consultora».

## Botones importantes

- Invitar usuario
- Editar acceso · Reenviar · Suspender · Reactivar

## Se conecta con

Todos los módulos (cada botón respeta el rol). La Bitácora de auditoría registra cada cambio.
TXT,
            'como-usar-trabajadores-y-legajo' => <<<'TXT'
La ficha única de cada trabajador y su legajo SST: datos, sede, área, puesto, historial de asignaciones y documentos con vencimiento. Es el «quién» de todo el sistema.

Lo usa: Jefe SST y Recursos Humanos.

## Cómo se usa

- Pulsa «Nuevo trabajador» para registrar a una persona (DNI, nombres, sede y puesto).
- Para muchos a la vez pulsa «Cargar desde Excel»: descarga la plantilla (.xlsx), completa una fila por trabajador, elige el modo (solo altas, altas y actualización, o cesar) y súbela. El sistema revisa cada fila antes de guardar; las filas con error no bloquean a las demás y puedes descargarlas para corregirlas.
- Cuando alguien cambia de puesto o sede, agrega una asignación en su ficha: queda el historial.
- Sube los documentos del legajo con su fecha de vencimiento.

## Lo que ves en la pantalla

Cuatro tarjetas: Trabajadores activos, Ingresos del mes, De licencia y Cesados. Al tocar una tarjeta se abre la lista ya filtrada. {LISTA}

## Ficha de cada trabajador

Pestañas Resumen, Detalle, Asignaciones, Legajo, Firmas e Historial.

## Botones importantes

- Nuevo trabajador
- Cargar desde Excel (con «Cargas anteriores» y «Descargar filas con error»)
- Exportar

## Se conecta con

EPP, EMO, Capacitaciones, Fotocheck, Accidentes, PETAR (revisión de ejecutantes) y el expediente SUNAFIL.
TXT,
            'como-usar-gestion-documental' => <<<'TXT'
Guarda los documentos del sistema de gestión (procedimientos, formatos, instructivos) con versiones: solo hay una versión vigente y queda quién la elaboró, revisó y aprobó, y qué trabajadores firmaron su cargo de lectura.

Lo usa: Jefe SST; los trabajadores firman su cargo de lectura.

## Cómo se usa

- En la pestaña «Documentos» pulsa «Subir documento» y elige su categoría.
- En la ficha del documento agrega la versión con su archivo o contenido y pulsa «Enviar a revisión».
- Quien revisa la ve en la «Bandeja de aprobación»: «Ver documento», «Aprobar con firma» o «Devolver» con un comentario.
- Con la versión aprobada pulsa «Poner vigente»: la anterior queda obsoleta.
- En «Campaña de cargos» eliges el documento y el grupo (sede, área o puesto) y pulsas «Pedir cargo de lectura»; luego «Enviar recordatorio a pendientes».

## Lo que ves en la pantalla

Cuatro tarjetas: Documentos vigentes, Por revisar (30 días), En borrador y Obsoletos. Arriba están la Bandeja de aprobación y la Campaña de cargos; abajo, las pestañas «Documentos» y «Categorías». {LISTA}

## Ficha de cada documento

Pestañas Resumen, Detalle, Versiones (con su línea de tiempo), Firmas e Historial. Cada versión tiene sus cargos de lectura.

## Botones importantes

- Subir documento
- Enviar a revisión · Aprobar · Devolver a borrador · Poner vigente
- Pedir cargo de lectura · Enviar recordatorio a pendientes

## Se conecta con

Firma electrónica, Expediente SUNAFIL, Sistema integrado ISO.
TXT,
            'como-usar-alertas-y-notificaciones' => <<<'TXT'
Avisa a tiempo lo que vence o está atrasado: acciones correctivas, notificaciones de accidentes, revisión del IPERC, inspecciones sin ejecutar, aptitudes médicas, EPP, fotochecks y capacitaciones por vencer. Llega a la campana y en un correo resumen diario. Está en Configuración.

Lo usa: Jefe SST (configura); todos reciben lo que les toca según su rol.

## Cómo se usa

- Cada mañana el sistema revisa los vencimientos y crea los avisos.
- Cada persona los ve en la campana (arriba a la derecha) y recibe un solo correo con el resumen.
- En la pestaña «Reglas de alerta» pulsa «Nueva regla» para ajustar con cuántos días de anticipación se avisa y a quién.
- En «Envíos recientes» ves qué se envió y si llegó.
- Cada persona elige en Mi cuenta qué avisos quiere por correo.

## Botones importantes

- Campana
- Nueva regla
- Exportar

## Se conecta con

Casi todos los módulos generan avisos. Si el equipo de EscudoSST activa WhatsApp, las alertas críticas también llegan por ahí a quienes lo aceptaron.
TXT,
            'como-usar-dashboard-e-indicadores' => <<<'TXT'
La pantalla de indicadores: muestra en un vistazo cómo va la seguridad de la empresa, con semáforo, y lo que vence pronto.

Lo usa: Jefe SST, Gerencia y supervisores.

## Cómo se usa

- Elige la sede (o «Todas las sedes») arriba.
- Filtra los indicadores por estado: En meta, Atención o Crítico.
- En cada indicador pulsa «¿Cómo se calcula?» para ver su fórmula y su numerador.
- Pulsa «Ver los registros de este indicador» para abrir la lista que lo explica.
- Revisa «Vencimientos próximos» para saber qué atender primero.

## Botones importantes

- Filtro de sede y de estado
- ¿Cómo se calcula?
- Ver registros

## Se conecta con

Lee de todos los módulos. Si tu rol no tiene acceso a los indicadores, el sistema te lleva a tus módulos.
TXT,
            'como-usar-firma-electronica-de-registros' => <<<'TXT'
Firma electrónica simple con evidencia para registros, actas, cargos e informes: quién firmó, cuándo, con qué método y la huella del documento. Está en Configuración.

Lo usa: Todos (cada uno firma lo suyo); el Jefe SST hace seguimiento.

## Cómo se usa

- En la ficha de un registro, pestaña «Firmas», pulsa «Pedir firma» y elige a la persona.
- La persona ve la solicitud en sus pendientes y pulsa «Firmar» (confirma su contraseña) o «Rechazar» con un motivo.
- Si el trabajador está presente sin usuario, usa «Firma del trabajador (presente)».
- En este módulo ves todas las solicitudes; usa «Reenviar recordatorio» para las pendientes.
- En «Verificar una firma» escribe el código (p. ej. A1B2-C3D4-E5F6) y pulsa «Verificar»: compara la huella firmada con la actual.

## Lo que ves en la pantalla

Cuatro tarjetas: Solicitudes abiertas, Firmadas, Rechazadas y Vencidas. Abajo, la pestaña «Solicitudes de firma». {LISTA}

## Botones importantes

- Pedir firma · Firmar · Rechazar
- Reenviar recordatorio
- Verificar

## Se conecta con

Documentos, actas del comité, investigaciones, ATS, PETAR y RISST. La firma digital con certificado está pendiente de proveedor.
TXT,
            'como-usar-bitacora-de-auditoria' => <<<'TXT'
Registro inalterable de quién hizo qué y cuándo en la empresa. Los accesos a datos médicos se registran aparte. Está en Configuración.

Lo usa: Administrador de la cuenta, Jefe SST y auditores.

## Cómo se usa

- En «Bitácora general» filtra por fecha (desde y hasta), usuario, módulo, acción o resultado.
- Toca un movimiento para ver los campos que cambiaron (antes y después). Los datos protegidos muestran que cambiaron, sin su valor.
- Pulsa «Abrir el registro» para ir a la ficha.
- En «Accesos a datos sensibles» ves quién consultó datos médicos, con qué motivo y si confirmó su contraseña.
- Pulsa «Exportar» para descargarla.

## Botones importantes

- Exportar
- Abrir el registro
- Anterior / Siguiente

## Se conecta con

Todos los módulos. Cada ficha tiene además su pestaña «Historial» con la misma información de ese registro.
TXT,
            'como-usar-diagnostico-de-linea-base' => <<<'TXT'
La evaluación inicial del sistema de gestión SST: qué cumples y qué no. Lo que falta se convierte en brechas con su acción.

Lo usa: Jefe SST.

## Cómo se usa

- Pulsa «Nuevo diagnóstico»: se abre un asistente por pasos.
- Datos: título, fecha y sede (déjala vacía para evaluar toda la empresa). Al continuar se crea en borrador con la lista base de EscudoSST.
- Responde cada ítem: Cumple, Parcial, No cumple o No aplica, con su nota de evidencia.
- Revisa el puntaje y las brechas que se generarán, y pulsa «Aprobar y generar brechas».
- Desde «Ver brechas» crea la acción correctiva de cada brecha.

## Lo que ves en la pantalla

Arriba, el resultado del diagnóstico por lineamiento: ítems, cumplimiento, brechas y estado (filtra por Cumple, En proceso o No cumple). Abajo, la pestaña «Diagnósticos». {LISTA}

## Ficha de cada diagnóstico

Pestañas Resumen, Detalle, Ítems evaluados, Brechas, Firmas e Historial.

## Botones importantes

- Nuevo diagnóstico
- Aprobar y generar brechas
- Crear acción correctiva (en cada brecha)

## Se conecta con

Acciones correctivas, Plan anual, Modo SUNAFIL.
TXT,
            'como-usar-politica-y-objetivos-sst' => <<<'TXT'
La política de SST firmada por la gerencia y los objetivos con sus metas medibles.

Lo usa: Jefe SST y Gerencia.

## Cómo se usa

- En «Versiones de la política» pulsa «Nueva versión» y escribe el texto.
- Pulsa «Enviar a revisión»; otra persona la aprueba con «Aprobar» y luego «Poner vigente» (la anterior queda obsoleta).
- En «Objetivos» pulsa «Nuevo objetivo» y, en su ficha, agrega sus metas.

## Ficha de cada registro

Pestañas Resumen, Detalle, Metas (en objetivos), Firmas e Historial. {LISTA}

## Botones importantes

- Nueva versión · Nuevo objetivo
- Enviar a revisión · Aprobar · Devolver a borrador · Poner vigente

## Se conecta con

Plan anual, Programa anual SST, Revisión por la dirección.
TXT,
            'como-usar-matriz-de-requisitos-legales' => <<<'TXT'
Las normas y requisitos legales que aplican a tu empresa, con su evaluación de cumplimiento y los cambios normativos que debes revisar.

Lo usa: Jefe SST.

## Cómo se usa

- Pulsa «Agregar desde el catálogo»: filtra por sector y tema, marca las normas que aplican, revisa sus requisitos y elige responsable, próxima evaluación y sede. Lo que ya está en tu matriz no se duplica.
- Para una norma que no está en el catálogo (p. ej. una ordenanza municipal o un estándar del cliente) pulsa «Norma propia».
- En la ficha de cada requisito registra su evaluación; si no cumple, pulsa «Crear acción correctiva».
- En «Novedades normativas» revisa los requisitos que cambiaron en los últimos 90 días y marca «Revisado».

## Lo que ves en la pantalla

Cuatro tarjetas: Requisitos aplicables, Evaluación por vencer (30 días), No aplican y Derogados. El catálogo tiene dos vistas: «Catálogo EscudoSST» y «Propias de la empresa». El texto del catálogo es referencial y debe validarlo un especialista. {LISTA}

## Ficha de cada requisito

Pestañas Resumen, Detalle, Evaluaciones, Firmas e Historial.

## Botones importantes

- Agregar desde el catálogo · Norma propia · Agregar requisito
- Revisado (en novedades normativas)
- Crear acción correctiva

## Se conecta con

Acciones correctivas, Auditorías, Modo SUNAFIL.
TXT,
            'como-usar-plan-anual-sst' => <<<'TXT'
El plan anual del sistema de gestión SST con sus componentes.

Lo usa: Jefe SST; lo revisa el Comité.

## Cómo se usa

- En «Planes anuales» pulsa «Nuevo» y completa los datos del año.
- En la ficha agrega sus componentes.
- Pulsa «Enviar a revisión», luego «Aprobar» y «Poner vigente». Al terminar el año, «Cerrar plan».

## Ficha de cada plan

Pestañas Resumen, Detalle, Componentes, Firmas e Historial. {LISTA}

## Botones importantes

- Enviar a revisión · Aprobar · Poner vigente · Cerrar plan

## Se conecta con

Programa anual SST, Programa de capacitación, Presupuesto.
TXT,
            'como-usar-programa-anual-sst-passt' => <<<'TXT'
El programa anual de SST (PASST): actividades con su cronograma, responsable, meta y evidencia de ejecución.

Lo usa: Jefe SST; lo revisa el Comité.

## Cómo se usa

- Pulsa «Nuevo programa» y luego agrega sus actividades con «Nueva actividad» en su ficha.
- El programa pasa por Borrador → Revisión del comité → Aprobado → En ejecución → Cerrado: usa «Iniciar ejecución» y, al final, «Cerrar programa».
- En el cronograma toca el mes de una actividad: «Registrar ejecución» con fecha, cantidad y evidencia (acta, informe o registro).
- Si una actividad se mueve, pulsa «Reprogramar»: el motivo es obligatorio y queda en el historial de reprogramaciones.

## Lo que ves en la pantalla

Arriba, el cronograma con el avance del programa, actividades, ejecutadas y atrasadas del mes, y el avance por objetivo. Abajo, la pestaña «Programas». {LISTA}

## Ficha de cada programa

Pestañas Resumen, Detalle, Actividades (cada una con sus Ejecuciones), Firmas e Historial.

## Botones importantes

- Nuevo programa · Nueva actividad
- Registrar ejecución · Reprogramar
- Iniciar ejecución · Cerrar programa

## Se conecta con

Plan anual, Política y objetivos, Indicadores.
TXT,
            'como-usar-programa-anual-de-capacitacion' => <<<'TXT'
El programa anual de capacitación: los temas programados del año, con su público y fecha.

Lo usa: Jefe SST y Recursos Humanos.

## Cómo se usa

- En «Programas» pulsa «Nuevo» y, en su ficha, agrega los temas programados.
- El programa se revisa y aprueba igual que el PASST; usa «Iniciar ejecución» y, al final, «Cerrar programa».
- Cada tema se ejecuta programando la capacitación en Capacitaciones e inducciones.

## Ficha de cada programa

Pestañas Resumen, Detalle, Temas programados, Firmas e Historial. {LISTA}

## Botones importantes

- Nuevo · Iniciar ejecución · Cerrar programa

## Se conecta con

Capacitaciones e inducciones, Aula virtual, Plan anual.
TXT,
            'como-usar-risst' => <<<'TXT'
El Reglamento Interno de SST: sus versiones aprobadas y la entrega a cada trabajador con su cargo firmado.

Lo usa: Jefe SST; los trabajadores firman el cargo.

## Cómo se usa

- En «Versiones del RISST» pulsa «Nueva versión» y sube el texto.
- Pulsa «Enviar a revisión», luego «Aprobar» (con Comité, la aprobación pide la sesión) y «Poner vigente».
- En «Entregas» registra la entrega a cada trabajador y pide su firma.

## Lo que ves en la pantalla

Cuatro tarjetas: Cargos firmados, Entregados sin firma, Pendientes y Trabajadores activos. {LISTA}

## Botones importantes

- Nueva versión
- Enviar a revisión · Aprobar · Poner vigente
- Pedir firma (en la entrega)

## Se conecta con

Comité SST, Trabajadores, Firma electrónica.
TXT,
            'como-usar-comite-o-supervisor-sst' => <<<'TXT'
El Comité de SST (o el Supervisor SST): sus integrantes, la elección electrónica de los representantes de los trabajadores y las reuniones con su acta.

Lo usa: Jefe SST, integrantes del comité y la junta electoral; los trabajadores votan.

## Cómo se usa

- Pestaña «Comité vigente»: integrantes con su cargo, a quién representan y desde cuándo; próxima reunión y acuerdos pendientes.
- Pestaña «Elección electrónica»: pulsa «Convocar elección» y «Publicar convocatoria»; con «Abrir inscripción» se arma el padrón con los trabajadores activos (puedes «Excluir» o «Reincorporar»).
- Inscribe candidatos (o postulan desde la app) y la junta los revisa: «Habilitar» u «Observar».
- Pulsa «Abrir votación» y entrega las «Credenciales de voto». Cada trabajador pulsa «Emitir mi voto» y «Confirmar voto»: es secreto, no se cambia y deja comprobante.
- Pulsa «Cerrar votación y escrutar» y luego «Generar acta».
- Pestaña «Reuniones y actas»: «Programar reunión», registra asistencia («Guardar asistencia y calcular quórum»), marca «Realizada con quórum» o «Realizada sin quórum», agrega acuerdos y pulsa «Firmar acta». «Pedir firma a los asistentes» y «Descargar PDF».

## Ficha de cada registro

Comité: Resumen, Detalle, Integrantes, Firmas e Historial (con «Activar comité»). Elección: Candidatos. Reunión: Acuerdos. {LISTA}

## Botones importantes

- Convocar elección · Abrir votación · Cerrar votación y escrutar · Generar acta
- Programar reunión · Firmar acta · Descargar PDF

## Se conecta con

RISST, Programa anual (lo revisa el comité), Investigaciones (envío al comité), Acciones correctivas (acuerdos).
TXT,
            'como-usar-presupuesto-sst' => <<<'TXT'
El presupuesto de SST por líneas y lo realmente gastado.

Lo usa: Jefe SST y Gerencia.

## Cómo se usa

- En «Presupuestos» pulsa «Nuevo» y agrega las líneas presupuestales en su ficha.
- Pulsa «Aprobar» cuando esté listo.
- En cada línea registra los gastos ejecutados; al terminar el periodo pulsa «Cerrar».

## Ficha de cada presupuesto

Pestañas Resumen, Detalle, Líneas presupuestales (con sus Gastos ejecutados), Firmas e Historial. {LISTA}

## Botones importantes

- Nuevo · Aprobar · Cerrar

## Se conecta con

Plan anual, Revisión por la dirección.
TXT,
            'como-usar-matriz-iperc' => <<<'TXT'
La matriz de identificación de peligros, evaluación de riesgos y controles (IPERC): una por sede, área, proceso o puesto, con sus versiones aprobadas.

Lo usa: Jefe SST y supervisores; se consulta por puesto.

## Cómo se usa

- En «Todas las matrices» pulsa «Nueva matriz» y elige su alcance (puesto, área o sede).
- Abre la matriz y pulsa «Agregar fila»: identificación (proceso, actividad, tarea, peligro, riesgo, consecuencia), evaluación inicial, controles por jerarquía («Agregar control») y riesgo residual.
- Con el complemento Asistente IA pulsa «Sugerir controles» para recibir propuestas.
- Pulsa «Enviar a aprobación»; quien aprueba puede «Observar» con un motivo.
- Descarga la matriz con «Descargar PDF» o «Excel».

## Lo que ves en la pantalla

Pestañas: Todas las matrices, Catálogo de peligros y Criterios de evaluación. Dentro de una matriz: Matriz del puesto, Historial de versiones (compara dos versiones) y Mapa de calor. «Datos de la matriz» muestra los trabajadores expuestos, la versión vigente y la versión en edición.

## Botones importantes

- Nueva matriz · Agregar fila · Agregar control · Eliminar fila
- Sugerir controles
- Enviar a aprobación · Observar
- Descargar PDF · Excel

## Se conecta con

Mapa de riesgos, PETS, ATS, PETAR, EPP por puesto, Grupos vulnerables, Asistente IA.
TXT,
            'como-usar-mapa-de-riesgos' => <<<'TXT'
El plano de cada sede con pictogramas de riesgos, equipos de emergencia y rutas, ligados a las filas del IPERC.

Lo usa: Jefe SST.

## Cómo se usa

- En «Mapas de riesgo» pulsa «Nuevo» y elige la sede.
- En la ficha sube el plano (imagen PNG o JPG) en «Plano» y guarda.
- Pulsa «Poner pictograma», toca el lugar del plano, elige el tipo y, si quieres, la fila IPERC que lo explica.
- Pulsa «Enviar a revisión», «Aprobar» y «Poner vigente» (una versión vigente por sede).
- Pulsa «Imprimir afiche» o «Imprimir mapa» para colocarlo en la sede.

## Lo que ves en la pantalla

Arriba, el mapa con su leyenda y el filtro de sede. Si una fila IPERC vinculada ya no está vigente, el pictograma avisa «Revisar vínculo».

## Botones importantes

- Poner pictograma · Quitar
- Imprimir afiche · Imprimir mapa
- Enviar a revisión · Aprobar · Poner vigente

## Se conecta con

Matriz IPERC, Equipos de emergencia.
TXT,
            'como-usar-monitoreo-de-agentes' => <<<'TXT'
Las mediciones de agentes físicos, químicos, biológicos y ergonómicos (ruido, iluminación, polvo, gases…) comparadas con sus límites.

Lo usa: Jefe SST.

## Cómo se usa

- En «Puntos de monitoreo» registra dónde se mide.
- En «Campañas» registra cada monitoreo (fecha, laboratorio, informe).
- En «Resultados» registra cada medición; el sistema la compara con el límite permisible.
- Si una medición supera el límite, pulsa «Crear acción correctiva» en su ficha.

## Lo que ves en la pantalla

Cuatro tarjetas: Puntos de monitoreo, Sobre el límite, Mediciones y Campañas. La pestaña «Límites permisibles» trae los valores de referencia. {LISTA}

## Botones importantes

- Nuevo · Crear acción correctiva · Exportar

## Se conecta con

Matriz IPERC, Vigilancia médica, Registros obligatorios (registro de monitoreo).
TXT,
            'como-usar-riesgos-ergonomicos-y-psicosociales' => <<<'TXT'
Evaluaciones ergonómicas por puesto y encuestas psicosociales anónimas con resultados agregados.

Lo usa: Jefe SST.

## Cómo se usa

- En «Evaluaciones ergonómicas» registra la evaluación de cada puesto.
- En «Encuestas psicosociales» pulsa «Nuevo» y luego «Abrir encuesta».
- Comparte el enlace o el QR con los trabajadores (también «Enviar por WhatsApp»). Las respuestas son anónimas.
- Pulsa «Cerrar y calcular resultados»: ves los resultados por tema. Si hay temas en riesgo alto, planifica acciones en Acciones correctivas.
- Pulsa «Marcar como informada» cuando comuniques los resultados.

## Botones importantes

- Abrir encuesta · Cerrar y calcular resultados · Marcar como informada · Reabrir
- Enviar por WhatsApp

## Se conecta con

Matriz IPERC, Acciones correctivas, Vigilancia médica.
TXT,
            'como-usar-pets-y-procedimientos' => <<<'TXT'
Los procedimientos escritos de trabajo seguro (PETS), con versiones y marcados como tarea crítica cuando corresponde.

Lo usa: Jefe SST y supervisores.

## Cómo se usa

- Pulsa «Nuevo PETS» y marca si es una tarea crítica.
- En su ficha agrega la versión con los pasos y controles.
- Pulsa «Enviar a revisión», «Aprobar» y «Poner vigente»: queda como la versión actual del procedimiento.

## Lo que ves en la pantalla

Cuatro tarjetas: PETS vigentes, Tareas críticas, Por revisar (30 días) y Archivados. {LISTA}

## Ficha de cada PETS

Pestañas Resumen, Detalle, Versiones, Firmas e Historial.

## Botones importantes

- Nuevo PETS
- Enviar a revisión · Aprobar · Poner vigente

## Se conecta con

Matriz IPERC, ATS, PETAR, Capacitaciones.
TXT,
            'como-usar-gestion-del-cambio' => <<<'TXT'
Complemento. Evalúa los riesgos antes de un cambio (de proceso, equipo, material o personal) para que no se introduzcan peligros sin control.

Lo usa: Jefe SST, gerencia y responsables del cambio.

## Cómo se usa

- Pulsa «Solicitar cambio» y describe qué cambia y por qué.
- Pulsa «Enviar»; el revisor usa «Pasar a revisión» y registra sus revisiones.
- Se decide con «Aprobar» o «Rechazar».
- Al terminar pulsa «Marcar implementado» y luego «Cerrar». Si el cambio exige una acción, «Crear acción correctiva».

## Lo que ves en la pantalla

Cuatro tarjetas: En revisión, Aprobados, Implementados y Rechazados. {LISTA}

## Ficha de cada solicitud

Pestañas Resumen, Detalle, Revisiones, Firmas e Historial.

## Botones importantes

- Solicitar cambio · Enviar · Aprobar · Rechazar · Marcar implementado · Cerrar

## Se conecta con

Matriz IPERC, PETS, Acciones correctivas.
TXT,
            'como-usar-inspecciones' => <<<'TXT'
Inspecciones planeadas con checklist: se ejecutan en el lugar y cada respuesta negativa se convierte en hallazgo con su acción.

Lo usa: Jefe SST y supervisores.

## Cómo se usa

- En «Plantillas» crea el checklist con sus ítems (o usa uno existente).
- En «Inspecciones» pulsa «Nueva inspección»: plantilla, sede, área, fecha y responsable.
- En la ficha pulsa «Ejecutar checklist» y responde cada ítem; «Guardar avance» si la terminas después.
- Los ítems que no cumplen quedan en «Hallazgos»; en cada uno pulsa «Crear acción correctiva».
- Con la inspección terminada, «Ver respuestas».

## Lo que ves en la pantalla

Cuatro tarjetas: Programadas, Ejecutadas, Hallazgos abiertos y Plantillas activas. Pestañas: Inspecciones, Hallazgos y Plantillas. {LISTA}

## Ficha de cada inspección

Pestañas Resumen, Detalle, Firmas e Historial. Una inspección completada ya no se edita.

## Botones importantes

- Nueva inspección
- Ejecutar checklist · Guardar avance · Ver respuestas
- Crear acción correctiva

## Se conecta con

Acciones correctivas, Registros obligatorios (registro de inspecciones), Indicadores.
TXT,
            'como-usar-actos-y-condiciones-subestandar' => <<<'TXT'
Los reportes de actos y condiciones subestándar, casi accidentes e ideas de mejora. Cualquier trabajador puede reportar escaneando un QR, sin usuario.

Lo usa: Jefe SST y supervisores revisan; todos reportan.

## Cómo se usa

- En «Puntos QR» crea los puntos de reporte (por ejemplo, uno por sede o área) e imprime su QR.
- Quien escanea el QR elige el tipo (condición insegura, acto inseguro, casi accidente o idea) y lo envía.
- En la «Bandeja de reportes» revisa cada uno: «Marcar revisado» o «Descartar» con un motivo.
- Si necesita corrección pulsa «Crear acción correctiva»; al terminar, «Cerrar».

## Lo que ves en la pantalla

Cuatro tarjetas: Reportes del mes, Sin revisar, Con acción y Cerrados. {LISTA}

## Botones importantes

- Marcar revisado · Descartar · Cerrar
- Crear acción correctiva

## Se conecta con

Acciones correctivas, Indicadores.
TXT,
            'como-usar-charlas-de-5-minutos' => <<<'TXT'
Las charlas de 5 minutos al inicio del turno, con su tema y la asistencia de los trabajadores.

Lo usa: Supervisores y Jefe SST.

## Cómo se usa

- Pulsa «Registrar charla»: tema, fecha, sede y expositor.
- En su ficha agrega a los asistentes.
- Cuando se dicte, pulsa «Marcar realizada».
- Con el complemento Asistente IA puedes pedir un guion de charla en «Consultas rápidas».

## Lo que ves en la pantalla

Cuatro tarjetas: Charlas del mes, Realizadas, Programadas y Asistentes registrados. {LISTA}

## Ficha de cada charla

Pestañas Resumen, Detalle, Asistencias, Firmas e Historial.

## Botones importantes

- Registrar charla · Marcar realizada

## Se conecta con

Capacitaciones, Indicadores, Asistente IA.
TXT,
            'como-usar-ats' => <<<'TXT'
El análisis de trabajo seguro (ATS): antes de empezar, el equipo revisa los pasos de la tarea, sus peligros y controles, y firma.

Lo usa: Supervisores y trabajadores.

## Cómo se usa

- Pulsa «Nuevo ATS»: tarea, lugar y fecha.
- En la ficha agrega los pasos (con su peligro y control) y a los ejecutores.
- Pulsa «Firmar ATS»; luego «Iniciar trabajo» y, al terminar, «Cerrar ATS».

## Lo que ves en la pantalla

Cuatro tarjetas: En ejecución, Firmados, En borrador y Cerrados. {LISTA}

## Ficha de cada ATS

Pestañas Resumen, Detalle, Pasos, Ejecutores, Firmas e Historial.

## Botones importantes

- Nuevo ATS · Firmar ATS · Iniciar trabajo · Cerrar ATS

## Se conecta con

PETAR (algunos permisos exigen ATS firmado), Matriz IPERC, PETS.
TXT,
            'como-usar-petar-y-permisos-de-alto-riesgo' => <<<'TXT'
El permiso escrito de trabajo de alto riesgo (PETAR): trabajos en altura, en caliente, espacios confinados, excavaciones, energías peligrosas…

Lo usa: Supervisores solicitan; Jefe SST y responsables aprueban.

## Cómo se usa

- Pulsa «Solicitar PETAR»: se abre un asistente por pasos.
- Tipo de trabajo: elige el tipo de permiso (muestra su vigencia máxima y si exige ATS firmado o medición de gases).
- Lugar, tarea y horario.
- Ejecutantes: el primero es el líder; agrega al vigía si aplica. El sistema revisa a cada uno: inducción, EMO apto vigente, SCTR, capacitación y EPP de su puesto.
- Verificaciones previas: responde cada punto en el lugar. Un «No» impide aprobar hasta corregirlo.
- Luego: «Solicitar aprobación», «Aprobar» (o «Rechazar»), «Activar trabajo», «Suspender» con motivo si hace falta y «Cerrar (área segura)».

## Lo que ves en la pantalla

Cuatro tarjetas: Vigentes ahora, Por aprobar, Suspendidos y Cerrados. Pestañas: Permisos y Tipos de permiso (el Jefe SST configura cada tipo, su lista de verificación y su cadena de aprobación). {LISTA}

## Ficha de cada permiso

Pestañas Resumen, Detalle, Verificaciones, Aprobaciones, Firmas e Historial.

## Botones importantes

- Solicitar PETAR
- Solicitar aprobación · Aprobar · Rechazar · Activar trabajo · Suspender · Cerrar (área segura)

## Se conecta con

ATS, Trabajadores, EMO, SCTR, Capacitaciones, EPP.
TXT,
            'como-usar-observacion-de-conducta-segura' => <<<'TXT'
Observaciones planeadas de conducta: se registra qué comportamientos fueron seguros y cuáles se deben mejorar, para reforzar y corregir.

Lo usa: Supervisores.

## Cómo se usa

- En «Observaciones» pulsa «Nuevo»: fecha, área y tarea observada.
- En su ficha marca cada comportamiento como seguro o a mejorar.
- Si hay algo que corregir pulsa «Crear acción correctiva».

## Lo que ves en la pantalla

Cuatro tarjetas: Observaciones, Del mes, Con conductas a mejorar y Revisadas. {LISTA}

## Ficha de cada observación

Pestañas Resumen, Detalle, Comportamientos, Firmas e Historial.

## Botones importantes

- Nuevo · Crear acción correctiva

## Se conecta con

Acciones correctivas, Indicadores.
TXT,
            'como-usar-epp' => <<<'TXT'
El equipo de protección personal: catálogo, lo que exige cada puesto, stock y las entregas a cada trabajador con su firma.

Lo usa: Jefe SST y almacén.

## Cómo se usa

- En «Catálogo de EPP» registra cada equipo con su vida útil.
- En «EPP por puesto» define qué exige cada puesto.
- En «Stock» registra ingresos y salidas.
- En «Entregas» pulsa «Registrar entrega»: trabajador, EPP y cantidad; pide su firma.
- Revisa la tarjeta «Por reponer (30 días)» para planificar el cambio.

## Lo que ves en la pantalla

Cuatro tarjetas: Entregas del mes, Por reponer (30 días), EPP en catálogo y Exigencias por puesto. Pestañas: Entregas, Stock, EPP por puesto y Catálogo de EPP. {LISTA}

## Ficha de cada entrega

Pestañas Resumen, Detalle, Firmas e Historial. Una entrega cerrada queda como registro.

## Botones importantes

- Registrar entrega
- Pedir firma
- Exportar

## Se conecta con

Trabajadores, Matriz IPERC, PETAR (revisa el EPP del puesto), Alertas.
TXT,
            'como-usar-equipos-de-emergencia-y-botiquines' => <<<'TXT'
Extintores, luces de emergencia, camillas, alarmas y botiquines: su ubicación, mantenimiento y controles periódicos.

Lo usa: Jefe SST y brigadas.

## Cómo se usa

- En «Equipos» pulsa «Nuevo» con su tipo, ubicación y próximo mantenimiento.
- En su ficha registra cada control (inspección o recarga).
- En «Botiquines» registra cada botiquín y su contenido con vencimiento.

## Lo que ves en la pantalla

Cuatro tarjetas: Equipos operativos, Por mantener (30 días), Fuera de servicio y Botiquines. {LISTA}

## Ficha de cada registro

Equipo: Resumen, Detalle, Controles de equipos, Firmas e Historial. Botiquín: Contenido de botiquín.

## Botones importantes

- Nuevo · Agregar (control o contenido)

## Se conecta con

Mapa de riesgos, Simulacros, Registros obligatorios (registro de equipos de seguridad o emergencia).
TXT,
            'como-usar-simulacros-y-brigadas' => <<<'TXT'
Los simulacros de emergencia y las brigadas con sus integrantes.

Lo usa: Jefe SST y jefes de brigada.

## Cómo se usa

- En «Simulacros» pulsa «Programar simulacro»: tipo, fecha y sede.
- Agrega a los participantes en su ficha.
- Pulsa «Marcar ejecutado» y luego «Evaluar: satisfactorio» o «Evaluar: por mejorar».
- En «Brigadas» registra cada brigada y sus brigadistas.

## Lo que ves en la pantalla

Cuatro tarjetas: Programados, Ejecutados, Brigadas y Brigadistas. {LISTA}

## Ficha de cada registro

Simulacro: Resumen, Detalle, Participantes, Firmas e Historial. Brigada: Brigadistas.

## Botones importantes

- Programar simulacro · Marcar ejecutado · Evaluar

## Se conecta con

Equipos de emergencia, Capacitaciones, Registros obligatorios.
TXT,
            'como-usar-capacitaciones-e-inducciones' => <<<'TXT'
Capacitaciones, inducciones y entrenamientos: sesiones, asistencia, evaluación y certificados, con la matriz de lo que exige cada cargo.

Lo usa: Jefe SST y Recursos Humanos.

## Cómo se usa

- En «Capacitaciones» pulsa «Programar capacitación»: tema, tipo, horas y expositor.
- En su ficha agrega las sesiones; en cada sesión registra la asistencia y márcala como realizada.
- Registra las evaluaciones y pulsa «Emitir certificados».
- En «Matriz por cargo» define qué capacitaciones exige cada puesto.
- En «Plantilla de certificado» configura el diseño; activa «Verificación pública» para que el QR del certificado funcione.

## Lo que ves en la pantalla

Arriba, la matriz por cargo: horas por trabajador del año, cumplimiento de la matriz, certificados por validar y por vencer. Pestañas: Capacitaciones, Certificados, Plantilla de certificado y Matriz por cargo. {LISTA}

## Ficha de cada capacitación

Pestañas Resumen, Detalle, Sesiones, Asistencias, Evaluaciones, Firmas e Historial. Cada certificado tiene «Imprimir certificado» y sus verificaciones.

## Botones importantes

- Programar capacitación · Emitir certificados · Imprimir certificado

## Se conecta con

Programa de capacitación, Aula virtual, Fotocheck, PETAR, Registros obligatorios.
TXT,
            'como-usar-aula-virtual-sst' => <<<'TXT'
Cursos en línea propios de la empresa: contenidos, evaluaciones, matrícula, notas y tiempo de permanencia real.

Lo usa: Jefe SST y capacitadores; los trabajadores ven «Mis cursos».

## Cómo se usa

- Pulsa «Nuevo curso»: nombre, categoría, horas que acredita, escala de nota, nota mínima, intentos y permanencia mínima. Al guardar se abre el curso.
- Pulsa «Agregar sección» y luego «Agregar elemento»: texto, video, documento, enlace o evaluación.
- Pulsa «Publicar» (o «Volver a borrador»).
- Matricula con «Matricular participantes» o «Matricular por puesto o área».
- El trabajador entra a «Mis cursos», pulsa «Marcar como vista» y «Empezar evaluación» y «Enviar respuestas».

## Lo que ves en la pantalla

Cuatro indicadores: Cursos publicados, Matrículas activas, Aprobados y Horas acreditadas del mes. Botones arriba: Banco de preguntas («Nuevo banco», «Nueva pregunta», «Importar desde Excel»), Recomendaciones («Recomendar curso», «Nueva regla»), Escalas, Por calificar («Calificar»), Capacitadores (permisos y disponibilidad) y Moodle (opcional). Abajo, «Todos los registros».

## Botones importantes

- Nuevo curso · Agregar sección · Publicar
- Matricular participantes · Matricular por puesto o área
- Calificar · Recomendar curso
- Moodle: Probar conexión · Enlazar · Enviar matrículas · Traer notas

## Se conecta con

Capacitaciones e inducciones, Venta de cursos, Matriz por cargo.
TXT,
            'como-usar-fotocheck-sst-con-qr' => <<<'TXT'
El fotocheck SST con QR: solo se emite si el trabajador cumple los requisitos del tipo de fotocheck (por ejemplo inducción, EMO apto o SCTR), y se valida en la portería.

Lo usa: Jefe SST.

## Cómo se usa

- En «Tipos de credencial» crea el tipo y agrega sus requisitos.
- Arriba elige al trabajador y el tipo de fotocheck: el sistema revisa si cumple.
- Pulsa «Emitir fotocheck» e «Imprimir fotocheck».
- Si cambia algo, en la ficha pulsa «Reevaluar requisitos»; para anularlo, «Revocar» con un motivo.

## Lo que ves en la pantalla

Indicadores: Fotochecks vigentes, Por vencer (30 días), No habilitados y Verificaciones este mes, y la vista previa del fotocheck. Pestañas: Fotochecks y Tipos de credencial. {LISTA}

## Ficha de cada fotocheck

Pestañas Resumen, Detalle, Verificaciones del fotocheck, Firmas e Historial; muestra los motivos de bloqueo si no está habilitado.

## Botones importantes

- Emitir fotocheck · Imprimir fotocheck · Reevaluar requisitos · Revocar

## Se conecta con

Trabajadores, EMO, Capacitaciones, SCTR, Ingreso por QR.
TXT,
            'como-usar-emos-y-aptitud' => <<<'TXT'
Los exámenes médicos ocupacionales (EMO) y la aptitud de cada trabajador. Los resultados clínicos van cifrados en la base médica y solo los ve el personal médico; los demás ven la aptitud y las restricciones en términos laborales.

Lo usa: Médico ocupacional; Jefe SST y RR. HH. ven la aptitud.

## Cómo se usa

- En «Órdenes de examen» pulsa «Programar EMO»: trabajador, tipo de examen y fecha.
- Cuando asiste pulsa «Marcar atendido»; si no, «No asistió» y luego «Reprogramar». «Anular» pide un motivo.
- El médico pulsa «Ver datos médicos» (confirma su contraseña) y «Guardar resultado»: exámenes, diagnósticos CIE-10, aptitud, vigencia y restricciones. Los archivos se suben con «Adjuntar».
- Para cambiar un resultado usa «Corregir resultado».

## Lo que ves en la pantalla

Cuatro tarjetas: Aptitudes vigentes, Por vencer (30 días), Exámenes programados y Aptos con restricciones. Pestañas: Órdenes de examen y Estados de aptitud. {LISTA}

## Botones importantes

- Programar EMO · Marcar atendido · Resultados recibidos · No asistió · Reprogramar
- Ver datos médicos · Guardar resultado · Corregir resultado

## Se conecta con

Fotocheck, PETAR, Vigilancia médica, Registros obligatorios. Cada acceso a datos médicos queda en la bitácora.
TXT,
            'como-usar-vigilancia-medica' => <<<'TXT'
Programas de vigilancia de la salud (por ejemplo conservación auditiva) y el seguimiento de cada caso con sus notas clínicas.

Lo usa: Médico ocupacional.

## Cómo se usa

- Pulsa «Entrar a vigilancia médica» y confirma tu contraseña.
- Pulsa «+ Nuevo programa»: código, riesgo vigilado, nombre y protocolo (exámenes y frecuencia).
- Pulsa «+ Abrir caso» para un trabajador, con su riesgo y próximo control.
- En el caso escribe las notas clínicas («Firmar y guardar nota»; para corregir, «Corregir con una adenda»).
- Al terminar, «Cerrar caso».

## Lo que ves en la pantalla

Indicadores: Casos abiertos, Controles vencidos, Riesgo alto y Casos cerrados. Lista de programas y de casos con filtros.

## Botones importantes

- + Nuevo programa · + Abrir caso · Cerrar caso
- Firmar y guardar nota · Corregir con una adenda

## Se conecta con

EMO, Monitoreo de agentes, Riesgos psicosociales.
TXT,
            'como-usar-descansos-medicos-y-ausentismo' => <<<'TXT'
Los descansos médicos y ausencias, con la tasa de ausentismo por área y mes. El detalle médico va cifrado.

Lo usa: Recursos Humanos y médico ocupacional.

## Cómo se usa

- En «Ausencias» pulsa «Nuevo»: trabajador, desde, hasta, origen y si es por el trabajo.
- El médico pulsa «Ver detalle» para registrar el diagnóstico CIE-10, el N.° de CITT, la entidad y el médico emisor («Guardar detalle»), y adjunta el certificado («Ver certificado» para abrirlo).

## Lo que ves en la pantalla

Cuatro tarjetas: Descansos del mes, Activos hoy, Por accidente de trabajo y Cerrados. Arriba, los días perdidos por mes (total y por accidente de trabajo) y la tasa de ausentismo por área y mes. {LISTA}

## Botones importantes

- Nuevo · Ver detalle · Guardar detalle · Ver certificado

## Se conecta con

Accidentes, Estadísticas (días perdidos), Vigilancia médica.
TXT,
            'como-usar-grupos-vulnerables' => <<<'TXT'
Adecuaciones de puesto para trabajadores en condición de vulnerabilidad (gestantes, menores, personas con discapacidad…), cruzadas con el IPERC del puesto. La condición se guarda cifrada en la base médica.

Lo usa: Jefe SST y médico ocupacional.

## Cómo se usa

- Pulsa «Nueva adecuación»: trabajador, tipo de adecuación y el ajuste del puesto en términos laborales (sin mencionar la condición de salud).
- Marca las familias de peligro que restringe: el sistema muestra los peligros del IPERC del puesto afectados.
- Si lo reubicas elige el nuevo puesto; si se mantiene, la justificación es obligatoria cuando hay peligros restringidos.
- Indica la vigencia (al vencer se revisa).
- El personal autorizado usa «Ver condición» o «Registrar condición».

## Lo que ves en la pantalla

Indicadores: Adecuaciones vigentes, Propuestas por revisar, Incompatibles con el IPERC y Vencen en 15 días. {LISTA}

## Botones importantes

- Nueva adecuación · Editar adecuación · Ver condición

## Se conecta con

Matriz IPERC, EMO.
TXT,
            'como-usar-venta-de-cursos-y-pagos' => <<<'TXT'
Venta de cursos del aula virtual a personas o empresas (cupos), con pedidos, cupones y registro del pago.

Lo usa: Administrador de la cuenta o responsable comercial.

## Cómo se usa

- En «Cursos a la venta» pulsa «Poner un curso a la venta» y publícala (estado «Publicada»).
- En la ficha usa «Vender esta oferta»: comprador (una persona o una empresa con cupos) y «Crear pedido».
- Cuando paguen, en el pedido pulsa «Registrar pago»: cómo pagó (Yape, Plin, transferencia, depósito o efectivo), N.° de operación y fecha.
- Crea cupones en «Cupones» y revisa los cupos en «Cupos para empresas».

## Lo que ves en la pantalla

Cuatro tarjetas: Pedidos pagados, Pendientes de pago, Cursos a la venta y Cupos de empresas. Pestañas: Cursos a la venta, Pedidos, Cupos para empresas, Comprobantes, Pasarela, Cupones y Participantes externos. {LISTA}

## Importante

Los pagos en línea con tarjeta y los comprobantes electrónicos válidos ante SUNAT están pendientes de la conexión con el proveedor: hoy el pago se registra a mano.

## Botones importantes

- Poner un curso a la venta · Crear pedido · Registrar pago

## Se conecta con

Aula virtual, Capacitaciones.
TXT,
            'como-usar-accidentes-incidentes-y-enfermedades' => <<<'TXT'
El registro de accidentes, incidentes peligrosos, incidentes y enfermedades ocupacionales, con el plazo de notificación a la autoridad.

Lo usa: Jefe SST y supervisores.

## Cómo se usa

- Pulsa «Registrar evento»: se abre un asistente por pasos.
- Qué pasó: tipo de evento (incidente, incidente peligroso, accidente leve, incapacitante, mortal o enfermedad ocupacional), fecha y hora, sede, lugar exacto, descripción, tarea y acciones inmediatas.
- Persona afectada: trabajador de la empresa, otra persona (visita, tercero) o nadie.
- Lesión y testigos: tipo de lesión, parte del cuerpo, días de descanso estimados, atención médica y «Agregar testigo».
- Notificación: el asistente muestra el plazo estimado; si ya se notificó, registra el N.° de constancia y la fecha. Al guardar, si el tipo lo exige, se abre la investigación.
- Desde la ficha: «Cerrar evento» o «Descartar» con un motivo.

## Lo que ves en la pantalla

Cuatro tarjetas: Eventos del año, Por notificar, En investigación y Cerrados. {LISTA}

## Ficha de cada evento

Pestañas Resumen, Detalle, Personas involucradas, Notificaciones, Firmas e Historial. Muestra el plazo de notificación que queda.

## Botones importantes

- Registrar evento · Registrar notificación · Cerrar evento · Descartar

## Se conecta con

Investigación de causas, Acciones correctivas, Estadísticas, Registros obligatorios.
TXT,
            'como-usar-investigacion-de-causas' => <<<'TXT'
La investigación de cada evento: equipo investigador, método (por ejemplo SCAT), árbol de causas, acciones y firmas del informe.

Lo usa: Jefe SST, supervisor del área y Comité.

## Cómo se usa

- Abre la investigación (se crea al registrar el evento) y agrega al equipo investigador.
- Pulsa «Empezar el análisis». Con el análisis SCAT marca el tipo de contacto, las causas inmediatas, las básicas y las necesidades de control.
- Agrega las causas e indica de qué causa depende cada una; pulsa «Actualizar árbol» para ver el árbol de causas.
- En cada causa pulsa «Crear acción correctiva».
- Pulsa «Enviar al comité», luego «Aprobar investigación», «Acciones en curso» y «Cerrar investigación».
- «Solicitar firmas» al equipo investigador y «Descargar PDF» o «Imprimir / Guardar PDF» del informe.

## Lo que ves en la pantalla

Cuatro tarjetas: Abiertas, Vencidas, Con acciones en curso y Cerradas. {LISTA}

## Ficha de cada investigación

Pestañas Resumen, Detalle, Equipo investigador, Causas, Firmas e Historial.

## Botones importantes

- Empezar el análisis · Enviar al comité · Aprobar investigación · Cerrar investigación
- Actualizar árbol · Crear acción correctiva · Solicitar firmas · Descargar PDF
- Con el Asistente IA: «Investigación guiada»

## Se conecta con

Accidentes, Acciones correctivas, Comité, Firma electrónica.
TXT,
            'como-usar-acciones-correctivas' => <<<'TXT'
Todas las acciones correctivas y preventivas de la empresa, vengan de donde vengan (hallazgos, causas, brechas, reportes, acuerdos, auditorías), con su evidencia y verificación de eficacia.

Lo usa: Jefe SST y responsables de cada acción.

## Cómo se usa

- Pulsa «Nueva acción» o créala desde su origen con «Crear acción correctiva».
- El responsable pulsa «Empezar», sube su evidencia (qué se hizo, foto o documento) y pulsa «Enviar a verificación».
- Quien verifica pulsa «Verificar» y la marca como eficaz o no eficaz (si no fue eficaz, se abre una nueva acción con el mismo origen), o «Devolver: falta completar».
- «Anular» pide un motivo.

## Lo que ves en la pantalla

Cuatro tarjetas: Abiertas, Vencidas, Por verificar y Verificadas. La ficha muestra el proceso: Abierta → En curso → Por verificar → Verificada. {LISTA}

## Ficha de cada acción

Pestañas Resumen, Detalle, Evidencias e Historial.

## Botones importantes

- Nueva acción · Empezar · Enviar a verificación · Verificar · Devolver: falta completar · Anular

## Se conecta con

Inspecciones, Investigaciones, Línea base, Reportes, Comité, Auditorías, Matriz legal, Monitoreo.
TXT,
            'como-usar-auditorias' => <<<'TXT'
Auditorías internas o externas del sistema de gestión SST, con su checklist y hallazgos.

Lo usa: Jefe SST y auditores.

## Cómo se usa

- Pulsa «Programar auditoría»: tipo, alcance, fecha y auditor.
- Pulsa «Iniciar auditoría», responde los ítems auditados y registra los hallazgos.
- En cada hallazgo pulsa «Crear acción correctiva».
- Pulsa «Completar» y luego «Cerrar».

## Lo que ves en la pantalla

Cuatro tarjetas: Programadas, En curso, Completadas y Hallazgos registrados. {LISTA}

## Ficha de cada auditoría

Pestañas Resumen, Detalle, Ítems auditados, Hallazgos, Firmas e Historial.

## Botones importantes

- Programar auditoría · Iniciar auditoría · Completar · Cerrar · Crear acción correctiva

## Se conecta con

Acciones correctivas, Revisión por la dirección, Registros obligatorios (registro de auditorías).
TXT,
            'como-usar-estadisticas-sst' => <<<'TXT'
Las estadísticas de accidentabilidad: horas-hombre, accidentes con baja, días perdidos e índices de frecuencia (IF), gravedad (IG) y accidentabilidad (IA).

Lo usa: Jefe SST y Gerencia.

## Cómo se usa

- En «Horas-hombre» pulsa «Registrar horas-hombre» para cada mes y sede.
- En «Estadísticas mensuales» elige el periodo y pulsa «Calcular mes».
- Cuando todas las sedes tengan sus horas-hombre, en la ficha del mes pulsa «Cerrar mes»: sus cifras ya no cambian.

## Lo que ves en la pantalla

Arriba, el índice de frecuencia mensual y una tabla por mes (trabajadores, horas-hombre, accidentes con baja, días perdidos, IF, IG, IA) con el acumulado del año. Los factores de cálculo se muestran en la pantalla. Un mes aún no calculado se muestra con el cálculo al vuelo. {LISTA}

## Botones importantes

- Registrar horas-hombre · Calcular mes · Cerrar mes

## Se conecta con

Accidentes, Descansos médicos, Registros obligatorios (registro de estadísticas), Indicadores.
TXT,
            'como-usar-revision-por-la-direccion' => <<<'TXT'
La revisión anual del sistema de gestión SST por la alta dirección, con sus decisiones.

Lo usa: Gerencia y Jefe SST.

## Cómo se usa

- En «Revisiones» pulsa «Nuevo» con la fecha y los participantes.
- Pulsa «Iniciar», registra las decisiones (salidas) en la ficha y pulsa «Completar».
- Si una decisión exige una acción, pulsa «Crear acción correctiva». Al final, «Cerrar».

## Ficha de cada revisión

Pestañas Resumen, Detalle, Decisiones, Firmas e Historial. {LISTA}

## Botones importantes

- Nuevo · Iniciar · Completar · Cerrar · Crear acción correctiva

## Se conecta con

Indicadores, Auditorías, Política y objetivos, Presupuesto.
TXT,
            'como-usar-registros-obligatorios' => <<<'TXT'
Los registros obligatorios del sistema de gestión SST, armados con los datos del sistema y listos para descargar.

Lo usa: Jefe SST.

## Cómo se usa

- 1. Elige el registro: accidentes de trabajo; enfermedades ocupacionales; incidentes peligrosos e incidentes; exámenes médicos ocupacionales; monitoreo de agentes; inspecciones internas; estadísticas; equipos de seguridad o emergencia; inducción, capacitación, entrenamiento y simulacros; auditorías.
- 2. Elige el periodo: mes actual, mes anterior, trimestre, año en curso o año anterior.
- Revisa la «Vista previa» con la razón social, domicilio, N.° de trabajadores, periodo y responsable.
- Pulsa «Excel» o «PDF» para descargarlo.
- En «Historial» ves lo exportado antes.

## Botones importantes

- Excel · PDF

## Se conecta con

Accidentes, EMO, Monitoreo, Inspecciones, Estadísticas, Equipos de emergencia, Capacitaciones, Simulacros, Auditorías.
TXT,
            'como-usar-acreditacion-de-contratistas' => <<<'TXT'
Los contratistas y sus trabajadores: requisitos según el nivel de riesgo, documentos con vencimiento y acreditación. Cada contratista tiene su propio portal.

Lo usa: Jefe SST (empresa principal); el contratista usa su portal.

## Cómo se usa

- Pulsa «Invitar contratista»: razón social, correo y nombre del administrador, servicio y nivel de riesgo (define qué requisitos se le exigen). Le llega un correo para crear su contraseña.
- En «Requisitos» defines los documentos exigidos por nivel de riesgo.
- En «Revisión de documentos» (Por revisar, Observados, Aprobados, Todos) abre cada documento: «Aprobar» u «Observar» con el motivo (el contratista lo ve en su portal).
- En la acreditación usa «Acreditar», «Observar» o «Suspender».

## El portal del contratista

El contratista entra con su usuario: «Editar datos» de su empresa, «Subir documento», «Agregar trabajador» o «Cargar trabajadores desde Excel», registra sus pólizas SCTR («Registrar póliza», «Cargar constancia») y pulsa «Solicitar acreditación».

## Lo que ves en la pantalla

Cuatro tarjetas: Contratistas activos, Documentos por revisar, Documentos observados y Trabajadores de terceros. {LISTA}

## Ficha de cada contratista

Pestañas Resumen, Detalle, Trabajadores, Documentos, Acreditaciones, Firmas e Historial.

## Botones importantes

- Invitar contratista · Nuevo contratista
- Aprobar · Observar · Acreditar · Suspender

## Se conecta con

Control de SCTR, Ingreso por QR, Homologación.
TXT,
            'como-usar-control-de-sctr' => <<<'TXT'
El Seguro Complementario de Trabajo de Riesgo (SCTR): pólizas vigentes y la cobertura de cada trabajador, propio o de terceros.

Lo usa: Recursos Humanos, Jefe SST y contratistas.

## Cómo se usa

- En «Pólizas» registra cada póliza (EsSalud, EPS, aseguradora privada u ONP) con su vigencia.
- En «Cobertura por trabajador» registra quién está cubierto y hasta cuándo (o carga la constancia).
- Revisa la tarjeta «Por vencer (30 días)» y la de «Sin cobertura».

## Lo que ves en la pantalla

Cuatro tarjetas: Pólizas vigentes, Trabajadores cubiertos, Por vencer (30 días) y Sin cobertura. {LISTA}

## Botones importantes

- Nuevo · Exportar

## Se conecta con

PETAR (revisa el SCTR de los ejecutantes), Fotocheck, Contratistas, Ingreso por QR.
TXT,
            'como-usar-ingreso-de-terceros-por-qr' => <<<'TXT'
La portería: control de ingreso y salida con el QR del fotocheck o el DNI, visitas con su inducción y quiénes están dentro.

Lo usa: Personal de portería y Jefe SST.

## Cómo se usa

- En «Puntos de acceso» crea cada portería.
- Pulsa «Abrir portería (escanear QR)», elige el punto de acceso y «Escanear QR con la cámara» (o escribe el N.° de fotocheck o el DNI).
- El sistema dice si la persona está habilitada. Si no lo está, puedes «Permitir por excepción» escribiendo quién lo autorizó y por qué (se avisa al Jefe SST).
- Para visitas pulsa «Registrar visitante»: nombre, documento (solo se guardan los últimos 4 dígitos visibles), a quién visita y la inducción de visita aceptada.
- En «Quiénes están dentro» pulsa «Registrar salida».

## Lo que ves en la pantalla

Indicadores: Ingresos hoy, No habilitados hoy, Por excepción hoy y Dentro ahora. Pestañas: Ingresos y salidas, y Puntos de acceso. {LISTA}

## Botones importantes

- Abrir portería (escanear QR) · Escanear QR con la cámara · Permitir por excepción · Registrar visitante · Registrar salida

## Se conecta con

Fotocheck, Contratistas, SCTR, EMO.
TXT,
            'como-usar-homologacion-de-proveedores' => <<<'TXT'
Complemento. Evalúa a tus proveedores con criterios ponderados y calcula su resultado.

Lo usa: Compras y Jefe SST.

## Cómo se usa

- En «Plantillas» crea la plantilla de evaluación y, en su ficha, «Agregar criterio» con su peso; «Guardar criterios».
- En «Proveedores evaluados» pulsa «Nuevo», elige la plantilla y califica cada criterio de 0 (no cumple) a 5 (cumple totalmente).
- Pulsa «Calcular resultado».

## Botones importantes

- Agregar criterio · Guardar criterios · Calcular resultado

## Se conecta con

Contratistas.
TXT,
            'como-usar-modo-inspeccion-sunafil' => <<<'TXT'
Para estar listo ante una inspección de SUNAFIL: autodiagnóstico con puntaje y expediente armado con los datos del sistema, para descargar o compartir con el inspector.

Lo usa: Jefe SST y Gerencia.

## Cómo se usa

- Revisa el «Autodiagnóstico frente a una inspección»: puntaje y las brechas a cerrar primero. «Volver a evaluar» lo recalcula.
- Si llega un requerimiento («¿Llegó un inspector?») pulsa «Generar expediente»: se abre el asistente «Nuevo expediente».
- Completa la orden de inspección (tipo de requerimiento y número), el inspector y la fecha y hora de comparecencia, el periodo, la sede, los trabajadores inspeccionados y las obligaciones que pide.
- Abre el expediente para ver lo que falta, subsanarlo y volver a armarlo antes de la comparecencia.
- Pulsa «Descargar ZIP» o, en «Compartir con el inspector», crea un enlace con días de vigencia, visitas y descargas máximas, y si requiere aprobación de gerencia. Copia el enlace al crearlo: no se vuelve a mostrar.

## Lo que ves en la pantalla

El autodiagnóstico, los expedientes recientes y la pestaña «Expedientes». {LISTA}

## Ficha de cada expediente

Pestañas Resumen, Detalle, Ítems del expediente, Enlaces al inspector (con su registro de accesos), Firmas e Historial.

## Botones importantes

- Generar expediente · Volver a evaluar
- Descargar ZIP · Crear enlace · Aprobar / Rechazar (gerencia) · Anular

## Se conecta con

Lee de todos los módulos (documentos, trabajadores, capacitaciones, EMO, EPP, IPERC, comité…).
TXT,
            'como-usar-asistente-ia' => <<<'TXT'
Complemento. Un asistente que sugiere, nunca decide: filas del IPERC, preguntas y causas para una investigación, controles para un peligro y guiones de charla. Responde con la biblioteca que prepara el equipo de EscudoSST y el catálogo de peligros.

Lo usa: Jefe SST y supervisores.

## Cómo se usa

- Pestaña «IPERC sugerido»: elige el puesto, agrega contexto (tareas, equipos o lugar) y pulsa «Generar sugerencias». En cada fila: «Aceptar», «Editar» o «Descartar». Para aceptar, la matriz del puesto debe tener una versión en borrador.
- Pestaña «Investigación guiada»: elige la investigación, responde las preguntas y pulsa «Proponer causas con mis respuestas»; acepta las que correspondan y pasan al árbol de causas.
- Pestaña «Consultas rápidas»: «Controles para un peligro», «Guía para investigar un accidente» o «Guion de charla de 5 minutos»; escribe y pulsa «Sugerir».
- Pestaña «Historial»: qué se pidió, quién y qué se aceptó o descartó.

## Reglas

No escribas datos de salud ni nombres completos: el asistente no los necesita. Revisa y adapta cada sugerencia antes de usarla.

## Botones importantes

- Generar sugerencias · Aceptar · Editar · Descartar · Sugerir

## Se conecta con

Matriz IPERC, Investigación de causas, Charlas de 5 minutos.
TXT,
            'como-usar-modo-consultora-y-marca-blanca' => <<<'TXT'
Plan Consultora. Un panel para gestionar todas tus empresas cliente desde una sola cuenta, con tu propia marca.

Lo usa: Consultoras SST y sus consultores.

## Cómo se usa

- En el panel multi-cliente ves tus empresas ordenadas por riesgo; toca una fila para entrar a esa empresa. Filtra por plan o por consultor.
- Pulsa «Invitar empresa»: razón social, correo del administrador y plan sugerido (la empresa elige y contrata su plan).
- Pulsa «Configurar marca blanca»: nombre de tu consultora, logo, colores, pie de página en documentos y la opción «Con tecnología EscudoSST»; «Activar la marca para mis clientes» y «Guardar marca».
- En «Consultores» asignas a tu equipo a cada empresa.

## Lo que ves en la pantalla

Indicadores: Empresas gestionadas, Trabajadores en total, Cumplimiento promedio y Alertas abiertas; próximas visitas, alertas críticas por empresa e invitaciones enviadas.

## Botones importantes

- Invitar empresa · Anular (invitación) · Configurar marca blanca · Guardar marca

## Se conecta con

Usuarios (la empresa cliente puede quitar el acceso a la consultora).
TXT,
            'como-usar-paquetes-sectoriales' => <<<'TXT'
Complemento. Contenido listo por sector: peligros, plantillas, permisos y temas de capacitación.

Lo usa: Jefe SST.

## Cómo se usa

- Elige el paquete de tu sector: construcción, minería, agroindustria, manufactura, retail y comercio, oficinas y servicios, o clínicas y salud.
- Pulsa «Aplicar»: el contenido se copia a tu empresa y queda marcado como «Aplicado».
- En «Instalaciones» ves qué paquetes aplicaste.

## Botones importantes

- Aplicar

## Se conecta con

Matriz IPERC, Inspecciones, PETAR, Capacitaciones.
TXT,
            'como-usar-sistema-integrado-iso' => <<<'TXT'
Complemento. Revisa cada cláusula de ISO 45001, 9001 o 14001 contra lo que ya está registrado en EscudoSST y muestra qué está implementado y qué falta.

Lo usa: Jefe SST y responsable de calidad.

## Cómo se usa

- Elige la norma (ISO 45001, ISO 9001 o ISO 14001) y pulsa «Evaluar con mis datos».
- Revisa cada cláusula en «Evidencias»: implementado, en curso o sin evidencia.
- Marca las que no aplican o verifica las que ya revisaste.

## Botones importantes

- Evaluar con mis datos

## Se conecta con

Lee de los módulos (política, IPERC, capacitaciones, auditorías, acciones, documentos…).
TXT,
            'como-usar-medio-ambiente-ssoma' => <<<'TXT'
Complemento. La parte ambiental (SSOMA): aspectos e impactos ambientales con su significancia, y la generación y disposición de residuos.

Lo usa: Jefe SST o responsable ambiental.

## Cómo se usa

- En «Aspectos ambientales» pulsa «Nuevo» con severidad y probabilidad: la significancia se calcula sola.
- En «Residuos» registra lo generado y su disposición.

## Botones importantes

- Nuevo · Exportar

## Se conecta con

Sistema integrado ISO (ISO 14001).
TXT,
            'como-usar-api-e-integraciones' => <<<'TXT'
Para conectar EscudoSST con otros sistemas de la empresa (RR. HH., ERP): una API de solo lectura con clave y secreto, y webhooks que avisan cuando pasa algo.

Lo usa: Administrador de la cuenta (área de sistemas).

## Cómo se usa

- En «Clientes de API» pulsa «Nuevo» y copia su secreto: se muestra una sola vez («Ya lo guardé»).
- Consulta los datos (trabajadores, eventos, acciones, inspecciones, capacitaciones) con esa clave.
- En «Webhooks» crea una dirección https con los eventos que quieres recibir; en su ficha ves las entregas.
- Si un secreto se expone usa «Rotar secreto» (o «Rotar secreto de firma» en el webhook); para quitar el acceso, «Revocar».

## Botones importantes

- Nuevo · Rotar secreto · Rotar secreto de firma · Revocar

## Se conecta con

Sistemas externos.
TXT,
        ];
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Autoayuda dentro de la aplicación (SCR-WEB-34): un artículo publicado por cada módulo, ligado a su pantalla
 * (screen_code SCR-<COD>-01), con para qué sirve, cómo se usa, sus botones importantes y con qué se conecta.
 * Son las «preguntas frecuentes» del sistema para las empresas (distintas de las del sitio web público). El equipo
 * los edita en Panel › Sitio web › Centro de ayuda. También actualiza «Cómo ingresar» al nuevo orden de verificación.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public function up(): void
    {
        // help_* tienen RLS solo para el rol de la plataforma: se escribe con esa conexión.
        $db = DB::connection('platform');
        $cat = $db->selectOne("select id from help_categories where slug = 'uso-de-los-modulos'")?->id
            ?? $db->selectOne("insert into help_categories (name, slug, icon, description, sort_order) values ('Uso de los módulos', 'uso-de-los-modulos', 'book', 'Para qué sirve cada módulo, cómo se usa y sus botones', 30) returning id")->id;
        $rows = [
            ['Cómo usar Empresas, sedes y áreas', 'como-usar-empresas-sedes-y-areas', 'SCR-TEN-01', 10, 'Es la base de todo: aquí se registra cómo está organizada la empresa. Sin sedes, áreas y puestos, los demás módulos no saben a quién aplicar el IPERC, el EPP, los exámenes o las capacitaciones.

Lo usa: Administrador de la cuenta y Jefe SST.

## Cómo se usa

- Registra las sedes (planta, obra, oficina o tienda).
- Dentro de cada sede crea las áreas (en forma de árbol: área › sub-área).
- Crea los puestos de trabajo: son la unidad para IPERC, EPP, exámenes médicos y capacitaciones.
- Define los turnos para calcular las horas-hombre.

## Botones importantes

- Nuevo: agrega una sede, área, puesto o turno.
- No deja desactivar una sede que tiene trabajadores activos.

## Se conecta con

Trabajadores, IPERC, EPP, EMO, Capacitaciones, Estadísticas (horas-hombre).'],
            ['Cómo usar Usuarios, roles y permisos', 'como-usar-usuarios-roles-y-permisos', 'SCR-USR-01', 11, 'Controla quién entra al sistema y qué puede hacer. Cada persona tiene un rol (Jefe SST, Supervisor, Médico, Trabajador, Gerencia, Auditor externo…) y opcionalmente se limita a una sede.

Lo usa: Administrador de la cuenta y Jefe SST.

## Cómo se usa

- En Usuarios, roles y permisos pulsa Invitar: correo, nombre y rol.
- La persona recibe un correo, crea su contraseña y configura su código de verificación (MFA).
- Puedes cambiar el rol o desactivar a alguien cuando deja la empresa.

## Botones importantes

- Invitar usuario
- Reenviar invitación
- Desactivar

## Se conecta con

Todos los módulos (cada botón respeta el rol). Bitácora de auditoría registra los cambios.'],
            ['Cómo usar Trabajadores y legajo', 'como-usar-trabajadores-y-legajo', 'SCR-TRB-01', 12, 'La ficha única de cada trabajador y su legajo SST: datos, puesto, sede, área, historial de cambios de puesto y documentos con vencimiento. Es el «quién» de todo el sistema.

Lo usa: Jefe SST y Recursos Humanos.

## Cómo se usa

- Nuevo trabajador con DNI, nombres y puesto (o carga de Excel).
- Cuando cambia de puesto o sede, registra una asignación nueva: queda el historial.
- Sube sus documentos del legajo (contrato, certificados) con fecha de vencimiento.

## Botones importantes

- Nuevo
- Asignaciones (historial de puesto)
- Legajo (documentos)

## Se conecta con

EPP, EMO, Capacitaciones, Fotocheck, Accidentes, Expediente SUNAFIL por trabajador.'],
            ['Cómo usar Gestión documental', 'como-usar-gestion-documental', 'SCR-DOC-01', 13, 'Guarda los documentos del sistema de gestión (procedimientos, formatos, instructivos) con versiones: solo hay una versión vigente y queda quién la elaboró, revisó y aprobó, y qué trabajadores tomaron conocimiento.

Lo usa: Jefe SST; los trabajadores confirman lectura.

## Cómo se usa

- Crea la categoría y el documento.
- Agrega la versión con su contenido o archivo.
- La envías a revisión, la aprueba otra persona y la pones vigente.
- Los trabajadores registran su toma de conocimiento.

## Botones importantes

- Enviar a revisión
- Aprobar
- Poner vigente
- Devolver a borrador

## Se conecta con

Firma electrónica, Expediente SUNAFIL (documentos adjuntos al ZIP), ISO.'],
            ['Cómo usar Alertas y notificaciones', 'como-usar-alertas-y-notificaciones', 'SCR-NOT-01', 14, 'Avisa a tiempo lo que vence o está atrasado: acciones correctivas, notificaciones de accidentes, revisión del IPERC, inspecciones sin ejecutar, aptitudes médicas, EPP, fotochecks y capacitaciones por vencer. Llega a la campana y en un correo resumen diario.

Lo usa: Todos (cada uno recibe lo que le toca según su rol).

## Cómo se usa

- Cada día a las 7:00 el sistema revisa vencimientos y crea los avisos.
- Cada persona ve sus avisos en la campana y recibe un solo correo con el resumen.
- En Preferencias cada uno elige qué avisos quiere por correo.

## Botones importantes

- Campana (arriba a la derecha)
- Preferencias

## Se conecta con

Casi todos los módulos generan avisos.'],
            ['Cómo usar Dashboard e indicadores', 'como-usar-dashboard-e-indicadores', 'SCR-DSH-01', 15, 'La pantalla de inicio: muestra en un vistazo cómo va la seguridad de la empresa con 15 indicadores con semáforo (verde, ámbar, rojo) y lo que hay que atender hoy.

Lo usa: Jefe SST, Gerencia y supervisores.

## Cómo se usa

- Elige la sede y el año arriba.
- Mira «Para atender hoy» y toca cada alerta para ir a resolverla.
- Toca un indicador para ver los registros de donde sale; el botón ? explica cómo se calcula.
- Si manejas varias empresas, entra al Tablero del grupo.

## Botones importantes

- Filtros Crítico / Atención / En meta
- Ver registros →
- ? (cómo se calcula)
- Tablero del grupo

## Se conecta con

Lee datos de todos los módulos.'],
            ['Cómo usar Firma electrónica de registros', 'como-usar-firma-electronica-de-registros', 'SCR-FIR-01', 16, 'Permite firmar registros dentro del sistema con valor de evidencia: queda quién firmó, cuándo, desde dónde y la huella del documento. Cada usuario guarda su firma (dibujada o foto) y se pega sola en los documentos.

Lo usa: Cualquier usuario que deba firmar; el área SST firma asistida por los trabajadores sin usuario.

## Cómo se usa

- Guarda tu firma en Mi cuenta › Mi firma (dibujo o foto).
- En cualquier registro, sección Firmas, pulsa Pedir firma y elige a la persona y en calidad de qué firma.
- La persona firma con su contraseña; el trabajador sin usuario firma en la pantalla del supervisor.
- Si el registro cambia después de pedir la firma, la firma ya no procede (hay que pedirla de nuevo).

## Botones importantes

- Pedir firma
- Firmar (con contraseña)
- Firma del trabajador (presente)
- Mi firma › Guardar firma

## Se conecta con

Certificados (firma del que valida), actas, documentos, cualquier registro.'],
            ['Cómo usar Bitácora de auditoría', 'como-usar-bitacora-de-auditoria', 'SCR-AUD-01', 17, 'Registro inalterable de quién hizo qué y cuándo dentro de la empresa (crear, cambiar, aprobar, borrar). Sirve para auditorías y para resolver dudas.

Lo usa: Administrador de la cuenta, Jefe SST y auditores.

## Cómo se usa

- Entra a Bitácora de auditoría.
- Filtra por persona, módulo o fecha.
- Los datos cifrados aparecen como «[cifrado]».

## Botones importantes

- Filtros por usuario, módulo y fecha

## Se conecta con

Todos los módulos escriben aquí.'],
            ['Cómo usar Diagnóstico de línea base', 'como-usar-diagnostico-de-linea-base', 'SCR-LBA-01', 18, 'El diagnóstico inicial (línea base) de la R.M. 050-2013-TR: dice qué cumple la empresa hoy y qué le falta. De cada ítem que no cumple sale una brecha para corregir.

Lo usa: Jefe SST.

## Cómo se usa

- Nuevo diagnóstico.
- Pulsa Cargar preguntas base y responde cada ítem (cumple, parcial, no cumple, no aplica).
- Al terminar, se calcula el puntaje y se crean las brechas.
- Apruébalo; desde cada brecha pulsa Crear acción correctiva.

## Botones importantes

- Cargar preguntas base
- Aprobar diagnóstico
- Crear acción correctiva (en cada brecha)

## Se conecta con

Acciones correctivas, Plan anual, Indicador de puntaje de línea base, ISO.'],
            ['Cómo usar Política y objetivos SST', 'como-usar-politica-y-objetivos-sst', 'SCR-POL-01', 19, 'La política de SST de la empresa (versionada, aprobada por la gerencia y difundida) y los objetivos con metas medibles.

Lo usa: Jefe SST; aprueba Gerencia.

## Cómo se usa

- Crea la versión de la política con su texto o archivo.
- Envíala a revisión; la aprueba otra persona (gerencia o comité).
- Ponla vigente: la anterior queda obsoleta.
- Registra los objetivos y sus metas con su valor actual.

## Botones importantes

- Enviar a revisión
- Aprobar
- Poner vigente

## Se conecta con

Expediente SUNAFIL (punto 01), ISO (cláusula 5).'],
            ['Cómo usar Matriz de requisitos legales', 'como-usar-matriz-de-requisitos-legales', 'SCR-MRL-01', 20, 'La lista de normas legales que aplican a la empresa y si se cumplen. También guarda los parámetros legales (plazos, porcentajes) que usa todo el sistema.

Lo usa: Jefe SST.

## Cómo se usa

- Agrega los requisitos legales que aplican.
- Registra la evaluación de cumplimiento de cada uno.
- Si no cumple, Crear acción correctiva.

## Botones importantes

- Nuevo requisito
- Evaluar
- Crear acción correctiva

## Se conecta con

Acciones correctivas, indicador de cumplimiento legal.'],
            ['Cómo usar Plan Anual SST', 'como-usar-plan-anual-sst', 'SCR-PLA-01', 21, 'El Plan Anual de SST: qué se va a hacer en el año, por objetivo, con responsable, fechas y recursos.

Lo usa: Jefe SST; aprueba el comité o la gerencia.

## Cómo se usa

- Crea el plan del año.
- Agrega sus componentes (actividades con responsable y fechas).
- Apruébalo (con la sesión del comité si la empresa tiene comité).

## Botones importantes

- Aprobar
- Poner vigente
- Cerrar

## Se conecta con

Programa anual (PASST), Presupuesto, Revisión por la dirección.'],
            ['Cómo usar Programa Anual SST (PASST)', 'como-usar-programa-anual-sst-passt', 'SCR-PAS-01', 22, 'El Programa Anual de SST (PASST): las actividades del año mes a mes y su avance real.

Lo usa: Jefe SST.

## Cómo se usa

- Crea el programa del año y sus actividades con meta y meses.
- Apruébalo.
- Cada mes registra la ejecución de cada actividad; el avance se calcula solo.

## Botones importantes

- Aprobar programa
- Registrar ejecución

## Se conecta con

Inicio (indicador de avance del PASST), Expediente SUNAFIL, Simulacros e inspecciones (se registran solas si están vinculadas).'],
            ['Cómo usar Programa anual de capacitación', 'como-usar-programa-anual-de-capacitacion', 'SCR-PCA-01', 23, 'El programa anual de capacitación: qué temas se dictarán en el año, a quién y cuándo (la ley pide al menos 4 al año).

Lo usa: Jefe SST.

## Cómo se usa

- Crea el programa y sus temas con mes y público.
- Apruébalo.
- Cada tema se ejecuta creando la capacitación en el módulo Capacitaciones.

## Botones importantes

- Aprobar programa

## Se conecta con

Capacitaciones, indicador de cumplimiento de capacitación.'],
            ['Cómo usar RISST', 'como-usar-risst', 'SCR-RIS-01', 24, 'El Reglamento Interno de SST (obligatorio desde 20 trabajadores) con sus versiones y la entrega firmada a cada trabajador.

Lo usa: Jefe SST.

## Cómo se usa

- Crea la versión del RISST y apruébala (con la sesión del comité si corresponde).
- Ponla vigente.
- Registra la entrega a cada trabajador con su cargo firmado.

## Botones importantes

- Aprobar
- Poner vigente
- Entregas

## Se conecta con

Expediente SUNAFIL (punto 02 y W5 por trabajador).'],
            ['Cómo usar Comité o Supervisor SST', 'como-usar-comite-o-supervisor-sst', 'SCR-COM-01', 25, 'El Comité de SST (o el Supervisor SST en empresas pequeñas): sus integrantes, elecciones, sesiones con acta y acuerdos con seguimiento.

Lo usa: Jefe SST y miembros del comité.

## Cómo se usa

- Registra el comité con sus integrantes (mitad empleador, mitad trabajadores) y actívalo.
- Programa la sesión, márcala realizada y registra el quórum.
- Registra los acuerdos; de cada uno puedes Crear acción correctiva.
- Escribe o adjunta el acta y márcala firmada.

## Botones importantes

- Marcar realizada
- Firmar acta
- Crear acción correctiva (en cada acuerdo)

## Se conecta con

Aprobación del Plan, Programa y RISST (piden la sesión), Acciones correctivas, Expediente SUNAFIL.'],
            ['Cómo usar Presupuesto SST', 'como-usar-presupuesto-sst', 'SCR-PRE-01', 26, 'El presupuesto de SST del año por rubro (EPP, capacitación, exámenes, monitoreos…) y lo realmente gastado.

Lo usa: Jefe SST y Gerencia.

## Cómo se usa

- Crea el presupuesto del año y sus líneas con monto.
- Apruébalo (queda quién lo aprobó y ya no cambian las líneas).
- Registra cada gasto en su línea: se suma solo.

## Botones importantes

- Aprobar
- Registrar gasto

## Se conecta con

Plan anual, Revisión por la dirección.'],
            ['Cómo usar Matriz IPERC', 'como-usar-matriz-iperc', 'SCR-IPC-01', 27, 'La matriz IPERC: identifica los peligros de cada puesto o tarea, evalúa el riesgo antes y después de los controles (método de la R.M. 050-2013-TR) y define los controles con responsable y fecha.

Lo usa: Jefe SST y supervisores; aprueba otra persona (comité o supervisor SST).

## Cómo se usa

- Crea la matriz para un área o puesto.
- Agrega las filas: peligro (del catálogo), riesgo, nivel inicial, controles y nivel residual.
- Envíala a revisión; aprueba otra persona y queda vigente con su versión.
- Cada control propuesto genera su acción correctiva; la matriz avisa cuando toca la revisión anual.

## Botones importantes

- Enviar a revisión
- Aprobar
- Nueva versión
- Exportar

## Se conecta con

Acciones correctivas, Mapa de riesgos, PETS, Asistente IA (sugerencias de controles), Expediente SUNAFIL.'],
            ['Cómo usar Mapa de riesgos', 'como-usar-mapa-de-riesgos', 'SCR-MAP-01', 28, 'El mapa de riesgos de cada sede: un plano con pictogramas de los peligros y equipos de emergencia, con leyenda y versión vigente.

Lo usa: Jefe SST.

## Cómo se usa

- Sube el plano de la sede.
- Coloca los pictogramas sobre el plano (peligros, extintores, rutas).
- Apruébalo y ponlo vigente; se puede imprimir con su leyenda.

## Botones importantes

- Agregar pictograma
- Poner vigente
- Imprimir

## Se conecta con

IPERC (peligros), Equipos de emergencia.'],
            ['Cómo usar Monitoreo de agentes', 'como-usar-monitoreo-de-agentes', 'SCR-MAG-01', 29, 'Registra las mediciones de agentes (ruido, polvo, iluminación, gases…) y las compara con el límite permisible para saber si se excede.

Lo usa: Jefe SST.

## Cómo se usa

- Define los puntos de monitoreo.
- Crea la campaña y registra cada medición con su valor.
- El sistema marca «dentro del límite» o «excede»; si excede, Crear acción correctiva.

## Botones importantes

- Nueva medición
- Crear acción correctiva

## Se conecta con

Acciones correctivas, Registro obligatorio de monitoreo.'],
            ['Cómo usar Riesgos ergonómicos y psicosociales', 'como-usar-riesgos-ergonomicos-y-psicosociales', 'SCR-PSI-01', 30, 'Evalúa los riesgos ergonómicos por puesto y los psicosociales con una encuesta anónima por enlace o QR; muestra resultados por área sin revelar quién respondió.

Lo usa: Jefe SST; los trabajadores responden la encuesta.

## Cómo se usa

- Registra las evaluaciones ergonómicas por puesto.
- Crea la encuesta, ábrela y comparte el enlace o QR.
- Al cerrarla, se calculan los resultados por área (solo si hay respuestas suficientes).

## Botones importantes

- Abrir encuesta
- Enlace y QR
- Cerrar y calcular

## Se conecta con

Acciones correctivas, Inicio.'],
            ['Cómo usar PETS y procedimientos', 'como-usar-pets-y-procedimientos', 'SCR-PET-01', 31, 'Los Procedimientos Escritos de Trabajo Seguro (PETS) para las tareas críticas, con versiones aprobadas.

Lo usa: Jefe SST y supervisores.

## Cómo se usa

- Crea el procedimiento y su versión con contenido o archivo.
- Envíala a revisión, la aprueba otra persona y la pones vigente.

## Botones importantes

- Enviar a revisión
- Aprobar
- Poner vigente

## Se conecta con

IPERC, PETAR, ATS.'],
            ['Cómo usar Gestión del cambio', 'como-usar-gestion-del-cambio', 'SCR-MOC-01', 32, 'Complemento. Controla los cambios que pueden crear nuevos riesgos (nueva máquina, proceso o instalación): se evalúa antes de implementar.

Lo usa: Jefe SST y responsables de área.

## Cómo se usa

- Registra la solicitud de cambio.
- Cada disciplina registra su revisión (aprueba o rechaza).
- Con la aprobación, se implementa y se cierra con su evaluación de riesgos.

## Botones importantes

- Enviar
- Aprobar
- Marcar implementado
- Crear acción correctiva

## Se conecta con

IPERC, Acciones correctivas.'],
            ['Cómo usar Inspecciones', 'como-usar-inspecciones', 'SCR-INS-01', 33, 'Las inspecciones internas con listas de verificación desde la web o el celular. Cada «no cumple» es un hallazgo con foto, responsable y fecha, que termina en acción correctiva.

Lo usa: Jefe SST y supervisores.

## Cómo se usa

- Elige una plantilla (o crea la tuya) y programa la inspección.
- Al ejecutarla, responde cada ítem; los «no cumple» crean hallazgos.
- Desde cada hallazgo Crear acción correctiva; cuando todas se verifican, la inspección se cierra sola.

## Botones importantes

- Ejecutar inspección
- Terminar
- Crear acción correctiva

## Se conecta con

Acciones correctivas, Inicio (inspecciones ejecutadas vs. programadas), Registro obligatorio de inspecciones.'],
            ['Cómo usar Actos y condiciones subestándar', 'como-usar-actos-y-condiciones-subestandar', 'SCR-ACT-01', 34, 'Reportes de actos y condiciones inseguras. Cualquier trabajador reporta desde su celular escaneando un QR (sin instalar nada ni contraseña, puede ser anónimo); el área SST lo revisa y le da acción.

Lo usa: Todos reportan; el Jefe SST revisa.

## Cómo se usa

- Crea los puntos de reporte, imprime su cartel con QR y pégalo en el lugar.
- Los reportes llegan como «Recibido».
- Revísalo con su nivel de riesgo (Marcar revisado) y Crear acción correctiva, o Descartar con motivo.

## Botones importantes

- Imprimir cartel con QR
- Marcar revisado
- Crear acción correctiva
- Descartar
- Cerrar

## Se conecta con

Acciones correctivas, Inicio (reportes sin revisar).'],
            ['Cómo usar Charlas de 5 minutos', 'como-usar-charlas-de-5-minutos', 'SCR-CH5-01', 35, 'Las charlas de 5 minutos antes de iniciar el trabajo, con su asistencia firmada.

Lo usa: Supervisores.

## Cómo se usa

- Programa la charla con su tema.
- Registra la asistencia.
- Márcala realizada.

## Botones importantes

- Marcar realizada

## Se conecta con

Asistente IA (guion de charla), Capacitaciones.'],
            ['Cómo usar ATS', 'como-usar-ats', 'SCR-ATS-01', 36, 'El Análisis de Trabajo Seguro: antes de una tarea se escriben sus pasos, peligros y controles, y cada trabajador firma que los conoce.

Lo usa: Supervisores y trabajadores.

## Cómo se usa

- Crea el ATS con los pasos de la tarea.
- Agrega a los participantes; cada uno firma.
- Pulsa Firmar ATS, luego Iniciar trabajo y al final Cerrar ATS.

## Botones importantes

- Firmar ATS
- Iniciar trabajo
- Cerrar ATS

## Se conecta con

PETAR (el permiso exige un ATS firmado).'],
            ['Cómo usar PETAR y permisos de alto riesgo', 'como-usar-petar-y-permisos-de-alto-riesgo', 'SCR-PTR-01', 37, 'Los permisos de trabajo de alto riesgo (PETAR): altura, caliente, espacio confinado, eléctrico… con su lista de verificación, aprobaciones en cadena y medición de gases.

Lo usa: Supervisores; aprueban los responsables definidos por tipo de permiso.

## Cómo se usa

- Crea el permiso: se generan solas las aprobaciones de su tipo.
- Completa las verificaciones previas (todas en «Sí» o «No aplica»).
- Cada aprobador firma su paso (quien solicita no puede aprobar).
- Actívalo en su horario con el ATS firmado (y gases si corresponde); al terminar, verificaciones de cierre y Cerrar.

## Botones importantes

- Enviar a aprobación
- Aprobar
- Activar
- Suspender
- Cerrar

## Se conecta con

ATS, PETS.'],
            ['Cómo usar Observación de conducta segura', 'como-usar-observacion-de-conducta-segura', 'SCR-OBS-01', 38, 'Observaciones de conducta segura: un observador mira cómo se trabaja y registra comportamientos seguros y de riesgo, para reforzar o corregir.

Lo usa: Supervisores.

## Cómo se usa

- Registra la observación y los comportamientos observados.
- De un comportamiento de riesgo, Crear acción correctiva.

## Botones importantes

- Nueva observación
- Crear acción correctiva

## Se conecta con

Acciones correctivas.'],
            ['Cómo usar EPP', 'como-usar-epp', 'SCR-EPP-01', 39, 'El control de equipos de protección personal: catálogo, qué EPP exige cada puesto, stock por sede y entrega firmada a cada trabajador con su vida útil.

Lo usa: Jefe SST y almacén.

## Cómo se usa

- Crea el catálogo de EPP y lo que exige cada puesto.
- Registra los ingresos al kardex.
- Registra cada entrega al trabajador; descuenta stock y avisa cuando toca reponer.

## Botones importantes

- Nueva entrega
- Movimiento de stock

## Se conecta con

Trabajadores, Alertas (EPP por reponer), Expediente SUNAFIL.'],
            ['Cómo usar Equipos de emergencia y botiquines', 'como-usar-equipos-de-emergencia-y-botiquines', 'SCR-EQE-01', 40, 'Equipos de emergencia (extintores, luces, alarmas) con sus inspecciones y mantenimientos, y los botiquines con su contenido.

Lo usa: Jefe SST y brigadas.

## Cómo se usa

- Registra cada equipo con su ubicación.
- Registra sus inspecciones y mantenimientos.
- Registra los botiquines y su contenido.

## Botones importantes

- Nueva revisión

## Se conecta con

Mapa de riesgos, Registro obligatorio de equipos de emergencia.'],
            ['Cómo usar Simulacros y brigadas', 'como-usar-simulacros-y-brigadas', 'SCR-SIM-01', 41, 'Simulacros (sismo, incendio, derrame…) con participantes, tiempos y evaluación, y las brigadas con sus integrantes.

Lo usa: Jefe SST.

## Cómo se usa

- Planifica el simulacro.
- Márcalo ejecutado y registra participantes.
- Evaluar con resultado; si algo falló, acción correctiva.

## Botones importantes

- Marcar ejecutado
- Evaluar

## Se conecta con

Programa anual (se registra solo), Registro obligatorio de capacitación y simulacros.'],
            ['Cómo usar Capacitaciones e inducciones', 'como-usar-capacitaciones-e-inducciones', 'SCR-CAP-01', 42, 'Capacitaciones e inducciones presenciales: sesiones, asistencia, evaluación y certificados con QR verificables en línea.

Lo usa: Jefe SST y capacitadores.

## Cómo se usa

- Crea la capacitación y su sesión.
- Registra la asistencia y la nota (la nota decide si aprobó).
- Marca la sesión realizada (se cierra la lista).
- Pulsa Emitir certificados; cada uno se imprime con QR y se puede activar su verificación pública.

## Botones importantes

- Marcar realizada
- Emitir certificados
- Imprimir certificado
- Verificación pública

## Se conecta con

Programa de capacitación, Fotocheck (requisitos), Alertas (por vencer), Expediente SUNAFIL.'],
            ['Cómo usar Aula virtual SST', 'como-usar-aula-virtual-sst', 'SCR-AUL-01', 43, 'El aula virtual: cursos con lecciones (texto, video, enlace) y examen. El trabajador avanza a su ritmo y al aprobar recibe su certificado automático.

Lo usa: Jefe SST arma los cursos; los trabajadores los llevan.

## Cómo se usa

- Crea el curso, sus lecciones y un banco de preguntas (marca la alternativa correcta en «Alternativas»).
- Agrega una lección tipo Examen y matricula a los trabajadores.
- El trabajador entra a Mis cursos, ve las lecciones y rinde el examen.
- Al completarlo se emite su certificado con QR.

## Botones importantes

- Mis cursos
- Marcar como vista
- Enviar respuestas
- Ver mi certificado

## Se conecta con

Capacitaciones (certificados), Venta de cursos.'],
            ['Cómo usar Fotocheck SST con QR', 'como-usar-fotocheck-sst-con-qr', 'SCR-FOT-01', 44, 'El fotocheck SST con QR: se habilita solo si el trabajador cumple sus requisitos (aptitud médica, capacitación, SCTR…). En la garita se escanea y dice si puede ingresar.

Lo usa: Jefe SST; vigilancia escanea.

## Cómo se usa

- Crea el tipo de fotocheck y sus requisitos.
- Emite el fotocheck al trabajador: queda «activo» o «bloqueado» con el motivo.
- Imprimir fotocheck con QR.
- Cada día se revisa solo: si vence un requisito, se bloquea.

## Botones importantes

- Imprimir fotocheck con QR
- Reevaluar requisitos
- Revocar

## Se conecta con

EMO, Capacitaciones, SCTR, Ingreso por QR.'],
            ['Cómo usar EMOs y aptitud', 'como-usar-emos-y-aptitud', 'SCR-EMO-01', 45, 'Los exámenes médicos ocupacionales: órdenes (ingreso, periódico, retiro) y la aptitud del trabajador. El Jefe SST solo ve «apto / apto con restricciones / no apto», nunca diagnósticos.

Lo usa: Personal médico registra; el Jefe SST consulta.

## Cómo se usa

- Crea la orden de examen (también se crean solas cuando la aptitud está por vencer).
- Marcar atendido el día del examen y luego Resultados recibidos.
- El médico registra la aptitud con vigencia y restricciones.

## Botones importantes

- Marcar atendido
- No asistió
- Resultados recibidos
- Anular

## Se conecta con

Fotocheck, Vigilancia médica, Alertas, Expediente SUNAFIL.'],
            ['Cómo usar Vigilancia médica', 'como-usar-vigilancia-medica', 'SCR-VMD-01', 46, 'La vigilancia médica en la base médica separada: programas por riesgo (ruido, ergonómico…) y casos con controles periódicos. Pide confirmar la contraseña y registra cada acceso.

Lo usa: Médico ocupacional y personal de salud.

## Cómo se usa

- Confirma tu contraseña.
- Crea el programa de vigilancia.
- Registra los casos y sus controles.

## Botones importantes

- Confirmar contraseña
- Nuevo programa
- Nuevo caso

## Se conecta con

EMO.'],
            ['Cómo usar Descansos médicos y ausentismo', 'como-usar-descansos-medicos-y-ausentismo', 'SCR-DES-01', 47, 'Los descansos médicos y el ausentismo, para saber cuántos días se pierden y por qué (sin datos clínicos en la base principal).

Lo usa: Personal de salud y RR. HH.

## Cómo se usa

- Registra la ausencia con fechas y tipo (si es por accidente o enfermedad ocupacional, se marca así).

## Botones importantes

- Nueva ausencia

## Se conecta con

Accidentes, Estadísticas.'],
            ['Cómo usar Grupos vulnerables', 'como-usar-grupos-vulnerables', 'SCR-VUL-01', 48, 'Adecuaciones de trabajo para grupos vulnerables (gestantes, menores, personas con discapacidad) sin exponer datos de salud.

Lo usa: Personal de salud y Jefe SST.

## Cómo se usa

- Registra la adecuación del puesto o tarea.

## Botones importantes

- Nueva adecuación

## Se conecta con

Puestos, EMO.'],
            ['Cómo usar Venta de cursos y pagos', 'como-usar-venta-de-cursos-y-pagos', 'SCR-VTA-01', 49, 'Vender los cursos del aula virtual a personas o a empresas (cupos). El pago se registra a mano con su número de operación; si se conecta una pasarela, se confirmaría solo.

Lo usa: Jefe SST o administración de un instituto o consultora.

## Cómo se usa

- Crea la oferta del curso con precio y publícala.
- En la oferta, Vender a una persona o empresa: se crea el pedido.
- Registrar pago (Yape, Plin, transferencia, depósito o efectivo con su número de operación).
- La persona queda matriculada o la empresa recibe su licencia con cupos.

## Botones importantes

- Crear pedido
- Registrar pago

## Se conecta con

Aula virtual, Proveedores y claves (pasarela).'],
            ['Cómo usar Accidentes, incidentes y enfermedades', 'como-usar-accidentes-incidentes-y-enfermedades', 'SCR-ACC-01', 50, 'Accidentes, incidentes y enfermedades ocupacionales: el sistema calcula el plazo para notificar (24 h si es mortal), abre la investigación cuando corresponde y suma los días perdidos.

Lo usa: Jefe SST.

## Cómo se usa

- Registra el evento (tipo, fecha, lugar, descripción).
- Agrega las personas afectadas con sus días perdidos.
- Registra la notificación con su número o constancia.
- Ciérralo cuando la investigación esté cerrada.

## Botones importantes

- Registrar notificación
- Cerrar evento
- Descartar

## Se conecta con

Investigación, Estadísticas, Registros obligatorios, Alertas (notificación por vencer).'],
            ['Cómo usar Investigación de causas', 'como-usar-investigacion-de-causas', 'SCR-INV-01', 51, 'La investigación de causas de accidentes e incidentes: equipo, causas inmediatas y básicas, conclusiones y acciones.

Lo usa: Jefe SST, comité y supervisores.

## Cómo se usa

- Arma el equipo con su líder.
- Registra las causas (inmediatas y básicas).
- Escribe las conclusiones y pásala al comité; Aprobar.
- Desde cada causa Crear acción correctiva; se cierra cuando todas se verifican.

## Botones importantes

- Empezar el análisis
- Enviar al comité
- Aprobar investigación
- Cerrar investigación
- Crear acción correctiva

## Se conecta con

Accidentes, Acciones correctivas, Asistente IA (guía de causas).'],
            ['Cómo usar Acciones correctivas', 'como-usar-acciones-correctivas', 'SCR-ACR-01', 52, 'El centro de la mejora continua: todas las acciones correctivas de cualquier origen (inspección, accidente, auditoría, reporte, comité…) con responsable, fecha, evidencia y verificación de eficacia.

Lo usa: Responsables de las acciones; verifica el área SST.

## Cómo se usa

- La acción nace desde su origen con Crear acción correctiva (o con Nuevo).
- El responsable sube la evidencia y la envía a verificar.
- Otra persona verifica: eficaz (se cierra el origen) o no eficaz (se abre otra acción).
- La barra de trazabilidad muestra de dónde viene y qué cerró.

## Botones importantes

- Agregar evidencia
- Enviar a verificación
- Verificar: eficaz / no eficaz
- Anular

## Se conecta con

Casi todos los módulos; Inicio (acciones vencidas).'],
            ['Cómo usar Auditorías', 'como-usar-auditorias', 'SCR-AUI-01', 53, 'Auditorías internas y externas del sistema de gestión, con sus hallazgos y no conformidades, que se resuelven con acciones correctivas.

Lo usa: Auditores y Jefe SST.

## Cómo se usa

- Crea la auditoría y Iniciar auditoría.
- Registra los hallazgos; de cada no conformidad, Crear acción correctiva.
- Completar con conclusiones (la obligatoria pide el registro MTPE del auditor).
- Cerrar cuando las no conformidades tengan su acción verificada.

## Botones importantes

- Iniciar auditoría
- Completar
- Cerrar
- Crear acción correctiva

## Se conecta con

Acciones correctivas, Registro obligatorio de auditorías, Revisión por la dirección.'],
            ['Cómo usar Estadísticas SST', 'como-usar-estadisticas-sst', 'SCR-EST-01', 54, 'Las estadísticas de SST: horas-hombre, índices de frecuencia, gravedad y accidentabilidad por mes y sede.

Lo usa: Jefe SST.

## Cómo se usa

- Registra las horas-hombre del mes por sede.
- Pulsa Cerrar mes: se calculan los índices y quedan fijos.

## Botones importantes

- Calcular / Cerrar mes

## Se conecta con

Accidentes, Registros obligatorios, Revisión por la dirección.'],
            ['Cómo usar Revisión por la dirección', 'como-usar-revision-por-la-direccion', 'SCR-RVD-01', 55, 'La revisión por la dirección: la alta dirección revisa el desempeño del año (carga sola las estadísticas, auditorías, acciones y eventos) y toma decisiones.

Lo usa: Gerencia y Jefe SST.

## Cómo se usa

- Iniciar: se cargan solas las entradas del año.
- Registra participantes, conclusiones y decisiones (cada una puede generar su acción).
- Completar y Cerrar cuando las decisiones estén cumplidas.

## Botones importantes

- Iniciar
- Completar
- Cerrar

## Se conecta con

Estadísticas, Auditorías, Acciones correctivas.'],
            ['Cómo usar Registros obligatorios', 'como-usar-registros-obligatorios', 'SCR-REG-01', 56, 'Los 10 registros obligatorios de la R.M. 050-2013-TR en Excel, armados solos con los datos del sistema (accidentes, enfermedades, incidentes, EMO, monitoreo, inspecciones, estadísticas, equipos, capacitaciones, auditorías).

Lo usa: Jefe SST.

## Cómo se usa

- Elige el registro y el periodo.
- Pulsa Descargar Excel: sale con empresa, RUC, periodo y todas las filas en español.

## Botones importantes

- Descargar Excel

## Se conecta con

Lee de todos los módulos operativos.'],
            ['Cómo usar Acreditación de contratistas', 'como-usar-acreditacion-de-contratistas', 'SCR-CTR-01', 57, 'La acreditación de contratistas: empresa, trabajadores, documentos exigidos (SCTR, seguros, certificados) y su aprobación antes de que ingresen.

Lo usa: Jefe SST.

## Cómo se usa

- Registra la empresa contratista y sus trabajadores.
- Define los requisitos y carga los documentos.
- Revisa, observa y acredita (lo aprueba otra persona si tú observaste).

## Botones importantes

- Enviar
- Observar
- Acreditar
- Suspender

## Se conecta con

SCTR, Fotocheck, Ingreso por QR.'],
            ['Cómo usar Control de SCTR', 'como-usar-control-de-sctr', 'SCR-SCT-01', 58, 'El control del SCTR (salud y pensión) de trabajadores propios y de contratistas, con sus vigencias. Sin los dos vigentes, el fotocheck se bloquea.

Lo usa: Jefe SST y RR. HH.

## Cómo se usa

- Registra las pólizas (salud y pensión).
- Registra la cobertura de cada trabajador por periodo.

## Botones importantes

- Nueva póliza
- Nueva cobertura

## Se conecta con

Fotocheck, Ingreso por QR, Contratistas.'],
            ['Cómo usar Ingreso de terceros por QR', 'como-usar-ingreso-de-terceros-por-qr', 'SCR-ING-01', 59, 'El control de ingreso en la garita: se evalúa al momento si la persona puede entrar (fotocheck, SCTR, contratista acreditado) y queda el registro inalterable.

Lo usa: Vigilancia.

## Cómo se usa

- Define los puntos de acceso.
- Registra cada ingreso: el sistema dice Permitido o Negado con el motivo.
- Se puede autorizar por excepción indicando el motivo.

## Botones importantes

- Registrar ingreso
- Autorizar por excepción

## Se conecta con

Fotocheck, SCTR, Contratistas.'],
            ['Cómo usar Homologación de proveedores', 'como-usar-homologacion-de-proveedores', 'SCR-HOM-01', 60, 'Complemento. Evalúa y homologa proveedores con criterios ponderados (SST, calidad, plazos…) y un puntaje mínimo para aprobarlos.

Lo usa: Jefe SST y compras.

## Cómo se usa

- Crea la plantilla con sus criterios y pesos (suman 100).
- Crea la evaluación del proveedor y califica cada criterio de 0 a 5.
- Pulsa Calcular resultado: puntaje de 0 a 100 y aprobado o no.

## Botones importantes

- Guardar criterios
- Calcular resultado

## Se conecta con

Contratistas.'],
            ['Cómo usar Modo Inspección SUNAFIL', 'como-usar-modo-inspeccion-sunafil', 'SCR-SUN-01', 61, 'El modo inspección SUNAFIL: arma el expediente con lo que la empresa tiene listo, muestra lo que falta para corregirlo antes de la inspección y se comparte con el inspector por un enlace que vence.

Lo usa: Jefe SST.

## Cómo se usa

- Crea el expediente con el periodo (y el trabajador inspeccionado, si lo hay).
- Pulsa Armar expediente: cada punto queda incluido, pendiente, vencido o no aplica; toca uno para resolverlo.
- Descargar ZIP con índice y documentos.
- Compartir con el inspector: enlace con días y visitas máximas, que se puede anular.

## Botones importantes

- Armar expediente
- Descargar ZIP
- Crear enlace
- Anular enlace

## Se conecta con

Lee de casi todos los módulos.'],
            ['Cómo usar Asistente IA', 'como-usar-asistente-ia', 'SCR-IAA-01', 62, 'Complemento. Un asistente que sugiere controles para un peligro, guía la investigación de un accidente y arma el guion de una charla. Lo entrena el equipo de EscudoSST (sin IA de pago).

Lo usa: Jefe SST y supervisores.

## Cómo se usa

- Elige qué necesitas (controles, investigación o charla).
- Escribe el peligro, el evento o el tema y pulsa Sugerir.
- Revisa las sugerencias y úsalas en tu IPERC, investigación o charla.
- Si no sabe algo, el equipo lo ve en Panel › Asistente IA y le enseña.

## Botones importantes

- Sugerir

## Se conecta con

IPERC, Investigación, Charlas.'],
            ['Cómo usar Modo consultora y marca blanca', 'como-usar-modo-consultora-y-marca-blanca', 'SCR-CON-01', 63, 'Para consultoras SST: manejan varias empresas cliente y pueden poner su propia marca (nombre, logo y colores) para que sus clientes vean el sistema con la marca de la consultora.

Lo usa: Administrador de la cuenta de la consultora y sus consultores.

## Cómo se usa

- Asigna consultores a cada empresa cliente.
- En Marca blanca, sube logo, elige colores, mira la vista previa y Activar.
- Usa el Tablero del grupo para ver todas las empresas lado a lado.

## Botones importantes

- Guardar marca
- Activar la marca para mis clientes

## Se conecta con

Todas las empresas cliente de la consultora.'],
            ['Cómo usar Paquetes sectoriales', 'como-usar-paquetes-sectoriales', 'SCR-PSE-01', 64, 'Complemento. Paquetes por sector (construcción, minería, agro, manufactura, retail, oficinas, clínicas) que cargan de una vez las capacitaciones típicas, plantillas de inspección, peligros y tipos de permiso.

Lo usa: Jefe SST.

## Cómo se usa

- Elige el paquete de tu sector y pulsa Aplicar (una sola vez).

## Botones importantes

- Aplicar

## Se conecta con

Capacitaciones, Inspecciones, IPERC, PETAR.'],
            ['Cómo usar Sistema integrado ISO', 'como-usar-sistema-integrado-iso', 'SCR-ISO-01', 65, 'Complemento. Revisa cada cláusula de ISO 45001, 9001 o 14001 con lo que ya está registrado en EscudoSST y muestra qué está implementado y qué falta.

Lo usa: Jefe SST y responsable de calidad.

## Cómo se usa

- Elige la norma y pulsa Evaluar con mis datos.
- Revisa cada cláusula: implementado, en curso o sin evidencia.
- Marca las que no aplican o verifica las que ya revisaste.

## Botones importantes

- Evaluar con mis datos

## Se conecta con

Lee de los módulos (política, IPERC, capacitaciones, auditorías, acciones…).'],
            ['Cómo usar Medio ambiente (SSOMA)', 'como-usar-medio-ambiente-ssoma', 'SCR-AMB-01', 66, 'Complemento. La parte ambiental (SSOMA): aspectos e impactos ambientales con su significancia y la generación y disposición de residuos.

Lo usa: Jefe SST o responsable ambiental.

## Cómo se usa

- Registra los aspectos ambientales con severidad y probabilidad (la significancia se calcula sola).
- Registra los residuos generados y su disposición.

## Botones importantes

- Nuevo aspecto
- Nuevo registro de residuos

## Se conecta con

ISO 14001.'],
            ['Cómo usar API e integraciones', 'como-usar-api-e-integraciones', 'SCR-API-01', 67, 'Para conectar EscudoSST con otros sistemas de la empresa (RR. HH., ERP): una API de solo lectura con clave y secreto, y webhooks que avisan cuando pasa algo.

Lo usa: Administrador de la cuenta (área de sistemas).

## Cómo se usa

- Crea un cliente y copia su secreto (se muestra una sola vez).
- Consulta con las cabeceras indicadas (trabajadores, eventos, acciones, inspecciones, capacitaciones).
- Crea un webhook https con los eventos que quieres recibir.

## Botones importantes

- Ver secreto
- Revocar
- Ver secreto de firma

## Se conecta con

Sistemas externos.'],
        ];
        foreach ($rows as [$title, $slug, $screen, $order, $body]) {
            if ($db->selectOne('select 1 as ok from help_articles where slug = ? and deleted_at is null', [$slug])) {
                continue;
            }
            $db->insert("insert into help_articles (category_id, title, slug, body, screen_code, status, published_at, sort_order) values (?, ?, ?, ?, ?, 'published', now(), ?)",
                [$cat, $title, $slug, $body, $screen, $order]);
        }
        $db->update("update help_articles set body = ?, updated_at = now() where slug = 'como-ingresar-a-escudosst'", [<<<'TXT'
Para entrar necesitas tu correo, tu contraseña y la verificación en dos pasos.

## Pasos

- Abre la página de inicio de sesión y escribe tu correo y contraseña.
- Escribe el código de 6 dígitos de tu app de autenticación (Google o Microsoft Authenticator).
- Si no tienes la app a la mano, elige «Código por correo»: te llega un código a tu correo.
- Si no puedes con ninguno, usa uno de tus códigos de recuperación (cada uno sirve una sola vez).
- Si trabajas con varias empresas, elige con cuál quieres entrar.

## Si no te llega el código por correo

Revisa la carpeta de correo no deseado y espera un minuto antes de pedir otro.
TXT]);
    }

    public function down(): void
    {
        DB::connection('platform')->delete("delete from help_articles where slug like 'como-usar-%'");
    }
};

# 11 · Normativa peruana de SST

> **Aviso:** este documento orienta el diseño. No es asesoría legal. Todo valor de la §4 es un **parámetro con vigencia** (`legal_parameters`: `key`, `value`, `unit`, `valid_from`, `valid_to`, `source`, `to_confirm`) y se carga con `to_confirm = true` hasta que un especialista SST/legal lo confirme. Nunca se escribe como constante en el código.

## 1. Normas principales

| Norma | Contenido | Módulos |
|---|---|---|
| Ley 29783, Ley de SST | Principios, SG-SST, obligaciones del empleador, comité, registros, derechos del trabajador | Todos |
| Ley 30222 | Modifica la Ley 29783 (EMO, capacitaciones, sanciones) | EMO, CAP, PCA |
| D.S. 005-2012-TR, Reglamento de la Ley 29783 (y modificatorias, entre ellas D.S. 006-2014-TR, D.S. 016-2016-TR, D.S. 020-2019-TR, D.S. 001-2021-TR) | Detalle operativo: comité y elecciones, registros (art. 33), IPERC, notificaciones, EMO | Todos |
| R.M. 050-2013-TR | Formatos referenciales de los registros obligatorios y guía de línea base | REG, LBA |
| D.S. 014-2013-TR | Registro de auditores y auditorías del SG-SST | AUI |
| R.M. 375-2008-TR | Norma básica de ergonomía y evaluación de riesgo disergonómico | PSI |
| D.S. 015-2005-SA | Valores límite permisibles de agentes químicos | MAG |
| Ley 26790 y D.S. 003-98-SA | Seguro Complementario de Trabajo de Riesgo (SCTR) | SCT, CTR |
| R.M. 312-2011-MINSA | Protocolos de exámenes médico ocupacionales | EMO, VMD |
| Ley 28806 y D.S. 019-2006-TR (y modificatorias) | Inspección del trabajo, escala de multas y reducción por subsanación | SUN, WEB (calculadora de multas) |
| Ley 29733 y D.S. 016-2024-JUS | Protección de datos personales | EMO, VMD, DES, VUL, AUD, WEB (leads, boletín, subencargados; ver `10`) |
| Ley 28048 | Protección de la mujer gestante en trabajos de riesgo | VUL |
| Ley 29973 | Personas con discapacidad (ajustes razonables) | VUL |
| Código de los Niños y Adolescentes y lista de trabajos peligrosos (D.S. 003-2010-MIMDES) | Trabajo adolescente | VUL |
| Código de Protección y Defensa del Consumidor (Ley 29571), modificado por la Ley 31435 | Libro de Reclamaciones, información al consumidor, publicidad | WEB (Libro de Reclamaciones de EscudoSST); VTA (empresas que venden cursos, ver `13` DA-27) |
| Reglamento del Libro de Reclamaciones, D.S. 011-2011-PCM y modificatorias (entre ellas D.S. 101-2022-PCM) | Contenido de la hoja, constancia, plazo de respuesta y prórroga, conservación, aviso | WEB |

### Sectoriales (paquetes PSE)

| Sector | Norma de referencia | Contenido del paquete |
|---|---|---|
| Construcción | D.S. 011-2019-TR (Reglamento SST en construcción) y Norma G.050 | IPERC de obra, PETAR, ATS, charlas, plan de SST de obra |
| Minería | D.S. 024-2016-EM y modificatoria D.S. 023-2017-EM | PETAR, formatos mineros, índices, capacitación anexos, notificaciones a la autoridad minera |
| Electricidad | R.M. 111-2013-MEM/DM (RESESATE) | Trabajo eléctrico, permisos, EPP dieléctrico |
| Hidrocarburos | D.S. 043-2007-EM | Permisos de trabajo, espacios confinados |
| Agroindustria | Ley 29783 + normas de agroquímicos | Exposición a plaguicidas, campañas, cuadrillas |
| Manufactura | Ley 29783 | Máquinas, ruido, guardas, bloqueo y etiquetado |
| Retail y oficinas | Ley 29783 + R.M. 375-2008-TR | Ergonomía, carga manual, atención al público |
| Clínicas | Ley 29783 + normas de bioseguridad | Riesgo biológico, punzocortantes, vacunación |

## 2. Mapa normativa → módulo

| Obligación | Fuente principal | Módulo(s) |
|---|---|---|
| Diagnóstico de línea base | Reglamento, R.M. 050-2013-TR | LBA |
| Política SST firmada y difundida | Ley art. 22-23 | POL, DOC, FIR |
| Objetivos y plan anual | Ley y reglamento | POL, PLA |
| Programa anual SST | Reglamento | PAS |
| Programa anual de capacitación (aprobado por el comité) | Reglamento | PCA, CAP |
| Capacitaciones mínimas por año en horario de trabajo | Ley art. 35, reglamento art. 27-29 | CAP, PCA |
| Requisitos legales aplicables | Ley (principio de cumplimiento), ISO 45001 | MRL |
| IPERC con participación de trabajadores | Ley art. 57, reglamento art. 77 | IPC |
| Mapa de riesgos | Ley art. 35, reglamento art. 32 | MAP |
| Monitoreo de agentes | Reglamento art. 33 | MAG |
| Ergonomía y psicosociales | R.M. 375-2008-TR, reglamento | PSI |
| Procedimientos de trabajo | Reglamento | PET |
| Comité SST (20+) o Supervisor SST (<20) | Ley art. 29-30, reglamento art. 38-73 | COM |
| Elección de representantes de trabajadores | Reglamento art. 47-50 | COM |
| RISST (20+ trabajadores) | Ley art. 34, reglamento art. 74-75 | RIS, DOC |
| Entrega de RISST a cada trabajador | Reglamento art. 75 | RIS, FIR |
| Recomendaciones SST al trabajador (descripción de riesgos del puesto) | Ley art. 35 | DOC, IPC, CAP |
| EPP adecuado | Ley art. 60-61 | EPP |
| Inspecciones internas | Reglamento art. 33 | INS |
| Equipos de seguridad o emergencia | Reglamento art. 33 | EQE |
| Simulacros y brigadas | Reglamento art. 33, normas de defensa civil | SIM |
| Exámenes médicos ocupacionales | Ley art. 49 (mod. Ley 30222), reglamento art. 101-102 | EMO |
| Vigilancia de la salud | Ley art. 49, R.M. 312-2011-MINSA | VMD |
| Confidencialidad de la información médica | Ley art. 71, Ley 29733 | EMO, VMD, DES, VUL |
| Protección de gestantes, adolescentes, discapacidad | Ley 28048, Código de Niños y Adolescentes, Ley 29973, Ley 29783 art. 64-67 | VUL |
| Notificación de accidentes, incidentes peligrosos y enfermedades | Ley art. 82, reglamento art. 110-111 | ACC |
| Investigación de accidentes e incidentes | Ley art. 58, reglamento art. 88-89 | INV |
| Acciones correctivas | Ley y reglamento | ACR |
| Auditorías del SG-SST | Ley art. 43, D.S. 014-2013-TR | AUI |
| Estadísticas | Reglamento art. 33 | EST |
| Revisión por la dirección | Reglamento art. 87 | RVD |
| 9 registros obligatorios | Reglamento art. 33-35, R.M. 050-2013-TR | REG |
| Coordinación con contratistas | Ley art. 68, reglamento art. 77 | CTR, ING, HOM |
| SCTR en actividades de riesgo | Ley 26790, D.S. 003-98-SA | SCT, CTR |
| Gestión del cambio | Ley art. 57 (evaluación ante cambios), ISO 45001 | MOC |
| Preparación para inspección | Ley 28806, D.S. 019-2006-TR | SUN |

## 3. Los 9 registros obligatorios (módulo REG)

| # | Registro | Fuente de datos |
|---|---|---|
| 1 | Accidentes de trabajo, incidentes peligrosos e incidentes | ACC, INV, ACR |
| 2 | Enfermedades ocupacionales | ACC, EMO (sin diagnóstico en la base principal; ver `10`) |
| 3 | Exámenes médicos ocupacionales | EMO (solo datos no sensibles: trabajador, tipo, fecha, aptitud) |
| 4 | Monitoreo de agentes físicos, químicos, biológicos, psicosociales y disergonómicos | MAG, PSI |
| 5 | Inspecciones internas de SST | INS, ACR |
| 6 | Estadísticas de seguridad y salud | EST |
| 7 | Equipos de seguridad o emergencia | EPP, EQE |
| 8 | Inducción, capacitación, entrenamiento y simulacros de emergencia | CAP, SIM, CH5 |
| 9 | Auditorías | AUI |

Nota: el art. 33 agrupa accidentes, enfermedades e incidentes en un solo literal; los formatos de la R.M. 050-2013-TR los separan. El producto los presenta como 9 registros. La forma final de agruparlos es el parámetro P-01.

Cada exportación: encabezado del empleador (razón social, RUC, domicilio, actividad económica, número de trabajadores), periodo, firma del responsable y, si aplica, del comité.

## 4. Parámetros legales a confirmar por un especialista

Todos con `to_confirm = true` hasta su validación. «Valor propuesto» es el punto de partida para desarrollo y pruebas, no un dato confirmado.

| # | Clave | Parámetro | Valor propuesto | Fuente a revisar | Módulo |
|---|---|---|---|---|---|
| P-01 | `registers.layout` | Número y separación de los registros obligatorios | 9 registros según §3 (accidentes e incidentes; enfermedades; EMO; monitoreo; inspecciones; estadísticas; equipos; capacitación y simulacros; auditorías) | Reglamento art. 33; R.M. 050-2013-TR | REG |
| P-02 | `committee.min_workers` | Número de trabajadores desde el cual se exige comité | 20 | Ley art. 29; reglamento art. 38 | COM, TEN |
| P-03 | `committee.members_min_max` | Mínimo y máximo de miembros del comité | 4 a 12 | Reglamento art. 43 | COM |
| P-04 | `committee.term_years` | Duración del mandato | 1 a 2 años (configurable por empresa dentro del rango) | Reglamento art. 62 | COM |
| P-05 | `committee.ordinary_meeting_frequency` | Frecuencia de reuniones ordinarias | Mensual | Reglamento art. 68 | COM |
| P-06 | `committee.election_call_lead_days` | Anticipación de la convocatoria a elecciones antes del fin del mandato | 15 días hábiles | Reglamento art. 49 | COM |
| P-07 | `committee.quorum_rule` | Quórum de instalación y votación de acuerdos | Mitad más uno de los miembros; acuerdos por mayoría simple | Reglamento art. 69-70 | COM |
| P-08 | `committee.member_leave_days` | Licencia con goce para funciones del comité (representantes de trabajadores) | 30 días naturales por año | Reglamento art. 73 | COM |
| P-09 | `risst.min_workers` | Trabajadores desde los cuales se exige RISST | 20 | Ley art. 34 | RIS |
| P-10 | `training.min_per_year` | Capacitaciones mínimas al año | 4 | Ley art. 35 (mod.); reglamento art. 27 | PCA, CAP |
| P-11 | `training.within_working_hours` | Capacitación dentro de la jornada | Sí | Ley art. 35; reglamento art. 29 | CAP |
| P-12 | `notify.fatal_accident_deadline` | Plazo para notificar accidente mortal | 24 horas desde ocurrido | Ley art. 82; reglamento art. 110 | ACC |
| P-13 | `notify.dangerous_incident_deadline` | Plazo para notificar incidente peligroso | 24 horas desde ocurrido | Reglamento art. 110 | ACC |
| P-14 | `notify.nonfatal_accident_deadline` | Plazo y responsable de notificar accidente no mortal | Último día hábil del mes siguiente; responsable: centro médico asistencial / empleador según caso | Reglamento art. 110; D.S. 012-2014-TR (aplicativo) | ACC |
| P-15 | `notify.occupational_disease_deadline` | Plazo para notificar enfermedad ocupacional | 5 días hábiles desde el diagnóstico (centro médico) | Reglamento art. 110 | ACC |
| P-16 | `notify.channel` | Canal oficial de notificación | Sistema informático de notificación del MTPE | Reglamento; D.S. 012-2014-TR | ACC |
| P-17 | `emo.periodic_frequency_default` | Periodicidad del EMO periódico | Cada 2 años; anual en actividades de alto riesgo | Reglamento art. 101 (mod. D.S. 016-2016-TR) | EMO |
| P-18 | `emo.preemployment_required` | Obligatoriedad y costo del EMO de inicio | Obligatorio, a cargo del empleador | Ley art. 49 (mod. Ley 30222); reglamento art. 101 | EMO |
| P-19 | `emo.exit_exam_rule` | EMO de retiro | A solicitud del trabajador | Ley art. 49 (mod.); reglamento art. 101 | EMO |
| P-20 | `emo.result_delivery_to_worker` | Entrega de resultados al trabajador | Obligatoria, por escrito | Ley art. 71; reglamento art. 102 | EMO |
| P-21 | `retention.accidents_incidents_years` | Conservación de registros de accidentes e incidentes | 10 años | Reglamento art. 35 | REG, ACC, AUD |
| P-22 | `retention.occupational_disease_years` | Conservación de registros de enfermedades ocupacionales | 20 años | Reglamento art. 35 | REG, ACC, EMO |
| P-23 | `retention.other_registers_years` | Conservación de los demás registros | 5 años | Reglamento art. 35 | REG |
| P-24 | `retention.clinical_record_years` | Conservación de la historia clínica ocupacional | 40 años | R.M. 312-2011-MINSA; normas de historia clínica | VMD, EMO |
| P-25 | `audit.frequency` | Periodicidad de auditorías del SG-SST y requisitos del auditor | Periódica, por auditor registrado en el MTPE (frecuencia a confirmar) | Ley art. 43; D.S. 014-2013-TR | AUI |
| P-26 | `stats.frequency_index_factor` | Factor del índice de frecuencia | 1 000 000 horas-hombre (alternativa 200 000) | R.M. 050-2013-TR (formato de estadísticas) | EST |
| P-27 | `stats.severity_index_factor` | Factor del índice de gravedad | 1 000 000 horas-hombre | R.M. 050-2013-TR | EST |
| P-28 | `stats.accident_rate_formula` | Índice de accidentabilidad | IF × IG / 1000 | R.M. 050-2013-TR; D.S. 024-2016-EM (minería) | EST |
| P-29 | `stats.lost_days_rules` | Días perdidos: cómputo (calendario, día del accidente, cargos por incapacidad permanente) | Días calendario desde el día siguiente; tabla de cargos a confirmar | R.M. 050-2013-TR; normas sectoriales | EST, ACC |
| P-30 | `sctr.risk_activities` | Actividades que obligan a contratar SCTR | Anexo 5 del D.S. 009-97-SA (lista CIIU) | Ley 26790; D.S. 003-98-SA | SCT, CTR |
| P-31 | `sctr.coverage_scope` | Coberturas exigidas (salud y pensiones) | Ambas, por trabajador expuesto | D.S. 003-98-SA | SCT |
| P-32 | `iperc.review_frequency` | Periodicidad de revisión del IPERC | Al menos anual y ante cambios o accidentes | Reglamento art. 77 | IPC |
| P-33 | `iperc.risk_matrix` | Matriz de evaluación (probabilidad × severidad) y niveles | Método referencial de la R.M. 050-2013-TR (índices de personas, procedimientos, capacitación, exposición) | R.M. 050-2013-TR; D.S. 024-2016-EM (minería) | IPC |
| P-34 | `inspections.committee_frequency` | Frecuencia de inspecciones del comité | Mensual | Reglamento art. 42 | INS, COM |
| P-35 | `drills.min_per_year` | Simulacros mínimos al año | 1 por tipo de emergencia relevante (a confirmar con normas de defensa civil) | Reglamento art. 33; normas INDECI | SIM |
| P-36 | `investigation.deadline` | Plazo interno para cerrar la investigación de accidentes | Configurable por empresa; propuesto 10 días hábiles | Ley art. 58; reglamento art. 88-89 (no fijan plazo; confirmar) | INV |
| P-37 | `policy.review_frequency` | Revisión de la política SST | Anual | Ley art. 22-23; ISO 45001 | POL |
| P-38 | `ergonomics.manual_load_limits` | Pesos máximos de carga manual por sexo y condición | Según R.M. 375-2008-TR | R.M. 375-2008-TR | PSI, VUL |
| P-39 | `chemical.exposure_limits` | Valores límite permisibles de agentes químicos | Tabla del D.S. 015-2005-SA | D.S. 015-2005-SA | MAG |
| P-40 | `noise.exposure_limit` | Límite de exposición a ruido | 85 dB(A) en 8 horas | R.M. 375-2008-TR; normas sectoriales | MAG |
| P-41 | `pregnant.restrictions` | Tareas restringidas para gestantes | Lista según Ley 28048 y su reglamento | Ley 28048 | VUL |
| P-42 | `minors.hazardous_work_list` | Trabajos peligrosos prohibidos para adolescentes | Lista del D.S. 003-2010-MIMDES | Código de Niños y Adolescentes | VUL |
| P-43 | `sunafil.uit_value` | Valor de la UIT vigente | S/ 5,500 (2026) | Decreto supremo anual del MEF | SUN |
| P-44 | `sunafil.fine_scale` | Escala de multas por gravedad y tamaño de empresa | Tabla del D.S. 019-2006-TR (mod.); muy grave no MYPE hasta 52.53 UIT | D.S. 019-2006-TR y modificatorias | SUN |
| P-45 | `datos.breach_notification_deadline` | Plazo para comunicar brechas de datos a la ANPD | 48 horas | D.S. 016-2024-JUS | AUD (ver `10`) |
| P-46 | `datos.arco_response_days` | Plazo de respuesta a solicitudes ARCO | Según tipo de derecho (a confirmar) | Ley 29733; D.S. 016-2024-JUS | TRB, AUD |
| P-47 | `supervisor.below_workers` | Empresas que designan Supervisor SST | Menos de 20 trabajadores | Ley art. 30 | COM |
| P-48 | `mining.notification_authority` | Autoridad y plazos de notificación en minería | Según D.S. 024-2016-EM (OSINERGMIN/SUNAFIL/MINEM según caso) | D.S. 024-2016-EM | ACC, PSE |
| P-49 | `web.complaint_response_days` | Plazo para responder una hoja de reclamación (días hábiles) | 15 días hábiles desde el registro | Ley 29571 (mod. Ley 31435); D.S. 011-2011-PCM y mod. (D.S. 101-2022-PCM) | WEB |
| P-50 | `web.complaint_extension_days` | Prórroga del plazo de respuesta (una vez, justificada y comunicada al consumidor) | Según el reglamento vigente (a confirmar si existe y cuánto) | D.S. 011-2011-PCM y modificatorias | WEB |
| P-51 | `web.complaint_retention_years` | Conservación de las hojas y su historial | 5 años desde el registro (mínimo); no se eliminan | D.S. 011-2011-PCM y modificatorias | WEB |
| P-52 | `web.complaint_warning_days` | Días hábiles antes del vencimiento para el semáforo amarillo y la alerta | Valor interno (no legal), configurable | — | WEB |
| P-53 | `web.complaint_minor_guardian_required` | Datos del padre, madre o apoderado si el consumidor es menor de edad | Obligatorio | D.S. 011-2011-PCM | WEB |
| P-54 | `web.complaint_report_format` | Formato y periodicidad del reporte de reclamos ante requerimiento de Indecopi | Excel/PDF por periodo con código, fecha, tipo, estado, fecha de respuesta y cumplimiento de plazo | Reglamento del Libro de Reclamaciones; requerimientos de Indecopi | WEB |
| P-55 | `web.business_holidays` | Calendario de feriados nacionales para contar días hábiles | Feriados oficiales del año (actualizables) | Normas que declaran feriados y días no laborables | WEB |
| P-56 | `web.cookie_consent_validity` | Vigencia del consentimiento de cookies antes de volver a pedirlo | A definir con abogado | Ley 29733; D.S. 016-2024-JUS | WEB |
| P-57 | `web.uit_value` | UIT vigente usada por la calculadora de multas del sitio web | Mismo valor que P-43 en cada periodo (a confirmar; se cargan juntos con la misma vigencia) | Decreto supremo anual del MEF | WEB |
| P-58 | `web.fine_table_micro` | Tabla de multas para microempresa: por gravedad (leve, grave, muy grave) y tramo de trabajadores afectados, en UIT (JSON) | Tabla del art. 48 del reglamento para microempresas (a confirmar el texto vigente) | D.S. 019-2006-TR, art. 48 y modificatorias (entre ellas D.S. 015-2017-TR) | WEB |
| P-59 | `web.fine_table_small` | Tabla de multas para pequeña empresa, misma estructura (JSON) | Tabla del art. 48 del reglamento para pequeñas empresas (a confirmar) | D.S. 019-2006-TR, art. 48 y modificatorias | WEB |
| P-60 | `web.fine_table_non_mype` | Tabla de multas para empresas no MYPE, misma estructura (JSON) | Tabla del art. 48 del reglamento para no MYPE; debe coincidir con P-44 (a confirmar) | D.S. 019-2006-TR, art. 48 y modificatorias | WEB |
| P-61 | `web.fine_correction_discount_pct` | Reducción de la multa por subsanación, según la etapa del procedimiento (JSON por etapa) | Porcentajes de reducción del art. 40 de la Ley 28806 y del reglamento (a confirmar etapas y porcentajes vigentes) | Ley 28806, art. 40 (mod. Ley 30222); D.S. 019-2006-TR y modificatorias | WEB |
| P-62 | `web.lead_retention_months` | Conservación de los leads que no se convierten en clientes, antes de anonimizarlos | A definir con abogado de protección de datos (solo el tiempo necesario para la finalidad informada) | Ley 29733 (principios de finalidad y proporcionalidad); D.S. 016-2024-JUS | WEB |
| P-63 | `web.analytics_event_retention_months` | Conservación de los eventos de métricas del sitio (`site_events`) antes de eliminarlos y dejar solo el agregado diario | A definir con abogado de protección de datos (lo mínimo necesario para medir) | Ley 29733 (finalidad y proporcionalidad); D.S. 016-2024-JUS | WEB |
| P-64 | `web.chatbot_retention_months` | Conservación de las conversaciones y mensajes del chatbot | A definir con abogado de protección de datos | Ley 29733; D.S. 016-2024-JUS | WEB |
| P-65 | `web.demo_recording_requires_consent` | Exigir consentimiento expreso del visitante para grabar la reunión de demo | `true` | Ley 29733 (consentimiento para tratar imagen y voz) | WEB |
| P-66 | `web.review_cache_max_hours` | Tope de horas que una copia de reseñas de Google puede mostrarse antes de volver a leerse | Según los términos vigentes del proveedor (a confirmar) | Términos de servicio de la API de Google Business Profile / Places | WEB |
| P-67 | `web.popup_max_per_visit` | Máximo de ventanas emergentes mostradas a un visitante por visita | 1 (a confirmar; ajuste de experiencia, no valor legal) | Ley 29571 (prácticas comerciales no agresivas); buenas prácticas de accesibilidad | WEB |

Reglas de implementación:
- Cada parámetro tiene historial: un cambio crea una nueva fila con `valid_from`; los cálculos usan el valor vigente a la fecha del hecho (por ejemplo, el plazo de notificación vigente el día del accidente).
- La interfaz muestra un distintivo «Por confirmar» junto a cualquier cálculo que use un parámetro con `to_confirm = true` (visible para el dueño de la plataforma y el jefe SST).
- Una empresa puede endurecer un parámetro (más estricto), nunca relajarlo por debajo del valor legal.
- Los paquetes sectoriales (PSE) pueden sobrescribir parámetros para su sector (p. ej. minería en P-26 a P-29 y P-48).

### 4.1 Libro de Reclamaciones de EscudoSST (módulo WEB)

GRUPO DE SOLUCIONES CREATIVAS SAC, como proveedor que ofrece EscudoSST en el Perú, publica un **Libro de Reclamaciones virtual** en su sitio web. Todo lo siguiente es referencia de diseño y **se confirma con abogado** antes de publicar:

- Aviso y enlace visibles en todas las páginas del sitio (pie) y en el inicio de sesión.
- Hoja con contenido mínimo: código correlativo único, fecha y hora; datos del proveedor (razón social, RUC, domicilio legal, tomados de `site_settings`); datos del consumidor (nombre, documento, domicilio, teléfono, correo) y, si es menor de edad, del padre, madre o apoderado; bien contratado (producto o servicio) con descripción y monto si aplica; tipo **reclamo** (disconformidad con el producto o servicio) o **queja** (malestar respecto de la atención); detalle y pedido del consumidor.
- Constancia inmediata en pantalla, en PDF y por correo al consumidor, con copia al responsable.
- Respuesta escrita dentro de P-49, con prórroga justificada según P-50 comunicada al consumidor; seguimiento público por código.
- Conservación según P-51; las hojas no se eliminan ni se editan (correcciones como eventos).
- Reporte exportable para requerimientos de Indecopi (P-54).
- Es **distinto** del Libro de Reclamaciones que pueda necesitar una empresa cliente que vende cursos a consumidores en su catálogo ([VTA](modulos/M-VTA.md)); esa decisión sigue abierta en `13` (DA-27).

### 4.2 Herramientas de captación del sitio web (módulo WEB)

Todo lo siguiente es referencia de diseño, **a confirmar** por un especialista SST y un abogado antes de publicar:

- **Calculadora de multas SUNAFIL** (`/herramientas/calculadora-multas`): usa P-57 a P-61 vigentes a la fecha del cálculo. Las tablas de multas del D.S. 019-2006-TR (art. 48) y sus modificatorias distinguen microempresa, pequeña empresa y no MYPE, la gravedad de la infracción (leve, grave, muy grave; arts. 23 a 28 para SST) y el número de trabajadores afectados. La reducción por subsanación (P-61) depende de la etapa del procedimiento. El resultado es **referencial**, no es asesoría legal ni una liquidación de SUNAFIL, y se rotula «valores a confirmar» mientras algún parámetro tenga `to_confirm = true`. P-57 y P-60 deben coincidir con P-43 y P-44 (mismo valor y vigencia); la decisión de unificarlos está en `13` (DA-40).
- **Autodiagnóstico Ley 29783** (`/herramientas/autodiagnostico`): las preguntas iniciales se basan en las obligaciones de la Ley 29783 y del D.S. 005-2012-TR (política, IPERC, comité o supervisor, RISST, capacitaciones, registros del art. 33, EMO, investigación de accidentes) y en la guía de línea base de la R.M. 050-2013-TR. Se publican marcadas «a validar por especialista SST» (`to_validate = true`) hasta su revisión (DA-38). Los pesos y los cortes de nivel son ajustes internos, no valores legales.
- **Boletín normativo SST**: resume normas y criterios publicados; no interpreta casos concretos (ver §5).
- **Leads**: su conservación sigue P-62 y lo descrito en `10` §1.7 y §9.

### 4.3 Sitio web v1.5 y v1.5-b (módulo WEB)

Referencia de diseño, **a confirmar** con abogado antes de publicar:
- **Métricas y chatbot:** los eventos de métricas se conservan según P-63 y las conversaciones del chatbot según P-64; ambos con `to_confirm = true` (ver `10` §1.8 y §9).
- **Grabación de demos:** P-65 (`true`) exige consentimiento expreso; sin él no se graba.
- **Reseñas de Google:** P-66 limita la caché según los términos del proveedor.
- **Ventanas emergentes:** P-67 limita cuántas ve un visitante por visita; no se muestran en el Libro de Reclamaciones ni en las políticas.
- **Veracidad (Ley 29571):** sellos, integraciones («disponible», «beta», «planificada»), reseñas, cifras y la calculadora de ahorro deben ser veraces y comprobables; las normas sectoriales del sitio solo se citan si figuran en este documento y con la marca «a validar».

## 5. Fuera del alcance normativo de V1

- Normativa de otros países.
- Trámites ante la autoridad (el producto prepara información; no presenta).
- Interpretación jurídica de casos concretos.

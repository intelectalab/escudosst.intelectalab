# M-IAA · 8.3 Asistente IA

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `IAA` |
| Número | 8.3 |
| Nombre | Asistente IA |
| Grupo | 8. Diferenciadores e integraciones |
| Plan | + · Complemento |
| Fase | 3 · Diferenciadores (cierra la versión 1) |
| Origen | Diferenciador |
| Resuelve | IPERC sugerido, investigación guiada, lectura de documentos |
| Tablas del catálogo | `ai_requests`, `ai_suggestions` |
| Dependencias | [IPC](M-IPC.md) (Matriz IPERC), [INV](M-INV.md) (Investigación de causas), [DOC](M-DOC.md) (Gestión documental), [USR](M-USR.md) (Usuarios, roles y permisos) |
| Prefijo de permisos | `iaa.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Asistir con IA a los usuarios SST: sugerir filas de IPERC por puesto/tarea, guiar la investigación de causas, leer documentos (p. ej. requerimientos o informes), proponer contenido y sugerir cursos a recomendar en el aula ([AUL](M-AUL.md)); toda salida es una sugerencia pendiente que una persona revisa y aplica.

**Problema que resuelve.** Elaborar un IPERC o una investigación de calidad exige experiencia que muchas empresas pequeñas no tienen; el asistente acelera sin reemplazar el criterio del responsable.

## 3. Base normativa

- Ley 29733 y D.S. 016-2024-JUS: minimización de datos personales enviados a terceros, flujo transfronterizo comunicado; prohibido enviar datos sensibles.
- Ley 29783, art. 57: la evaluación de riesgos es responsabilidad del empleador (la IA no aprueba).
- Ley 31814 (promoción del uso de la IA) y su reglamento en lo aplicable (a confirmar).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Solicita sugerencias y las aplica o descarta | `iaa.ai_requests.create`, `iaa.ai_suggestions.update` |
| Supervisor SST (`sst_supervisor`) | Solicita sugerencias en su ámbito | `iaa.ai_requests.create`, `iaa.ai_suggestions.update` |
| Jefe de área / línea (`area_lead`) | Solicita sugerencias para IPERC/ATS de su área | `iaa.ai_requests.create` |
| Consultor SST (`consultant`) | Usa el asistente en empresas cliente | `iaa.ai_requests.create`, `iaa.ai_suggestions.update` |
| Administrador de cuenta (`account_admin`) | Activa el complemento y consulta el consumo | `iaa.ai_requests.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `ai_requests` | principal | Solicitud a la IA | company_id, user_id, use_case (iperc_suggest, investigation_guide, document_read, text_draft, course_recommendation), context_ref_type, context_ref_id, prompt_template_version, input_redacted (jsonb), provider, model, tokens_in, tokens_out, status, error, created_at |
| `ai_suggestions` | principal | Sugerencia pendiente | company_id, request_id, target_type (iperc_row, investigation_cause, document_field, text, course_recommendation), target_ref_id, payload (jsonb), rationale, status (pendiente, aceptada, editada y aceptada, descartada), decided_by, decided_at, applied_entity_id |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-IAA-01 | Sugerir IPERC | Desde el editor IPERC | Botón «Sugerir con IA» por tarea; panel lateral con filas propuestas (peligro, riesgo, controles por jerarquía) para aceptar, editar o descartar una por una |
| SCR-IAA-02 | Investigación guiada | Desde INV | Preguntas guiadas por el método; propuesta de causas inmediatas y básicas como sugerencias |
| SCR-IAA-03 | Leer documento | Extracción | Subir un PDF (p. ej. requerimiento SUNAFIL, informe de monitoreo); campos extraídos como sugerencias para revisar |
| SCR-IAA-04 | Sugerir recomendaciones de cursos | Desde AUL | Sugerencias de cursos por persona, cargo o empresa compradora con motivo; van a la bandeja de recomendaciones por aprobar de [AUL](M-AUL.md) |
| SCR-IAA-05 | Historial de IA | Trazabilidad | Solicitudes, sugerencias y decisiones por usuario |
| SCR-IAA-06 | Consumo | Administración | Solicitudes por mes y caso de uso (sin precios) |

## 7. Flujos principales

1. El usuario invoca un caso de uso desde el módulo.
2. Un redactor elimina datos personales innecesarios y bloquea cualquier dato médico; se arma el prompt con plantilla versionada.
3. Se llama al proveedor detrás de la interfaz `AiProvider`.
4. La respuesta se valida contra un esquema y se guarda como `ai_suggestions` pendientes.
5. El usuario acepta (tal cual o editada) o descarta; al aceptar, el módulo crea el registro como borrador a nombre del usuario.

## 8. Reglas de negocio

- **RN-IAA-01.** La IA nunca escribe directamente en tablas de negocio: toda salida es `ai_suggestions` en estado pendiente.
- **RN-IAA-02.** Aplicar una sugerencia crea o modifica el registro en estado borrador a nombre de quien la acepta; los flujos de aprobación del módulo siguen siendo obligatorios.
- **RN-IAA-03.** Antes de enviar, el redactor elimina nombres, DNI, teléfonos y correos (o los reemplaza por marcadores) y bloquea cualquier campo de la base médica; si detecta un dato sensible, la solicitud se rechaza.
- **RN-IAA-04.** Las respuestas deben cumplir el esquema JSON del caso de uso; las que no lo cumplen se descartan y se registra error.
- **RN-IAA-05.** Cada sugerencia de IPERC indica que es «Sugerencia IA» hasta que se apruebe la versión, y la fila conserva la marca de origen.
- **RN-IAA-06.** El uso está limitado por `ai_monthly_request_limit` del complemento por empresa.
- **RN-IAA-07.** El proveedor y el modelo están detrás de la interfaz `AiProvider`; se registra proveedor y modelo en cada solicitud.
- **RN-IAA-08.** No se usan los datos de clientes para entrenar modelos (condición contractual con el proveedor).
- **RN-IAA-09.** Las sugerencias de recomendación de cursos reciben solo datos seudonimizados (cargo, área, cursos y certificados, compras); nunca datos médicos ni aptitud; se envían a la bandeja de [AUL](M-AUL.md) y solo una persona las aprueba antes de mostrarse o enviarse.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| ai_requests | queued → completed / failed / rejected_sensitive | |
| ai_suggestions | pending → accepted / edited_accepted / discarded | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Límite mensual al 90 % | Administrador de cuenta | Correo | Al alcanzarlo |
| Sugerencias listas (procesos largos) | Solicitante | App | Al completar |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Uso de IA por caso, usuario y tasa de aceptación (calidad de sugerencias).

## 12. Integración con otros módulos

**Consume:**

- [IPC](M-IPC.md), [INV](M-INV.md), [DOC](M-DOC.md), [SUN](M-SUN.md), catálogo de peligros y paquetes [PSE](M-PSE.md).

**Produce:**

- Sugerencias de recomendación de cursos para [AUL](M-AUL.md); sugerencias aplicables en [IPC](M-IPC.md), [INV](M-INV.md), [ATS](M-ATS.md), [SUN](M-SUN.md), [RIS](M-RIS.md).

## 13. Criterios de aceptación clave

1. **Dado** una solicitud de sugerencias IPERC, **cuando** responde la IA, **entonces** se crean sugerencias pendientes y ninguna fila de IPERC.
2. **Dado** un usuario acepta una fila sugerida, **cuando** se aplica, **entonces** la fila aparece en la versión en borrador con marca «Sugerencia IA».
3. **Dado** un texto con DNI y nombre del trabajador, **cuando** se envía, **entonces** el proveedor recibe marcadores en lugar de los datos.
4. **Dado** un intento de enviar datos de un EMO, **cuando** se procesa, **entonces** la solicitud se rechaza como sensible.
5. **Dado** el límite mensual alcanzado, **cuando** se pide otra sugerencia, **entonces** se informa del límite y no se llama al proveedor.
6. **Dado** una sugerencia de recomendación de curso generada por IA, **cuando** nadie la aprueba, **entonces** no se muestra al destinatario ni se envía.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Decisiones automáticas o aprobaciones por IA.

**Preguntas abiertas:**

- ¿Qué proveedor de IA y en qué región se procesará (flujo transfronterizo)?

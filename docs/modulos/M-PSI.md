# M-PSI · 3.4 Riesgos ergonómicos y psicosociales

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `PSI` |
| Número | 3.4 |
| Nombre | Riesgos ergonómicos y psicosociales |
| Grupo | 3. Gestión de riesgos |
| Plan | C · Corporativo |
| Fase | 4 · Plan Corporativo |
| Origen | Ley |
| Resuelve | Evaluaciones y encuestas |
| Tablas del catálogo | `ergonomic_assessments`, `psychosocial_surveys`, `psychosocial_responses` |
| Dependencias | [TRB](M-TRB.md) (Trabajadores y legajo), [IPC](M-IPC.md) (Matriz IPERC), [ACR](M-ACR.md) (Acciones correctivas) |
| Prefijo de permisos | `psi.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Evaluar riesgos disergonómicos por puesto (métodos configurables) y aplicar encuestas psicosociales anónimas, con resultados agregados y planes de acción.

**Problema que resuelve.** La norma de ergonomía y los factores psicosociales se cumplen poco; las encuestas en papel no garantizan anonimato y nadie analiza los resultados.

## 3. Base normativa

- R.M. 375-2008-TR: norma básica de ergonomía y procedimiento de evaluación de riesgo disergonómico (lista de verificación).
- Ley 29783, art. 56 y D.S. 005-2012-TR, art. 103: factores de riesgo psicosocial.
- Ley 29733: respuestas de encuestas como datos sensibles si permiten identificar; anonimato.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Programa evaluaciones y encuestas; analiza resultados | `psi.ergonomic_assessments.create`, `psi.psychosocial_surveys.create`, `psi.psychosocial_surveys.view` |
| Jefe de área / línea (`area_lead`) | Facilita evaluaciones ergonómicas de su área | `psi.ergonomic_assessments.create` |
| Trabajador (`worker`) | Responde encuestas de forma anónima | `psi.psychosocial_responses.create` |
| Médico ocupacional (`occupational_doctor`) | Consulta resultados para vigilancia | `psi.ergonomic_assessments.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `ergonomic_assessments` | principal | Evaluación por puesto | company_id, position_id, method (rm375 checklist, REBA, RULA, OWAS), assessed_on, score, risk_level, findings, recommendations, file_ids |
| `psychosocial_surveys` | principal | Encuesta | company_id, instrument (cuestionario configurable), scope, opens_at, closes_at, min_group_size, status |
| `psychosocial_responses` | principal | Respuesta anónima | survey_id, response_token_hash, segment (área/sede agregado), answers (jsonb), submitted_at; sin worker_id |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-PSI-01 | Evaluaciones ergonómicas | Lista por puesto | Método, puntaje, nivel, recomendaciones |
| SCR-PSI-02 | Formulario ergonómico | Captura | Lista de verificación del método elegido; fotos; cálculo |
| SCR-PSI-03 | Encuestas | Programación | Instrumento, segmentos, fechas, participación |
| SCR-PSI-04 | Responder encuesta (app del trabajador) | Anónimo | Preguntas en tarjetas; aviso de anonimato |
| SCR-PSI-05 | Resultados psicosociales | Agregados | Dimensiones por segmento con semáforo; segmentos bajo `min_group_size` ocultos |

## 7. Flujos principales

1. Ergonomía: se evalúa cada puesto con el método elegido; niveles altos generan acciones y revisión del IPERC.
2. Psicosocial: se abre la encuesta; cada trabajador recibe token único; responde de forma anónima; al cerrar se calculan dimensiones por segmento.

## 8. Reglas de negocio

- **RN-PSI-01.** Las respuestas psicosociales no guardan `worker_id`; el token solo impide responder dos veces.
- **RN-PSI-02.** No se muestran resultados de segmentos con menos de `min_group_size` respuestas.
- **RN-PSI-03.** Un nivel ergonómico alto exige acción correctiva y marca el IPERC del puesto para revisión.
- **RN-PSI-04.** El instrumento y su baremo son configurables y versionados.
- **RN-PSI-05.** Los resultados solo se calculan tras el cierre de la encuesta.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| psychosocial_surveys | draft → open → closed → analyzed | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Encuesta abierta | Trabajadores del alcance | App, WhatsApp | Al abrir; recordatorio antes del cierre |
| Evaluación ergonómica con nivel alto | Jefe SST | App | Inmediato |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Informe ergonómico por puesto; resultados psicosociales agregados (insumo del registro de monitoreo de factores disergonómicos y psicosociales en [REG](M-REG.md)).

## 12. Integración con otros módulos

**Consume:**

- [TRB](M-TRB.md), [IPC](M-IPC.md).

**Produce:**

- Acciones en [ACR](M-ACR.md); revisión de [IPC](M-IPC.md).

## 13. Criterios de aceptación clave

1. **Dado** una encuesta con un área de 3 respuestas y `min_group_size` 5, **cuando** se ven resultados, **entonces** esa área no aparece desagregada.
2. **Dado** un trabajador que ya respondió, **cuando** intenta otra vez, **entonces** se rechaza.
3. **Dado** una evaluación REBA con nivel alto, **cuando** se guarda, **entonces** se crea la acción correctiva.
4. **Dado** la tabla de respuestas, **cuando** se inspecciona, **entonces** no contiene identificador del trabajador.
5. **Dado** una encuesta abierta, **cuando** se piden resultados, **entonces** no están disponibles.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Intervención psicológica individual.

**Preguntas abiertas:**

- ¿Qué instrumento psicosocial validado se licenciará (p. ej. ISTAS21/CoPsoQ)?

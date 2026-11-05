# M-SCT · 7.2 Control de SCTR

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `SCT` |
| Número | 7.2 |
| Nombre | Control de SCTR |
| Grupo | 7. Contratistas y terceros |
| Plan | P · Profesional |
| Fase | 2 · Plan Profesional |
| Origen | Ley |
| Resuelve | Pólizas y vigencias por trabajador |
| Tablas del catálogo | `sctr_policies`, `sctr_coverages` |
| Dependencias | [TRB](M-TRB.md) (Trabajadores y legajo), [CTR](M-CTR.md) (Acreditación de contratistas) |
| Prefijo de permisos | `sct.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Registrar las pólizas del Seguro Complementario de Trabajo de Riesgo (salud y pensión) y la cobertura de cada trabajador propio o contratista por periodo, controlando vigencias.

**Problema que resuelve.** Trabajadores en actividades de riesgo laboran sin SCTR vigente porque la constancia mensual no se controla; es una infracción y un riesgo económico grave ante un accidente.

## 3. Base normativa

- Ley 26790 y D.S. 003-98-SA: SCTR obligatorio para actividades de riesgo (Anexo 5), coberturas de salud y pensión.
- Ley 29783, art. 68: la principal verifica el SCTR de contratistas.
- Actividades de riesgo según Anexo 5 del D.S. 009-97-SA y modificatorias (a confirmar).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Recursos Humanos (`hr`) | Registra pólizas y constancias mensuales | `sct.sctr_policies.create`, `sct.sctr_policies.update`, `sct.sctr_coverages.create` |
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Consulta y controla vigencias | `sct.sctr_policies.view`, `sct.sctr_coverages.view` |
| Administrador de contratista (`contractor_admin`) | Carga pólizas y constancias de su personal | `sct.sctr_policies.create`, `sct.sctr_coverages.create` |
| Jefe de área / línea (`area_lead`) | Consulta cobertura de su personal | `sct.sctr_coverages.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `sctr_policies` | principal | Póliza | company_id, contractor_id, coverage_type (salud, pensión), insurer_name (EPS/EsSalud/aseguradora), policy_number, start_date, end_date, file_id, status |
| `sctr_coverages` | principal | Cobertura por trabajador y periodo | company_id, policy_id, worker_id, contractor_worker_id, period_start, period_end, certificate_file_id, source (constancia, planilla importada) |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-SCT-01 | Pólizas | Lista | Por tipo, aseguradora, vigencia con semáforo |
| SCR-SCT-02 | Constancias mensuales | Carga | Importación de la lista de asegurados del periodo (Excel/PDF); conciliación con trabajadores activos |
| SCR-SCT-03 | Cobertura por trabajador | Consulta | Trabajadores con salud y pensión vigentes o faltantes |

## 7. Flujos principales

1. RR.HH. registra la póliza vigente (salud y pensión).
2. Cada periodo carga la constancia con la lista de asegurados; el sistema concilia con trabajadores activos que requieren SCTR.
3. Los faltantes se alertan y afectan fotocheck y permisos.

## 8. Reglas de negocio

- **RN-SCT-01.** Un trabajador requiere SCTR si su puesto o sede está marcado como actividad de riesgo (`requires_sctr`).
- **RN-SCT-02.** La cobertura vigente exige salud y pensión, cada una con periodo que incluya la fecha consultada.
- **RN-SCT-03.** Una cobertura no puede exceder la vigencia de su póliza.
- **RN-SCT-04.** La conciliación identifica trabajadores activos sin cobertura y asegurados que no están activos.
- **RN-SCT-05.** Al vencer la cobertura sin renovación, el trabajador queda «sin SCTR» y se reevalúan [FOT](M-FOT.md) y [PTR](M-PTR.md).
- **RN-SCT-06.** Los trabajadores contratistas se controlan con las pólizas del contratista.
- **RN-SCT-07.** Las constancias cargadas no se editan; se reemplazan con nueva carga.
- **RN-SCT-08.** Se identifica por (worker_id, coverage_type, periodo) sin duplicados.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| sctr_policies | active → expiring → expired; active → cancelled | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Póliza por vencer | RR.HH., Jefe SST | Correo, app | `sctr_policy_notice_days` antes |
| Periodo sin constancia cargada | RR.HH. | App | `sctr_period_due_day` del periodo |
| Trabajador sin cobertura | Jefe de área, Jefe SST | App | Al conciliar |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Cobertura SCTR por periodo y trabajador; conciliación; aporte al expediente [SUN](M-SUN.md) (SCTR del trabajador en el periodo).

## 12. Integración con otros módulos

**Consume:**

- [TRB](M-TRB.md), [CTR](M-CTR.md), [TEN](M-TEN.md).

**Produce:**

- Vigencia SCTR para [FOT](M-FOT.md), [PTR](M-PTR.md), [CTR](M-CTR.md), [ACC](M-ACC.md) (notificación a aseguradora).

## 13. Criterios de aceptación clave

1. **Dado** un trabajador con salud vigente y pensión vencida, **cuando** se consulta, **entonces** figura «sin SCTR».
2. **Dado** una constancia con 48 asegurados y 50 activos que requieren SCTR, **cuando** se concilia, **entonces** se listan 2 faltantes.
3. **Dado** una cobertura con periodo fuera de la póliza, **cuando** se guarda, **entonces** se rechaza.
4. **Dado** la cobertura vence, **cuando** corre el job, **entonces** el fotocheck se reevalúa.
5. **Dado** una constancia del mismo periodo, **cuando** se carga dos veces, **entonces** no hay duplicados.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Cotización o contratación de seguros.

**Preguntas abiertas:**

- ¿Se puede importar la lista de asegurados desde el portal de la aseguradora?

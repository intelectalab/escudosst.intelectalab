# M-CON · 8.4 Modo consultora y marca blanca

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `CON` |
| Número | 8.4 |
| Nombre | Modo consultora y marca blanca |
| Grupo | 8. Diferenciadores e integraciones |
| Plan | K · Consultora |
| Fase | 3 · Diferenciadores (cierra la versión 1) |
| Origen | Diferenciador |
| Resuelve | Panel multi-cliente con marca propia |
| Tablas del catálogo | `white_label_settings`, `consultant_assignments`, `client_invitations` |
| Dependencias | [USR](M-USR.md) (Usuarios, roles y permisos), [TEN](M-TEN.md) (Empresas, sedes y áreas), [SUS](M-SUS.md) (Suscripciones y facturación), [DSH](M-DSH.md) (Dashboard e indicadores), [SUN](M-SUN.md) (Modo Inspección SUNAFIL) |
| Prefijo de permisos | `con.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Permitir que una consultora SST gestione varias empresas cliente desde un panel multi-cliente, con asignación de consultores por empresa, invitación de clientes y marca blanca (logo, colores, dominio) en la plataforma y documentos.

**Problema que resuelve.** Las consultoras SST atienden decenas de clientes con Excel y carpetas; necesitan una vista consolidada y presentar el sistema con su marca. Son también un canal de venta.

## 3. Base normativa

- Ley 29733 y D.S. 016-2024-JUS: la consultora actúa como encargada del tratamiento de los datos de cada cliente; acceso solo a empresas asignadas.
- Ley 29783, art. 29 y D.S. 005-2012-TR: la responsabilidad del SG-SST sigue siendo del empleador.

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Administrador de cuenta (`account_admin`) | Administra la cuenta de la consultora, marca blanca, invita clientes y asigna consultores | `con.white_label_settings.update`, `con.client_invitations.create`, `con.consultant_assignments.create`, `con.consultant_assignments.update` |
| Consultor SST (`consultant`) | Opera las empresas asignadas desde el panel | `con.consultant_assignments.view` |
| Jefe SST / Responsable del SG-SST (`sst_manager`) | (en la empresa cliente) Acepta la gestión de la consultora y puede revocarla | `con.client_invitations.update` |
| Dueño de la plataforma (`platform_owner`) | Habilita el plan Consultora y dominios personalizados | `con.white_label_settings.view` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `white_label_settings` | principal | Marca blanca de la cuenta consultora | account_id, brand_name, logo_file_id, primary_color, secondary_color, custom_domain, domain_verified_at, email_sender_name, report_footer, is_active |
| `consultant_assignments` | principal | Consultor asignado a empresa cliente | account_id, consultant_user_id, company_id, role_code, start_date, end_date, status |
| `client_invitations` | principal | Invitación a una empresa cliente | account_id, company_ruc, company_id, invited_email, mode (crear empresa nueva, vincular empresa existente), token_hash, expires_at, status, accepted_at |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-CON-01 | Panel multi-cliente | Vista consolidada | Tabla de empresas con plan, semáforo de preparación SUNAFIL, % PASST, acciones vencidas, próximos vencimientos; orden por riesgo; acceso con un clic a cada empresa |
| SCR-CON-02 | Agenda de la consultora | Vencimientos cruzados | Vencimientos de todas las empresas asignadas en un calendario |
| SCR-CON-03 | Clientes | Gestión | Invitar/vincular empresas; estado de la invitación; desvincular |
| SCR-CON-04 | Consultores | Asignaciones | Matriz consultor × empresa con rol |
| SCR-CON-05 | Marca blanca | Configuración | Logo, colores con vista previa, nombre del remitente, dominio personalizado con verificación DNS |

## 7. Flujos principales

1. La consultora invita a una empresa: crea una nueva (por RUC) o vincula una existente.
2. Si la empresa ya existe en otra cuenta, su administrador debe aceptar la vinculación; puede revocarla.
3. El administrador asigna consultores con un rol por empresa.
4. El panel muestra solo las empresas con asignación vigente.

## 8. Reglas de negocio

- **RN-CON-01.** Un consultor solo accede a empresas con `consultant_assignments` vigentes; cada acceso opera con RLS de esa empresa.
- **RN-CON-02.** Vincular una empresa existente exige aceptación expresa de su administrador; la empresa puede revocar la vinculación y la consultora pierde acceso inmediato.
- **RN-CON-03.** Cada empresa cliente conserva su propio plan y feature flags; el panel no habilita módulos no contratados.
- **RN-CON-04.** La marca blanca se aplica a la interfaz, correos y PDF de las empresas gestionadas por la consultora; el pie legal de EscudoSST como proveedor se mantiene donde el contrato lo exija.
- **RN-CON-05.** El dominio personalizado se activa solo tras verificación DNS (`domain_verified_at`).
- **RN-CON-06.** Las invitaciones vencen en `client_invite_ttl_days` y son de un solo uso.
- **RN-CON-07.** El panel consolidado agrega indicadores ya calculados por empresa; no cruza datos personales entre empresas.
- **RN-CON-08.** Al desvincular, los datos permanecen en la empresa cliente; nada se copia a la consultora.
- **RN-CON-09.** La activación por empresa del aula ([AUL](M-AUL.md)) y de la venta de cursos ([VTA](M-VTA.md)) de cada empresa cliente la decide su administrador de cuenta; el panel multi-cliente muestra su estado pero la consultora no la cambia.
- **RN-CON-10.** Una consultora que vende cursos lo hace desde su propia empresa con VTA activo; sus clientes compran cupos como empresas compradoras y los asignan a sus trabajadores, sin cruzar datos entre las empresas cliente.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| client_invitations | sent → accepted / expired / revoked | |
| consultant_assignments | active → ended | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Invitación de consultora | Administrador de la empresa cliente | Correo | Al enviar |
| Vinculación revocada por el cliente | Administrador de la consultora | Correo, app | Inmediato |
| Vencimientos de clientes de la semana | Consultor | Correo | Día parametrizado |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Reporte consolidado de clientes (cumplimiento, brechas, vencimientos) con marca de la consultora.
- Exportación por cliente para informes mensuales de la consultora.

## 12. Integración con otros módulos

**Consume:**

- [DSH](M-DSH.md), [SUN](M-SUN.md), [EST](M-EST.md), [SUS](M-SUS.md).

**Produce:**

- Contexto de marca blanca para interfaz, [NOT](M-NOT.md) y PDF de todos los módulos.

## 13. Criterios de aceptación clave

1. **Dado** un consultor sin asignación a la empresa B, **cuando** intenta abrirla por URL, **entonces** recibe 403.
2. **Dado** una empresa existente invitada, **cuando** su administrador no acepta, **entonces** la consultora no tiene acceso.
3. **Dado** una empresa revoca la vinculación, **cuando** el consultor recarga el panel, **entonces** la empresa desaparece y no puede acceder.
4. **Dado** marca blanca configurada, **cuando** se genera un PDF de IPERC del cliente, **entonces** lleva el logo y colores de la consultora.
5. **Dado** un dominio sin verificación DNS, **cuando** se intenta activar, **entonces** se rechaza.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Facturación de la consultora a sus clientes.

**Preguntas abiertas:**

- ¿El pie «Con tecnología EscudoSST» es obligatorio en marca blanca?

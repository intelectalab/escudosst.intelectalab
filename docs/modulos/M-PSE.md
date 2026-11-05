# M-PSE · 8.5 Paquetes sectoriales

## 1. Encabezado

| Campo | Valor |
|---|---|
| Código | `PSE` |
| Número | 8.5 |
| Nombre | Paquetes sectoriales |
| Grupo | 8. Diferenciadores e integraciones |
| Plan | + · Complemento |
| Fase | 3 · Diferenciadores (cierra la versión 1) |
| Origen | Diferenciador |
| Resuelve | Plantillas y formatos por sector |
| Tablas del catálogo | `sector_packs`, `sector_pack_items`, `company_sector_packs` |
| Dependencias | [IPC](M-IPC.md) (Matriz IPERC), [INS](M-INS.md) (Inspecciones), [CAP](M-CAP.md) (Capacitaciones e inducciones), [PET](M-PET.md) (PETS y procedimientos), [PTR](M-PTR.md) (PETAR y permisos de alto riesgo) |
| Prefijo de permisos | `pse.<tabla>.<acción>` |

## 2. Objetivo y problema que resuelve

**Objetivo.** Ofrecer paquetes sectoriales (construcción, minería, agroindustria, manufactura, retail, oficinas, clínicas) con peligros, criterios, plantillas de inspección, temas de capacitación, formatos y obligaciones específicas que se instalan en la empresa como contenido editable.

**Problema que resuelve.** Cada sector tiene normas y formatos propios (G.050, D.S. 024-2016-EM); configurar desde cero desanima a los clientes y produce sistemas incompletos.

## 3. Base normativa

- D.S. 024-2016-EM y modificatoria D.S. 023-2017-EM (minería).
- Norma G.050 Seguridad durante la construcción y D.S. 011-2019-TR (Reglamento de SST para el sector construcción).
- Normas sectoriales de agroindustria, salud y otras aplicables (lista a validar por especialista).

> Todo valor legal citado (plazos, periodicidades, umbrales, factores) se implementa como parámetro con vigencia y queda «a confirmar por especialista SST/legal»; nunca como constante en código.

## 4. Roles y permisos

| Rol | Qué hace en el módulo | Permisos |
|---|---|---|
| Dueño de la plataforma (`platform_owner`) | Publica y versiona paquetes | `pse.sector_packs.create`, `pse.sector_packs.update`, `pse.sector_pack_items.create` |
| Jefe SST / Responsable del SG-SST (`sst_manager`) | Instala paquetes y aplica actualizaciones | `pse.company_sector_packs.create`, `pse.company_sector_packs.update` |
| Consultor SST (`consultant`) | Instala paquetes en empresas cliente | `pse.company_sector_packs.create` |

Roles no listados no tienen acceso al módulo. Los permisos se acotan además por `membership_scopes` (sede/área) y por el plan contratado (feature flag del módulo).

## 5. Tablas

| Tabla | Base | Propósito | Campos clave |
|---|---|---|---|
| `sector_packs` | principal | Paquete global | code, sector, name, version, description, legal_basis, status, published_at |
| `sector_pack_items` | principal | Ítem del paquete | pack_id, item_type (peligro, criterio IPERC, plantilla de inspección, tema de capacitación, tipo de permiso, PETS modelo, requisito legal, obligación SUNAFIL, formato de registro, KPI), payload (jsonb), item_key, version |
| `company_sector_packs` | principal | Instalación en una empresa | company_id, pack_id, installed_version, installed_at, installed_by, item_map (jsonb item_key → id local), update_available |

Todas las tablas de empresa llevan las columnas estándar (`id` UUID v7, `company_id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `deleted_at`) salvo las inalterables, y quedan bajo RLS por `app.company_id`.

## 6. Pantallas

| ID | Pantalla | Propósito | Elementos principales |
|---|---|---|---|
| SCR-PSE-01 | Catálogo de paquetes | Explorar | Paquetes por sector con contenido y versión |
| SCR-PSE-02 | Instalar paquete | Asistente | Selección de ítems a instalar; vista previa; conflicto con contenido existente |
| SCR-PSE-03 | Actualizaciones | Novedades | Diferencias entre versión instalada y nueva por ítem; aplicar selectivamente |

## 7. Flujos principales

1. El Jefe SST elige el sector; el sistema muestra ítems.
2. Al instalar, cada ítem se copia como contenido propio de la empresa (editable) y se registra el mapa.
3. Cuando EscudoSST publica una nueva versión, se muestra la actualización disponible; la empresa elige qué ítems actualizar sin perder sus ediciones.

## 8. Reglas de negocio

- **RN-PSE-01.** Los paquetes son catálogo global sin `company_id`; al instalar se copian a la empresa.
- **RN-PSE-02.** La instalación es idempotente por `item_key`: reinstalar no duplica ítems.
- **RN-PSE-03.** Una actualización nunca sobrescribe ítems editados por la empresa sin confirmación; muestra diferencias.
- **RN-PSE-04.** Los criterios IPERC sectoriales se instalan como `risk_criteria` alternativos, no reemplazan los vigentes sin decisión del usuario.
- **RN-PSE-05.** Los valores legales del paquete se instalan como parámetros con vigencia y marca `to_confirm` cuando corresponda.
- **RN-PSE-06.** Desinstalar un paquete no borra contenido ya usado en registros; solo lo marca como no vinculado.
- **RN-PSE-07.** Requiere el complemento PSE activo; sin él, los ítems instalados permanecen en solo lectura.
- **RN-PSE-08.** Una empresa puede instalar más de un paquete; los ítems con el mismo `item_key` se instalan una vez.

## 9. Estados y transiciones

| Entidad | Estados | Transiciones |
|---|---|---|
| company_sector_packs | installed → update_available → updated; installed → uninstalled | |

## 10. Notificaciones y alertas

| Evento | Destinatario | Canal | Anticipación / momento (parámetro) |
|---|---|---|---|
| Nueva versión del paquete | Jefe SST | App, correo | Al publicar |

Los envíos se hacen mediante el módulo [NOT](M-NOT.md); cada regla es configurable por empresa y respeta las preferencias del usuario.

## 11. Reportes y exportaciones

- Inventario de contenido sectorial instalado y su versión.

## 12. Integración con otros módulos

**Consume:**

- Catálogo global de paquetes.

**Produce:**

- Contenido para [IPC](M-IPC.md), [INS](M-INS.md), [CAP](M-CAP.md), [PET](M-PET.md), [PTR](M-PTR.md), [MRL](M-MRL.md), [SUN](M-SUN.md), [EST](M-EST.md).

## 13. Criterios de aceptación clave

1. **Dado** un paquete instalado, **cuando** se reinstala, **entonces** no se duplican ítems.
2. **Dado** una plantilla instalada y editada, **cuando** llega actualización, **entonces** se muestran diferencias y no se sobrescribe sin confirmar.
3. **Dado** criterios mineros instalados, **cuando** se abre IPERC, **entonces** los criterios vigentes siguen siendo los anteriores hasta elegir.
4. **Dado** complemento PSE desactivado, **cuando** se consulta contenido instalado, **entonces** queda solo lectura.
5. **Dado** desinstalar con plantillas usadas en inspecciones, **cuando** se ejecuta, **entonces** las inspecciones conservan su plantilla.

## 14. Fuera de alcance y preguntas abiertas

**Fuera de alcance:**

- Asesoría sectorial personalizada.

**Preguntas abiertas:**

- ¿Qué sectores entran en la versión 1 del complemento?

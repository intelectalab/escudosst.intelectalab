# Especificaciones funcionales por módulo · EscudoSST

Una especificación por módulo del catálogo (`spec/catalogo.json`). Cada archivo sigue la misma estructura de 14 secciones: encabezado, objetivo, base normativa, roles y permisos, tablas, pantallas (`SCR-<CODE>-NN`), flujos, reglas de negocio (`RN-<CODE>-NN`), estados, notificaciones, reportes, integración, criterios de aceptación, fuera de alcance y preguntas abiertas.

Planes: E Esencial · P Profesional · C Corporativo · + Complemento · K Consultora · I Interno. Versión 1 = fases 0 a 3.

Verificación: `python3 tools/modulos/verificar_modulos.py`.

## Motor común de registros

Cada módulo de la empresa abre en `/m/<código>` con una pestaña por tabla de su §5: lista con búsqueda y filtros, y un formulario para crear, editar y archivar. No hay una pantalla escrita a mano por tabla:

- `database/tools/build_registry.py` lee `database/catalogs/model.json` (campos, tipos, opciones y referencias), `database/catalogs/labels_es.json` (nombres en español) y el §5 de cada ficha, y genera `backend/resources/registry/registry.json`. Si cambia el modelo o un nombre, se vuelve a ejecutar.
- La API `/api/v1/records/{módulo}/{tabla}` (`backend/app/Modules/REC`) valida contra ese catálogo, respeta el plan (feature flags), los permisos `{módulo}.{tabla}.{acción}` y RLS, cifra los campos `[CIFRADO EN APLICACIÓN]` y deja cada cambio en `audit_logs`.
- Las reglas propias de una tabla van en `backend/app/Modules/REC/Rules` (hoy: sedes, áreas, puestos y turnos de TEN; trabajadores y asignaciones de TRB).
- Los flujos especiales de cada ficha (cálculos, PDF, firmas, plazos y alertas) se agregan encima, módulo por módulo.

## Grupo 1. Núcleo de la plataforma

| Código | Número | Nombre | Plan | Fase | Especificación |
|---|---|---|---|---|---|
| `TEN` | 1.1 | Empresas, sedes y áreas | E · Esencial | 0 · Cimientos | [M-TEN.md](M-TEN.md) |
| `USR` | 1.2 | Usuarios, roles y permisos | E · Esencial | 0 · Cimientos | [M-USR.md](M-USR.md) |
| `TRB` | 1.3 | Trabajadores y legajo | E · Esencial | 0 · Cimientos | [M-TRB.md](M-TRB.md) |
| `DOC` | 1.4 | Gestión documental | E · Esencial | 0 · Cimientos | [M-DOC.md](M-DOC.md) |
| `NOT` | 1.5 | Alertas y notificaciones | E · Esencial | 0 · Cimientos | [M-NOT.md](M-NOT.md) |
| `DSH` | 1.6 | Dashboard e indicadores | E · Esencial | 0 · Cimientos | [M-DSH.md](M-DSH.md) |
| `FIR` | 1.7 | Firma electrónica de registros | P · Profesional | 0 · Cimientos | [M-FIR.md](M-FIR.md) |
| `AUD` | 1.8 | Bitácora de auditoría | E · Esencial | 0 · Cimientos | [M-AUD.md](M-AUD.md) |
| `SUS` | 1.9 | Suscripciones y facturación | I · Interno | 0 · Cimientos | [M-SUS.md](M-SUS.md) |
| `WEB` | 1.10 | Sitio web público y CMS | I · Interno | 1 · Plan Esencial | [M-WEB.md](M-WEB.md) |

## Grupo 2. Planificación del sistema de gestión

| Código | Número | Nombre | Plan | Fase | Especificación |
|---|---|---|---|---|---|
| `LBA` | 2.1 | Diagnóstico de línea base | E · Esencial | 1 · Plan Esencial | [M-LBA.md](M-LBA.md) |
| `POL` | 2.2 | Política y objetivos SST | E · Esencial | 1 · Plan Esencial | [M-POL.md](M-POL.md) |
| `MRL` | 2.3 | Matriz de requisitos legales | P · Profesional | 2 · Plan Profesional | [M-MRL.md](M-MRL.md) |
| `PLA` | 2.4 | Plan Anual SST | E · Esencial | 1 · Plan Esencial | [M-PLA.md](M-PLA.md) |
| `PAS` | 2.5 | Programa Anual SST (PASST) | E · Esencial | 1 · Plan Esencial | [M-PAS.md](M-PAS.md) |
| `PCA` | 2.6 | Programa anual de capacitación | E · Esencial | 1 · Plan Esencial | [M-PCA.md](M-PCA.md) |
| `RIS` | 2.7 | RISST | P · Profesional | 2 · Plan Profesional | [M-RIS.md](M-RIS.md) |
| `COM` | 2.8 | Comité o Supervisor SST | E · Esencial | 1 · Plan Esencial | [M-COM.md](M-COM.md) |
| `PRE` | 2.9 | Presupuesto SST | C · Corporativo | 4 · Plan Corporativo | [M-PRE.md](M-PRE.md) |

## Grupo 3. Gestión de riesgos

| Código | Número | Nombre | Plan | Fase | Especificación |
|---|---|---|---|---|---|
| `IPC` | 3.1 | Matriz IPERC | E · Esencial | 1 · Plan Esencial | [M-IPC.md](M-IPC.md) |
| `MAP` | 3.2 | Mapa de riesgos | P · Profesional | 2 · Plan Profesional | [M-MAP.md](M-MAP.md) |
| `MAG` | 3.3 | Monitoreo de agentes | P · Profesional | 2 · Plan Profesional | [M-MAG.md](M-MAG.md) |
| `PSI` | 3.4 | Riesgos ergonómicos y psicosociales | C · Corporativo | 4 · Plan Corporativo | [M-PSI.md](M-PSI.md) |
| `PET` | 3.5 | PETS y procedimientos | C · Corporativo | 4 · Plan Corporativo | [M-PET.md](M-PET.md) |
| `MOC` | 3.6 | Gestión del cambio | + · Complemento | 5 · Complementos restantes | [M-MOC.md](M-MOC.md) |

## Grupo 4. Operación en campo

| Código | Número | Nombre | Plan | Fase | Especificación |
|---|---|---|---|---|---|
| `INS` | 4.1 | Inspecciones | E · Esencial | 1 · Plan Esencial | [M-INS.md](M-INS.md) |
| `ACT` | 4.2 | Actos y condiciones subestándar | E · Esencial | 1 · Plan Esencial | [M-ACT.md](M-ACT.md) |
| `CH5` | 4.3 | Charlas de 5 minutos | P · Profesional | 2 · Plan Profesional | [M-CH5.md](M-CH5.md) |
| `ATS` | 4.4 | ATS | P · Profesional | 2 · Plan Profesional | [M-ATS.md](M-ATS.md) |
| `PTR` | 4.5 | PETAR y permisos de alto riesgo | C · Corporativo | 4 · Plan Corporativo | [M-PTR.md](M-PTR.md) |
| `OBS` | 4.6 | Observación de conducta segura | C · Corporativo | 4 · Plan Corporativo | [M-OBS.md](M-OBS.md) |
| `EPP` | 4.7 | EPP | E · Esencial | 1 · Plan Esencial | [M-EPP.md](M-EPP.md) |
| `EQE` | 4.8 | Equipos de emergencia y botiquines | P · Profesional | 2 · Plan Profesional | [M-EQE.md](M-EQE.md) |
| `SIM` | 4.9 | Simulacros y brigadas | P · Profesional | 2 · Plan Profesional | [M-SIM.md](M-SIM.md) |

## Grupo 5. Personas y salud ocupacional

| Código | Número | Nombre | Plan | Fase | Especificación |
|---|---|---|---|---|---|
| `CAP` | 5.1 | Capacitaciones e inducciones | E · Esencial | 1 · Plan Esencial | [M-CAP.md](M-CAP.md) |
| `AUL` | 5.2 | Aula virtual SST | P · Profesional | 2 · Plan Profesional | [M-AUL.md](M-AUL.md) |
| `FOT` | 5.3 | Fotocheck SST con QR | P · Profesional | 2 · Plan Profesional | [M-FOT.md](M-FOT.md) |
| `EMO` | 5.4 | EMOs y aptitud | P · Profesional | 2 · Plan Profesional | [M-EMO.md](M-EMO.md) |
| `VMD` | 5.5 | Vigilancia médica | C · Corporativo | 4 · Plan Corporativo | [M-VMD.md](M-VMD.md) |
| `DES` | 5.6 | Descansos médicos y ausentismo | C · Corporativo | 4 · Plan Corporativo | [M-DES.md](M-DES.md) |
| `VUL` | 5.7 | Grupos vulnerables | C · Corporativo | 4 · Plan Corporativo | [M-VUL.md](M-VUL.md) |
| `VTA` | 5.8 | Venta de cursos y pagos | P · Profesional | 2 · Plan Profesional | [M-VTA.md](M-VTA.md) |

## Grupo 6. Eventos y mejora continua

| Código | Número | Nombre | Plan | Fase | Especificación |
|---|---|---|---|---|---|
| `ACC` | 6.1 | Accidentes, incidentes y enfermedades | E · Esencial | 1 · Plan Esencial | [M-ACC.md](M-ACC.md) |
| `INV` | 6.2 | Investigación de causas | P · Profesional | 2 · Plan Profesional | [M-INV.md](M-INV.md) |
| `ACR` | 6.3 | Acciones correctivas | E · Esencial | 1 · Plan Esencial | [M-ACR.md](M-ACR.md) |
| `AUI` | 6.4 | Auditorías | P · Profesional | 2 · Plan Profesional | [M-AUI.md](M-AUI.md) |
| `EST` | 6.5 | Estadísticas SST | E · Esencial | 1 · Plan Esencial | [M-EST.md](M-EST.md) |
| `RVD` | 6.6 | Revisión por la dirección | C · Corporativo | 4 · Plan Corporativo | [M-RVD.md](M-RVD.md) |
| `REG` | 6.7 | Registros obligatorios | E · Esencial | 1 · Plan Esencial | [M-REG.md](M-REG.md) |

## Grupo 7. Contratistas y terceros

| Código | Número | Nombre | Plan | Fase | Especificación |
|---|---|---|---|---|---|
| `CTR` | 7.1 | Acreditación de contratistas | C · Corporativo | 4 · Plan Corporativo | [M-CTR.md](M-CTR.md) |
| `SCT` | 7.2 | Control de SCTR | P · Profesional | 2 · Plan Profesional | [M-SCT.md](M-SCT.md) |
| `ING` | 7.3 | Ingreso de terceros por QR | C · Corporativo | 4 · Plan Corporativo | [M-ING.md](M-ING.md) |
| `HOM` | 7.4 | Homologación de proveedores | + · Complemento | 5 · Complementos restantes | [M-HOM.md](M-HOM.md) |

## Grupo 8. Diferenciadores e integraciones

| Código | Número | Nombre | Plan | Fase | Especificación |
|---|---|---|---|---|---|
| `SUN` | 8.1 | Modo Inspección SUNAFIL | P · Profesional | 2 · Plan Profesional | [M-SUN.md](M-SUN.md) |
| `OFF` | 8.2 | App móvil offline | + · Complemento | 3 · Diferenciadores (cierra la versión 1) | [M-OFF.md](M-OFF.md) |
| `IAA` | 8.3 | Asistente IA | + · Complemento | 3 · Diferenciadores (cierra la versión 1) | [M-IAA.md](M-IAA.md) |
| `CON` | 8.4 | Modo consultora y marca blanca | K · Consultora | 3 · Diferenciadores (cierra la versión 1) | [M-CON.md](M-CON.md) |
| `PSE` | 8.5 | Paquetes sectoriales | + · Complemento | 3 · Diferenciadores (cierra la versión 1) | [M-PSE.md](M-PSE.md) |
| `ISO` | 8.6 | Sistema integrado ISO | + · Complemento | 5 · Complementos restantes | [M-ISO.md](M-ISO.md) |
| `AMB` | 8.7 | Medio ambiente (SSOMA) | + · Complemento | 5 · Complementos restantes | [M-AMB.md](M-AMB.md) |
| `API` | 8.8 | API e integraciones | C · Corporativo | 4 · Plan Corporativo | [M-API.md](M-API.md) |

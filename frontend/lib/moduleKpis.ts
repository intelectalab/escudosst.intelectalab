// Tarjetas de indicadores arriba de cada módulo, como en el prototipo (4 tarjetas con número grande de color).
// Cada tarjeta cuenta registros de una lista con filtros (la misma API de la lista); «of» la vuelve porcentaje.
// Al tocarla se abre la lista ya filtrada. Fechas relativas: today, +30d, -30d, monthStart, yearStart.

export type Kpi = {
  label: string;
  table: string;
  filters?: Record<string, string>;
  of?: { table: string; filters?: Record<string, string> };
  color: "brand" | "ok" | "amber" | "bad" | "orange" | "teal";
  note?: string;
};

export const MODULE_KPIS: Record<string, Kpi[]> = {
  TRB: [
    { label: "Trabajadores activos", table: "workers", filters: { status: "active,on_leave" }, color: "brand" },
    { label: "Ingresos del mes", table: "workers", filters: { hire_on__gte: "monthStart" }, color: "ok" },
    { label: "De licencia", table: "workers", filters: { status: "on_leave" }, color: "amber" },
    { label: "Cesados", table: "workers", filters: { status: "terminated" }, color: "teal" },
  ],
  DOC: [
    { label: "Documentos vigentes", table: "documents", filters: { status: "active" }, color: "brand" },
    { label: "Por revisar (30 días)", table: "documents", filters: { status: "active", next_review_on__lte: "+30d" }, color: "amber" },
    { label: "En borrador", table: "documents", filters: { status: "draft" }, color: "orange" },
    { label: "Obsoletos", table: "documents", filters: { status: "obsolete" }, color: "teal" },
  ],
  FIR: [
    { label: "Solicitudes abiertas", table: "signature_requests", filters: { status: "pending" }, color: "brand" },
    { label: "Firmadas", table: "signature_requests", filters: { status: "signed" }, color: "ok" },
    { label: "Rechazadas", table: "signature_requests", filters: { status: "declined" }, color: "bad" },
    { label: "Vencidas", table: "signature_requests", filters: { status: "expired" }, color: "amber" },
  ],
  MRL: [
    { label: "Requisitos aplicables", table: "legal_requirements", filters: { status: "active" }, color: "brand" },
    { label: "Evaluación por vencer (30 días)", table: "legal_requirements", filters: { status: "active", next_evaluation_on__lte: "+30d" }, color: "amber" },
    { label: "No aplican", table: "legal_requirements", filters: { status: "not_applicable" }, color: "teal" },
    { label: "Derogados", table: "legal_requirements", filters: { status: "repealed" }, color: "orange" },
  ],
  RIS: [
    { label: "Cargos firmados", table: "risst_deliveries", filters: { status: "signed" }, color: "ok" },
    { label: "Entregados sin firma", table: "risst_deliveries", filters: { status: "delivered" }, color: "amber" },
    { label: "Pendientes", table: "risst_deliveries", filters: { status: "pending" }, color: "bad" },
    { label: "Trabajadores activos", table: "workers", filters: { status: "active,on_leave" }, color: "brand" },
  ],
  MAG: [
    { label: "Puntos de monitoreo", table: "monitoring_points", color: "brand" },
    { label: "Sobre el límite", table: "measurements", filters: { result: "exceeds_limit" }, color: "bad" },
    { label: "Mediciones", table: "measurements", color: "teal" },
    { label: "Campañas", table: "monitoring_campaigns", color: "orange" },
  ],
  INS: [
    { label: "Programadas", table: "inspections", filters: { status: "scheduled,overdue" }, color: "brand" },
    { label: "Ejecutadas", table: "inspections", filters: { status: "completed,closed" }, color: "ok" },
    { label: "Hallazgos abiertos", table: "findings", filters: { status: "open,action_assigned" }, color: "orange" },
    { label: "Plantillas activas", table: "inspection_templates", filters: { status: "active" }, color: "teal" },
  ],
  ACT: [
    { label: "Reportes del mes", table: "substandard_reports", filters: { occurred_at__gte: "monthStart" }, color: "brand" },
    { label: "Sin revisar", table: "substandard_reports", filters: { status: "received" }, color: "bad" },
    { label: "Con acción", table: "substandard_reports", filters: { status: "action_assigned" }, color: "amber" },
    { label: "Cerrados", table: "substandard_reports", filters: { status: "closed" }, color: "ok" },
  ],
  CH5: [
    { label: "Charlas del mes", table: "safety_talks", filters: { talk_on__gte: "monthStart" }, color: "brand" },
    { label: "Realizadas", table: "safety_talks", filters: { status: "held" }, color: "ok" },
    { label: "Programadas", table: "safety_talks", filters: { status: "scheduled" }, color: "amber" },
    { label: "Asistentes registrados", table: "talk_attendances", color: "teal" },
  ],
  ATS: [
    { label: "En ejecución", table: "ats_forms", filters: { status: "in_execution" }, color: "brand" },
    { label: "Firmados", table: "ats_forms", filters: { status: "signed" }, color: "ok" },
    { label: "En borrador", table: "ats_forms", filters: { status: "draft" }, color: "amber" },
    { label: "Cerrados", table: "ats_forms", filters: { status: "closed" }, color: "teal" },
  ],
  PTR: [
    { label: "Vigentes ahora", table: "work_permits", filters: { status: "active" }, color: "ok" },
    { label: "Por aprobar", table: "work_permits", filters: { status: "pending_approval" }, color: "amber" },
    { label: "Suspendidos", table: "work_permits", filters: { status: "suspended" }, color: "bad" },
    { label: "Cerrados", table: "work_permits", filters: { status: "closed" }, color: "teal" },
  ],
  OBS: [
    { label: "Observaciones", table: "behavior_observations", color: "brand" },
    { label: "Del mes", table: "behavior_observations", filters: { observed_on__gte: "monthStart" }, color: "teal" },
    { label: "Con conductas a mejorar", table: "behavior_observations", filters: { at_risk_count__gt: "0" }, color: "orange" },
    { label: "Revisadas", table: "behavior_observations", filters: { status: "reviewed" }, color: "ok" },
  ],
  EPP: [
    { label: "Entregas del mes", table: "ppe_deliveries", filters: { delivered_at__gte: "monthStart" }, color: "brand" },
    { label: "Por reponer (30 días)", table: "ppe_deliveries", filters: { status: "delivered", expires_on__lte: "+30d" }, color: "amber" },
    { label: "EPP en catálogo", table: "ppe_items", filters: { is_active: "true" }, color: "teal" },
    { label: "Exigencias por puesto", table: "ppe_position_requirements", color: "orange" },
  ],
  AMB: [
    { label: "Aspectos vigentes", table: "environmental_aspects", filters: { status: "active" }, color: "brand" },
    { label: "Aspectos significativos", table: "environmental_aspects", filters: { status: "active", is_significant: "true" }, color: "bad" },
    { label: "Residuos peligrosos almacenados", table: "waste_records", filters: { waste_type: "hazardous,biocontaminated", status: "stored" }, color: "amber" },
    { label: "Residuos del mes", table: "waste_records", filters: { generated_on__gte: "monthStart" }, color: "teal" },
  ],
  ISO: [
    { label: "Cláusulas con evidencia", table: "iso_evidences", filters: { status: "implemented,verified" }, color: "ok" },
    { label: "En proceso", table: "iso_evidences", filters: { status: "in_progress" }, color: "amber" },
    { label: "Sin evidencia", table: "iso_evidences", filters: { status: "not_started" }, color: "bad" },
    { label: "No aplican", table: "iso_evidences", filters: { status: "not_applicable" }, color: "teal" },
  ],
  EQE: [
    { label: "Equipos operativos", table: "emergency_equipment", filters: { status: "operational" }, color: "ok" },
    { label: "Por mantener (30 días)", table: "emergency_equipment", filters: { next_maintenance_on__lte: "+30d" }, color: "amber" },
    { label: "Fuera de servicio", table: "emergency_equipment", filters: { status: "needs_maintenance,out_of_service" }, color: "bad" },
    { label: "Botiquines", table: "first_aid_kits", color: "teal" },
  ],
  SIM: [
    { label: "Programados", table: "drills", filters: { status: "planned" }, color: "brand" },
    { label: "Ejecutados", table: "drills", filters: { status: "executed,evaluated" }, color: "ok" },
    { label: "Brigadas", table: "brigades", color: "teal" },
    { label: "Brigadistas", table: "brigade_members", color: "orange" },
  ],
  EMO: [
    { label: "Aptitudes vigentes", table: "fitness_status", filters: { is_current: "true", status: "fit,fit_with_restrictions" }, color: "ok" },
    { label: "Por vencer (30 días)", table: "fitness_status", filters: { is_current: "true", valid_until__lte: "+30d" }, color: "amber" },
    { label: "Exámenes programados", table: "medical_exam_orders", filters: { status: "scheduled" }, color: "brand" },
    { label: "Aptos con restricciones", table: "fitness_status", filters: { is_current: "true", status: "fit_with_restrictions" }, color: "orange" },
  ],
  DES: [
    { label: "Descansos del mes", table: "absences", filters: { starts_on__gte: "monthStart" }, color: "brand" },
    { label: "Activos hoy", table: "absences", filters: { starts_on__lte: "today", ends_on__gte: "today" }, color: "amber" },
    { label: "Por accidente de trabajo", table: "absences", filters: { is_work_related: "true" }, color: "bad" },
    { label: "Cerrados", table: "absences", filters: { status: "closed" }, color: "teal" },
  ],
  ACC: [
    { label: "Eventos del año", table: "sst_events", filters: { occurred_at__gte: "yearStart" }, color: "brand" },
    { label: "Por notificar", table: "sst_events", filters: { status: "reported,registered" }, color: "bad" },
    { label: "En investigación", table: "sst_events", filters: { status: "under_investigation" }, color: "amber" },
    { label: "Cerrados", table: "sst_events", filters: { status: "closed" }, color: "ok" },
  ],
  INV: [
    { label: "Abiertas", table: "investigations", filters: { status: "open,analysis,committee_review" }, color: "amber" },
    { label: "Vencidas", table: "investigations", filters: { status: "open,analysis,committee_review", due_on__lt: "today" }, color: "bad" },
    { label: "Con acciones en curso", table: "investigations", filters: { status: "approved,actions_in_progress" }, color: "brand" },
    { label: "Cerradas", table: "investigations", filters: { status: "closed" }, color: "ok" },
  ],
  ACR: [
    { label: "Abiertas", table: "corrective_actions", filters: { status: "open,in_progress" }, color: "brand" },
    { label: "Vencidas", table: "corrective_actions", filters: { status: "open,in_progress,overdue", due_on__lt: "today" }, color: "bad" },
    { label: "Por verificar", table: "corrective_actions", filters: { status: "pending_verification" }, color: "amber" },
    { label: "Verificadas", table: "corrective_actions", filters: { status: "verified" }, color: "ok" },
  ],
  CTR: [
    { label: "Contratistas activos", table: "contractors", filters: { status: "active" }, color: "brand" },
    { label: "Documentos por revisar", table: "contractor_documents", filters: { status: "submitted" }, color: "amber" },
    { label: "Documentos observados", table: "contractor_documents", filters: { status: "observed,rejected,expired" }, color: "bad" },
    { label: "Trabajadores de terceros", table: "contractor_workers", color: "teal" },
  ],
  SCT: [
    { label: "Pólizas vigentes", table: "sctr_policies", filters: { status: "active" }, color: "ok" },
    { label: "Trabajadores cubiertos", table: "sctr_coverages", filters: { status: "covered" }, color: "brand" },
    { label: "Por vencer (30 días)", table: "sctr_coverages", filters: { status: "covered", covered_until__lte: "+30d" }, color: "amber" },
    { label: "Sin cobertura", table: "sctr_coverages", filters: { status: "not_covered,expired" }, color: "bad" },
  ],
  PET: [
    { label: "PETS vigentes", table: "procedures", filters: { status: "active" }, color: "brand" },
    { label: "Tareas críticas", table: "procedures", filters: { is_critical_task: "true" }, color: "orange" },
    { label: "Por revisar (30 días)", table: "procedures", filters: { status: "active", next_review_on__lte: "+30d" }, color: "amber" },
    { label: "Archivados", table: "procedures", filters: { status: "archived" }, color: "teal" },
  ],
  MOC: [
    { label: "En revisión", table: "change_requests", filters: { status: "submitted,under_review" }, color: "amber" },
    { label: "Aprobados", table: "change_requests", filters: { status: "approved" }, color: "brand" },
    { label: "Implementados", table: "change_requests", filters: { status: "implemented,closed" }, color: "ok" },
    { label: "Rechazados", table: "change_requests", filters: { status: "rejected" }, color: "bad" },
  ],
  AUI: [
    { label: "Programadas", table: "audits", filters: { status: "planned" }, color: "brand" },
    { label: "En curso", table: "audits", filters: { status: "in_progress,report_draft" }, color: "amber" },
    { label: "Completadas", table: "audits", filters: { status: "completed,closed" }, color: "ok" },
    { label: "Hallazgos registrados", table: "audit_findings", color: "orange" },
  ],
  VTA: [
    { label: "Pedidos pagados", table: "orders", filters: { status: "paid" }, color: "ok" },
    { label: "Pendientes de pago", table: "orders", filters: { status: "pending" }, color: "amber" },
    { label: "Cursos a la venta", table: "course_offers", color: "brand" },
    { label: "Cupos de empresas", table: "course_licenses", color: "teal" },
  ],
};

/** Convierte las fechas relativas a dd en formato AAAA-MM-DD (hora de Lima). */
export function resolveDate(v: string): string {
  const now = new Date(new Date().toLocaleString("en-US", { timeZone: "America/Lima" }));
  const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  const m = v.match(/^([+-])(\d+)d$/);
  if (m) {
    const d = new Date(now);
    d.setDate(d.getDate() + (m[1] === "+" ? 1 : -1) * Number(m[2]));
    return iso(d);
  }
  if (v === "monthStart") return iso(new Date(now.getFullYear(), now.getMonth(), 1));
  if (v === "yearStart") return iso(new Date(now.getFullYear(), 0, 1));
  return v;
}

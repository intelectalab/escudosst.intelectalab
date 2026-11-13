"use client";

// Legajo SST del trabajador en PDF con índice (M-TRB §11): datos, puestos, capacitaciones, EPP, aptitud médica (solo estado y
// restricción laboral), fotocheck, SCTR, RISST y documentos firmados, charlas y documentos del legajo.

import { Facts, Section, Sheet, ReportLoading, cell, fmtD, fmtDT, useReport, type Company } from "./FieldReports";

type Legajo = {
  company: Company;
  worker: { full_name: string; document: string; employee_code: string | null; position: string | null; area: string | null; site: string | null; hire_on: string | null;
    termination_on: string | null; employment_type: string | null; status: string };
  assignments: { starts_on: string; ends_on: string | null; change_reason: string | null; position: string | null; area: string | null; site: string | null }[];
  trainings: { course_title: string; certificate_code: string; issued_on: string; expires_on: string | null; hours: number | null; status: string }[];
  ppe: { name: string; quantity: number; size: string | null; delivered_at: string; expires_on: string | null; status: string; signed: boolean }[];
  fitness: { status: string; valid_from: string | null; valid_until: string | null; work_restrictions: string | null } | null;
  credentials: { credential_number: string; type: string | null; status: string; valid_from: string | null; expires_on: string | null }[];
  sctr: { covered_from: string; covered_until: string; constancy_number: string | null; insurer_name: string | null; coverage_kind: string }[];
  risst: { title: string; version_number: number; delivered_on: string | null; method: string | null; status: string }[];
  acknowledgements: { code: string; title: string; version_number: number; acknowledged_at: string; method: string | null }[];
  talks: { topic: string; talk_on: string | null; signed: boolean }[];
  documents: { document_kind: string; title: string; issued_on: string | null; expires_on: string | null; status: string }[];
  generated_at: string;
};
const W_STATUS: Record<string, string> = { active: "Activo", on_leave: "En licencia", terminated: "Cesado", suspended: "Suspendido" };
const CERT: Record<string, string> = { validated: "Válido", pending_validation: "Por validar", expired: "Vencido" };
const FIT: Record<string, string> = { fit: "Apto", fit_with_restrictions: "Apto con restricciones", unfit: "No apto", pending: "Pendiente", expired: "Vencido" };
const CRED: Record<string, string> = { active: "Habilitado", blocked: "No habilitado", expired: "Vencido", revoked: "Revocado", draft: "Borrador", issued: "Emitido" };
const DOCST: Record<string, string> = { valid: "Vigente", expiring: "Por vencer", expired: "Vencido", replaced: "Reemplazado" };
const RIS: Record<string, string> = { signed: "Firmado", delivered: "Entregado", pending: "Pendiente" };

function Rows<T>({ head, rows, render, empty }: { head: string[]; rows: T[]; render: (r: T) => React.ReactNode[]; empty: string }) {
  if (!rows.length) return <p className="text-sm text-gray-600">{empty}</p>;
  return (
    <table className="w-full border-collapse text-sm">
      <thead><tr className="bg-gray-100">{head.map((h) => <th key={h} className={cell}>{h}</th>)}</tr></thead>
      <tbody>{rows.map((r, i) => <tr key={i} style={{ breakInside: "avoid" }}>{render(r).map((c, k) => <td key={k} className={cell}>{c}</td>)}</tr>)}</tbody>
    </table>
  );
}

export function LegajoReport({ id }: { id: string }) {
  const { data: d, error } = useReport<Legajo>(`/trb/workers/${id}/legajo`);
  if (!d) return <ReportLoading error={error} />;
  const w = d.worker;
  const sections: [string, React.ReactNode][] = [
    ["Datos del trabajador", <Facts key="w" rows={[["Nombre", w.full_name], ["Documento", w.document], ["Código", w.employee_code], ["Puesto", w.position],
      ["Área / sede", [w.area, w.site].filter(Boolean).join(" · ") || "—"], ["Ingreso", fmtD(w.hire_on)], ["Cese", w.termination_on ? fmtD(w.termination_on) : "—"],
      ["Estado", W_STATUS[w.status] ?? w.status]]} />],
    ["Historial de puestos", <Rows key="a" head={["Desde", "Hasta", "Puesto", "Área / sede", "Motivo"]} rows={d.assignments} empty="Sin asignaciones registradas."
      render={(r) => [fmtD(r.starts_on), r.ends_on ? fmtD(r.ends_on) : "Vigente", r.position ?? "—", [r.area, r.site].filter(Boolean).join(" · ") || "—", r.change_reason ?? "—"]} />],
    ["Inducción y capacitaciones", <Rows key="t" head={["Capacitación", "Certificado", "Emitido", "Vence", "Horas", "Estado"]} rows={d.trainings} empty="Sin certificados."
      render={(r) => [r.course_title, r.certificate_code, fmtD(r.issued_on), r.expires_on ? fmtD(r.expires_on) : "Sin vencimiento", r.hours ?? "—", CERT[r.status] ?? r.status]} />],
    ["Entregas de EPP", <Rows key="p" head={["EPP", "Cantidad", "Entregado", "Reposición", "Firmado"]} rows={d.ppe} empty="Sin entregas de EPP."
      render={(r) => [r.size ? `${r.name} (talla ${r.size})` : r.name, r.quantity, fmtD(r.delivered_at), r.expires_on ? fmtD(r.expires_on) : "—", r.signed ? "Sí" : "No"]} />],
    ["Aptitud médica", d.fitness
      ? <Facts key="f" rows={[["Estado", FIT[d.fitness.status] ?? d.fitness.status], ["Vigencia", `${fmtD(d.fitness.valid_from)} a ${fmtD(d.fitness.valid_until)}`],
        ["Restricción laboral", d.fitness.work_restrictions ?? "—"]]} />
      : <p key="f" className="text-sm text-gray-600">Sin aptitud registrada.</p>],
    ["Fotocheck", <Rows key="c" head={["Número", "Tipo", "Estado", "Desde", "Vence"]} rows={d.credentials} empty="Sin fotocheck."
      render={(r) => [r.credential_number, r.type ?? "—", CRED[r.status] ?? r.status, fmtD(r.valid_from), fmtD(r.expires_on)]} />],
    ["SCTR", <Rows key="s" head={["Cobertura", "Aseguradora", "Constancia", "Desde", "Hasta"]} rows={d.sctr} empty="Sin coberturas de SCTR."
      render={(r) => [r.coverage_kind === "health" ? "Salud" : "Pensión", r.insurer_name ?? "—", r.constancy_number ?? "—", fmtD(r.covered_from), fmtD(r.covered_until)]} />],
    ["RISST y documentos firmados", <div key="r" className="space-y-2">
      <Rows head={["RISST", "Versión", "Entregado", "Estado"]} rows={d.risst} empty="Sin entregas del RISST."
        render={(r) => [r.title, r.version_number, fmtD(r.delivered_on), RIS[r.status] ?? r.status]} />
      <Rows head={["Documento", "Versión", "Leído y firmado"]} rows={d.acknowledgements} empty="Sin cargos de documentos."
        render={(r) => [`${r.code} · ${r.title}`, r.version_number, fmtDT(r.acknowledged_at)]} />
    </div>],
    ["Charlas de 5 minutos", <Rows key="k" head={["Tema", "Fecha", "Firmó"]} rows={d.talks} empty="Sin charlas registradas."
      render={(r) => [r.topic, fmtD(r.talk_on), r.signed ? "Sí" : "No"]} />],
    ["Documentos del legajo", <Rows key="d" head={["Tipo", "Documento", "Emitido", "Vence", "Estado"]} rows={d.documents} empty="Sin documentos en el legajo."
      render={(r) => [r.document_kind, r.title, fmtD(r.issued_on), r.expires_on ? fmtD(r.expires_on) : "—", DOCST[r.status] ?? r.status]} />],
  ];
  return (
    <Sheet company={d.company} title="Legajo SST del trabajador" sub={`${w.full_name} · Generado el ${fmtDT(d.generated_at)}`}>
      <section className="space-y-1 text-sm">
        <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">Índice</h2>
        <ol className="list-decimal pl-6">{sections.map(([t]) => <li key={t}>{t}</li>)}</ol>
      </section>
      {sections.map(([t, body], i) => <Section key={t} n={i + 1} title={t}>{body}</Section>)}
    </Sheet>
  );
}

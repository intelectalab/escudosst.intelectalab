"use client";

// Informes imprimibles de registros (§11 de las fichas), con el formato de los demás informes:
//  - M-RVD: informe de revisión por la dirección con entradas, conclusiones, salidas y firmas.
//  - M-FIR: constancia de firma con huellas, fecha y hora de Lima, método y evidencia.
//  - M-COM: acta de convocatoria a elecciones y acta de instalación del comité, con firmas; informe anual del comité (§11, para RVD).
//  - M-EPP: registro de entrega de EPP del trabajador con sus firmas (constancia).
//  - M-DOC: historial de versiones con aprobadores y constancias de cargo de la versión vigente.
// Se guardan como PDF desde el diálogo de impresión del navegador.

import { useState } from "react";
import { downloadFile } from "@/lib/records";
import { cell, type Company, Facts, fmtD, fmtDT, ReportLoading, Section, Sheet, useReport } from "./FieldReports";

type Sig = { signer_name: string; signed_at: string; method: string; image: string | null };
const METHOD: Record<string, string> = { pin: "Contraseña", drawn: "Trazo", password: "Contraseña", otp: "Código de un solo uso", app_signature: "App", assisted_in_person: "Asistida en persona" };
const yes = (v: boolean | null | undefined) => (v ? "Sí" : "No");

function Firmas({ list, empty = "Sin firmas registradas." }: { list: Sig[]; empty?: string }) {
  if (!list.length) return <p className="text-sm text-gray-600">{empty}</p>;
  return (
    <div className="grid grid-cols-2 gap-3">
      {list.map((s, i) => (
        <div key={i} className="rounded border border-gray-300 p-2 text-sm" style={{ breakInside: "avoid" }}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          {s.image ? <img src={s.image} alt={`Firma de ${s.signer_name}`} className="h-14 object-contain" /> : <div className="h-14" />}
          <p className="border-t border-gray-300 pt-1 font-semibold">{s.signer_name}</p>
          <p className="text-xs text-gray-600">{fmtDT(s.signed_at)} · {METHOD[s.method] ?? s.method}</p>
        </div>
      ))}
    </div>
  );
}

function Table({ head, rows, empty }: { head: string[]; rows: React.ReactNode[][]; empty: string }) {
  return (
    <table className="w-full border-collapse text-sm">
      <thead><tr>{head.map((h) => <th key={h} className={`${cell} bg-gray-50 text-left`}>{h}</th>)}</tr></thead>
      <tbody>
        {rows.length === 0 ? <tr><td className={cell} colSpan={head.length}>{empty}</td></tr>
          : rows.map((r, i) => <tr key={i} style={{ breakInside: "avoid" }}>{r.map((c, j) => <td key={j} className={cell}>{c ?? "—"}</td>)}</tr>)}
      </tbody>
    </table>
  );
}

/** Muestra un valor guardado como JSON (lista, objeto o texto) como texto legible. */
function Value({ v }: { v: unknown }) {
  if (v === null || v === undefined || v === "") return <>—</>;
  if (Array.isArray(v)) return <ul className="list-disc pl-5">{v.map((x, i) => <li key={i}><Value v={x} /></li>)}</ul>;
  if (typeof v === "object") {
    return (
      <ul className="list-disc pl-5">
        {Object.entries(v as Record<string, unknown>).map(([k, x]) => <li key={k}><b>{k.replaceAll("_", " ")}:</b> <Value v={x} /></li>)}
      </ul>
    );
  }
  return <>{String(v)}</>;
}

// ── M-RVD ────────────────────────────────────────────────────────────────────────────────────────────────────────────
type Review = {
  company: Company;
  review: { year: number; title: string; review_on: string | null; status: string; conclusions: string | null; policy_changes_needed: boolean | null;
    approved_at: string | null; approved_by: string | null; inputs: unknown; participants: unknown };
  outputs: { output_type: string; description: string; due_on: string | null; status: string; responsible: string | null; action_code: string | null; action_status: string | null }[];
  signatures: Sig[];
};
const RVD_STATUS: Record<string, string> = { planned: "Planificada", in_progress: "En curso", completed: "Completada", closed: "Cerrada" };
const OUTPUT: Record<string, string> = { decision: "Decisión", improvement: "Mejora", resource: "Recursos", objective_change: "Cambio de objetivos", policy_change: "Cambio de política", other: "Otro" };
const OUT_STATUS: Record<string, string> = { open: "Abierta", in_progress: "En curso", done: "Cumplida", cancelled: "Anulada" };

export function ReviewReport({ id }: { id: string }) {
  const { data, error } = useReport<Review>(`/reports/management_reviews/${id}`);
  if (!data) return <ReportLoading error={error} />;
  const r = data.review;
  return (
    <Sheet company={data.company} title="Informe de revisión por la dirección" sub={r.title.includes(String(r.year)) ? r.title : `${r.title} · ${r.year}`} draft={data.signatures.length ? null : "SIN FIRMA DE GERENCIA"}>
      <Section n={1} title="Datos de la revisión">
        <Facts rows={[["Fecha de la revisión", fmtD(r.review_on)], ["Estado", RVD_STATUS[r.status] ?? r.status], ["Requiere cambios en la política", yes(r.policy_changes_needed)],
          ["Aprobada por", r.approved_by ? `${r.approved_by} · ${fmtDT(r.approved_at)}` : "—"]]} />
      </Section>
      <Section n={2} title="Participantes"><div className="text-sm"><Value v={r.participants} /></div></Section>
      <Section n={3} title="Entradas de la revisión"><div className="text-sm"><Value v={r.inputs} /></div></Section>
      <Section n={4} title="Conclusiones"><p className="whitespace-pre-line text-sm">{r.conclusions ?? "—"}</p></Section>
      <Section n={5} title="Salidas: decisiones y acciones">
        <Table head={["Tipo", "Descripción", "Responsable", "Fecha límite", "Acción", "Estado"]} empty="Sin salidas registradas."
          rows={data.outputs.map((o) => [OUTPUT[o.output_type] ?? o.output_type, o.description, o.responsible, fmtD(o.due_on), o.action_code, OUT_STATUS[o.status] ?? o.status])} />
      </Section>
      <Section n={6} title="Firmas"><Firmas list={data.signatures} /></Section>
    </Sheet>
  );
}

// ── M-FIR ────────────────────────────────────────────────────────────────────────────────────────────────────────────
type SignatureCert = {
  company: Company;
  signature: { id: string; signer_name: string; signer_document_masked: string | null; method: string; role: string | null; signed_at_lima: string; requested_at_lima: string | null;
    requested_by: string | null; document_hash: string; evidence_hash: string; hash_scope: string | null; ip: string | null; user_agent: string | null; otp_channel: string | null;
    geo: [number, number] | null; subject: { table: string; id: string; title: string; module: string }; image: string | null;
    void: { reason: string; voided_at: string; voided_by_name: string } | null };
};
const ROLE: Record<string, string> = { attendee: "Asistente", responsible: "Responsable", approver: "Aprobador", reviewer: "Revisor", worker: "Trabajador", witness: "Testigo",
  committee_member: "Miembro del comité", doctor: "Médico" };

export function SignatureCertificate({ id }: { id: string }) {
  const { data, error } = useReport<SignatureCert>(`/reports/signatures/${id}`);
  if (!data) return <ReportLoading error={error} />;
  const s = data.signature;
  return (
    <Sheet company={data.company} title="Constancia de firma electrónica" sub={`${s.subject.title} (${s.subject.module})`}>
      <Section n={1} title="Firmante">
        <Facts rows={[["Nombre", s.signer_name], ["Documento", s.signer_document_masked], ["En calidad de", s.role ? (ROLE[s.role] ?? s.role) : null],
          ["Método", METHOD[s.method] ?? s.method], ["Fecha y hora (America/Lima)", s.signed_at_lima]]} />
        {/* eslint-disable-next-line @next/next/no-img-element */}
        {s.image && <img src={s.image} alt={`Firma de ${s.signer_name}`} className="mt-2 h-20 object-contain" />}
        {s.void && (
          <p className="mt-2 rounded border border-red-300 px-2 py-1 text-sm text-red-700">
            <b>Anulada</b> · {s.void.reason} · {s.void.voided_by_name} · {fmtDT(s.void.voided_at)}
          </p>
        )}
      </Section>
      <Section n={2} title="Registro firmado">
        <Facts rows={[["Registro", `${s.subject.title} · ${s.subject.table}`], ["Identificador", <span key="i" className="break-all font-mono text-xs">{s.subject.id}</span>],
          ["Solicitada por", s.requested_by ? `${s.requested_by}${s.requested_at_lima ? ` · ${s.requested_at_lima}` : ""}` : null],
          ["Huella del registro (SHA-256)", <span key="h" className="break-all font-mono text-xs">{s.document_hash}</span>],
          ["Alcance de la huella", s.hash_scope === "content" ? "Contenido del registro" : "Registro completo"]]} />
      </Section>
      <Section n={3} title="Evidencia">
        <Facts rows={[["Huella de la evidencia (SHA-256)", <span key="e" className="break-all font-mono text-xs">{s.evidence_hash}</span>], ["IP", s.ip], ["Navegador o dispositivo", s.user_agent],
          ["Canal del código", s.otp_channel], ["Ubicación", s.geo ? s.geo.join(", ") : null], ["Identificador de la firma", <span key="s" className="break-all font-mono text-xs">{s.id}</span>]]} />
      </Section>
    </Sheet>
  );
}

// ── M-COM ────────────────────────────────────────────────────────────────────────────────────────────────────────────
type ElectionCall = {
  company: Company;
  election: { title: string; period_label: string | null; status: string; convened_at: string | null; nominations_open_at: string | null; nominations_close_at: string | null;
    voting_opens_at: string | null; voting_closes_at: string | null; seats_titular: number | null; seats_alternate: number | null; electoral_board: unknown; eligible_voters: number | null };
  committee: { name: string; committee_type: string; period_start_on: string | null; period_end_on: string | null; site: string | null } | null;
  signatures: Sig[];
};

export function ElectionCallReport({ id }: { id: string }) {
  const { data, error } = useReport<ElectionCall>(`/reports/elections/${id}/call`);
  if (!data) return <ReportLoading error={error} />;
  const e = data.election;
  return (
    <Sheet company={data.company} title="Acta de convocatoria a elecciones" sub={`${e.title}${e.period_label ? ` · ${e.period_label}` : ""}`}>
      <Section n={1} title="Convocatoria">
        <Facts rows={[["Órgano", data.committee ? `${data.committee.name}${data.committee.site ? ` · ${data.committee.site}` : ""}` : null],
          ["Fecha de convocatoria", fmtDT(e.convened_at)], ["Puestos titulares", e.seats_titular], ["Puestos suplentes", e.seats_alternate],
          ["Trabajadores en el padrón", e.eligible_voters]]} />
      </Section>
      <Section n={2} title="Cronograma">
        <Table head={["Etapa", "Desde", "Hasta"]} empty="Sin cronograma."
          rows={[["Inscripción de candidatos", fmtDT(e.nominations_open_at), fmtDT(e.nominations_close_at)], ["Votación", fmtDT(e.voting_opens_at), fmtDT(e.voting_closes_at)]]} />
      </Section>
      <Section n={3} title="Junta electoral"><div className="text-sm"><Value v={e.electoral_board} /></div></Section>
      <Section n={4} title="Firmas"><Firmas list={data.signatures} /></Section>
    </Sheet>
  );
}

type CommitteeAct = {
  company: Company;
  committee: { name: string; committee_type: string; site: string | null; installation_on: string | null; period_start_on: string | null; period_end_on: string | null;
    members_required: number | null; status: string; notes: string | null };
  members: { full_name: string; document: string | null; position: string | null; representation: string; member_type: string; role_in_committee: string; starts_on: string | null;
    ends_on: string | null; designation_reference: string | null; election: string | null }[];
  signatures: Sig[];
};
const REPRESENTATION: Record<string, string> = { employer: "Empleador", workers: "Trabajadores", supervisor: "Supervisor SST" };
const MEMBER_TYPE: Record<string, string> = { titular: "Titular", alternate: "Suplente" };
const COMMITTEE_ROLE: Record<string, string> = { president: "Presidente", secretary: "Secretario", member: "Miembro", observer: "Observador" };

export function CommitteeInstallationReport({ id }: { id: string }) {
  const { data, error } = useReport<CommitteeAct>(`/reports/committees/${id}`);
  if (!data) return <ReportLoading error={error} />;
  const c = data.committee;
  return (
    <Sheet company={data.company} title="Acta de instalación" sub={c.name}>
      <Section n={1} title="Instalación">
        <Facts rows={[["Sede", c.site], ["Fecha de instalación", fmtD(c.installation_on)], ["Mandato", `${fmtD(c.period_start_on)} al ${fmtD(c.period_end_on)}`],
          ["Número de miembros", c.members_required]]} />
      </Section>
      <Section n={2} title="Integrantes">
        <Table head={["Nombre", "Documento", "Representa a", "Condición", "Cargo", "Origen"]} empty="Sin integrantes activos."
          rows={data.members.map((m) => [m.full_name, m.document, REPRESENTATION[m.representation] ?? m.representation, MEMBER_TYPE[m.member_type] ?? m.member_type,
            COMMITTEE_ROLE[m.role_in_committee] ?? m.role_in_committee, m.election ?? m.designation_reference])} />
      </Section>
      {c.notes && <Section n={3} title="Observaciones"><p className="whitespace-pre-line text-sm">{c.notes}</p></Section>}
      <Section n={c.notes ? 4 : 3} title="Firmas"><Firmas list={data.signatures} /></Section>
    </Sheet>
  );
}

type CommitteeAnnual = {
  company: Company;
  year: number;
  committee: { name: string; committee_type: string; site: string | null; period_start_on: string | null; period_end_on: string | null; installation_on: string | null; status: string };
  summary: { meetings_planned: number; meetings_held: number; meetings_cancelled: number; meetings_pending: number; ordinary_held: number; extraordinary_held: number;
    months_with_ordinary: number; with_quorum: number; minutes_signed: number; average_attendance_pct: number | null; agreements_total: number;
    agreements_by_status: Record<string, number>; inspections_with_committee: number; inspections_with_committee_done: number };
  members: { name: string; position: string | null; representation: string; member_type: string; role_in_committee: string; status: string; attended: number; attendance_pct: number | null }[];
  meetings: { number: number | null; meeting_type_label: string; scheduled_at: string | null; held_at: string | null; status_label: string; quorum_reached: boolean | null; present: number | null;
    agreements: number }[];
  agreements: { number: number | null; meeting_number: number | null; description: string; responsible: string | null; due_on: string | null; status: string; status_label: string;
    action_code: string | null }[];
};
const AGREEMENT_STATUS: Record<string, string> = { agreed: "Acordado", in_progress: "En curso", fulfilled: "Cumplido", overdue: "Vencido", cancelled: "Anulado" };

/** M-COM §11: informe anual del comité para la revisión por la dirección (sesiones, asistencia, acuerdos e inspecciones del año). */
export function CommitteeAnnualReport({ id, year: initial }: { id: string; year: number }) {
  const [year, setYear] = useState(initial);
  const { data, error } = useReport<CommitteeAnnual>(`/reports/committees/${id}/annual?year=${year}`);
  if (!data) return <ReportLoading error={error} />;
  const c = data.committee;
  const s = data.summary;
  const pct = (v: number | null) => (v === null ? "—" : `${v} %`);
  const now = new Date().getFullYear();
  return (
    <Sheet company={data.company} title={`Informe anual del ${c.committee_type === "supervisor" ? "Supervisor" : "Comité"} SST ${data.year}`} sub={c.site ? `${c.name} · ${c.site}` : c.name}>
      <div className="no-print flex flex-wrap items-center justify-end gap-2 text-sm">
        <label className="flex items-center gap-1">Año
          <select className="rounded border border-gray-300 px-2 py-1" value={year} onChange={(e) => setYear(Number(e.target.value))}>
            {Array.from({ length: 6 }, (_, i) => now - i).map((y) => <option key={y} value={y}>{y}</option>)}
          </select>
        </label>
        <button type="button" className="rounded-lg border border-gray-300 px-3 py-1 font-semibold"
          onClick={() => void downloadFile(`/reports/committees/${id}/annual.xlsx?year=${year}`, `informe-anual-comite-${year}.xlsx`)}>Excel</button>
      </div>
      <Section n={1} title="Comité">
        <Facts rows={[["Mandato", `${fmtD(c.period_start_on)} al ${fmtD(c.period_end_on)}`], ["Fecha de instalación", fmtD(c.installation_on)], ["Integrantes en el año", data.members.length]]} />
      </Section>
      <Section n={2} title="Resumen del año">
        <Facts rows={[["Sesiones programadas", s.meetings_planned], ["Sesiones realizadas", `${s.meetings_held} (ordinarias ${s.ordinary_held} · extraordinarias ${s.extraordinary_held})`],
          ["Meses con sesión ordinaria", s.months_with_ordinary], ["Canceladas · por realizar", `${s.meetings_cancelled} · ${s.meetings_pending}`],
          ["Sesiones con quórum", s.with_quorum], ["Actas firmadas", s.minutes_signed], ["Asistencia promedio", pct(s.average_attendance_pct)],
          ["Acuerdos", `${s.agreements_total} · ${Object.entries(s.agreements_by_status).filter(([, n]) => n > 0).map(([k, n]) => `${AGREEMENT_STATUS[k] ?? k}: ${n}`).join(" · ") || "sin acuerdos"}`],
          ["Inspecciones con participación del comité", `${s.inspections_with_committee} (finalizadas ${s.inspections_with_committee_done})`]]} />
      </Section>
      <Section n={3} title="Asistencia por integrante">
        <Table head={["Integrante", "Representa a", "Condición", "Cargo", "Sesiones asistidas", "Asistencia"]} empty="Sin integrantes en el año."
          rows={data.members.map((m) => [m.name, REPRESENTATION[m.representation] ?? m.representation, MEMBER_TYPE[m.member_type] ?? m.member_type,
            COMMITTEE_ROLE[m.role_in_committee] ?? m.role_in_committee, `${m.attended} de ${s.meetings_held}`, pct(m.attendance_pct)])} />
      </Section>
      <Section n={4} title="Sesiones">
        <Table head={["N.°", "Tipo", "Programada", "Realizada", "Estado", "Quórum", "Asistentes", "Acuerdos"]} empty="Sin sesiones en el año."
          rows={data.meetings.map((m) => [m.number, m.meeting_type_label, fmtDT(m.scheduled_at), fmtDT(m.held_at), m.status_label,
            m.quorum_reached === null ? null : yes(m.quorum_reached), m.present, m.agreements])} />
      </Section>
      <Section n={5} title="Acuerdos y su estado">
        <Table head={["Sesión", "N.°", "Acuerdo", "Responsable", "Fecha límite", "Estado", "Acción"]} empty="Sin acuerdos en el año."
          rows={data.agreements.map((a) => [a.meeting_number, a.number, a.description, a.responsible, fmtD(a.due_on), a.status_label, a.action_code])} />
      </Section>
    </Sheet>
  );
}

// ── M-EPP ────────────────────────────────────────────────────────────────────────────────────────────────────────────
type PpeWorker = {
  company: Company;
  worker: { full_name: string; document: string | null; position: string | null; area: string | null; site: string | null };
  deliveries: { code: string; item: string; technical_standard: string | null; quantity: number; size: string | null; delivered_at: string; delivery_reason: string | null;
    expires_on: string | null; returned_at: string | null; return_condition: string | null; status: string; delivered_by: string | null; signatures: Sig[] }[];
};
const PPE_REASON: Record<string, string> = { initial: "Entrega inicial", replacement: "Reposición", loss: "Pérdida", damage: "Deterioro", other: "Otro" };
const PPE_STATUS: Record<string, string> = { delivered: "Entregado", returned: "Devuelto", replaced: "Reemplazado", lost: "Perdido" };

export function PpeDeliveryReport({ id }: { id: string }) {
  const { data, error } = useReport<PpeWorker>(`/reports/ppe_deliveries/worker/${id}`);
  if (!data) return <ReportLoading error={error} />;
  const w = data.worker;
  return (
    <Sheet company={data.company} title="Registro de entrega de equipos de protección personal" sub={w.full_name}>
      <Section n={1} title="Trabajador">
        <Facts rows={[["Nombre", w.full_name], ["Documento", w.document], ["Puesto", w.position], ["Área", w.area], ["Sede", w.site]]} />
      </Section>
      <Section n={2} title="Entregas">
        <Table head={["Fecha", "EPP", "Norma", "Cant.", "Talla", "Motivo", "Vence", "Entregó", "Estado", "Firma del trabajador"]} empty="Sin entregas registradas."
          rows={data.deliveries.map((d) => [fmtD(d.delivered_at), `${d.code} · ${d.item}`, d.technical_standard, d.quantity, d.size, d.delivery_reason ? (PPE_REASON[d.delivery_reason] ?? d.delivery_reason) : null,
            fmtD(d.expires_on), d.delivered_by, PPE_STATUS[d.status] ?? d.status,
            d.signatures.length
              // eslint-disable-next-line @next/next/no-img-element
              ? d.signatures.map((s, i) => <span key={i} className="block text-xs">{s.image ? <img src={s.image} alt={`Firma de ${s.signer_name}`} className="h-8 object-contain" /> : null}{fmtDT(s.signed_at)}</span>)
              : "Sin firma"])} />
      </Section>
    </Sheet>
  );
}

// ── M-DOC ────────────────────────────────────────────────────────────────────────────────────────────────────────────
type DocReport = {
  company: Company;
  document: { code: string; title: string; status: string; category: string | null; owner: string | null; next_review_on: string | null;
    current_version: { version_number: number; version_label: string | null } | null };
  versions: { version_number: number; version_label: string | null; change_summary: string | null; status: string; submitted_at: string | null; submitted_by: string | null;
    reviewed_by: string | null; review_comments: string | null; approved_at: string | null; approved_by: string | null; effective_on: string | null; effective_until: string | null;
    obsoleted_at: string | null; signatures: Sig[] }[];
  acknowledgements: { person: string; document: string | null; position: string | null; method: string | null; acknowledged_at: string }[];
};
const VERSION_STATUS: Record<string, string> = { draft: "Borrador", in_review: "En revisión", approved: "Aprobada", current: "Vigente", obsolete: "Obsoleta" };
const ACK_METHOD: Record<string, string> = { app_signature: "Firma en la app", scanned_paper: "Firma física escaneada", qr: "QR", in_person_assisted: "Asistida en persona" };

export function DocumentReport({ id }: { id: string }) {
  const { data, error } = useReport<DocReport>(`/reports/documents/${id}`);
  if (!data) return <ReportLoading error={error} />;
  const d = data.document;
  const v = (x: { version_number: number; version_label: string | null }) => x.version_label ?? `v${x.version_number}`;
  return (
    <Sheet company={data.company} title="Historial del documento y constancias de cargo" sub={`${d.code} · ${d.title}`}>
      <Section n={1} title="Documento">
        <Facts rows={[["Categoría", d.category], ["Responsable", d.owner], ["Versión vigente", d.current_version ? v(d.current_version) : null], ["Próxima revisión", fmtD(d.next_review_on)]]} />
      </Section>
      <Section n={2} title="Historial de versiones">
        <Table head={["Versión", "Cambios", "Enviada", "Revisó", "Aprobó", "Vigencia", "Estado"]} empty="Sin versiones."
          rows={data.versions.map((x) => [v(x), x.change_summary, x.submitted_by ? `${x.submitted_by} · ${fmtD(x.submitted_at)}` : null, x.reviewed_by,
            x.approved_by ? `${x.approved_by} · ${fmtD(x.approved_at)}` : null, x.effective_on ? `${fmtD(x.effective_on)}${x.effective_until ? ` al ${fmtD(x.effective_until)}` : ""}` : null,
            VERSION_STATUS[x.status] ?? x.status])} />
      </Section>
      <Section n={3} title={`Constancias de cargo de la versión vigente (${data.acknowledgements.length})`}>
        <Table head={["Nombre", "Documento", "Puesto", "Forma", "Fecha y hora"]} empty="Aún no hay cargos de la versión vigente."
          rows={data.acknowledgements.map((a) => [a.person, a.document, a.position, a.method ? (ACK_METHOD[a.method] ?? a.method) : null, fmtDT(a.acknowledged_at)])} />
      </Section>
    </Sheet>
  );
}

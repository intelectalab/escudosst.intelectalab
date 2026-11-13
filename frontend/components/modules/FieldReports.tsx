"use client";

// Informes imprimibles de campo (§11 de las fichas), con el mismo formato del informe de investigación:
//  - M-INS: informe de la inspección con respuestas, fotos, puntaje, hallazgos y firmas.
//  - M-PTR: PETAR con verificaciones, mediciones de gases, aprobaciones, cuadrilla y cierre.
//  - M-ATS: ATS con pasos, peligros y controles, participantes y sus firmas.
// Se guardan como PDF desde el diálogo de impresión del navegador.

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";

type Sig = { signer_name: string; signed_at: string; method: string; image: string | null };
export type Company = { name: string; legal_name: string; ruc: string };

export const cell = "border border-gray-300 px-2 py-1 align-top";
export const fmtD = (d?: string | null) => (d ? new Date(d.length === 10 ? `${d}T12:00:00` : d).toLocaleDateString("es-PE") : "—");
export const fmtDT = (d?: string | null) => (d ? new Date(d).toLocaleString("es-PE", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—");
const METHOD: Record<string, string> = { pin: "PIN", drawn: "Trazo", password: "Contraseña", otp: "Código", app_signature: "App" };
const ANSWER: Record<string, string> = { yes: "Sí", no: "No", na: "No aplica", compliant: "Cumple", non_compliant: "No cumple" };

export function useReport<T>(path: string) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: T }>(path, { withCompany: true }).then((r) => setData(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [path]);
  return { data, error };
}

export function Sheet({ company, title, sub, draft, children }: { company: Company; title: string; sub: string; draft?: string | null; children: React.ReactNode }) {
  return (
    <div className="min-h-screen bg-gray-100 p-4 text-gray-900 print:bg-white print:p-0">
      <style>{`@page { size: A4; margin: 14mm } @media print { .no-print { display: none } } img { break-inside: avoid }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-3xl flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3 shadow">
        <span className="text-sm text-gray-600">Para el PDF elige «Guardar como PDF» en el diálogo de impresión.</span>
        <button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">Imprimir / Guardar PDF</button>
      </div>
      <div className="mx-auto max-w-3xl space-y-5 bg-white p-8 shadow print:max-w-none print:p-0 print:shadow-none">
        <header className="space-y-1 text-center">
          <p className="text-xs text-gray-600">{company.legal_name} · RUC {company.ruc}</p>
          <h1 className="text-lg font-bold uppercase">{title}</h1>
          <p className="text-sm">{sub}</p>
          {draft && <p className="text-sm font-bold text-red-700">{draft}</p>}
        </header>
        {children}
      </div>
    </div>
  );
}

export function Section({ n, title, children }: { n: number; title: string; children: React.ReactNode }) {
  return (
    <section className="space-y-2">
      <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">{n}. {title}</h2>
      {children}
    </section>
  );
}

export function Facts({ rows }: { rows: [string, React.ReactNode][] }) {
  return (
    <table className="w-full border-collapse text-sm">
      <tbody>{rows.map(([k, v]) => <tr key={k}><td className={`${cell} w-1/3 bg-gray-50 font-semibold`}>{k}</td><td className={cell}>{v ?? "—"}</td></tr>)}</tbody>
    </table>
  );
}

function Signatures({ list, empty }: { list: Sig[]; empty: string }) {
  if (!list.length) return <p className="text-sm text-gray-600">{empty}</p>;
  return (
    <div className="grid grid-cols-2 gap-3">
      {list.map((s, i) => (
        <div key={i} className="rounded border border-gray-300 p-2 text-sm" style={{ breakInside: "avoid" }}>
          {s.image ? <img src={s.image} alt={`Firma de ${s.signer_name}`} className="h-14 object-contain" /> : <div className="h-14" />}
          <p className="border-t border-gray-300 pt-1 font-semibold">{s.signer_name}</p>
          <p className="text-xs text-gray-600">{fmtDT(s.signed_at)} · {METHOD[s.method] ?? s.method}</p>
        </div>
      ))}
    </div>
  );
}

export const ReportLoading = ({ error }: { error: string | null }) => <p className="p-8">{error ?? "Preparando el informe…"}</p>;

// ─────────────────────────────── Inspección

type Insp = {
  company: Company;
  inspection: { code: string; title: string; inspection_type: string; status: string; scheduled_on: string | null; started_at: string | null; completed_at: string | null;
    score_pct: number | null; summary: string | null; participants: unknown; committee_participation: boolean; template: { name: string; version_number: number } | null;
    site: string | null; area: string | null; inspector: string | null };
  answers: { question_snapshot: string; answer: string | null; numeric_value: number | null; is_compliant: boolean | null; is_critical: boolean; comment: string | null; section: string | null; photo: string | null }[];
  findings: { finding_type: string; description: string; severity: string | null; status: string; action_code: string | null; action_due: string | null; photo: string | null }[];
  signatures: Sig[];
};
const INS_STATUS: Record<string, string> = { scheduled: "Programada", in_progress: "En ejecución", completed: "Completada", closed: "Cerrada", cancelled: "Cancelada" };

export function InspectionReport({ id }: { id: string }) {
  const { data, error } = useReport<Insp>(`/reports/inspections/${id}`);
  if (!data) return <ReportLoading error={error} />;
  const i = data.inspection;
  const people = Array.isArray(i.participants) ? (i.participants as unknown[]).map((p) => (typeof p === "string" ? p : (p as { name?: string })?.name ?? "")).filter(Boolean).join(", ") : "";
  return (
    <Sheet company={data.company} title="Informe de inspección" sub={`${i.code} · Estado: ${INS_STATUS[i.status] ?? i.status}`}
      draft={["completed", "closed"].includes(i.status) ? null : "BORRADOR · la inspección aún no está completada"}>
      <Section n={1} title="Datos de la inspección">
        <Facts rows={[["Título", i.title], ["Plantilla", i.template ? `${i.template.name} (versión ${i.template.version_number})` : "—"], ["Sede / área", [i.site, i.area].filter(Boolean).join(" · ") || "—"],
          ["Programada", fmtD(i.scheduled_on)], ["Realizada", `${fmtDT(i.started_at)} a ${fmtDT(i.completed_at)}`], ["Inspector", i.inspector], ["Participantes", people || "—"],
          ["Participó el comité", i.committee_participation ? "Sí" : "No"], ["Puntaje", i.score_pct == null ? "—" : `${i.score_pct} %`]]} />
      </Section>
      <Section n={2} title="Lista de verificación">
        <table className="w-full border-collapse text-sm">
          <thead><tr className="bg-gray-100"><th className={cell}>Pregunta</th><th className={cell}>Respuesta</th><th className={cell}>Observación</th></tr></thead>
          <tbody>
            {data.answers.map((a, k) => (
              <tr key={k} style={{ breakInside: "avoid" }}>
                <td className={cell}>{a.section ? <span className="block text-xs text-gray-500">{a.section}</span> : null}{a.question_snapshot}{a.is_critical ? <b className="text-red-700"> (crítica)</b> : null}</td>
                <td className={`${cell} whitespace-nowrap ${a.is_compliant === false ? "font-bold text-red-700" : ""}`}>{a.answer ? ANSWER[a.answer] ?? a.answer : a.numeric_value ?? "—"}</td>
                <td className={cell}>{a.comment ?? ""}{a.photo ? <img src={a.photo} alt="Foto de la respuesta" className="mt-1 max-h-40" /> : null}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Section>
      <Section n={3} title="Hallazgos">
        {data.findings.length === 0 ? <p className="text-sm text-gray-600">Sin hallazgos.</p> : (
          <table className="w-full border-collapse text-sm">
            <thead><tr className="bg-gray-100"><th className={cell}>Hallazgo</th><th className={cell}>Gravedad</th><th className={cell}>Acción correctiva</th></tr></thead>
            <tbody>
              {data.findings.map((f, k) => (
                <tr key={k} style={{ breakInside: "avoid" }}>
                  <td className={cell}>{f.description}{f.photo ? <img src={f.photo} alt="Foto del hallazgo" className="mt-1 max-h-40" /> : null}</td>
                  <td className={cell}>{f.severity ?? "—"}</td>
                  <td className={cell}>{f.action_code ? `${f.action_code} · vence ${fmtD(f.action_due)}` : "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Section>
      {i.summary && <Section n={4} title="Conclusiones"><p className="whitespace-pre-line text-sm">{i.summary}</p></Section>}
      <Section n={i.summary ? 5 : 4} title="Firmas"><Signatures list={data.signatures} empty="Sin firmas registradas." /></Section>
    </Sheet>
  );
}

// ─────────────────────────────── PETAR

type Permit = {
  company: Company;
  permit: { code: string; type: { name: string; category: string; legal_reference: string | null } | null; status: string; work_description: string; location_text: string | null;
    site: string | null; area: string | null; contractor: string | null; leader: string | null; requested_by: string | null; valid_from: string; valid_until: string; activated_at: string | null;
    extension_count: number; suspended_reason: string | null; closed_at: string | null; closed_by: string | null; closure_notes: string | null; area_left_safe: boolean | null;
    ats: string | null; procedure: string | null; gas_measurements: Record<string, unknown>[]; isolation_points: unknown[] };
  crew: { name: string | null; role: string | null }[];
  checks: { check_phase: string; question: string; answer: string | null; value: string | null; comment: string | null; checked_at: string | null; checked_by: string | null; photo: string | null }[];
  approvals: { step_order: number; approver_role: string | null; decision: string | null; decided_at: string | null; comments: string | null; approver: string | null }[];
  signatures: Sig[];
};
const PTR_STATUS: Record<string, string> = { draft: "Borrador", pending_approval: "Por aprobar", approved: "Aprobado", active: "En ejecución", suspended: "Suspendido", closed: "Cerrado", expired: "Vencido", cancelled: "Anulado", rejected: "Rechazado" };
const PHASE: Record<string, string> = { pre_start: "Antes de iniciar", during: "Durante el trabajo", closure: "Al cerrar" };
const DECISION: Record<string, string> = { approved: "Aprobó", rejected: "Rechazó", pending: "Pendiente" };

export function PermitReport({ id }: { id: string }) {
  const { data, error } = useReport<Permit>(`/reports/work_permits/${id}`);
  if (!data) return <ReportLoading error={error} />;
  const p = data.permit;
  const gasKeys = [...new Set(p.gas_measurements.flatMap((g) => Object.keys(g ?? {})))];
  return (
    <Sheet company={data.company} title={`Permiso de trabajo de alto riesgo${p.type ? ` · ${p.type.name}` : ""}`} sub={`${p.code} · Estado: ${PTR_STATUS[p.status] ?? p.status}`}
      draft={["draft", "pending_approval"].includes(p.status) ? "BORRADOR · el permiso aún no está aprobado" : null}>
      <Section n={1} title="Datos del permiso">
        <Facts rows={[["Trabajo", p.work_description], ["Lugar", [p.site, p.area, p.location_text].filter(Boolean).join(" · ") || "—"], ["Contratista", p.contractor ?? "Personal propio"],
          ["Responsable de la ejecución", p.leader], ["Solicitado por", p.requested_by], ["Vigencia", `${fmtDT(p.valid_from)} a ${fmtDT(p.valid_until)}`], ["Inicio del trabajo", fmtDT(p.activated_at)],
          ["Ampliaciones", String(p.extension_count)], ["ATS / PETS", [p.ats, p.procedure].filter(Boolean).join(" · ") || "—"], ["Base legal", p.type?.legal_reference ?? "—"]]} />
      </Section>
      <Section n={2} title="Cuadrilla">
        {data.crew.length === 0 ? <p className="text-sm text-gray-600">Sin cuadrilla registrada.</p> : <ul className="list-disc pl-5 text-sm">{data.crew.map((c, k) => <li key={k}>{c.name ?? "—"}{c.role ? ` · ${c.role}` : ""}</li>)}</ul>}
      </Section>
      <Section n={3} title="Verificaciones">
        {data.checks.length === 0 ? <p className="text-sm text-gray-600">Sin verificaciones registradas.</p> : (
          <table className="w-full border-collapse text-sm">
            <thead><tr className="bg-gray-100"><th className={cell}>Momento</th><th className={cell}>Verificación</th><th className={cell}>Respuesta</th><th className={cell}>Verificó</th></tr></thead>
            <tbody>
              {data.checks.map((k, i) => (
                <tr key={i} style={{ breakInside: "avoid" }}>
                  <td className={cell}>{PHASE[k.check_phase] ?? k.check_phase}</td>
                  <td className={cell}>{k.question}{k.comment ? <span className="block text-xs text-gray-600">{k.comment}</span> : null}{k.photo ? <img src={k.photo} alt="Foto de la verificación" className="mt-1 max-h-32" /> : null}</td>
                  <td className={cell}>{k.answer ? ANSWER[k.answer] ?? k.answer : k.value ?? "—"}</td>
                  <td className={cell}>{k.checked_by ?? "—"}<span className="block text-xs text-gray-600">{fmtDT(k.checked_at)}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Section>
      {gasKeys.length > 0 && (
        <Section n={4} title="Mediciones de gases">
          <table className="w-full border-collapse text-sm">
            <thead><tr className="bg-gray-100">{gasKeys.map((k) => <th key={k} className={cell}>{k}</th>)}</tr></thead>
            <tbody>{p.gas_measurements.map((g, i) => <tr key={i}>{gasKeys.map((k) => <td key={k} className={cell}>{String(g?.[k] ?? "—")}</td>)}</tr>)}</tbody>
          </table>
        </Section>
      )}
      <Section n={gasKeys.length ? 5 : 4} title="Aprobaciones">
        {data.approvals.length === 0 ? <p className="text-sm text-gray-600">Sin aprobaciones registradas.</p> : (
          <table className="w-full border-collapse text-sm">
            <thead><tr className="bg-gray-100"><th className={cell}>Paso</th><th className={cell}>Aprobador</th><th className={cell}>Decisión</th><th className={cell}>Fecha</th></tr></thead>
            <tbody>{data.approvals.map((a) => <tr key={a.step_order}><td className={cell}>{a.step_order}</td><td className={cell}>{a.approver ?? a.approver_role ?? "—"}</td><td className={cell}>{a.decision ? DECISION[a.decision] ?? a.decision : "Pendiente"}{a.comments ? <span className="block text-xs text-gray-600">{a.comments}</span> : null}</td><td className={cell}>{fmtDT(a.decided_at)}</td></tr>)}</tbody>
          </table>
        )}
      </Section>
      <Section n={gasKeys.length ? 6 : 5} title="Cierre">
        <Facts rows={[["Cerrado", fmtDT(p.closed_at)], ["Cerró", p.closed_by], ["Área queda segura", p.area_left_safe == null ? "—" : p.area_left_safe ? "Sí" : "No"],
          ["Observaciones", p.closure_notes], ...(p.suspended_reason ? [["Motivo de suspensión", p.suspended_reason] as [string, string]] : [])]} />
      </Section>
      <Section n={gasKeys.length ? 7 : 6} title="Firmas"><Signatures list={data.signatures} empty="Sin firmas registradas." /></Section>
    </Sheet>
  );
}

// ─────────────────────────────── ATS

type Ats = {
  company: Company;
  ats: { code: string | null; task: string; status: string; performed_on: string | null; work_location: string | null; site: string | null; area: string | null; contractor: string | null;
    supervisor: string | null; leader: string | null; permit: string | null; procedure: string | null; required_ppe: unknown; tools_equipment: unknown; started_at: string | null; closed_at: string | null; is_high_risk: boolean };
  steps: { step_number: number; description: string; hazards: string | null; risks: string | null; controls: string | null; responsible: string | null }[];
  participants: { name: string; role: string | null; signed_at: string | null; late_signature: boolean; image: string | null }[];
  signatures: Sig[];
};
const ROLE: Record<string, string> = { leader: "Líder", executor: "Ejecutor", supervisor: "Supervisor", watcher: "Vigía" };
const ATS_STATUS: Record<string, string> = { draft: "Borrador", signed: "Firmado", in_execution: "En ejecución", closed: "Cerrado", cancelled: "Anulado" };
const list = (v: unknown) => (Array.isArray(v) ? v.map((x) => (typeof x === "string" ? x : (x as { name?: string })?.name ?? "")).filter(Boolean).join(", ") : typeof v === "string" ? v : "");

export function AtsReport({ id }: { id: string }) {
  const { data, error } = useReport<Ats>(`/reports/ats_forms/${id}`);
  if (!data) return <ReportLoading error={error} />;
  const a = data.ats;
  return (
    <Sheet company={data.company} title="Análisis de trabajo seguro (ATS)" sub={`${a.code ?? "ATS"} · Estado: ${ATS_STATUS[a.status] ?? a.status}`}
      draft={a.status === "draft" ? "BORRADOR · el ATS aún no está firmado" : null}>
      <Section n={1} title="Datos de la tarea">
        <Facts rows={[["Tarea", a.task], ["Fecha", fmtD(a.performed_on)], ["Lugar", [a.site, a.area, a.work_location].filter(Boolean).join(" · ") || "—"], ["Contratista", a.contractor ?? "Personal propio"],
          ["Supervisor", a.supervisor], ["Líder de la tarea", a.leader], ["PETAR / PETS", [a.permit, a.procedure].filter(Boolean).join(" · ") || "—"], ["Alto riesgo", a.is_high_risk ? "Sí" : "No"],
          ["EPP requerido", list(a.required_ppe) || "—"], ["Herramientas y equipos", list(a.tools_equipment) || "—"], ["Inicio / cierre", `${fmtDT(a.started_at)} / ${fmtDT(a.closed_at)}`]]} />
      </Section>
      <Section n={2} title="Pasos, peligros y controles">
        <table className="w-full border-collapse text-sm">
          <thead><tr className="bg-gray-100"><th className={cell}>N.°</th><th className={cell}>Paso</th><th className={cell}>Peligros</th><th className={cell}>Riesgos</th><th className={cell}>Controles</th><th className={cell}>Responsable</th></tr></thead>
          <tbody>{data.steps.map((s) => <tr key={s.step_number} style={{ breakInside: "avoid" }}><td className={cell}>{s.step_number}</td><td className={cell}>{s.description}</td><td className={cell}>{s.hazards ?? "—"}</td><td className={cell}>{s.risks ?? "—"}</td><td className={cell}>{s.controls ?? "—"}</td><td className={cell}>{s.responsible ?? "—"}</td></tr>)}</tbody>
        </table>
      </Section>
      <Section n={3} title="Participantes y firmas">
        <div className="grid grid-cols-2 gap-3">
          {data.participants.map((p, k) => (
            <div key={k} className="rounded border border-gray-300 p-2 text-sm" style={{ breakInside: "avoid" }}>
              {p.image ? <img src={p.image} alt={`Firma de ${p.name}`} className="h-12 object-contain" /> : <div className="h-12" />}
              <p className="border-t border-gray-300 pt-1 font-semibold">{p.name}{p.role ? ` · ${ROLE[p.role] ?? p.role}` : ""}</p>
              <p className="text-xs text-gray-600">{p.signed_at ? fmtDT(p.signed_at) : "Sin firma"}{p.late_signature ? " · firmado tarde" : ""}</p>
            </div>
          ))}
        </div>
      </Section>
      {data.signatures.length > 0 && <Section n={4} title="Otras firmas"><Signatures list={data.signatures} empty="" /></Section>}
    </Sheet>
  );
}

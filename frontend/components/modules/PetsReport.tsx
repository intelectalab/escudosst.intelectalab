"use client";

// PETS (M-PET §11 y SCR-PET-04): el PDF de una versión (pasos con su riesgo y control, EPP, capacitaciones requeridas, firmas) con un
// QR que abre siempre la versión vigente; y la vista para el celular en campo con los mismos pasos.

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { QrCode } from "@/components/mfa/QrCode";
import { safeHtml } from "@/lib/workerApp";
import { Facts, Section, Sheet, ReportLoading, cell, fmtD, useReport, type Company } from "./FieldReports";

export type Pets = {
  company: Company;
  procedure: { id: string; code: string; title: string; type: string; critical: boolean; site: string | null; area: string | null; position: string | null; next_review_on: string | null };
  version: { id: string; number: number; status: string; is_current: boolean; effective_on: string | null; approved_at: string | null; prepared_by: string | null; reviewed_by: string | null;
    approved_by: string | null; content_html: string | null; required_ppe: string[]; change_summary: string | null };
  steps: { step: string; risk: string; control: string; significant: boolean }[];
  trainings: string[];
  signatures: { signer_name: string; signed_at: string; method: string }[];
};
const STATUS: Record<string, string> = { draft: "Borrador", in_review: "En revisión", approved: "Aprobada", current: "Vigente", obsolete: "Obsoleta" };

export function PetsReport({ id }: { id: string }) {
  const { data: d, error } = useReport<Pets>(`/pet/versions/${id}/report`);
  if (!d) return <ReportLoading error={error} />;
  const v = d.version;
  const link = typeof window === "undefined" ? "" : `${window.location.origin}/m/pet/ver/${d.procedure.id}`;
  return (
    <Sheet company={d.company} title={`Procedimiento escrito de trabajo seguro · ${d.procedure.code}`} sub={`${d.procedure.title} · versión ${v.number} · ${STATUS[v.status] ?? v.status}`}
      draft={v.is_current || v.status === "approved" ? null : v.status === "obsolete" ? "VERSIÓN OBSOLETA · escanea el QR para ver la vigente" : "BORRADOR · el PETS aún no está aprobado"}>
      <Section n={1} title="Datos">
        <div className="flex items-start justify-between gap-4">
          <Facts rows={[["Sede", d.procedure.site ?? "Toda la empresa"], ["Área", d.procedure.area ?? "—"], ["Puesto", d.procedure.position ?? "—"], ["Tarea crítica", d.procedure.critical ? "Sí" : "No"],
            ["Vigente desde", fmtD(v.effective_on)], ["Próxima revisión", fmtD(d.procedure.next_review_on)], ["Elaboró", v.prepared_by ?? "—"], ["Revisó", v.reviewed_by ?? "—"], ["Aprobó", `${v.approved_by ?? "—"} · ${fmtD(v.approved_at)}`]]} />
          {link && <div className="shrink-0 text-center text-[10px] text-gray-600"><QrCode value={link} size={84} /><p>Versión vigente</p></div>}
        </div>
      </Section>
      <Section n={2} title="Pasos, riesgos y controles">
        {d.steps.length === 0 ? <p className="text-sm text-gray-600">Sin pasos registrados.</p> : (
          <table className="w-full border-collapse text-[11px]">
            <thead><tr className="bg-gray-100"><th className={cell}>N.º</th><th className={cell}>Paso</th><th className={cell}>Riesgo</th><th className={cell}>Control</th></tr></thead>
            <tbody>{d.steps.map((s, k) => (
              <tr key={k} style={{ breakInside: "avoid" }}><td className={cell}>{k + 1}</td><td className={cell}>{s.step}</td>
                <td className={`${cell} ${s.significant ? "font-bold text-red-700" : ""}`}>{s.risk}{s.significant ? " (significativo)" : ""}</td><td className={cell}>{s.control}</td></tr>
            ))}</tbody>
          </table>
        )}
      </Section>
      <Section n={3} title="EPP y capacitaciones requeridas">
        <Facts rows={[["EPP", d.version.required_ppe.length ? d.version.required_ppe.join(", ") : "—"], ["Capacitaciones", d.trainings.length ? d.trainings.join(", ") : "—"]]} />
      </Section>
      {v.content_html && <Section n={4} title="Descripción"><div className="prose max-w-none text-sm" dangerouslySetInnerHTML={{ __html: safeHtml(v.content_html) }} /></Section>}
      <Section n={v.content_html ? 5 : 4} title="Firmas">
        {d.signatures.length === 0 ? <p className="text-sm text-gray-600">Sin firmas registradas.</p> : (
          <ul className="text-sm">{d.signatures.map((s, k) => <li key={k}>{s.signer_name} · {new Date(s.signed_at).toLocaleString("es-PE")}</li>)}</ul>
        )}
      </Section>
    </Sheet>
  );
}

/** Vista en campo: el QR del PETS abre la versión vigente del procedimiento. */
export function PetsField({ procedureId }: { procedureId: string }) {
  const [d, setD] = useState<Pets | null>(null);
  const [err, setErr] = useState<string | null>(null);
  useEffect(() => { api<{ data: Pets }>(`/pet/procedures/${procedureId}/current`, { withCompany: true }).then((r) => setD(r.data)).catch((e: ApiError) => setErr(e.detail ?? e.title)); }, [procedureId]);
  if (err) return <p className="p-4 text-sm text-bad">{err}</p>;
  if (!d) return <p className="p-4 text-sm text-muted">Cargando…</p>;
  return (
    <div className="mx-auto max-w-xl space-y-4 p-4">
      <div>
        <p className="text-xs text-muted">{d.procedure.code} · versión {d.version.number} vigente desde {fmtD(d.version.effective_on)}</p>
        <h1 className="text-lg font-bold">{d.procedure.title}</h1>
        {d.procedure.critical && <p className="text-xs font-semibold text-bad">Tarea crítica</p>}
      </div>
      {d.version.required_ppe.length > 0 && <div className="rounded-xl bg-brand-soft p-3 text-sm"><b>EPP obligatorio:</b> {d.version.required_ppe.join(", ")}</div>}
      <ol className="space-y-2">
        {d.steps.map((s, k) => (
          <li key={k} className={`rounded-xl border p-3 text-sm ${s.significant ? "border-bad" : "border-line"}`}>
            <p className="font-semibold">{k + 1}. {s.step}</p>
            {s.risk && <p className={s.significant ? "text-bad" : "text-muted"}>Riesgo: {s.risk}</p>}
            {s.control && <p>Control: {s.control}</p>}
          </li>
        ))}
      </ol>
      <a href={`/imprimir/pets/${d.version.id}`} className="inline-block text-sm font-semibold text-brand-strong underline">Ver o imprimir el PDF</a>
    </div>
  );
}

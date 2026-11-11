"use client";

// Informe de la investigación para imprimir o guardar en PDF (M-INV SCR-INV-06): datos del evento, equipo investigador,
// método (árbol de causas o SCAT), causas encadenadas, conclusiones, acciones correctivas y firmas del equipo.

import { useEffect, useMemo, useState } from "react";
import { api, type ApiError } from "@/lib/api";

type Cause = { id: string; parent_cause_id: string | null; cause_level: string; cause_category: string | null; scat_code: string | null; description: string; action_code: string | null };
type Member = { id: string; name: string; role: string; signed_at: string | null; image: string | null; pending?: boolean };
type Report = {
  company: { name: string; legal_name: string; ruc: string };
  investigation: {
    code: string | null; method: string; status: string; started_on: string | null; due_on: string | null; summary: string | null; immediate_causes: string | null;
    basic_causes: string | null; conclusions: string | null; lessons_learned: string | null; approved_at: string | null; approved_by: string | null; scat_contact: { code: string; label: string }[];
  };
  event: {
    code: string; event_type: string; occurred_at: string; exact_location: string | null; description: string; activity_at_time: string | null; severity: string | null;
    lost_days: number; injured_count: number; material_damage: string | null; immediate_actions: string | null; site: string | null; area: string | null;
  } | null;
  team: Member[];
  causes: Cause[];
  actions: { code: string; title: string; due_on: string; status: string; priority: string; responsible: string | null }[];
  signatures: { signer_name: string; signed_at: string; image: string | null }[];
};

const METHOD: Record<string, string> = { cause_tree: "Árbol de causas", scat: "SCAT (técnica sistemática de análisis de causas)", five_whys: "5 porqués", fishbone: "Espina de pescado (Ishikawa)", other: "Otro" };
const EVENT: Record<string, string> = { fatal_accident: "Accidente mortal", disabling_accident: "Accidente incapacitante", minor_accident: "Accidente leve", dangerous_incident: "Incidente peligroso", incident: "Incidente", occupational_disease: "Enfermedad ocupacional" };
const STATUS: Record<string, string> = { open: "Abierta", analysis: "En análisis", committee_review: "En revisión del comité", approved: "Aprobada", actions_in_progress: "Acciones en curso", closed: "Cerrada" };
const ROLE: Record<string, string> = { leader: "Líder", member: "Integrante", committee_representative: "Representante del comité", worker_representative: "Representante de los trabajadores", expert: "Especialista", witness: "Testigo" };
const LEVELS: [string, string, string][] = [
  ["immediate", "Causas inmediatas", "Causa inmediata"], ["basic", "Causas básicas", "Causa básica"], ["root", "Causas raíz", "Causa raíz"],
  ["lack_of_control", "Necesidades de acción de control", "Necesidad de control"],
];
const CATEGORY: Record<string, string> = { unsafe_act: "Acto", unsafe_condition: "Condición", personal_factor: "Factor personal", job_factor: "Factor del trabajo", management_system: "Sistema de gestión" };
const ACTION: Record<string, string> = { open: "Abierta", in_progress: "En curso", pending_verification: "Por verificar", verified: "Verificada", overdue: "Vencida", cancelled: "Anulada" };

const fmtDate = (v: string | null | undefined) => (v ? new Date(v.length === 10 ? `${v}T12:00:00` : v).toLocaleDateString("es-PE", { day: "2-digit", month: "2-digit", year: "numeric" }) : "—");
const fmtDateTime = (v: string | null | undefined) => (v ? new Date(v).toLocaleString("es-PE", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—");

function Section({ n, title, children }: { n: number; title: string; children: React.ReactNode }) {
  return (
    <section className="space-y-2 break-inside-avoid">
      <h2 className="border-b border-gray-300 pb-1 text-sm font-bold uppercase">{n}. {title}</h2>
      {children}
    </section>
  );
}

function Text({ v }: { v: string | null | undefined }) {
  return <p className="whitespace-pre-line text-sm">{v?.trim() ? v : <span className="text-gray-500">No registrado.</span>}</p>;
}

function SignBox({ name, role, at, image, pending }: { name: string; role?: string; at: string | null; image: string | null; pending?: boolean }) {
  return (
    <div className="text-center text-xs">
      <div className="flex h-14 items-end justify-center">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        {image && <img src={image} alt={`Firma de ${name}`} className="max-h-14" />}
      </div>
      <div className="border-t border-gray-500 pt-1">
        <b>{name}</b>
        {role && <p>{role}</p>}
        {at && <p className="text-gray-500">Firmó el {fmtDateTime(at)}</p>}
        {!at && pending && <p className="text-gray-500">Firma electrónica pendiente</p>}
      </div>
    </div>
  );
}

export function InvReportSheet({ id }: { id: string }) {
  const [data, setData] = useState<Report | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: Report }>(`/inv/investigations/${id}/report`, { withCompany: true })
      .then((r) => setData(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [id]);

  const kids = useMemo(() => {
    const ids = new Set((data?.causes ?? []).map((c) => c.id));
    const m = new Map<string, Cause[]>();
    for (const c of data?.causes ?? []) {
      const k = c.parent_cause_id && ids.has(c.parent_cause_id) ? c.parent_cause_id : "root";
      m.set(k, [...(m.get(k) ?? []), c]);
    }
    return m;
  }, [data]);

  if (error) return <p className="p-8">{error}</p>;
  if (!data) return <p className="p-8">Preparando el informe…</p>;
  const { investigation: inv, event: ev } = data;
  const draft = !["approved", "actions_in_progress", "closed"].includes(inv.status);
  const levelOne = (lv: string) => LEVELS.find((l) => l[0] === lv)?.[2] ?? lv;

  const node = (c: Cause, depth: number): React.ReactNode => (
    <li key={c.id}>
      {c.scat_code && <span className="font-mono text-[11px] text-gray-500">{c.scat_code} </span>}
      {c.description}
      <span className="text-xs text-gray-500">
        {" "}({levelOne(c.cause_level)}{c.cause_category ? ` · ${CATEGORY[c.cause_category] ?? c.cause_category}` : ""}{c.action_code ? ` · acción ${c.action_code}` : ""})
      </span>
      {(kids.get(c.id) ?? []).length > 0 && depth < 8 && <ul className="ml-5 list-[circle] space-y-1 pl-2">{(kids.get(c.id) ?? []).map((k) => node(k, depth + 1))}</ul>}
    </li>
  );
  const cell = "border border-gray-300 px-2 py-1";
  const extraSignatures = data.signatures.filter((s) => !data.team.some((m) => m.name === s.signer_name && m.signed_at));

  return (
    <div className="min-h-screen bg-gray-100 p-4 text-gray-900 print:bg-white print:p-0">
      <style>{`@page { size: A4; margin: 16mm } @media print { .no-print { display: none } }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-3xl flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3 shadow">
        <span className="text-sm text-gray-600">Para el PDF elige «Guardar como PDF» en el diálogo de impresión.</span>
        <button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">Imprimir / Guardar PDF</button>
      </div>
      <div className="mx-auto max-w-3xl space-y-5 bg-white p-8 shadow print:max-w-none print:p-0 print:shadow-none">
        <header className="space-y-1 text-center">
          <p className="text-xs text-gray-600">{data.company.legal_name} · RUC {data.company.ruc}</p>
          <h1 className="text-lg font-bold uppercase">Informe de investigación · {ev ? (EVENT[ev.event_type] ?? "Evento") : "Evento"}</h1>
          <p className="text-sm">{inv.code ?? "Investigación"} · Estado: {STATUS[inv.status] ?? inv.status}</p>
          {draft && <p className="text-sm font-bold text-red-700">BORRADOR · la investigación aún no está aprobada</p>}
        </header>

        <Section n={1} title="Datos del evento">
          {ev ? (
            <table className="w-full border-collapse text-sm">
              <tbody>
                {[
                  ["Código", ev.code], ["Tipo", EVENT[ev.event_type] ?? ev.event_type], ["Fecha y hora", fmtDateTime(ev.occurred_at)],
                  ["Sede / área", [ev.site, ev.area].filter(Boolean).join(" · ") || "—"], ["Lugar exacto", ev.exact_location ?? "—"],
                  ["Actividad que se realizaba", ev.activity_at_time ?? "—"], ["Afectados / días perdidos", `${ev.injured_count} / ${ev.lost_days}`],
                  ["Daño material", ev.material_damage ?? "—"],
                ].map(([k, v]) => (
                  <tr key={k}><th className={`${cell} w-1/3 bg-gray-50 text-left font-semibold`}>{k}</th><td className={cell}>{v}</td></tr>
                ))}
              </tbody>
            </table>
          ) : <Text v={null} />}
          <p className="text-sm font-semibold">Descripción</p>
          <Text v={ev?.description} />
          {ev?.immediate_actions && (<><p className="text-sm font-semibold">Acciones inmediatas</p><Text v={ev.immediate_actions} /></>)}
        </Section>

        <Section n={2} title="Equipo investigador">
          <p className="text-sm">Inicio: {fmtDate(inv.started_on)} · Plazo: {fmtDate(inv.due_on)}</p>
          {data.team.length ? (
            <table className="w-full border-collapse text-sm">
              <thead><tr className="bg-gray-100"><th className={`${cell} text-left`}>Integrante</th><th className={`${cell} text-left`}>Rol</th></tr></thead>
              <tbody>{data.team.map((m) => <tr key={m.id}><td className={cell}>{m.name}</td><td className={cell}>{ROLE[m.role] ?? m.role}</td></tr>)}</tbody>
            </table>
          ) : <Text v={null} />}
        </Section>

        <Section n={3} title="Método de análisis">
          <p className="text-sm">{METHOD[inv.method] ?? inv.method}</p>
          {inv.summary && <Text v={inv.summary} />}
          {inv.method === "scat" && inv.scat_contact.length > 0 && (
            <p className="text-sm"><b>Tipo de contacto:</b> {inv.scat_contact.map((c) => `${c.code} ${c.label}`).join(" · ")}</p>
          )}
        </Section>

        <Section n={4} title={inv.method === "scat" ? "Causas (SCAT)" : "Árbol de causas"}>
          {data.causes.length ? (
            <>
              <p className="text-sm font-semibold">Evento: {ev?.description ? ev.description.slice(0, 200) : "evento investigado"}</p>
              <ul className="ml-4 list-disc space-y-1 pl-2 text-sm">{(kids.get("root") ?? []).map((c) => node(c, 0))}</ul>
              <div className="space-y-1 pt-2 text-sm">
                {LEVELS.map(([lv, label]) => {
                  const list = data.causes.filter((c) => c.cause_level === lv);
                  return list.length ? <p key={lv}><b>{label} ({list.length}).</b> {list.map((c) => c.description).join("; ")}</p> : null;
                })}
              </div>
            </>
          ) : (
            <>
              <p className="text-sm font-semibold">Causas inmediatas</p>
              <Text v={inv.immediate_causes} />
              <p className="text-sm font-semibold">Causas básicas</p>
              <Text v={inv.basic_causes} />
            </>
          )}
        </Section>

        <Section n={5} title="Conclusiones">
          <Text v={inv.conclusions} />
          {inv.lessons_learned && (<><p className="text-sm font-semibold">Lecciones aprendidas</p><Text v={inv.lessons_learned} /></>)}
        </Section>

        <Section n={6} title="Acciones correctivas">
          {data.actions.length ? (
            <table className="w-full border-collapse text-sm">
              <thead><tr className="bg-gray-100">{["Código", "Acción", "Responsable", "Fecha meta", "Estado"].map((h) => <th key={h} className={`${cell} text-left`}>{h}</th>)}</tr></thead>
              <tbody>
                {data.actions.map((a) => (
                  <tr key={a.code}>
                    <td className={`${cell} font-mono text-xs`}>{a.code}</td>
                    <td className={cell}>{a.title}</td>
                    <td className={cell}>{a.responsible ?? "—"}</td>
                    <td className={cell}>{fmtDate(a.due_on)}</td>
                    <td className={cell}>{ACTION[a.status] ?? a.status}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : <p className="text-sm text-gray-500">Aún no hay acciones correctivas vinculadas.</p>}
        </Section>

        <Section n={7} title="Firmas del equipo investigador">
          {inv.approved_at && <p className="text-sm">Aprobado el {fmtDateTime(inv.approved_at)}{inv.approved_by ? ` por ${inv.approved_by}` : ""}.</p>}
          {data.team.some((m) => m.signed_at) && <p className="text-xs text-gray-600">Firmas electrónicas con evidencia (M-FIR, Ley 27269); se verifican con su código en «Verificar firma».</p>}
          <div className="grid grid-cols-2 gap-6 pt-8 sm:grid-cols-3">
            {data.team.map((m) => <SignBox key={m.id} name={m.name} role={ROLE[m.role] ?? m.role} at={m.signed_at} image={m.image} pending={m.pending} />)}
            {extraSignatures.map((s) => <SignBox key={s.signer_name + s.signed_at} name={s.signer_name} at={s.signed_at} image={s.image} />)}
          </div>
        </Section>
        <p className="pt-4 text-xs text-gray-500">Generado el {fmtDateTime(new Date().toISOString())} · {data.company.name}</p>
      </div>
    </div>
  );
}

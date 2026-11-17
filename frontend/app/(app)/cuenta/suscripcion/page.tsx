"use client";

// SCR-SUS-05 · Mi suscripción (administrador de la cuenta): plan, complementos y módulos con su estado; los bloqueados
// tienen «Solicitar mejora», que llega como consulta al equipo de EscudoSST. Los precios se muestran «Por definir».

import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";

type Detail = {
  plan: { code: string; name: string; status: string; billing_period: string | null; started_at: string | null; trial_ends_at: string | null; current_period_end: string | null;
    limits: { workers: number | null; sites: number | null; companies: number | null }; price: string } | null;
  addons: { code: string; name: string; status: string }[];
  modules: { code: string; name: string; group_number: number; is_addon: boolean; mode: string; company_enabled: boolean }[];
};
const MODE: Record<string, [string, string]> = {
  enabled: ["Activo", "text-ok"], read_only: ["Solo lectura", "text-amber-ink"], disabled_by_company: ["Desactivado por la empresa", "text-muted"], locked: ["No incluido", "text-muted"],
};
const STATUS: Record<string, string> = { trialing: "En prueba", active: "Activa", past_due: "Pago pendiente", grace: "En periodo de gracia", read_only: "Solo lectura", cancelled: "Cancelada" };
const day = (v: string | null) => (v ? new Date(v).toLocaleDateString("es-PE") : "—");

export default function MySubscriptionPage() {
  const [d, setD] = useState<Detail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: Detail }>("/me/subscription/detail", { withCompany: true }).then((r) => setD(r.data)).catch((e) => setError(e instanceof ApiError ? e.detail ?? e.title : "No se pudo cargar."));
  }, []);
  const ask = (code?: string) => api<{ data: { code: string } }>("/me/subscription/upgrade", { method: "POST", withCompany: true, body: code ? { module_code: code } : {} })
    .then((r) => setMsg(`Solicitud enviada (${r.data.code}). El equipo de EscudoSST te responderá en «Ayuda».`)).catch((e) => setMsg(e instanceof ApiError ? e.detail ?? e.title : "No se pudo enviar."));
  if (error) return <p className="rounded-xl border border-line bg-surface p-6 text-muted">{error}</p>;
  if (!d) return <div className="h-48 animate-pulse rounded-xl bg-surface" />;
  return (
    <div className="space-y-4">
      <h1 className="font-display text-2xl font-bold">Mi suscripción</h1>
      {msg && <p role="status" className="rounded-lg bg-ok-soft px-4 py-2 text-sm text-ok">{msg}</p>}
      {d.plan && (
        <section className="grid gap-3 rounded-xl border border-line bg-surface p-4 text-sm sm:grid-cols-4">
          <div><p className="text-xs text-muted">Plan</p><p className="text-lg font-bold">{d.plan.name}</p></div>
          <div><p className="text-xs text-muted">Estado</p><p className="font-semibold">{STATUS[d.plan.status] ?? d.plan.status}</p></div>
          <div><p className="text-xs text-muted">{d.plan.status === "trialing" ? "Prueba hasta" : "Periodo actual hasta"}</p><p className="font-semibold">{day(d.plan.status === "trialing" ? d.plan.trial_ends_at : d.plan.current_period_end)}</p></div>
          <div><p className="text-xs text-muted">Precio</p><p className="font-semibold">{d.plan.price}</p></div>
          <div className="sm:col-span-4"><button type="button" className="rounded-lg bg-brand px-4 py-2 font-semibold text-white" onClick={() => ask()}>Solicitar mejora del plan</button></div>
        </section>
      )}
      <section className="space-y-2 rounded-xl border border-line bg-surface p-4 text-sm">
        <p className="font-semibold">Complementos</p>
        {d.addons.length === 0 ? <p className="text-muted">Sin complementos contratados.</p> : <ul>{d.addons.map((a) => <li key={a.code}>{a.name} · {a.status === "active" ? "Activo" : a.status}</li>)}</ul>}
      </section>
      <section className="space-y-2 rounded-xl border border-line bg-surface p-4 text-sm">
        <p className="font-semibold">Módulos</p>
        <ul className="divide-y divide-line">{d.modules.map((m) => {
          const [label, tone] = MODE[m.mode] ?? [m.mode, "text-muted"];
          return (
            <li key={m.code} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
              <span><b className="font-mono">{m.code}</b> {m.name}{m.is_addon ? " · complemento" : ""}</span>
              <span className="flex items-center gap-3"><span className={tone}>{label}</span>
                {m.mode === "locked" && <button type="button" className="text-xs font-semibold text-brand underline" onClick={() => ask(m.code)}>Solicitar mejora</button>}</span>
            </li>
          );
        })}</ul>
      </section>
    </div>
  );
}

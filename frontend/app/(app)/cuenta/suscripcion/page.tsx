"use client";

// SCR-SUS-05 · Mi suscripción (administrador de la cuenta): plan, complementos y módulos con su estado; los bloqueados
// tienen «Solicitar mejora», que llega como consulta al equipo de EscudoSST. Los precios se muestran «Por definir».

import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";

type Detail = {
  plan: { code: string; name: string; status: string; billing_period: string | null; started_at: string | null; trial_ends_at: string | null; current_period_end: string | null;
    limits: { workers: number | null; sites: number | null; companies: number | null }; price: string } | null;
  usage: Record<string, { used: number; limit: number | null }> | null;
  addons: { code: string; name: string; status: string }[];
  modules: { code: string; name: string; group_number: number; is_addon: boolean; mode: string; company_enabled: boolean }[];
};
const MODE: Record<string, [string, string]> = {
  enabled: ["Activo", "text-ok"], read_only: ["Solo lectura", "text-amber-ink"], disabled_by_company: ["Desactivado por la empresa", "text-muted"], locked: ["No incluido", "text-muted"],
};
const STATUS: Record<string, string> = { trialing: "En prueba", active: "Activa", past_due: "Pago pendiente", grace: "En periodo de gracia", read_only: "Solo lectura", cancelled: "Cancelada" };
// Límites del plan (docs/03 §5): al llegar al tope no se crean más; lo existente sigue igual.
const USAGE: [string, string, string?][] = [
  ["workers", "Trabajadores"], ["sites", "Sedes"], ["management_users", "Usuarios de gestión"], ["qr_points", "Puntos de reporte QR"], ["storage", "Almacenamiento", "GB"],
];
const num = (v: number) => v.toLocaleString("es-PE", { maximumFractionDigits: 2 });
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
      {d.usage && (
        <section className="space-y-2 rounded-xl border border-line bg-surface p-4 text-sm">
          <p className="font-semibold">Uso del plan</p>
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">{USAGE.map(([k, label, unit]) => {
            const u = d.usage?.[k];
            if (!u) return null;
            const full = u.limit !== null && u.used >= u.limit;
            const pct = u.limit ? Math.min(100, Math.round((u.used / u.limit) * 100)) : 0;
            return (
              <li key={k}>
                <p className="text-xs text-muted">{label}</p>
                <p className={`font-semibold ${full ? "text-amber-ink" : ""}`}>{num(u.used)}{unit ? ` ${unit}` : ""} de {u.limit === null ? "sin límite" : `${num(u.limit)}${unit ? ` ${unit}` : ""}`}</p>
                {u.limit !== null && <div className="mt-1 h-1.5 rounded-full bg-line"><div className={`h-1.5 rounded-full ${full ? "bg-amber" : "bg-brand"}`} style={{ width: `${pct}%` }} /></div>}
                {full && <p className="mt-1 text-xs text-muted">Llegaste al límite: mejora el plan para agregar más.</p>}
              </li>
            );
          })}</ul>
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

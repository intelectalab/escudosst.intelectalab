"use client";

// Primeros pasos de una empresa nueva (docs/07 §3.3 «onboarding», M-TEN y M-USR): lista numerada con progreso que
// se muestra en el Inicio mientras la empresa no tenga sedes, puestos o trabajadores, hasta terminarla u ocultarla.
// 1 Datos de la empresa · 2 Sedes · 3 Áreas y puestos · 4 Trabajadores (Excel) · 5 Invitar usuarios · 6 Línea base.
// Cada paso se completa aquí mismo o enlaza a su módulo. Avance: GET /ten/onboarding.

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { useApp } from "@/components/AppContext";
import { api } from "@/lib/api";
import { records, recordsHref } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { LbaWizard } from "./LbaWizard";
import { RefSelect } from "./RefSelect";
import { TrbImport } from "./TrbImport";
import { BTN, errorText } from "./Wizard";

type Company = {
  ruc: string; legal_name: string; trade_name: string | null; ciiu_code: string | null; economic_sector: string | null; risk_level: string | null;
  headcount_declared: number | null; fiscal_address: string | null; phone: string | null; email: string | null;
};
type State = {
  company: Company;
  counts: Record<"sites" | "areas" | "positions" | "workers" | "users" | "assessments", number>;
  steps: { code: string; done: boolean }[];
  completed: boolean;
  dismissed_at: string | null;
  is_new: boolean;
  can: Record<"company" | "sites" | "positions" | "workers" | "users" | "baseline", boolean>;
};

const SECTORS: [string, string][] = [
  ["construction", "Construcción"], ["mining", "Minería"], ["agroindustry", "Agroindustria"], ["manufacturing", "Manufactura"], ["retail", "Comercio"],
  ["services", "Servicios"], ["office", "Oficinas"], ["health", "Salud"], ["education", "Educación"], ["transport", "Transporte"], ["energy", "Energía"], ["other", "Otro"],
];
const RISKS: [string, string][] = [["low", "Bajo"], ["medium", "Medio"], ["high", "Alto"], ["very_high", "Muy alto"]];

const TITLES: Record<string, [string, string]> = {
  company: ["Datos de la empresa", "Sector, nivel de riesgo y dirección: definen el modelo SST y los requisitos."],
  sites: ["Sedes", "Plantas, oficinas, obras o tiendas donde trabaja tu personal."],
  positions: ["Áreas y puestos", "El puesto es la unidad para IPERC, EPP, exámenes y capacitaciones."],
  workers: ["Trabajadores", "Cárgalos desde Excel con su sede y puesto."],
  users: ["Invitar usuarios", "Da acceso a tu equipo con el rol que corresponde."],
  baseline: ["Diagnóstico de línea base", "Mide dónde está tu sistema de gestión y genera las brechas a cerrar."],
};

const storage = () => {
  try {
    return window.localStorage;
  } catch {
    return null;
  }
};

export function Onboarding() {
  const { user, company } = useApp();
  const key = `escudo.onboarding.${user.id}.${company.company_id}`;
  const [s, setS] = useState<State | null>(null);
  const [open, setOpen] = useState<string | null>(null);
  const [hidden, setHidden] = useState(false);
  const [wizard, setWizard] = useState<"trb" | "lba" | null>(null);

  const load = useCallback(() => {
    api<{ data: State }>("/ten/onboarding", { withCompany: true })
      .then((r) => {
        setS(r.data);
        setOpen((o) => o ?? r.data.steps.find((x) => !x.done)?.code ?? null);
      })
      .catch(() => setS(null));
  }, []);
  useEffect(() => {
    const local = storage()?.getItem(key);
    if (local === "dismissed") setHidden(true);
    else load();
  }, [key, load]);

  // Una vez visto, se mantiene hasta terminarlo u ocultarlo (aunque ya haya sedes, puestos y trabajadores).
  const started = typeof window !== "undefined" && storage()?.getItem(key) === "started";
  const visible = !!s && !hidden && !s.dismissed_at && !s.completed && (s.is_new || started);
  useEffect(() => {
    if (visible && !started) storage()?.setItem(key, "started");
  }, [visible, started, key]);
  if (!visible || !s) return null;

  const done = s.steps.filter((x) => x.done).length;
  const pct = Math.round((done / s.steps.length) * 100);
  const dismiss = async () => {
    storage()?.setItem(key, "dismissed");
    setHidden(true);
    if (s.can.company) await api("/ten/onboarding/dismiss", { method: "POST", withCompany: true }).catch(() => undefined);
  };

  return (
    <section className="rounded-xl border border-line bg-surface" aria-label="Primeros pasos">
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-line px-4 py-3 sm:px-5">
        <div>
          <h2 className="font-display text-lg font-semibold">Primeros pasos</h2>
          <p className="text-sm text-muted">Configura {company.name} en seis pasos. Puedes hacerlos en el orden que quieras.</p>
        </div>
        <button type="button" className="text-sm font-semibold text-muted hover:text-fg" onClick={dismiss}>Ocultar</button>
      </div>
      <div className="px-4 pt-3 sm:px-5">
        <div className="h-1.5 overflow-hidden rounded-full bg-bg" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label="Avance de los primeros pasos">
          <div className="h-full bg-brand transition-all" style={{ width: `${pct}%` }} />
        </div>
        <p className="mt-1 text-xs text-muted">{done} de {s.steps.length} pasos listos</p>
      </div>
      <ol className="divide-y divide-line px-2 pb-2 sm:px-3">
        {s.steps.map((st, i) => {
          const [title, hint] = TITLES[st.code];
          const isOpen = open === st.code;
          return (
            <li key={st.code}>
              <button type="button" aria-expanded={isOpen} onClick={() => setOpen(isOpen ? null : st.code)} className="flex w-full items-center gap-3 rounded-lg px-2 py-3 text-left hover:bg-bg">
                <span className={`grid h-7 w-7 shrink-0 place-items-center rounded-full text-sm font-bold ${st.done ? "bg-ok-soft text-ok" : "bg-brand-soft text-brand-strong"}`}>{st.done ? "✓" : i + 1}</span>
                <span className="min-w-0 flex-1">
                  <b className={`block text-sm ${st.done ? "text-muted line-through decoration-1" : ""}`}>{title}</b>
                  <span className="block text-xs text-muted">{hint}</span>
                </span>
                <Icon name="chevron" size={16} />
              </button>
              {isOpen && (
                <div className="space-y-3 px-2 pb-4 sm:pl-12">
                  {st.code === "company" && <CompanyStep s={s} onSaved={load} />}
                  {st.code === "sites" && <SiteStep s={s} onSaved={load} />}
                  {st.code === "positions" && <PositionStep s={s} onSaved={load} />}
                  {st.code === "workers" && (
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-sm text-muted">{s.counts.workers} trabajador(es) registrados.</span>
                      {s.can.workers && <Button variant="primary" icon="download" disabled={!s.counts.sites || !s.counts.positions} onClick={() => setWizard("trb")}>Cargar desde Excel</Button>}
                      <Link className={BTN} href="/m/trb">Ver trabajadores</Link>
                      {(!s.counts.sites || !s.counts.positions) && <p className="w-full text-xs text-amber-ink">Primero crea al menos una sede y un puesto.</p>}
                    </div>
                  )}
                  {st.code === "users" && (
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-sm text-muted">{s.counts.users} usuario(s) con acceso o invitados.</span>
                      <Link className={BTN} href="/m/usr">{s.can.users ? "Invitar usuarios" : "Ver usuarios"}</Link>
                    </div>
                  )}
                  {st.code === "baseline" && (
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-sm text-muted">{s.counts.assessments ? `${s.counts.assessments} diagnóstico(s).` : "Aún no hay diagnóstico."}</span>
                      {s.can.baseline && <Button variant="primary" icon="checklist" onClick={() => setWizard("lba")}>Nuevo diagnóstico</Button>}
                      <Link className={BTN} href="/m/lba">Ir a línea base</Link>
                    </div>
                  )}
                </div>
              )}
            </li>
          );
        })}
      </ol>
      {wizard === "trb" && <TrbImport onClose={() => setWizard(null)} onDone={load} />}
      {wizard === "lba" && <LbaWizard onClose={() => setWizard(null)} onDone={load} />}
    </section>
  );
}

function CompanyStep({ s, onSaved }: { s: State; onSaved: () => void }) {
  const c = s.company;
  const [f, setF] = useState({
    trade_name: c.trade_name ?? "", ciiu_code: c.ciiu_code ?? "", economic_sector: c.economic_sector ?? "", risk_level: c.risk_level ?? "",
    headcount_declared: c.headcount_declared != null ? String(c.headcount_declared) : "", fiscal_address: c.fiscal_address ?? "", phone: c.phone ?? "", email: c.email ?? "",
  });
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<[string, boolean] | null>(null);
  const put = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setF({ ...f, [k]: e.target.value });
  const save = async () => {
    setBusy(true);
    setMsg(null);
    try {
      await api("/ten/company", {
        method: "PATCH", withCompany: true,
        body: Object.fromEntries(Object.entries(f).map(([k, v]) => [k, k === "headcount_declared" ? (v === "" ? null : Number(v)) : v.trim() || null])),
      });
      setMsg(["Datos guardados.", true]);
      onSaved();
    } catch (e) {
      setMsg([errorText(e), false]);
    } finally {
      setBusy(false);
    }
  };
  if (!s.can.company) return <p className="text-sm text-muted">{c.legal_name} · RUC {c.ruc}. Pide al administrador de la cuenta que complete los datos de la empresa.</p>;
  return (
    <div className="space-y-3">
      <p className="text-sm"><b>{c.legal_name}</b> · RUC {c.ruc}</p>
      <div className="grid gap-3 sm:grid-cols-2">
        <FormField label="Nombre comercial"><input className="input" value={f.trade_name} maxLength={200} onChange={put("trade_name")} /></FormField>
        <FormField label="Código CIIU"><input className="input" value={f.ciiu_code} maxLength={5} inputMode="numeric" onChange={put("ciiu_code")} /></FormField>
        <FormField label="Sector">
          <select className="input" value={f.economic_sector} onChange={put("economic_sector")}>
            <option value="">Elige</option>
            {SECTORS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </FormField>
        <FormField label="Nivel de riesgo">
          <select className="input" value={f.risk_level} onChange={put("risk_level")}>
            <option value="">Elige</option>
            {RISKS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </FormField>
        <FormField label="Trabajadores (aprox.)"><input className="input" type="number" min={0} value={f.headcount_declared} onChange={put("headcount_declared")} /></FormField>
        <FormField label="Dirección fiscal"><input className="input" value={f.fiscal_address} maxLength={300} onChange={put("fiscal_address")} /></FormField>
        <FormField label="Teléfono"><input className="input" value={f.phone} maxLength={30} onChange={put("phone")} /></FormField>
        <FormField label="Correo"><input className="input" type="email" value={f.email} maxLength={200} onChange={put("email")} /></FormField>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <Button variant="primary" loading={busy} onClick={save}>Guardar datos</Button>
        {msg && <span role="status" className={`text-sm ${msg[1] ? "text-ok" : "text-bad"}`}>{msg[0]}</span>}
      </div>
    </div>
  );
}

function SiteStep({ s, onSaved }: { s: State; onSaved: () => void }) {
  const [f, setF] = useState({ code: "", name: "", address: "" });
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<[string, boolean] | null>(null);
  const add = async () => {
    setBusy(true);
    setMsg(null);
    try {
      await records.create("TEN", "sites", { code: f.code.trim(), name: f.name.trim(), address: f.address.trim() || null, is_main: s.counts.sites === 0 });
      setF({ code: "", name: "", address: "" });
      setMsg([`Sede «${f.name.trim()}» creada.`, true]);
      onSaved();
    } catch (e) {
      setMsg([errorText(e), false]);
    } finally {
      setBusy(false);
    }
  };
  return (
    <div className="space-y-3">
      <p className="text-sm text-muted">{s.counts.sites} sede(s) registradas. <Link className="font-semibold text-brand hover:underline" href={recordsHref("TEN", "sites")}>Ver sedes</Link></p>
      {s.can.sites && (
        <>
          <div className="grid gap-3 sm:grid-cols-[120px_1fr_1fr]">
            <FormField label="Código"><input className="input" value={f.code} maxLength={20} placeholder="ATE" onChange={(e) => setF({ ...f, code: e.target.value })} /></FormField>
            <FormField label="Nombre"><input className="input" value={f.name} maxLength={200} placeholder="Planta Ate" onChange={(e) => setF({ ...f, name: e.target.value })} /></FormField>
            <FormField label="Dirección"><input className="input" value={f.address} maxLength={300} onChange={(e) => setF({ ...f, address: e.target.value })} /></FormField>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <Button variant="primary" icon="plus" loading={busy} disabled={!f.code.trim() || !f.name.trim()} onClick={add}>Agregar sede</Button>
            {msg && <span role="status" className={`text-sm ${msg[1] ? "text-ok" : "text-bad"}`}>{msg[0]}</span>}
          </div>
        </>
      )}
    </div>
  );
}

function PositionStep({ s, onSaved }: { s: State; onSaved: () => void }) {
  const [a, setA] = useState({ site_id: "", code: "", name: "" });
  const [p, setP] = useState({ area_id: "", code: "", name: "", is_critical: false });
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<[string, boolean] | null>(null);
  const [v, setV] = useState(0);
  const run = async (what: string, fn: () => Promise<unknown>, ok: string) => {
    setBusy(what);
    setMsg(null);
    try {
      await fn();
      setMsg([ok, true]);
      setV((x) => x + 1);
      onSaved();
    } catch (e) {
      setMsg([errorText(e), false]);
    } finally {
      setBusy(null);
    }
  };
  if (!s.counts.sites) return <p className="text-sm text-amber-ink">Primero crea al menos una sede.</p>;
  return (
    <div className="space-y-4">
      <p className="text-sm text-muted">
        {s.counts.areas} área(s) y {s.counts.positions} puesto(s).{" "}
        <Link className="font-semibold text-brand hover:underline" href={recordsHref("TEN", "positions")}>Ver puestos</Link>
      </p>
      {s.can.sites && (
        <div className="space-y-2 rounded-xl border border-line p-3">
          <p className="text-sm font-semibold">Nueva área</p>
          <div className="grid gap-3 sm:grid-cols-3">
            <FormField label="Sede"><RefSelect key={`s${v}`} module="TEN" table="areas" field="site_id" label="Sede" value={a.site_id} onChange={(id) => setA({ ...a, site_id: id })} /></FormField>
            <FormField label="Código"><input className="input" value={a.code} maxLength={20} placeholder="PRD" onChange={(e) => setA({ ...a, code: e.target.value })} /></FormField>
            <FormField label="Nombre"><input className="input" value={a.name} maxLength={200} placeholder="Producción" onChange={(e) => setA({ ...a, name: e.target.value })} /></FormField>
          </div>
          <Button icon="plus" loading={busy === "a"} disabled={!a.site_id || !a.code.trim() || !a.name.trim()}
            onClick={() => run("a", () => records.create("TEN", "areas", { site_id: a.site_id, code: a.code.trim(), name: a.name.trim() }).then(() => setA({ ...a, code: "", name: "" })), `Área «${a.name.trim()}» creada.`)}>
            Agregar área
          </Button>
        </div>
      )}
      {s.can.positions && (
        <div className="space-y-2 rounded-xl border border-line p-3">
          <p className="text-sm font-semibold">Nuevo puesto</p>
          <div className="grid gap-3 sm:grid-cols-3">
            <FormField label="Área"><RefSelect key={`a${v}`} module="TEN" table="positions" field="area_id" label="Área" value={p.area_id} onChange={(id) => setP({ ...p, area_id: id })} /></FormField>
            <FormField label="Código"><input className="input" value={p.code} maxLength={20} placeholder="OP-PRE" onChange={(e) => setP({ ...p, code: e.target.value })} /></FormField>
            <FormField label="Nombre"><input className="input" value={p.name} maxLength={200} placeholder="Operador de prensa" onChange={(e) => setP({ ...p, name: e.target.value })} /></FormField>
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={p.is_critical} onChange={(e) => setP({ ...p, is_critical: e.target.checked })} /> Puesto crítico (alto riesgo)
          </label>
          <Button icon="plus" loading={busy === "p"} disabled={!p.code.trim() || !p.name.trim()}
            onClick={() => run("p", () => records.create("TEN", "positions", { area_id: p.area_id || null, code: p.code.trim(), name: p.name.trim(), is_critical: p.is_critical }).then(() => setP({ ...p, code: "", name: "" })), `Puesto «${p.name.trim()}» creado.`)}>
            Agregar puesto
          </Button>
        </div>
      )}
      {msg && <p role="status" className={`text-sm ${msg[1] ? "text-ok" : "text-bad"}`}>{msg[0]}</p>}
    </div>
  );
}

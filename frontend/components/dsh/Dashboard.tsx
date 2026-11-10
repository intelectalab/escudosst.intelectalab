"use client";

// Tablero principal (M-DSH, SCR-DSH-01): indicadores con semáforo, alertas para actuar hoy y accesos.
// Cada tarjeta explica de dónde sale su valor (numerador / denominador) y lleva al módulo de sus registros.

import Link from "next/link";
import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { useApp } from "@/components/AppContext";
import { recordsHref } from "@/lib/records";
import { Modal } from "@/components/ui/Modal";

type Kpi = {
  code: string;
  name: string;
  description: string | null;
  module: string;
  module_name: string;
  unit: string;
  direction: string;
  locked: boolean;
  required_plan?: string;
  value?: number | null;
  numerator?: number | null;
  denominator?: number | null;
  label?: string;
  basis?: string;
  color?: "green" | "amber" | "red" | null;
  link: Link_;
};

type Link_ = { module: string | null; table: string | null; filters: Record<string, string> };

/** A dónde lleva una tarjeta: la lista ya filtrada de los registros que la componen. */
const hrefOf = (l: Link_) => (l.module && l.table ? recordsHref(l.module, l.table, { filters: l.filters }) : l.module ? `/m/${l.module.toLowerCase()}` : "/inicio");

type Overview = {
  year: number;
  site_id: string | null;
  kpis: Kpi[];
  alerts: { module: string; tone: "bad" | "amber"; count: number; text: string; link: Link_ }[];
  counts: { workers: number; sites: number; positions: number };
  sites: { id: string; name: string }[];
};

const COLORS = {
  green: { card: "border-ok/40", dot: "bg-ok", text: "text-ok", chip: "bg-ok-soft text-ok", label: "En meta" },
  amber: { card: "border-amber-ink/40", dot: "bg-amber-ink", text: "text-amber-ink", chip: "bg-amber-soft text-amber-ink", label: "Atención" },
  red: { card: "border-bad/40", dot: "bg-bad", text: "text-bad", chip: "bg-bad-soft text-bad", label: "Crítico" },
} as const;

const GROUP_NAMES: Record<number, string> = {
  1: "Gestión y configuración", 2: "Planificación", 3: "Riesgos", 4: "Operación en campo", 5: "Personas y salud", 6: "Eventos y mejora", 7: "Contratistas", 8: "Herramientas",
};

export function Dashboard() {
  const { user, company, modules, companiesCount } = useApp();
  const now = new Date().getFullYear();
  const [year, setYear] = useState(now);
  const [site, setSite] = useState("");
  const [data, setData] = useState<Overview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState<Kpi | null>(null);
  const [colorFilter, setColorFilter] = useState<"red" | "amber" | "green" | "none" | null>(null);

  const dsh = modules.find((m) => m.code === "DSH");
  const canSee = !!dsh && dsh.role_can_use && (dsh.mode === "enabled" || dsh.mode === "read_only");

  useEffect(() => {
    if (!canSee) return;
    setError(null);
    const q = new URLSearchParams({ year: String(year) });
    if (site) q.set("site_id", site);
    api<{ data: Overview }>(`/dsh/overview?${q}`, { withCompany: true })
      .then((r) => setData(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [year, site, canSee]);

  const first = user.full_name.split(" ")[0];
  const active = data?.kpis.filter((k) => !k.locked) ?? [];
  const locked = data?.kpis.filter((k) => k.locked) ?? [];
  const red = active.filter((k) => k.color === "red").length;

  const filtered = colorFilter ? active.filter((k) => (colorFilter === "none" ? !k.color : k.color === colorFilter)) : active;
  const groupOf = (code: string) => modules.find((m) => m.code === code)?.group_number ?? 9;
  const groups = [...new Set(filtered.map((k) => groupOf(k.module)))].sort((a, b) => a - b);
  const count = (c: "green" | "amber" | "red") => active.filter((k) => k.color === c).length;

  return (
    <div className="space-y-6">
      {/* Encabezado */}
      <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#0d3b6e] via-[#124a87] to-[#1f7fd1] p-6 text-white shadow-lg sm:p-8">
        <div className="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/10 blur-2xl" aria-hidden="true" />
        <div className="relative flex flex-wrap items-start justify-between gap-6">
          <div className="space-y-3">
            <p className="text-sm font-semibold uppercase tracking-wider text-white/70">{company.name}</p>
            <h1 className="font-display text-3xl font-bold">Hola, {first} 👋</h1>
            <p className="max-w-xl text-white/80">
              {data ? (red > 0 ? `Tienes ${red} ${red === 1 ? "indicador" : "indicadores"} en rojo y ${data.alerts.length} ${data.alerts.length === 1 ? "tema" : "temas"} para atender.` : "Vas bien: ningún indicador en rojo.") : "Este es el resumen de la seguridad y salud de tu empresa."}
            </p>
            {data && (
              <div className="flex flex-wrap gap-2 pt-1">
                {[
                  [data.counts.workers, data.counts.workers === 1 ? "trabajador" : "trabajadores", recordsHref("TRB", "workers", { filters: { status: "active,on_leave", ...(site ? { current_site_id: site } : {}) } })],
                  [data.counts.sites, data.counts.sites === 1 ? "sede" : "sedes", recordsHref("TEN", "sites", { filters: { status: "active" } })],
                  [data.counts.positions, data.counts.positions === 1 ? "puesto" : "puestos", recordsHref("TEN", "positions", { filters: { status: "active" } })],
                ].map(([n, label, href]) => (
                  <Link key={String(label)} href={String(href)} className="rounded-xl bg-white/10 px-4 py-2 ring-1 ring-white/20 transition hover:bg-white/20">
                    <span className="font-display text-xl font-bold">{n}</span> <span className="text-sm text-white/80">{label}</span>
                  </Link>
                ))}
                {canSee && companiesCount > 1 && (
                  <Link href="/grupo" className="rounded-xl bg-[#F4B90F] px-4 py-2 text-sm font-bold text-[#0d3b6e] transition hover:brightness-105">
                    Tablero del grupo ({companiesCount} empresas) →
                  </Link>
                )}
              </div>
            )}
          </div>
          {canSee && data && (
            <div className="flex flex-wrap gap-3">
              <label className="flex flex-col gap-1 text-xs font-semibold text-white/80">
                Sede
                <select className="filter-control !border-white/30 !bg-white/95 !text-[#0b2440]" value={site} onChange={(e) => setSite(e.target.value)}>
                  <option value="">Todas</option>
                  {data.sites.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
              </label>
              <label className="flex flex-col gap-1 text-xs font-semibold text-white/80">
                Año
                <select className="filter-control !border-white/30 !bg-white/95 !text-[#0b2440]" value={year} onChange={(e) => setYear(Number(e.target.value))}>
                  {[now, now - 1, now - 2].map((y) => <option key={y} value={y}>{y}</option>)}
                </select>
              </label>
            </div>
          )}
        </div>
      </section>

      {!canSee && (
        <p className="rounded-xl border border-line bg-surface p-6 text-muted">Tu rol no tiene acceso a los indicadores. Usa el menú para ir a tus módulos.</p>
      )}
      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-bad">{error}</p>}
      {canSee && !data && !error && (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-busy="true">
          {[0, 1, 2, 3, 4, 5].map((i) => <div key={i} className="h-40 animate-pulse rounded-2xl bg-surface shadow-sm" />)}
        </div>
      )}

      {data && (
        <>
          {/* Para atender hoy */}
          <section aria-label="Para atender" className="space-y-3">
            <h2 className="flex items-center gap-2 font-display text-lg font-semibold">
              <span className="grid h-8 w-8 place-items-center rounded-lg bg-bad-soft text-bad">!</span>
              Para atender {data.year === now ? "hoy" : `(${data.year})`}
            </h2>
            {data.alerts.length === 0 ? (
              <p className="rounded-2xl border border-ok/30 bg-ok-soft px-5 py-4 text-sm text-ok">
                ✓ {data.year === now ? "Nada urgente: no hay vencimientos ni pendientes críticos." : "Las alertas solo se muestran para el año actual."}
              </p>
            ) : (
              <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {data.alerts.map((a, i) => (
                  <li key={i}>
                    <Link href={hrefOf(a.link)}
                      className={`group flex items-center gap-4 rounded-2xl border bg-surface p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md ${a.tone === "bad" ? "border-bad/30" : "border-amber-ink/30"}`}>
                      <span className={`grid h-12 w-12 shrink-0 place-items-center rounded-xl font-display text-xl font-bold ${a.tone === "bad" ? "bg-bad text-white" : "bg-amber-soft text-amber-ink"}`}>{a.count}</span>
                      <span className="flex-1 text-sm font-semibold">{a.text}</span>
                      <span className="text-muted transition group-hover:translate-x-0.5 group-hover:text-brand">→</span>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {/* Indicadores */}
          <section aria-label="Indicadores" className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h2 className="flex items-center gap-2 font-display text-lg font-semibold">
                <span className="grid h-8 w-8 place-items-center rounded-lg bg-brand-soft text-brand-strong">▦</span>
                Indicadores {data.year}
              </h2>
              <div className="flex flex-wrap gap-2 text-sm" role="group" aria-label="Filtrar indicadores">
                {([[null, `Todos (${active.length})`, "border-line"], ["red", `Crítico (${count("red")})`, "border-bad/40 text-bad"], ["amber", `Atención (${count("amber")})`, "border-amber-ink/40 text-amber-ink"], ["green", `En meta (${count("green")})`, "border-ok/40 text-ok"]] as const).map(([v, label, cls]) => (
                  <button key={String(v)} type="button" onClick={() => setColorFilter(v)}
                    className={`rounded-full border px-3 py-1 font-semibold transition ${cls} ${colorFilter === v ? "bg-bg ring-2 ring-brand/30" : "bg-surface hover:bg-bg"}`}>{label}</button>
                ))}
              </div>
            </div>
            <p className="text-sm text-muted">Toca un indicador para ver los registros de donde sale; el botón «?» explica cómo se calcula.</p>
            {groups.map((g) => (
              <div key={g} className="space-y-3">
                <h3 className="text-xs font-bold uppercase tracking-wider text-muted">{GROUP_NAMES[g] ?? "Otros"}</h3>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                  {filtered.filter((k) => groupOf(k.module) === g).map((k) => {
                    const c = k.color ? COLORS[k.color] : null;
                    const pct = k.unit === "%" && typeof k.value === "number" ? Math.max(0, Math.min(100, k.value)) : null;
                    return (
                      <div key={k.code} className="group relative overflow-hidden rounded-2xl border border-line bg-surface shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                        <span className={`absolute inset-x-0 top-0 h-1.5 ${c ? c.dot : "bg-line"}`} aria-hidden="true" />
                        <Link href={hrefOf(k.link)} className="block space-y-3 p-5 pr-12 pt-6" title="Ver los registros de este indicador">
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="rounded-md bg-bg px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-muted">{k.module}</span>
                            {c && (
                              <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold ${c.chip}`}>
                                <span className={`h-1.5 w-1.5 rounded-full ${c.dot}`} aria-hidden="true" />{c.label}
                              </span>
                            )}
                          </div>
                          <p className="text-sm font-semibold leading-snug">{k.name}</p>
                          <p className={`font-display text-4xl font-bold ${k.value === null ? "text-muted" : c ? c.text : ""}`}>{k.label}</p>
                          {pct !== null && (
                            <div className="h-2 overflow-hidden rounded-full bg-bg" aria-hidden="true">
                              <div className={`h-full rounded-full ${c ? c.dot : "bg-brand"}`} style={{ width: `${pct}%` }} />
                            </div>
                          )}
                          <p className="line-clamp-2 text-xs text-muted">{k.basis}</p>
                          <p className="text-xs font-semibold text-brand group-hover:underline">Ver registros →</p>
                        </Link>
                        <button onClick={() => setOpen(k)}
                          className="absolute right-3 top-4 grid h-7 w-7 place-items-center rounded-full border border-line bg-surface text-xs font-bold text-muted hover:border-brand hover:text-brand"
                          aria-label={`Cómo se calcula: ${k.name}`} title="¿Cómo se calcula?">?</button>
                      </div>
                    );
                  })}
                </div>
              </div>
            ))}
            {filtered.length === 0 && <p className="rounded-xl bg-bg px-4 py-3 text-sm text-muted">No hay indicadores con ese estado.</p>}
          </section>

          {locked.length > 0 && (
            <section aria-label="Indicadores de otros planes" className="space-y-3">
              <h2 className="font-semibold text-muted">Disponibles en otros planes</h2>
              <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {locked.map((k) => (
                  <Link key={k.code} href={hrefOf(k.link)} className="rounded-xl border border-dashed border-line bg-bg p-4 text-sm hover:border-brand">
                    <p className="font-semibold text-muted">🔒 {k.name}</p>
                    <p className="text-xs text-muted">Incluido en el plan {k.required_plan}. Toca para ver qué incluye.</p>
                  </Link>
                ))}
              </div>
            </section>
          )}
        </>
      )}

      {open && (
        <Modal title={open.name} subtitle={`Módulo: ${open.module_name}`} icon="chart" onClose={() => setOpen(null)} size="sm"
          footer={
            <div className="flex w-full justify-end">
              <Link href={hrefOf(open.link)} className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-strong">
                Ver registros
              </Link>
            </div>
          }
        >
          <div className="space-y-3 text-sm">
            <p className="font-display text-4xl font-semibold">{open.label}</p>
            <p>{open.basis}</p>
            {open.numerator !== null && open.denominator !== null && open.denominator !== undefined && (
              <p className="rounded-lg bg-bg px-3 py-2 text-muted">
                Numerador: <b className="text-fg">{open.numerator}</b> · Denominador: <b className="text-fg">{open.denominator}</b>
              </p>
            )}
            <p className="text-muted">
              {open.direction === "higher_better" ? "Mientras más alto, mejor." : open.direction === "lower_better" ? "Mientras más bajo, mejor." : "Sirve para seguir la tendencia."}
              {open.unit === "%" && open.direction === "higher_better" && " Verde desde 90 %, ámbar desde 70 %."}
            </p>
          </div>
        </Modal>
      )}
    </div>
  );
}

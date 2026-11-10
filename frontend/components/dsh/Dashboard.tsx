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
  green: { card: "border-ok/40", dot: "bg-ok", text: "text-ok", label: "En meta" },
  amber: { card: "border-amber-ink/40", dot: "bg-amber-ink", text: "text-amber-ink", label: "Atención" },
  red: { card: "border-bad/40", dot: "bg-bad", text: "text-bad", label: "Crítico" },
} as const;

export function Dashboard() {
  const { user, company, modules } = useApp();
  const now = new Date().getFullYear();
  const [year, setYear] = useState(now);
  const [site, setSite] = useState("");
  const [data, setData] = useState<Overview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState<Kpi | null>(null);

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

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">Hola, {first}</h1>
          <p className="text-muted">
            {company.name}
            {data && (
              <>
                {" · "}
                <Link className="font-semibold text-brand hover:underline" href={recordsHref("TRB", "workers", { filters: { status: "active,on_leave", ...(site ? { current_site_id: site } : {}) } })}>
                  {data.counts.workers} trabajadores
                </Link>
                {" · "}
                <Link className="font-semibold text-brand hover:underline" href={recordsHref("TEN", "sites", { filters: { status: "active" } })}>
                  {data.counts.sites} sede(s)
                </Link>
                {" · "}
                <Link className="font-semibold text-brand hover:underline" href={recordsHref("TEN", "positions", { filters: { status: "active" } })}>
                  {data.counts.positions} puestos
                </Link>
              </>
            )}
          </p>
        </div>
        {canSee && data && (
          <div className="flex flex-wrap gap-3">
            <label className="flex flex-col gap-1 text-xs font-semibold text-muted">
              Sede
              <select className="filter-control" value={site} onChange={(e) => setSite(e.target.value)}>
                <option value="">Todas</option>
                {data.sites.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </select>
            </label>
            <label className="flex flex-col gap-1 text-xs font-semibold text-muted">
              Año
              <select className="filter-control" value={year} onChange={(e) => setYear(Number(e.target.value))}>
                {[now, now - 1, now - 2].map((y) => (
                  <option key={y} value={y}>
                    {y}
                  </option>
                ))}
              </select>
            </label>
          </div>
        )}
      </div>

      {!canSee && (
        <p className="rounded-xl border border-line bg-surface p-6 text-muted">Tu rol no tiene acceso a los indicadores. Usa el menú para ir a tus módulos.</p>
      )}
      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-bad">{error}</p>}
      {canSee && !data && !error && <p className="text-muted">Calculando indicadores…</p>}

      {data && (
        <>
          {/* Alertas para actuar hoy */}
          <section aria-label="Para atender" className="rounded-2xl border border-line bg-surface p-5 shadow-sm">
            <h2 className="font-semibold">Para atender {data.year === now ? "hoy" : `(${data.year})`}</h2>
            {data.alerts.length === 0 ? (
              <p className="mt-1 text-sm text-muted">{data.year === now ? "Nada urgente: no hay vencimientos ni pendientes críticos." : "Las alertas solo se muestran para el año actual."}</p>
            ) : (
              <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                {data.alerts.map((a, i) => (
                  <li key={i}>
                    <Link
                      href={hrefOf(a.link)}
                      className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium hover:brightness-95 ${a.tone === "bad" ? "bg-bad-soft text-bad" : "bg-amber-soft text-amber-ink"}`}
                    >
                      <span className="text-lg font-bold tabular-nums">{a.count}</span>
                      <span>{a.text}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {/* Indicadores */}
          <section aria-label="Indicadores" className="space-y-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <h2 className="font-semibold">Indicadores {data.year}</h2>
              <p className="text-sm text-muted">
                {active.length} indicadores · {red > 0 ? `${red} en rojo` : "ninguno en rojo"} · toca uno para ver de dónde sale
              </p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              {active.map((k) => {
                const c = k.color ? COLORS[k.color] : null;
                return (
                  <div key={k.code} className={`relative rounded-2xl border bg-surface shadow-sm transition hover:shadow-md ${c ? c.card : "border-line"}`}>
                    <Link href={hrefOf(k.link)} className="block space-y-2 p-5 pr-12" title="Ver los registros de este indicador">
                      <div className="flex items-start justify-between gap-2">
                        <p className="text-sm font-semibold">{k.name}</p>
                        {c && (
                          <span className={`inline-flex shrink-0 items-center gap-1 text-xs font-semibold ${c.text}`}>
                            <span className={`h-2 w-2 rounded-full ${c.dot}`} aria-hidden="true" />
                            {c.label}
                          </span>
                        )}
                      </div>
                      <p className={`font-display text-3xl font-semibold ${k.value === null ? "text-muted" : c ? c.text : ""}`}>{k.label}</p>
                      <p className="line-clamp-2 text-xs text-muted">{k.basis}</p>
                      <p className="text-xs font-semibold text-brand">Ver registros →</p>
                    </Link>
                    <button
                      onClick={() => setOpen(k)}
                      className="absolute right-3 top-3 grid h-7 w-7 place-items-center rounded-full border border-line text-xs font-bold text-muted hover:border-brand hover:text-brand"
                      aria-label={`Cómo se calcula: ${k.name}`}
                      title="¿Cómo se calcula?"
                    >
                      ?
                    </button>
                  </div>
                );
              })}
            </div>
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

"use client";

// Tablero principal (M-DSH, SCR-DSH-01): indicadores con semáforo, alertas para actuar hoy y accesos.
// Cada tarjeta explica de dónde sale su valor (numerador / denominador) y lleva al módulo de sus registros.

import Link from "next/link";
import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { useApp } from "@/components/AppContext";
import { recordsHref } from "@/lib/records";
import { Modal } from "@/components/ui/Modal";
import { Icon } from "@/components/ui/Icon";
import { ActionsCard, ActivityCard, HeatCard, ManagementPanel, MonthlyCard, PasstCard, type Home } from "@/components/dsh/HomeBlocks";
import { Onboarding } from "@/components/modules/Onboarding";
import { ElectionHomeCard } from "@/components/modules/ComVoteLinks";

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
  const [home, setHome] = useState<Home | null>(null);
  const isManagement = company.role.code === "management";

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
    if (isManagement) q.set("view", "management");
    setHome(null);
    api<{ data: Home }>(`/dsh/home?${q}`, { withCompany: true })
      .then((r) => setHome(r.data))
      .catch(() => setHome(null));
  }, [year, site, canSee, isManagement]);

  const first = user.full_name.split(" ")[0];
  const active = data?.kpis.filter((k) => !k.locked) ?? [];

  const filtered = colorFilter ? active.filter((k) => (colorFilter === "none" ? !k.color : k.color === colorFilter)) : active;
  const groupOf = (code: string) => modules.find((m) => m.code === code)?.group_number ?? 9;
  const groups = [...new Set(filtered.map((k) => groupOf(k.module)))].sort((a, b) => a - b);
  const count = (c: "green" | "amber" | "red") => active.filter((k) => k.color === c).length;

  return (
    <div className="space-y-4">
      <Onboarding />
      {/* Tarjeta «Elección del comité» del inicio del trabajador (docs/07 §5.2): solo con votación abierta. */}
      {modules.some((m) => m.code === "COM" && m.role_can_use) && <ElectionHomeCard />}
      {/* Cabecera plana (prototipo m-inicio.js) */}
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="space-y-1">
          <nav aria-label="Migas de pan" className="text-xs text-muted">{isManagement ? "Panel de Gerencia" : "Panel de inicio"}</nav>
          <h1 className="font-display text-2xl font-bold">{isManagement ? "Panel de Gerencia" : `Hola, ${first}`}</h1>
          <p className="text-sm text-muted">
            {isManagement ? `Resumen ejecutivo de Seguridad y Salud en el Trabajo de ${company.name} · ` : ""}Así está el sistema de gestión SST de {company.name} hoy, {new Intl.DateTimeFormat("es-PE", { dateStyle: "long", timeZone: "America/Lima" }).format(new Date())}.
          </p>
        </div>
        {canSee && data && (
          <div className="flex flex-wrap items-center gap-2">
            {companiesCount > 1 && (
              <Link href="/grupo" className="inline-flex h-10 items-center rounded-lg border border-line bg-surface px-3 text-sm font-semibold hover:bg-bg">Tablero del grupo ({companiesCount})</Link>
            )}
            <label className="sr-only" htmlFor="dsh-site">Sede</label>
            <select id="dsh-site" className="filter-control h-10 !w-auto" value={site} onChange={(e) => setSite(e.target.value)}>
              <option value="">Todas las sedes</option>
              {data.sites.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
            <label className="sr-only" htmlFor="dsh-year">Año</label>
            <select id="dsh-year" className="filter-control h-10 !w-auto" value={year} onChange={(e) => setYear(Number(e.target.value))}>
              {[now, now - 1, now - 2].map((y) => <option key={y} value={y}>{y}</option>)}
            </select>
          </div>
        )}
      </div>

      {!canSee && (
        <p className="rounded-xl border border-line bg-surface p-6 text-muted">Tu rol no tiene acceso a los indicadores. Usa el menú para ir a tus módulos.</p>
      )}
      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-bad">{error}</p>}
      {canSee && !data && !error && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5" aria-busy="true">
          {[0, 1, 2, 3, 4, 5, 6, 7].map((i) => <div key={i} className="h-32 animate-pulse rounded-xl bg-surface shadow-sm" />)}
        </div>
      )}

      {data && isManagement && home?.management && <ManagementPanel data={home} />}

      {data && (
        <>
          <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
            <div className="min-w-0 space-y-4">
          {!isManagement && home?.passt && <PasstCard data={home.passt} year={home.year} />}
          {/* Indicadores: una sola grilla densa, ordenada por tema */}
          <section aria-label="Indicadores" className="space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="flex items-center gap-2 font-display text-base font-semibold">
                <span className="grid h-7 w-7 place-items-center rounded-lg bg-brand-soft text-brand-strong"><Icon name="chart" size={15} /></span>
                Indicadores {data.year}
                <span className="hidden text-xs font-normal text-muted lg:inline">· toca uno para ver sus registros; «?» explica el cálculo</span>
              </h2>
              <div className="flex flex-wrap gap-1.5 text-xs" role="group" aria-label="Filtrar indicadores">
                {([[null, `Todos (${active.length})`, "border-line", ""], ["red", `Crítico (${count("red")})`, "border-bad/40 text-bad", "bg-bad"], ["amber", `Atención (${count("amber")})`, "border-amber-ink/40 text-amber-ink", "bg-amber-ink"], ["green", `En meta (${count("green")})`, "border-ok/40 text-ok", "bg-ok"]] as const).map(([v, label, cls, dot]) => (
                  <button key={String(v)} type="button" onClick={() => setColorFilter(v)} aria-pressed={colorFilter === v}
                    className={`inline-flex h-8 items-center gap-1.5 rounded-full border px-3 font-semibold transition ${cls} ${colorFilter === v ? "bg-bg ring-2 ring-brand/30" : "bg-surface hover:bg-bg"}`}>
                    {dot && <span className={`h-2 w-2 rounded-full ${dot}`} aria-hidden="true" />}{label}
                  </button>
                ))}
              </div>
            </div>
            <div className="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-3 2xl:grid-cols-4">
              {groups.flatMap((g) => filtered.filter((k) => groupOf(k.module) === g)).map((k) => {
                const c = k.color ? COLORS[k.color] : null;
                const pct = k.unit === "%" && typeof k.value === "number" ? Math.max(0, Math.min(100, k.value)) : null;
                const edge = k.color === "green" ? "border-l-ok" : k.color === "amber" ? "border-l-amber-ink" : k.color === "red" ? "border-l-bad" : "border-l-line";
                return (
                  <div key={k.code} className={`group flex flex-col rounded-xl border border-l-4 bg-surface p-3 transition hover:border-brand/40 sm:p-4 ${c ? c.card : "border-line"} ${edge}`}>
                    <div className="flex items-center gap-1.5">
                      <span className="truncate text-[11px] font-semibold text-muted">{GROUP_NAMES[groupOf(k.module)]}</span>
                      {c && (
                        <span className={`inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-bold ${c.chip}`}>
                          <span className={`h-1.5 w-1.5 rounded-full ${c.dot}`} aria-hidden="true" />{c.label}
                        </span>
                      )}
                      <button onClick={() => setOpen(k)}
                        className="ml-auto grid h-6 w-6 shrink-0 place-items-center rounded-full border border-line text-[11px] font-bold text-muted hover:border-brand hover:text-brand"
                        aria-label={`Cómo se calcula: ${k.name}`} title="¿Cómo se calcula?">?</button>
                    </div>
                    <Link href={hrefOf(k.link)} className="mt-2 flex flex-1 flex-col" title="Ver los registros de este indicador">
                      <p className="line-clamp-2 min-h-[2.5rem] text-[13px] font-semibold leading-snug">{k.name}</p>
                      <div className="mt-1 flex items-end justify-between gap-2">
                        <p className={`font-display font-bold leading-none ${k.value === null || k.value === undefined ? "text-lg text-muted" : `text-3xl ${c ? c.text : "text-brand"}`}`}>{k.label}</p>
                        <span className="hidden text-[11px] font-semibold text-brand sm:inline">Ver →</span>
                      </div>
                      {pct !== null && (
                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-bg" aria-hidden="true">
                          <div className={`h-full rounded-full ${c ? c.dot : "bg-brand"}`} style={{ width: `${pct}%` }} />
                        </div>
                      )}
                      <p className="mt-2 line-clamp-2 hidden text-[11px] leading-snug text-muted sm:block">{k.basis}</p>
                    </Link>
                  </div>
                );
              })}
            </div>
            {filtered.length === 0 && <p className="rounded-xl bg-bg px-4 py-3 text-sm text-muted">No hay indicadores con ese estado.</p>}
          </section>
          {!isManagement && home?.monthly && <MonthlyCard data={home.monthly} />}
          {home?.actions && <ActionsCard data={home.actions} />}

            </div>
            <aside className="space-y-4">
              <section aria-label="Vencimientos próximos" className="rounded-xl border border-line bg-surface p-4">
                <div className="mb-3 flex items-center justify-between gap-2">
                  <h2 className="font-display text-base font-semibold">Vencimientos próximos</h2>
                  {data.alerts.length > 0 && <span className="rounded-full bg-bad-soft px-2 py-0.5 text-xs font-bold text-bad">{data.alerts.length} por atender</span>}
                </div>
                {data.alerts.length === 0 ? (
                  <p className="flex items-center gap-2 text-sm text-ok"><Icon name="check" size={16} /> {data.year === now ? "Nada vencido ni por vencer." : "Las alertas solo se muestran para el año actual."}</p>
                ) : (
                  <ul className="divide-y divide-line">
                    {data.alerts.map((a, i) => (
                      <li key={i}>
                        <Link href={hrefOf(a.link)} className="group flex items-center gap-3 py-2.5">
                          <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${a.tone === "bad" ? "bg-bad" : "bg-[#F4B90F]"}`} aria-hidden="true" />
                          <span className="min-w-0 flex-1 text-sm">{a.text}</span>
                          <span className={`font-display text-base font-bold ${a.tone === "bad" ? "text-bad" : "text-amber-ink"}`}>{a.count}</span>
                          <span className="sr-only">{a.tone === "bad" ? "Vencido" : "Por vencer"}</span>
                        </Link>
                      </li>
                    ))}
                  </ul>
                )}
              </section>
              {home?.heatmap && <HeatCard data={home.heatmap} />}
              {home && <ActivityCard items={home.activity} />}
              <section aria-label="Tu empresa" className="rounded-xl border border-line bg-surface p-4">
                <h2 className="mb-3 font-display text-base font-semibold">Tu empresa</h2>
                <ul className="space-y-2 text-sm">
                  {([
                    [data.counts.workers, "Trabajadores activos", recordsHref("TRB", "workers", { filters: { status: "active,on_leave", ...(site ? { current_site_id: site } : {}) } })],
                    [data.counts.sites, "Sedes", recordsHref("TEN", "sites", { filters: { status: "active" } })],
                    [data.counts.positions, "Puestos de trabajo", recordsHref("TEN", "positions", { filters: { status: "active" } })],
                  ] as const).map(([n, label, href]) => (
                    <li key={label}>
                      <Link href={href} className="flex items-center justify-between rounded-lg px-2 py-1.5 hover:bg-bg">
                        <span className="text-muted">{label}</span>
                        <b className="font-display text-lg text-brand">{n}</b>
                      </Link>
                    </li>
                  ))}
                </ul>
              </section>
            </aside>
          </div>

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

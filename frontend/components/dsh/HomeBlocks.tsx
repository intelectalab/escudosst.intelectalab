"use client";

// Bloques del panel de inicio (M-DSH SCR-DSH-01, prototipo m-inicio.js): programa anual, índices mensuales,
// acciones correctivas abiertas, mapa de calor del IPERC vigente, actividad reciente y Panel de Gerencia.
// Todo sale de GET /dsh/home con los registros reales de la empresa.

import Link from "next/link";
import { useState } from "react";
import { recordsHref } from "@/lib/records";
import { Modal } from "@/components/ui/Modal";
import { Icon } from "@/components/ui/Icon";

export type Month = { month: number; accidents: number; lost_days: number; fi: number | null; si: number | null; ai: number | null };
export type YearSummary = { year: number; months: Month[]; total: { accidents: number; lost_days: number; fi: number | null; si: number | null; ai: number | null } };
type HeatRow = { id: string; matrix_id: string; matrix: string; row_number: number; position: string | null; hazard: string; risk: string; level: string; color: string | null };

export type Home = {
  year: number;
  passt: { program_id: string | null; program_title: string | null; total: number; done: number; pct: number | null; bars: { type: string; label: string; total: number; done: number; pct: number | null }[] } | null;
  monthly: { current: YearSummary; previous: YearSummary | null } | null;
  actions: { open: number; items: { id: string; code: string; title: string; source_type: string; priority: string; status: string; due_on: string; responsible: string | null }[] } | null;
  heatmap: { total: number; cells: { p: number; s: number; count: number; rows: HeatRow[] }[] } | null;
  activity: { id: string; at: string; actor: string; verb: string; full?: boolean; what: string; name: string | null; module: string | null; module_name: string | null; table: string | null; entity_id: string | null }[];
  management?: {
    compliance: { pct: number; indicators: number } | null;
    accidents: { current: number; previous: number; days: number; previous_days: number } | null;
    sunafil: { score: number | null; gaps: number } | null;
    accident_rate: { current: YearSummary; previous: YearSummary | null } | null;
    pending: { module: string; table: string; id: string; name: string; kind: string; since: string }[];
    sites: { id: string; name: string; pct: number | null }[];
    critical: { id: string; hazard: string; risk: string; matrix_id: string; matrix_code: string; place: string | null; position_name: string | null; pending_controls: number }[] | null;
  };
};

const MONTHS = ["E", "F", "M", "A", "M", "J", "J", "A", "S", "O", "N", "D"];
const fmtDate = (d: string | null) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}` : "—");
const num = (n: number | null | undefined, dec = 2) => (n === null || n === undefined ? "—" : n.toLocaleString("es-PE", { maximumFractionDigits: dec }));
const tone = (p: number | null) => (p === null ? "bg-line" : p >= 90 ? "bg-ok" : p >= 70 ? "bg-amber-ink" : "bg-bad");
const toneText = (p: number | null) => (p === null ? "text-muted" : p >= 90 ? "text-ok" : p >= 70 ? "text-amber-ink" : "text-bad");

const ACTION_STATUS: Record<string, [string, string]> = {
  open: ["Abierta", "bg-brand-soft text-brand-strong"],
  in_progress: ["En proceso", "bg-amber-soft text-amber-ink"],
  overdue: ["Vencida", "bg-bad-soft text-bad"],
};

export function Card({ title, link, extra, children, className = "" }: { title: string; link?: [string, string]; extra?: React.ReactNode; children: React.ReactNode; className?: string }) {
  return (
    <section aria-label={title} className={`rounded-xl border border-line bg-surface p-4 ${className}`}>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-display text-base font-semibold">{title}</h2>
        {extra}
        {link && <Link href={link[1]} className="text-sm font-semibold text-brand hover:underline">{link[0]}</Link>}
      </div>
      {children}
    </section>
  );
}

function Bar({ pct }: { pct: number | null }) {
  return (
    <div className="h-2 overflow-hidden rounded-full bg-bg" aria-hidden="true">
      <div className={`h-full rounded-full ${tone(pct)}`} style={{ width: `${pct ?? 0}%` }} />
    </div>
  );
}

export function Ring({ pct, label, size = 112 }: { pct: number | null; label: string; size?: number }) {
  const r = 46;
  const c = 2 * Math.PI * r;
  const stroke = pct === null ? "var(--color-line, #ddd)" : pct >= 90 ? "var(--color-ok, #22A35A)" : pct >= 70 ? "var(--color-amber-ink, #B7791F)" : "var(--color-bad, #E2453C)";
  return (
    <div className="relative shrink-0" style={{ width: size, height: size }} role="img" aria-label={`${label}: ${pct === null ? "no aplica" : `${pct} %`}`}>
      <svg viewBox="0 0 112 112" width={size} height={size} className="-rotate-90">
        <circle cx="56" cy="56" r={r} fill="none" stroke="currentColor" className="text-bg" strokeWidth="12" />
        {pct !== null && <circle cx="56" cy="56" r={r} fill="none" stroke={stroke} strokeWidth="12" strokeLinecap="round" strokeDasharray={`${(c * pct) / 100} ${c}`} />}
      </svg>
      <span className={`absolute inset-0 grid place-items-center font-display text-2xl font-bold ${toneText(pct)}`}>{pct === null ? "—" : `${pct}%`}</span>
    </div>
  );
}

// ─── Programa anual ─────────────────────────────────────────

export function PasstCard({ data, year }: { data: NonNullable<Home["passt"]>; year: number }) {
  return (
    <Card title={`Cumplimiento del Programa Anual SST ${year}`} link={["Ver PASST", "/m/pas"]}>
      {data.total === 0 ? (
        <p className="text-sm text-muted">Aún no hay actividades en el programa anual de este año. Créalo en el módulo Programa Anual SST.</p>
      ) : (
        <div className="flex flex-wrap items-center gap-5">
          <div className="text-center">
            <Ring pct={data.pct} label="Avance del programa anual" />
            <p className="mt-1 text-xs text-muted">{data.done} de {data.total} actividades</p>
          </div>
          <div className="min-w-[13rem] flex-1 space-y-2.5">
            {data.bars.map((b) => (
              <div key={b.type}>
                <div className="flex justify-between text-sm">
                  <span>{b.label}</span>
                  <b className={b.pct === null ? "font-normal text-muted" : ""}>{b.pct === null ? "Sin programar" : `${b.pct}%`}</b>
                </div>
                <Bar pct={b.pct} />
              </div>
            ))}
          </div>
        </div>
      )}
    </Card>
  );
}

// ─── Gráfico de líneas (SVG propio) ──────────────────────────

type Series = { name: string; values: (number | null)[]; color: string; dashed?: boolean; area?: boolean };

export function LineChart({ series, labels, aria }: { series: Series[]; labels: string[]; aria: string }) {
  const W = 560;
  const H = 200;
  const pad = { l: 34, r: 10, t: 12, b: 24 };
  const all = series.flatMap((s) => s.values.filter((v): v is number => v !== null));
  const max = Math.max(1, ...all) * 1.15;
  const x = (i: number) => pad.l + (labels.length <= 1 ? 0 : (i * (W - pad.l - pad.r)) / (labels.length - 1));
  const y = (v: number) => H - pad.b - (v / max) * (H - pad.t - pad.b);
  const path = (vals: (number | null)[]) => {
    let d = "";
    let pen = false;
    vals.forEach((v, i) => {
      if (v === null) {
        pen = false;
        return;
      }
      d += `${pen ? "L" : "M"}${x(i).toFixed(1)} ${y(v).toFixed(1)} `;
      pen = true;
    });
    return d;
  };
  const ticks = [0, max / 2, max].map((t) => Math.round(t * 10) / 10);
  const [hover, setHover] = useState<number | null>(null);

  if (all.length === 0) return <p className="text-sm text-muted">Sin datos: registra las horas-hombre del mes en Estadísticas para calcular los índices.</p>;

  return (
    <div>
      <svg viewBox={`0 0 ${W} ${H}`} className="w-full" role="img" aria-label={aria} onMouseLeave={() => setHover(null)}>
        {ticks.map((t) => (
          <g key={t}>
            <line x1={pad.l} x2={W - pad.r} y1={y(t)} y2={y(t)} stroke="currentColor" className="text-line" strokeWidth="1" />
            <text x={pad.l - 6} y={y(t) + 4} textAnchor="end" fontSize="10" fill="currentColor" className="text-muted">{num(t, 1)}</text>
          </g>
        ))}
        {labels.map((l, i) => (
          <text key={i} x={x(i)} y={H - 6} textAnchor="middle" fontSize="10" fill="currentColor" className="text-muted">{l}</text>
        ))}
        {series.map((s) =>
          s.area ? (
            <path key={`${s.name}-a`} d={`${path(s.values)} L${x(s.values.length - 1)} ${H - pad.b} L${x(0)} ${H - pad.b} Z`} fill={s.color} opacity="0.08" />
          ) : null,
        )}
        {series.map((s) => (
          <path key={s.name} d={path(s.values)} fill="none" stroke={s.color} strokeWidth="2.25" strokeDasharray={s.dashed ? "5 4" : undefined} strokeLinejoin="round" strokeLinecap="round" />
        ))}
        {series.map((s) =>
          s.values.map((v, i) => (v === null ? null : <circle key={`${s.name}${i}`} cx={x(i)} cy={y(v)} r={hover === i ? 4 : 2.5} fill={s.color} />)),
        )}
        {labels.map((_, i) => (
          <rect key={`h${i}`} x={x(i) - 12} y={pad.t} width="24" height={H - pad.t - pad.b} fill="transparent" onMouseEnter={() => setHover(i)} />
        ))}
        {hover !== null && <line x1={x(hover)} x2={x(hover)} y1={pad.t} y2={H - pad.b} stroke="currentColor" className="text-line" strokeDasharray="2 3" />}
      </svg>
      <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
        {series.map((s) => (
          <span key={s.name} className="inline-flex items-center gap-1.5">
            <span className="inline-block h-0.5 w-4" style={{ background: s.color, borderTop: s.dashed ? `2px dashed ${s.color}` : undefined }} aria-hidden="true" />
            {s.name}
            {hover !== null && <b className="text-fg">{num(s.values[hover])}</b>}
          </span>
        ))}
        {hover !== null && <span>· mes {hover + 1}</span>}
      </div>
    </div>
  );
}

const BRAND = "#1b6fb7";
const AMBER = "#F4B90F";
const RED = "#E2453C";
const GREY = "#8A93A0";

export function MonthlyCard({ data }: { data: NonNullable<Home["monthly"]> }) {
  const m = data.current.months;
  return (
    <Card title={`Índices mensuales ${data.current.year}`} extra={<span className="text-xs text-muted">IF, IG ÷ 10 e IA por mes</span>} link={["Ver estadísticas", "/m/est"]}>
      <LineChart
        aria="Índices de frecuencia, gravedad y accidentabilidad por mes"
        labels={m.map((x) => MONTHS[x.month - 1])}
        series={[
          { name: "Índice de frecuencia", values: m.map((x) => x.fi), color: BRAND, area: true },
          { name: "Índice de gravedad ÷ 10", values: m.map((x) => (x.si === null ? null : Math.round(x.si) / 10)), color: AMBER },
          { name: "Índice de accidentabilidad", values: m.map((x) => x.ai), color: RED },
        ]}
      />
      <p className="mt-2 text-xs text-muted">
        Acumulado del año: IF {num(data.current.total.fi)} · IG {num(data.current.total.si)} · IA {num(data.current.total.ai)} · {data.current.total.accidents} accidente(s) con baja.
      </p>
    </Card>
  );
}

// ─── Acciones correctivas ─────────────────────────────────

export function ActionsCard({ data }: { data: NonNullable<Home["actions"]> }) {
  const today = new Date().toISOString().slice(0, 10);
  return (
    <Card title="Acciones correctivas abiertas" extra={<span className="text-xs text-muted">{data.open} abierta(s)</span>} link={["Ver todas", recordsHref("ACR", "corrective_actions", { filters: { status: "open,in_progress,overdue" } })]}>
      {data.items.length === 0 ? (
        <p className="flex items-center gap-2 text-sm text-ok"><Icon name="check" size={16} /> No hay acciones correctivas abiertas.</p>
      ) : (
        <div className="-mx-4 overflow-x-auto">
          <table className="w-full min-w-[520px] text-left text-sm">
            <thead className="border-b border-line text-xs text-muted">
              <tr><th className="px-4 py-2 font-semibold">Acción</th><th className="px-2 py-2 font-semibold">Responsable</th><th className="px-2 py-2 font-semibold">Vence</th><th className="px-4 py-2 font-semibold">Estado</th></tr>
            </thead>
            <tbody>
              {data.items.map((a) => {
                const late = a.due_on < today;
                const [label, cls] = late ? ACTION_STATUS.overdue : (ACTION_STATUS[a.status] ?? [a.status, "bg-bg text-muted"]);
                return (
                  <tr key={a.id} className="border-b border-line last:border-0 hover:bg-bg">
                    <td className="px-4 py-2">
                      <Link href={recordsHref("ACR", "corrective_actions", { open: a.id })} className="font-semibold hover:text-brand">{a.title}</Link>
                      <p className="text-xs text-muted">{a.code}</p>
                    </td>
                    <td className="px-2 py-2">{a.responsible ?? "—"}</td>
                    <td className={`px-2 py-2 ${late ? "font-semibold text-bad" : ""}`}>{fmtDate(a.due_on)}</td>
                    <td className="px-4 py-2"><span className={`rounded-full px-2 py-0.5 text-xs font-bold ${cls}`}>{label}</span></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}

// ─── Mapa de calor ────────────────────────────────────────

const PROB = ["Improbable", "Posible", "Probable"];
const SEV = ["Leve", "Moderado", "Grave"];
const HEAT_CLS = ["", "bg-ok-soft text-ok", "bg-[#E8F5C8] text-[#4D7A12]", "bg-amber-soft text-amber-ink", "bg-[#FDE2CF] text-[#B4541A]", "bg-bad-soft text-bad"];

export function HeatCard({ data }: { data: NonNullable<Home["heatmap"]> }) {
  const [open, setOpen] = useState<{ p: number; s: number } | null>(null);
  const cell = (p: number, s: number) => data.cells.find((c) => c.p === p && c.s === s);
  const sel = open ? cell(open.p, open.s) : null;
  return (
    <Card title="Mapa de calor de riesgos" link={["IPERC", "/m/ipc"]}>
      {data.total === 0 ? (
        <p className="text-sm text-muted">Aún no hay matrices IPERC vigentes. Cuando apruebes una, sus riesgos aparecen aquí.</p>
      ) : (
        <>
          <div className="grid grid-cols-[auto_repeat(3,minmax(0,1fr))] gap-1 text-center text-xs">
            <span />
            {SEV.map((s) => <span key={s} className="pb-1 font-semibold text-muted">{s}</span>)}
            {[3, 2, 1].map((p) => (
              <div key={p} className="contents">
                <span className="self-center pr-1 text-right font-semibold text-muted">{PROB[p - 1]}</span>
                {[1, 2, 3].map((s) => {
                  const n = cell(p, s)?.count ?? 0;
                  return (
                    <button key={s} type="button" disabled={!n} onClick={() => setOpen({ p, s })}
                      className={`h-11 rounded-lg font-display text-lg font-bold transition ${HEAT_CLS[p + s - 1]} ${n ? "hover:ring-2 hover:ring-brand/40" : "opacity-50"}`}
                      aria-label={`${PROB[p - 1]} × ${SEV[s - 1]}: ${n} riesgos`}>
                      {n}
                    </button>
                  );
                })}
              </div>
            ))}
          </div>
          <p className="mt-2 text-xs text-muted">Riesgos residuales de las matrices IPERC vigentes (probabilidad × severidad). Toca una celda para ver el detalle.</p>
        </>
      )}
      {open && sel && (
        <Modal title={`Riesgos ${PROB[open.p - 1]} × ${SEV[open.s - 1]}`} subtitle={`${sel.count} riesgo(s) en matrices vigentes`} icon="grid" onClose={() => setOpen(null)}
          footer={<div className="flex w-full justify-end gap-2"><Link href="/m/ipc" className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-strong">Abrir IPERC</Link></div>}>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-line text-xs text-muted"><tr><th className="py-2 pr-2">Puesto</th><th className="py-2 pr-2">Peligro</th><th className="py-2 pr-2">Nivel</th><th className="py-2">Matriz</th></tr></thead>
              <tbody>
                {sel.rows.map((r) => (
                  <tr key={r.id} className="border-b border-line last:border-0">
                    <td className="py-2 pr-2">{r.position ?? "—"}</td>
                    <td className="py-2 pr-2"><b>{r.hazard}</b><p className="text-xs text-muted">{r.risk}</p></td>
                    <td className="py-2 pr-2">{r.level}</td>
                    <td className="py-2"><Link className="font-semibold text-brand hover:underline" href={`/m/ipc?t=matrices&matriz=${r.matrix_id}`}>{r.matrix} · fila {r.row_number}</Link></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Modal>
      )}
    </Card>
  );
}

// ─── Actividad reciente ───────────────────────────────────

const when = (at: string) => new Intl.DateTimeFormat("es-PE", { dateStyle: "short", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(at));

export function ActivityCard({ items }: { items: Home["activity"] }) {
  return (
    <Card title="Actividad reciente">
      {items.length === 0 ? (
        <p className="text-sm text-muted">Sin cambios en los últimos 90 días.</p>
      ) : (
        <ol className="relative space-y-3 border-l-2 border-line pl-4">
          {items.map((a) => {
            const text = (
              <>
                {a.full ? <><b>{a.actor}</b> {a.verb} · {a.what}</> : <><b>{a.actor}</b> {a.verb} {a.name ? <>«{a.name}»</> : "un registro"} en {a.what}</>}
              </>
            );
            return (
              <li key={a.id} className="relative text-sm">
                <span className="absolute -left-[1.4rem] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-surface bg-brand" aria-hidden="true" />
                {a.module && a.table && a.entity_id && a.verb !== "eliminó" ? <Link href={recordsHref(a.module, a.table, { open: a.entity_id })} className="hover:text-brand">{text}</Link> : <span>{text}</span>}
                <span className="block text-xs text-muted">{a.module_name ? `${a.module_name} · ` : ""}{when(a.at)}</span>
              </li>
            );
          })}
        </ol>
      )}
    </Card>
  );
}

// ─── Panel de Gerencia ────────────────────────────────────

function Big({ label, value, hint, cls = "text-brand" }: { label: string; value: string; hint: string; cls?: string }) {
  return (
    <div className="rounded-xl border border-line bg-surface p-4">
      <p className="text-[13px] font-semibold text-muted">{label}</p>
      <p className={`mt-1 font-display text-3xl font-bold ${cls}`}>{value}</p>
      <p className="mt-1 text-xs text-muted">{hint}</p>
    </div>
  );
}

const MODULE_ICON: Record<string, string> = { IPC: "clipboard", MAP: "map", POL: "file", PLA: "calendar", PAS: "calendar", PRE: "wallet", RIS: "book" };

export function ManagementPanel({ data }: { data: Home }) {
  const m = data.management!;
  const acc = m.accidents;
  const rate = m.accident_rate;
  const prevYear = data.year - 1;
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Big label="Cumplimiento del SG-SST" value={m.compliance ? `${m.compliance.pct}%` : "—"} cls={toneText(m.compliance?.pct ?? null)}
          hint={m.compliance ? `Promedio de ${m.compliance.indicators} indicadores medibles` : "Aún no hay indicadores para medir"} />
        <Big label="Accidentes con baja (año)" value={acc ? String(acc.current) : "—"} cls={acc && acc.current > 0 ? "text-bad" : "text-ok"}
          hint={acc ? `${prevYear}: ${acc.previous} en el mismo periodo` : "Módulo de accidentes no disponible"} />
        <Big label="Días perdidos (año)" value={acc ? String(acc.days) : "—"} cls={acc && acc.days > acc.previous_days ? "text-bad" : "text-brand"}
          hint={acc ? `${prevYear}: ${acc.previous_days} en el mismo periodo` : "—"} />
        <Big label="Puntaje autodiagnóstico SUNAFIL" value={m.sunafil?.score !== null && m.sunafil?.score !== undefined ? `${m.sunafil.score}/100` : "—"} cls={toneText(m.sunafil?.score ?? null)}
          hint={m.sunafil ? `${m.sunafil.gaps} brecha(s) abierta(s)` : "Disponible con Modo inspección SUNAFIL"} />
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title={`Accidentabilidad ${prevYear} vs ${data.year}`} link={["Ver estadísticas", "/m/est"]}>
          {rate ? (
            <LineChart
              aria="Índice de accidentabilidad por mes, año actual y anterior"
              labels={MONTHS.slice(0, Math.max(rate.current.months.length, rate.previous?.months.length ?? 0))}
              series={[
                { name: String(data.year), values: rate.current.months.map((x) => x.ai), color: BRAND, area: true },
                ...(rate.previous ? [{ name: String(prevYear), values: rate.previous.months.map((x) => x.ai), color: GREY, dashed: true }] : []),
              ]}
            />
          ) : (
            <p className="text-sm text-muted">Disponible con el módulo de Estadísticas.</p>
          )}
        </Card>
        <Card title="Pendientes de su aprobación" extra={m.pending.length ? <span className="rounded-full bg-amber-soft px-2 py-0.5 text-xs font-bold text-amber-ink">{m.pending.length}</span> : undefined}>
          {m.pending.length === 0 ? (
            <p className="flex items-center gap-2 text-sm text-ok"><Icon name="check" size={16} /> Nada espera su aprobación.</p>
          ) : (
            <ul className="divide-y divide-line">
              {m.pending.map((p) => (
                <li key={`${p.table}${p.id}`}>
                  <Link href={p.module === "IPC" ? `/m/ipc?t=matrices&matriz=${p.id}` : recordsHref(p.module, p.table, { open: p.id })} className="flex items-center gap-3 py-2.5 hover:text-brand">
                    <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand-soft text-brand-strong"><Icon name={(MODULE_ICON[p.module] ?? "pen") as "pen"} size={15} /></span>
                    <span className="min-w-0 flex-1 text-sm"><b className="block truncate">{p.name}</b><span className="text-xs text-muted">{p.kind} · desde {fmtDate(p.since?.slice(0, 10) ?? null)}</span></span>
                    <span className="rounded-full bg-amber-soft px-2 py-0.5 text-xs font-bold text-amber-ink">Pendiente</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <Card title="Cumplimiento por sede">
          {m.sites.length === 0 ? (
            <p className="text-sm text-muted">Registra las sedes de la empresa.</p>
          ) : (
            <div className="space-y-2.5">
              {m.sites.map((s) => (
                <div key={s.id}>
                  <div className="flex justify-between text-sm"><span>{s.name}</span><b className={toneText(s.pct)}>{s.pct === null ? "No aplica" : `${s.pct}%`}</b></div>
                  <Bar pct={s.pct} />
                </div>
              ))}
              <p className="text-xs text-muted">Promedio de los indicadores en % de cada sede.</p>
            </div>
          )}
        </Card>
        <Card title="Riesgos críticos abiertos" link={["IPERC", "/m/ipc"]}>
          {!m.critical ? (
            <p className="text-sm text-muted">Disponible con la Matriz IPERC.</p>
          ) : m.critical.length === 0 ? (
            <p className="flex items-center gap-2 text-sm text-ok"><Icon name="check" size={16} /> No hay riesgos significativos sin controlar.</p>
          ) : (
            <ul className="divide-y divide-line">
              {m.critical.map((r) => (
                <li key={r.id}>
                  <Link href={`/m/ipc?t=matrices&matriz=${r.matrix_id}`} className="flex items-start gap-3 py-2.5 hover:text-brand">
                    <span className="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-bad" aria-hidden="true" />
                    <span className="min-w-0 text-sm">
                      <b className="block">{r.hazard} — {r.risk}</b>
                      <span className="text-xs text-muted">
                        {[r.place, r.position_name, r.matrix_code].filter(Boolean).join(" · ")} · {r.pending_controls > 0 ? `${r.pending_controls} control(es) por implementar` : "sin controles implementados"}
                      </span>
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  );
}

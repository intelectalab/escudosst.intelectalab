"use client";

// Monitoreo de agentes (M-MAG): SCR-MAG-01 mapa de puntos por sede con semáforo del último resultado aprobado por agente,
// SCR-MAG-02 calendario del año (campañas y puntos que vencen según la frecuencia legal) y RN-MAG-04 tabla de conversión de unidades.

import { useEffect, useMemo, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card } from "./Summary";

type M = { agent: string; value: number; unit: string | null; result: string | null; color: string | null; measured_at: string };
type P = { id: string; code: string | null; name: string; site: string | null; area: string | null; lat: number | null; lng: number | null; color: string | null; measurements: M[] };
type Month = { month: number; campaigns: { id: string; title: string; status: string }[]; due: { id: string; code: string | null; name: string; site: string | null; due_on: string }[] };
type Conv = { id: string; from_unit: string; to_unit: string; factor: string; source: string | null; global: boolean; agent: string | null };
type Agent = { id: string; label: string };

const COLOR: Record<string, string> = { green: "#2e7d32", amber: "#b26a00", red: "#c62828" };
const LABEL: Record<string, string> = { green: "Dentro del límite", amber: "Nivel de acción", red: "Sobre el límite" };
const MONTHS = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Set", "Oct", "Nov", "Dic"];
const errText = (e: unknown) => Object.values((e as ApiError).errors ?? {})[0]?.[0] ?? (e as ApiError).detail ?? (e as ApiError).title;
const dmy = (s: string) => s.slice(0, 10).split("-").reverse().join("/");

function Dot({ c }: { c: string | null }) {
  return <span className="inline-block h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: c ? COLOR[c] : "#9e9e9e" }} aria-hidden />;
}

/** Plano de la sede: los puntos con coordenadas, ubicados por latitud y longitud, con el color del peor resultado. */
function SitePlan({ pts }: { pts: P[] }) {
  const geo = pts.filter((p) => p.lat != null && p.lng != null);
  if (geo.length === 0) return null;
  const lats = geo.map((p) => p.lat as number), lngs = geo.map((p) => p.lng as number);
  const [la0, la1, lo0, lo1] = [Math.min(...lats), Math.max(...lats), Math.min(...lngs), Math.max(...lngs)];
  const W = 560, H = 240, PAD = 28;
  const x = (v: number) => (lo1 === lo0 ? W / 2 : PAD + ((v - lo0) / (lo1 - lo0)) * (W - 2 * PAD));
  const y = (v: number) => (la1 === la0 ? H / 2 : H - PAD - ((v - la0) / (la1 - la0)) * (H - 2 * PAD));
  const cLat = (la0 + la1) / 2, cLng = (lo0 + lo1) / 2;
  return (
    <div className="space-y-1">
      <svg viewBox={`0 0 ${W} ${H}`} className="w-full max-w-2xl rounded-lg border border-line bg-surface-2" role="img" aria-label="Plano de los puntos de monitoreo de la sede">
        {geo.map((p) => (
          <g key={p.id}>
            <circle cx={x(p.lng as number)} cy={y(p.lat as number)} r={8} fill={p.color ? COLOR[p.color] : "#9e9e9e"} stroke="white" strokeWidth={2}>
              <title>{`${p.code ?? ""} ${p.name}: ${p.color ? LABEL[p.color] : "Sin medición aprobada"}`}</title>
            </circle>
            <text x={x(p.lng as number) + 11} y={y(p.lat as number) + 4} fontSize="11" fill="currentColor">{p.code ?? p.name}</text>
          </g>
        ))}
      </svg>
      <a className="text-xs text-brand underline" target="_blank" rel="noreferrer" href={`https://www.openstreetmap.org/?mlat=${cLat}&mlon=${cLng}#map=17/${cLat}/${cLng}`}>Ver la zona en el mapa</a>
    </div>
  );
}

export function MagOverview({ readOnly }: { readOnly: boolean }) {
  const [pts, setPts] = useState<P[] | null>(null);
  const [year, setYear] = useState(new Date().getFullYear());
  const [cal, setCal] = useState<{ frequency_months: number; months: Month[] } | null>(null);
  const [convs, setConvs] = useState<Conv[]>([]);
  const [agents, setAgents] = useState<Agent[]>([]);
  const [form, setForm] = useState({ agent_type_id: "", from_unit: "", to_unit: "", factor: "", source: "" });
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [tick, setTick] = useState(0);

  useEffect(() => { api<{ data: P[] }>("/mag/points", { withCompany: true }).then((r) => setPts(r.data)).catch(() => setPts(null)); }, []);
  useEffect(() => { api<{ data: { frequency_months: number; months: Month[] } }>(`/mag/calendar?year=${year}`, { withCompany: true }).then((r) => setCal(r.data)).catch(() => setCal(null)); }, [year]);
  useEffect(() => { api<{ data: Conv[] }>("/mag/unit-conversions", { withCompany: true }).then((r) => setConvs(r.data)).catch(() => setConvs([])); }, [tick]);
  useEffect(() => { api<{ data: Agent[] }>("/records/MAG/monitoring_points/options/agent_type_id?per_page=200", { withCompany: true }).then((r) => setAgents(r.data ?? [])).catch(() => setAgents([])); }, []);

  const sites = useMemo(() => {
    const g = new Map<string, P[]>();
    (pts ?? []).forEach((p) => g.set(p.site ?? "Sin sede", [...(g.get(p.site ?? "Sin sede") ?? []), p]));
    return [...g.entries()];
  }, [pts]);

  const save = async () => {
    setMsg(null);
    try {
      await api("/mag/unit-conversions", { method: "POST", withCompany: true, body: { ...form, agent_type_id: form.agent_type_id || null, factor: Number(form.factor) } });
      setForm({ agent_type_id: "", from_unit: "", to_unit: "", factor: "", source: "" });
      setMsg({ ok: true, text: "Conversión registrada." });
      setTick((t) => t + 1);
    } catch (e) { setMsg({ ok: false, text: errText(e) }); }
  };
  const remove = async (id: string) => {
    if (!window.confirm("¿Quitar esta conversión? Las mediciones ya convertidas conservan su valor original.")) return;
    try { await api(`/mag/unit-conversions/${id}`, { method: "DELETE", withCompany: true }); setTick((t) => t + 1); } catch (e) { setMsg({ ok: false, text: errText(e) }); }
  };

  return (
    <>
      {pts && pts.length > 0 && (
        <Card title="Mapa de puntos de monitoreo">
          <div className="mb-3 flex flex-wrap gap-4 text-xs text-muted">
            {Object.entries(LABEL).map(([k, v]) => <span key={k} className="inline-flex items-center gap-1.5"><Dot c={k} />{v}</span>)}
            <span className="inline-flex items-center gap-1.5"><Dot c={null} />Sin medición aprobada</span>
          </div>
          {pts.some((p) => p.lat == null || p.lng == null) && (
            <p className="mb-3 text-xs text-muted">Los puntos con latitud y longitud se ubican en el plano de su sede; agrégalas en cada punto para verlos allí.</p>
          )}
          <div className="space-y-5">
            {sites.map(([site, list]) => (
              <section key={site} className="space-y-2">
                <h4 className="text-sm font-semibold">{site} <span className="font-normal text-muted">· {list.length} punto(s)</span></h4>
                <SitePlan pts={list} />
                <ul className="divide-y divide-line text-sm">{list.map((p) => (
                  <li key={p.id} className="flex flex-wrap items-start gap-2 py-1.5">
                    <span className="mt-1.5"><Dot c={p.color} /></span>
                    <span className="min-w-[12rem] font-medium">{p.code ? `${p.code} · ` : ""}{p.name}{p.area ? <span className="font-normal text-muted"> · {p.area}</span> : null}</span>
                    <span className="text-xs text-muted">{p.measurements.length === 0 ? "Sin medición aprobada" : p.measurements.map((m) => `${m.agent}: ${m.value} ${m.unit ?? ""} (${dmy(m.measured_at)})`).join(" · ")}</span>
                  </li>
                ))}</ul>
              </section>
            ))}
          </div>
        </Card>
      )}

      {cal && (
        <Card title={`Calendario de mediciones ${year}`} action={
          <div className="flex items-center gap-1">
            <Button size="sm" onClick={() => setYear((y) => y - 1)} aria-label="Año anterior">‹</Button>
            <Button size="sm" onClick={() => setYear((y) => y + 1)} aria-label="Año siguiente">›</Button>
          </div>}>
          <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-4">
            {cal.months.map((m) => (
              <div key={m.month} className="min-h-[5.5rem] rounded-lg border border-line p-2 text-xs">
                <p className="mb-1 font-semibold">{MONTHS[m.month - 1]}</p>
                {m.campaigns.map((c) => <p key={c.id} className="truncate rounded bg-brand/10 px-1.5 py-0.5 text-brand-strong" title={c.title}>{c.title}</p>)}
                {m.due.map((d) => <p key={d.id} className="mt-0.5 truncate text-amber-ink" title={`${d.name}${d.site ? ` · ${d.site}` : ""}`}>Vence {dmy(d.due_on).slice(0, 5)}: {d.code ?? d.name}</p>)}
                {m.campaigns.length === 0 && m.due.length === 0 && <p className="text-muted">—</p>}
              </div>
            ))}
          </div>
          <p className="mt-2 text-xs text-muted">Cada punto se vuelve a medir cada {cal.frequency_months} meses desde su última medición aprobada. Los puntos nunca medidos o ya vencidos se listan en «Puntos sin monitoreo al día».</p>
        </Card>
      )}

      <Card title="Conversión de unidades">
        <p className="mb-2 text-xs text-muted">Si una medición llega en otra unidad que la del límite, se convierte con esta tabla y se guarda el valor original. Sin conversión registrada, la medición no se acepta.</p>
        {convs.length === 0 ? <p className="text-sm text-muted">Aún no hay conversiones registradas.</p> : (
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line text-xs text-muted"><tr><th className="py-1">Agente</th><th>De</th><th>A</th><th className="text-right">Factor</th><th className="pl-3">Fuente</th><th /></tr></thead>
            <tbody>{convs.map((c) => (
              <tr key={c.id} className="border-b border-line last:border-0">
                <td className="py-1.5">{c.agent ?? "Todos"}</td><td>{c.from_unit}</td><td>{c.to_unit}</td><td className="text-right">{Number(c.factor)}</td>
                <td className="pl-3 text-xs">{c.source ?? "—"}{c.global && <span className="text-muted"> (general)</span>}</td>
                <td className="text-right">{!readOnly && !c.global && <button type="button" className="text-xs text-bad underline" onClick={() => void remove(c.id)}>Quitar</button>}</td>
              </tr>
            ))}</tbody>
          </table>
        )}
        {!readOnly && (
          <div className="mt-3 flex flex-wrap items-end gap-2 text-sm">
            <select className="input !w-auto" aria-label="Agente" value={form.agent_type_id} onChange={(e) => setForm({ ...form, agent_type_id: e.target.value })}>
              <option value="">Todos los agentes</option>
              {agents.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
            </select>
            <input className="input !w-28" placeholder="De (ppm)" aria-label="Unidad de origen" value={form.from_unit} onChange={(e) => setForm({ ...form, from_unit: e.target.value })} />
            <input className="input !w-28" placeholder="A (mg/m³)" aria-label="Unidad de destino" value={form.to_unit} onChange={(e) => setForm({ ...form, to_unit: e.target.value })} />
            <input className="input !w-28" type="number" step="any" min="0" placeholder="Factor" aria-label="Factor" value={form.factor} onChange={(e) => setForm({ ...form, factor: e.target.value })} />
            <input className="input min-w-[14rem] flex-1" placeholder="Fuente (norma, ficha técnica o laboratorio)" aria-label="Fuente" value={form.source} onChange={(e) => setForm({ ...form, source: e.target.value })} />
            <Button size="sm" variant="primary" onClick={() => void save()}>Registrar</Button>
          </div>
        )}
        {msg && <p className={`mt-2 text-xs ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
      </Card>
    </>
  );
}

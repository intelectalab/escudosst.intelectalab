"use client";

// Monitoreo de agentes (M-MAG): SCR-MAG-03 carga masiva de mediciones desde el Excel del laboratorio (revisar y registrar las filas
// válidas) y SCR-MAG-05 tendencia por punto: valores en el tiempo con la línea del límite y la del nivel de acción.

import { useEffect, useRef, useState } from "react";
import { api, API_URL, ApiError } from "@/lib/api";
import { downloadFile } from "@/lib/records";
import { session } from "@/lib/session";
import { Button } from "@/components/ui/Button";
import { Card } from "./Summary";

type Opt = { id: string; title?: string; name?: string; code?: string | null; status?: string };
type Row = { row: number; point: string; measured_at: string; value: string; unit: string; errors: string[]; result: string | null };
type Imp = { applied: boolean; total: number; valid: number; rows: Row[] };
type Pt = { measured_at: string; value: number; unit: string | null; agent: string | null; limit: number | null; ratio: number | null; result: string | null };
const RESULT: Record<string, string> = { within_limit: "Dentro del límite", action_level: "Nivel de acción", exceeds_limit: "Sobre el límite" };

async function upload(fields: Record<string, string>, file: File): Promise<Imp> {
  const body = new FormData();
  body.append("file", file);
  Object.entries(fields).forEach(([k, v]) => body.append(k, v));
  const headers: Record<string, string> = { Accept: "application/json" };
  const t = session.getToken();
  const c = session.getCompanyId();
  if (t) headers.Authorization = `Bearer ${t}`;
  if (c) headers["X-Company-Id"] = c;
  const res = await fetch(`${API_URL}/api/v1/mag/import`, { method: "POST", headers, body });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(res.status, data.type ?? "error", data.title ?? "Error", data.detail, data.errors);
  return data.data as Imp;
}

function Trend({ pts, action }: { pts: Pt[]; action: number }) {
  if (pts.length === 0) return <p className="text-sm text-muted">El punto aún no tiene mediciones.</p>;
  const W = 640, H = 220, P = 36;
  const limit = pts.find((p) => p.limit != null)?.limit ?? null;
  const max = Math.max(...pts.map((p) => p.value), limit ?? 0) * 1.15 || 1;
  const x = (i: number) => P + (pts.length === 1 ? (W - 2 * P) / 2 : (i * (W - 2 * P)) / (pts.length - 1));
  const y = (v: number) => H - P - (v / max) * (H - 2 * P);
  const color = (r: string | null) => (r === "exceeds_limit" ? "var(--color-bad, #c62828)" : r === "action_level" ? "var(--color-amber-ink, #b26a00)" : "var(--color-brand, #1f7fd1)");
  return (
    <div className="space-y-2">
      <svg viewBox={`0 0 ${W} ${H}`} className="w-full max-w-3xl" role="img" aria-label="Tendencia de las mediciones del punto con el límite y el nivel de acción">
        <line x1={P} y1={H - P} x2={W - P} y2={H - P} stroke="currentColor" opacity={0.3} />
        {limit != null && <>
          <line x1={P} y1={y(limit)} x2={W - P} y2={y(limit)} stroke="#c62828" strokeDasharray="6 4" />
          <text x={W - P} y={y(limit) - 4} textAnchor="end" fontSize="11" fill="#c62828">Límite {limit}</text>
          <line x1={P} y1={y(limit * action)} x2={W - P} y2={y(limit * action)} stroke="#b26a00" strokeDasharray="3 4" />
          <text x={W - P} y={y(limit * action) - 4} textAnchor="end" fontSize="11" fill="#b26a00">Nivel de acción {+(limit * action).toFixed(3)}</text>
        </>}
        <polyline fill="none" stroke="var(--color-brand, #1f7fd1)" strokeWidth={2} points={pts.map((p, i) => `${x(i)},${y(p.value)}`).join(" ")} />
        {pts.map((p, i) => <circle key={i} cx={x(i)} cy={y(p.value)} r={4} fill={color(p.result)}><title>{`${new Date(p.measured_at).toLocaleDateString("es-PE")}: ${p.value} ${p.unit ?? ""}`}</title></circle>)}
      </svg>
      <table className="w-full max-w-3xl text-left text-xs">
        <thead className="border-b border-line text-muted"><tr><th className="py-1">Fecha</th><th>Agente</th><th className="text-right">Valor</th><th className="text-right">Límite</th><th className="text-right">Cociente</th><th className="pl-3">Resultado</th></tr></thead>
        <tbody>{pts.map((p, i) => <tr key={i} className="border-b border-line last:border-0"><td className="py-1">{new Date(p.measured_at).toLocaleString("es-PE")}</td><td>{p.agent}</td>
          <td className="text-right">{p.value} {p.unit}</td><td className="text-right">{p.limit ?? "—"}</td><td className="text-right">{p.ratio ?? "—"}</td><td className="pl-3">{p.result ? RESULT[p.result] ?? p.result : "—"}</td></tr>)}</tbody>
      </table>
    </div>
  );
}

export function MagTools({ readOnly, onSaved }: { readOnly: boolean; onSaved: () => void }) {
  const [campaigns, setCampaigns] = useState<Opt[]>([]);
  const [points, setPoints] = useState<Opt[]>([]);
  const [campaign, setCampaign] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [imp, setImp] = useState<Imp | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [point, setPoint] = useState("");
  const [trend, setTrend] = useState<{ action_level_ratio: number; points: Pt[] } | null>(null);
  const input = useRef<HTMLInputElement>(null);

  useEffect(() => {
    api<{ data: Opt[] }>("/records/MAG/monitoring_campaigns?per_page=100", { withCompany: true }).then((r) => setCampaigns((r.data ?? []).filter((c) => c.status !== "closed"))).catch(() => setCampaigns([]));
    api<{ data: Opt[] }>("/records/MAG/monitoring_points?per_page=300", { withCompany: true }).then((r) => setPoints(r.data ?? [])).catch(() => setPoints([]));
  }, []);
  useEffect(() => {
    setTrend(null);
    if (!point) return;
    api<{ data: { action_level_ratio: number; points: Pt[] } }>(`/mag/trend?point_id=${point}`, { withCompany: true }).then((r) => setTrend(r.data)).catch(() => setTrend({ action_level_ratio: 0.5, points: [] }));
  }, [point, imp]);

  const run = async (apply: boolean) => {
    if (!file) return;
    setBusy(true);
    setMsg(null);
    try {
      setImp(await upload({ campaign_id: campaign, ...(apply ? { apply: "1" } : {}) }, file));
      if (apply) onSaved();
    } catch (e) {
      const err = e as ApiError;
      setMsg(err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="grid gap-4 xl:grid-cols-2">
      {!readOnly && (
        <Card title="Cargar mediciones desde Excel">
          <div className="flex flex-wrap items-end gap-3">
            <select className="input max-w-xs" aria-label="Campaña" value={campaign} onChange={(e) => { setCampaign(e.target.value); setImp(null); }}>
              <option value="">Elige la campaña…</option>{campaigns.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
            </select>
            <Button icon="download" onClick={() => downloadFile("/mag/import/template", "plantilla-mediciones.xlsx")}>Descargar plantilla</Button>
          </div>
          <input ref={input} type="file" accept=".xlsx,.csv" className="mt-3 block w-full text-sm" aria-label="Archivo de mediciones" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setImp(null); }} />
          <div className="mt-3 flex flex-wrap gap-2">
            <Button variant="primary" loading={busy && !imp} disabled={busy || !campaign || !file} onClick={() => run(false)}>Revisar</Button>
            {imp && !imp.applied && <Button icon="check" loading={busy} disabled={busy || imp.valid === 0} onClick={() => run(true)}>Registrar {imp.valid} medición(es) válida(s)</Button>}
          </div>
          {msg && <p role="alert" className="mt-2 text-sm text-bad">{msg}</p>}
          {imp && (
            <div className="mt-3 space-y-2 text-sm">
              <p className={imp.applied ? "text-ok" : ""}>{imp.applied ? `Se registraron ${imp.rows.filter((r) => !r.errors.length).length} medición(es); las filas con error no se registraron.` : `${imp.valid} de ${imp.total} filas listas para registrar.`}</p>
              <ul className="max-h-60 space-y-1 overflow-y-auto">
                {imp.rows.map((r) => (
                  <li key={r.row} className={r.errors.length ? "text-bad" : ""}>Fila {r.row}: {r.point} · {r.measured_at} · {r.value} {r.unit}
                    {r.result && <span className="text-muted"> — {RESULT[r.result] ?? r.result}</span>}{r.errors.length > 0 && <span> — {r.errors.join(" ")}</span>}</li>
                ))}
              </ul>
            </div>
          )}
        </Card>
      )}
      <Card title="Tendencia por punto">
        <select className="input mb-3 max-w-md" aria-label="Punto de monitoreo" value={point} onChange={(e) => setPoint(e.target.value)}>
          <option value="">Elige el punto…</option>{points.map((p) => <option key={p.id} value={p.id}>{[p.code, p.name].filter(Boolean).join(" · ")}</option>)}
        </select>
        {trend && <Trend pts={trend.points} action={trend.action_level_ratio} />}
      </Card>
    </div>
  );
}

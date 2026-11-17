"use client";

// Observación de conducta segura (M-OBS): SCR-OBS-01 «Observar» (cada comportamiento de la lista vigente con botones Seguro / En
// riesgo, sin registrar a quién se observó, RN-OBS-01), SCR-OBS-02 tendencias (% seguro por categoría, área y mes) y SCR-OBS-03 lista
// de comportamientos con versiones (RN-OBS-05).

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card, Kpi } from "./Summary";

type Item = { category: string; behavior: string };
type List = { id: string; version_number: number; status: string; items: Item[]; change_summary: string | null; published_at: string | null; published_by: string | null };
type Lists = { current: List | null; draft: List | null; history: List[] };
type Bucket = { key: string | number; safe: number; total: number; pct: number | null };
type Trends = { year: number; alert_pct: number; by_category: Bucket[]; by_area: Bucket[]; by_month: Bucket[]; observations: number };
type Opt = { id: string; name: string; site_id?: string | null };

const CAT: Record<string, string> = { ppe: "EPP", body_position: "Posición del cuerpo", tools: "Herramientas y equipos", procedures: "Procedimientos", housekeeping: "Orden y limpieza", communication: "Comunicación", other: "Otros" };
const MONTHS = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Set", "Oct", "Nov", "Dic"];
const fail = (e: unknown) => { const err = e as ApiError; return err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title); };

function PctTable({ title, rows, label, alert }: { title: string; rows: Bucket[]; label: (k: string | number) => string; alert: number }) {
  return (
    <div>
      <h4 className="mb-1 text-xs font-semibold text-muted">{title}</h4>
      {rows.length === 0 ? <p className="text-sm text-muted">Sin observaciones.</p> : (
        <table className="w-full text-left text-sm"><tbody>
          {rows.map((r) => (
            <tr key={String(r.key)} className="border-b border-line last:border-0">
              <td className="py-1 pr-2">{label(r.key)}</td>
              <td className="w-1/2 py-1 pr-2"><div className="h-2.5 rounded bg-bg"><div className={`h-2.5 rounded ${r.pct != null && r.pct < alert ? "bg-bad" : "bg-ok"}`} style={{ width: `${r.pct ?? 0}%` }} /></div></td>
              <td className={`py-1 text-right font-semibold ${r.pct != null && r.pct < alert ? "text-bad" : ""}`}>{r.pct == null ? "—" : `${r.pct}%`}<span className="ml-1 text-xs font-normal text-muted">({r.safe}/{r.total})</span></td>
            </tr>
          ))}
        </tbody></table>
      )}
    </div>
  );
}

export function ObsModule({ readOnly, onSaved }: { readOnly: boolean; onSaved: () => void }) {
  const [lists, setLists] = useState<Lists | null>(null);
  const [trends, setTrends] = useState<Trends | null>(null);
  const [year, setYear] = useState(new Date().getFullYear());
  const [sites, setSites] = useState<Opt[]>([]);
  const [areas, setAreas] = useState<Opt[]>([]);
  const [obs, setObs] = useState({ site: "", area: "", task: "", count: "", feedback: true, comments: "" });
  const [answers, setAnswers] = useState<Record<number, string>>({});
  const [edit, setEdit] = useState<{ items: Item[]; summary: string } | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const loadLists = useCallback(() => api<{ data: Lists }>("/obs/lists", { withCompany: true }).then((r) => setLists(r.data)).catch(() => setLists(null)), []);
  useEffect(() => { void loadLists(); }, [loadLists]);
  useEffect(() => { api<{ data: Trends }>(`/obs/trends?year=${year}`, { withCompany: true }).then((r) => setTrends(r.data)).catch(() => setTrends(null)); }, [year, msg]);
  useEffect(() => {
    api<{ data: Opt[] }>("/records/TEN/sites?per_page=100", { withCompany: true }).then((r) => setSites(r.data ?? [])).catch(() => setSites([]));
    api<{ data: Opt[] }>("/records/TEN/areas?per_page=100", { withCompany: true }).then((r) => setAreas(r.data ?? [])).catch(() => setAreas([]));
  }, []);

  const items = lists?.current?.items ?? [];
  const submit = async () => {
    setBusy(true);
    setMsg(null);
    try {
      const r = await api<{ data: { safe: number; at_risk: number } }>("/obs/observe", { method: "POST", withCompany: true, body: {
        site_id: obs.site, area_id: obs.area || null, task_observed: obs.task || null, observed_worker_count: obs.count ? Number(obs.count) : null,
        feedback_given: obs.feedback, comments: obs.comments || null, items: items.map((it, i) => ({ ...it, result: answers[i] ?? "not_observed" })),
      } });
      setMsg({ ok: true, text: `Observación enviada: ${r.data.safe} seguro(s) y ${r.data.at_risk} en riesgo.` });
      setAnswers({});
      setObs({ ...obs, task: "", count: "", comments: "" });
      onSaved();
    } catch (e) { setMsg({ ok: false, text: fail(e) }); } finally { setBusy(false); }
  };
  const saveDraft = async (publish: boolean) => {
    if (!edit) return;
    setBusy(true);
    setMsg(null);
    try {
      await api("/obs/lists", { method: "POST", withCompany: true, body: { items: edit.items.filter((i) => i.behavior.trim()), change_summary: edit.summary || null } });
      if (publish) await api("/obs/lists/publish", { method: "POST", withCompany: true });
      setEdit(null);
      setMsg({ ok: true, text: publish ? "Lista publicada: es la nueva versión vigente." : "Borrador guardado." });
      void loadLists();
    } catch (e) { setMsg({ ok: false, text: fail(e) }); } finally { setBusy(false); }
  };

  return (
    <div className="space-y-4">
      {msg && <p role={msg.ok ? "status" : "alert"} className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
      <div className="grid gap-4 xl:grid-cols-2">
        {!readOnly && (
          <Card title="Observar">
            {items.length === 0 ? <p className="text-sm text-muted">Primero publica la lista de comportamientos (tarjeta «Lista de comportamientos»).</p> : (
              <div className="space-y-3">
                <div className="grid gap-2 sm:grid-cols-2">
                  <select className="input" aria-label="Sede" value={obs.site} onChange={(e) => setObs({ ...obs, site: e.target.value, area: "" })}>
                    <option value="">Sede…</option>{sites.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                  </select>
                  <select className="input" aria-label="Área" value={obs.area} onChange={(e) => setObs({ ...obs, area: e.target.value })}>
                    <option value="">Área (opcional)…</option>{areas.filter((a) => !obs.site || a.site_id === obs.site).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                  </select>
                  <input className="input" placeholder="Tarea observada (opcional)" value={obs.task} onChange={(e) => setObs({ ...obs, task: e.target.value })} />
                  <input type="number" min={1} className="input" placeholder="N.° de trabajadores observados" value={obs.count} onChange={(e) => setObs({ ...obs, count: e.target.value })} />
                </div>
                <p className="text-xs text-muted">No se registra a quién se observó. Versión de la lista: {lists?.current?.version_number}.</p>
                <ul className="max-h-96 space-y-1 overflow-y-auto">
                  {items.map((it, i) => (
                    <li key={i} className="flex flex-wrap items-center justify-between gap-2 border-b border-line py-1.5 text-sm last:border-0">
                      <span className="min-w-0 flex-1"><b>{it.behavior}</b> <span className="text-xs text-muted">{CAT[it.category] ?? it.category}</span></span>
                      <span className="flex gap-1">
                        {([["safe", "Seguro", "bg-ok-soft text-ok"], ["at_risk", "En riesgo", "bg-bad-soft text-bad"]] as const).map(([v, l, cls]) => (
                          <button key={v} type="button" aria-pressed={answers[i] === v} onClick={() => setAnswers((a) => ({ ...a, [i]: a[i] === v ? "" : v }))}
                            className={`rounded-lg border px-2.5 py-1 text-xs font-semibold ${answers[i] === v ? `${cls} border-transparent` : "border-line"}`}>{l}</button>
                        ))}
                      </span>
                    </li>
                  ))}
                </ul>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={obs.feedback} onChange={(e) => setObs({ ...obs, feedback: e.target.checked })} /> Di retroalimentación al trabajador</label>
                <textarea className="input min-h-[56px]" placeholder="Comentarios (opcional)" value={obs.comments} onChange={(e) => setObs({ ...obs, comments: e.target.value })} />
                <Button variant="primary" loading={busy} disabled={busy || !obs.site || !Object.values(answers).some(Boolean)} onClick={submit}>Enviar observación</Button>
              </div>
            )}
          </Card>
        )}
        <Card title="Tendencias">
          <div className="mb-3 flex items-center gap-3">
            <input type="number" className="input w-28" aria-label="Año" value={year} onChange={(e) => setYear(Number(e.target.value))} />
            {trends && <span className="text-xs text-muted">{trends.observations} observación(es). En rojo, bajo el {trends.alert_pct} % de conductas seguras.</span>}
          </div>
          {trends && (
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-3">
                <Kpi label="% seguro del año" value={(() => { const s = trends.by_category.reduce((a, b) => a + b.safe, 0); const t = trends.by_category.reduce((a, b) => a + b.total, 0); return t ? `${Math.round((s * 100) / t)}%` : "—"; })()} />
                <Kpi label="Observaciones" value={String(trends.observations)} />
              </div>
              <PctTable title="Por categoría" rows={trends.by_category} label={(k) => CAT[String(k)] ?? String(k)} alert={trends.alert_pct} />
              <PctTable title="Por área" rows={trends.by_area} label={(k) => String(k)} alert={trends.alert_pct} />
              <PctTable title="Por mes" rows={trends.by_month} label={(k) => MONTHS[Number(k) - 1] ?? String(k)} alert={trends.alert_pct} />
            </div>
          )}
        </Card>
      </div>
      <Card title="Lista de comportamientos">
        {lists?.current ? <p className="mb-2 text-sm">Vigente: <b>versión {lists.current.version_number}</b>{lists.current.published_at ? ` · publicada el ${new Date(lists.current.published_at).toLocaleDateString("es-PE")}` : ""}{lists.current.published_by ? ` por ${lists.current.published_by}` : ""} · {lists.current.items.length} comportamiento(s)</p>
          : <p className="mb-2 text-sm text-muted">Aún no hay una lista vigente.</p>}
        {lists?.draft && !edit && <p className="mb-2 text-sm text-amber-ink">Hay un borrador (versión {lists.draft.version_number}) sin publicar.</p>}
        {!readOnly && !edit && (
          <Button icon="pen" onClick={() => setEdit({ items: (lists?.draft ?? lists?.current)?.items.map((x) => ({ ...x })) ?? [{ category: "ppe", behavior: "" }], summary: lists?.draft?.change_summary ?? "" })}>
            {lists?.draft ? "Seguir editando el borrador" : "Editar (crea una nueva versión)"}
          </Button>
        )}
        {edit && (
          <div className="space-y-2">
            {edit.items.map((it, i) => (
              <div key={i} className="flex flex-wrap gap-2">
                <select className="input w-48" aria-label={`Categoría ${i + 1}`} value={it.category} onChange={(e) => setEdit({ ...edit, items: edit.items.map((x, j) => (j === i ? { ...x, category: e.target.value } : x)) })}>
                  {Object.entries(CAT).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
                <input className="input min-w-[12rem] flex-1" aria-label={`Comportamiento ${i + 1}`} value={it.behavior} onChange={(e) => setEdit({ ...edit, items: edit.items.map((x, j) => (j === i ? { ...x, behavior: e.target.value } : x)) })} />
                <button type="button" className="text-xs text-bad underline" onClick={() => setEdit({ ...edit, items: edit.items.filter((_, j) => j !== i) })}>Quitar</button>
              </div>
            ))}
            <button type="button" className="text-sm font-semibold text-brand-strong underline" onClick={() => setEdit({ ...edit, items: [...edit.items, { category: "ppe", behavior: "" }] })}>Agregar comportamiento</button>
            <input className="input" placeholder="Qué cambió en esta versión (opcional)" value={edit.summary} onChange={(e) => setEdit({ ...edit, summary: e.target.value })} />
            <div className="flex flex-wrap gap-2">
              <Button loading={busy} disabled={busy} onClick={() => saveDraft(false)}>Guardar borrador</Button>
              <Button variant="primary" loading={busy} disabled={busy || !edit.items.some((i) => i.behavior.trim())} onClick={() => saveDraft(true)}>Publicar versión</Button>
              <Button variant="ghost" onClick={() => setEdit(null)}>Cancelar</Button>
            </div>
          </div>
        )}
        {lists && lists.history.length > 0 && (
          <details className="mt-3 text-sm"><summary className="cursor-pointer font-semibold">Versiones anteriores ({lists.history.length})</summary>
            <ul className="mt-1 space-y-1">{lists.history.map((h) => <li key={h.id}>Versión {h.version_number} · {h.items.length} comportamiento(s){h.change_summary ? ` · ${h.change_summary}` : ""}</li>)}</ul>
          </details>
        )}
      </Card>
    </div>
  );
}

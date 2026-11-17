"use client";

// Charlas de 5 minutos (M-CH5): SCR-CH5-02 «Registrar charla» con la lista de trabajadores del área precargada (se desmarca a
// quien no asistió) y SCR-CH5-01 cobertura del día por área (RN-CH5-05: asistentes del área / trabajadores activos del área).

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card } from "./Summary";

type Opt = { id: string; name: string; site_id?: string | null };
type Worker = { id: string; full_name: string; document: string | null; position: string | null };
type Cov = { id: string; area: string; site: string | null; workers: number; attended: number; talks: number; pct: number | null };
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

export function Ch5Talks({ readOnly, onSaved }: { readOnly: boolean; onSaved: () => void }) {
  const [sites, setSites] = useState<Opt[]>([]);
  const [areas, setAreas] = useState<Opt[]>([]);
  const [site, setSite] = useState("");
  const [area, setArea] = useState("");
  const [workers, setWorkers] = useState<Worker[] | null>(null);
  const [picked, setPicked] = useState<Set<string>>(new Set());
  const [topic, setTopic] = useState("");
  const [content, setContent] = useState("");
  const [date, setDate] = useState(today);
  const [minutes, setMinutes] = useState("5");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [covDate, setCovDate] = useState(today);
  const [cov, setCov] = useState<Cov[] | null>(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    api<{ data: Opt[] }>("/records/TEN/sites?per_page=200", { withCompany: true }).then((r) => setSites(r.data ?? [])).catch(() => setSites([]));
    api<{ data: Opt[] }>("/records/TEN/areas?per_page=500", { withCompany: true }).then((r) => setAreas(r.data ?? [])).catch(() => setAreas([]));
  }, []);
  useEffect(() => {
    setWorkers(null);
    if (!area) return;
    api<{ data: Worker[] }>(`/ch5/area-workers?area_id=${area}`, { withCompany: true })
      .then((r) => { setWorkers(r.data); setPicked(new Set(r.data.map((w) => w.id))); })
      .catch((e: ApiError) => setMsg({ ok: false, text: e.detail ?? e.title }));
  }, [area]);
  useEffect(() => {
    api<{ data: { areas: Cov[] } }>(`/ch5/coverage?date=${covDate}`, { withCompany: true }).then((r) => setCov(r.data.areas)).catch(() => setCov([]));
  }, [covDate, reload]);

  const toggle = (id: string) => setPicked((p) => { const n = new Set(p); if (n.has(id)) n.delete(id); else n.add(id); return n; });
  const save = async () => {
    setBusy(true);
    setMsg(null);
    try {
      const r = await api<{ data: { attendees: number } }>("/ch5/quick", {
        method: "POST", withCompany: true,
        body: { topic, content: content || null, talk_on: date, site_id: site, area_id: area, duration_minutes: minutes ? Number(minutes) : null, worker_ids: [...picked] },
      });
      setMsg({ ok: true, text: `Charla registrada como realizada con ${r.data.attendees} asistente(s). Cada trabajador puede firmar su asistencia desde su app.` });
      setTopic("");
      setContent("");
      setReload((v) => v + 1);
      onSaved();
    } catch (e) {
      const err = e as ApiError;
      setMsg({ ok: false, text: err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title) });
    } finally {
      setBusy(false);
    }
  };
  const areaOpts = areas.filter((a) => !site || a.site_id === site);

  return (
    <div className="grid gap-4 xl:grid-cols-2">
      {!readOnly && (
        <Card title="Registrar charla">
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Sede</span>
              <select className="input" value={site} onChange={(e) => { setSite(e.target.value); setArea(""); }}>
                <option value="">Elige la sede…</option>{sites.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select></label>
            <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Área</span>
              <select className="input" value={area} onChange={(e) => setArea(e.target.value)} disabled={!site}>
                <option value="">Elige el área…</option>{areaOpts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </select></label>
            <label className="text-sm sm:col-span-2"><span className="mb-1 block text-xs font-semibold text-muted">Tema</span>
              <input className="input" value={topic} onChange={(e) => setTopic(e.target.value)} maxLength={300} /></label>
            <label className="text-sm sm:col-span-2"><span className="mb-1 block text-xs font-semibold text-muted">Resumen (opcional)</span>
              <textarea className="input min-h-[64px]" value={content} onChange={(e) => setContent(e.target.value)} /></label>
            <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Fecha</span>
              <input type="date" className="input" value={date} onChange={(e) => setDate(e.target.value)} /></label>
            <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Duración (minutos)</span>
              <input type="number" min={1} max={120} className="input" value={minutes} onChange={(e) => setMinutes(e.target.value)} /></label>
          </div>
          {workers && (
            <div className="mt-3">
              <div className="mb-1 flex items-center justify-between text-xs font-semibold text-muted">
                <span>Asistentes: {picked.size} de {workers.length}</span>
                <span className="space-x-3">
                  <button type="button" className="underline" onClick={() => setPicked(new Set(workers.map((w) => w.id)))}>Marcar todos</button>
                  <button type="button" className="underline" onClick={() => setPicked(new Set())}>Desmarcar todos</button>
                </span>
              </div>
              {workers.length === 0 ? <p className="text-sm text-muted">El área no tiene trabajadores activos.</p> : (
                <ul className="max-h-72 space-y-1 overflow-y-auto rounded-lg border border-line p-2 text-sm">
                  {workers.map((w) => (
                    <li key={w.id}>
                      <label className="flex items-center gap-2">
                        <input type="checkbox" checked={picked.has(w.id)} onChange={() => toggle(w.id)} />
                        <span><b>{w.full_name}</b> <span className="text-xs text-muted">{[w.document, w.position].filter(Boolean).join(" · ")}</span></span>
                      </label>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
          <div className="mt-3">
            <Button variant="primary" icon="check" loading={busy} disabled={busy || !topic.trim() || !area || picked.size === 0} onClick={save}>Registrar charla realizada</Button>
          </div>
          {msg && <p role={msg.ok ? "status" : "alert"} className={`mt-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
        </Card>
      )}
      <Card title="Cobertura del día por área">
        <input type="date" className="input mb-3 max-w-[12rem]" aria-label="Fecha de la cobertura" value={covDate} onChange={(e) => setCovDate(e.target.value)} />
        {!cov ? <div className="h-24 animate-pulse rounded-lg bg-bg" /> : cov.length === 0 ? <p className="text-sm text-muted">No hay áreas con trabajadores activos.</p> : (
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line text-xs text-muted">
              <tr><th className="py-1 pr-2">Área</th><th className="py-1 pr-2 text-right">Charlas</th><th className="py-1 pr-2 text-right">Asistentes</th><th className="py-1 pr-2 text-right">Trabajadores</th><th className="py-1 text-right">Cobertura</th></tr>
            </thead>
            <tbody>
              {cov.map((c) => (
                <tr key={c.id} className="border-b border-line last:border-0">
                  <td className="py-1.5 pr-2">{c.area}{c.site ? <span className="block text-xs text-muted">{c.site}</span> : null}</td>
                  <td className="py-1.5 pr-2 text-right">{c.talks}</td>
                  <td className="py-1.5 pr-2 text-right">{c.attended}</td>
                  <td className="py-1.5 pr-2 text-right">{c.workers}</td>
                  <td className={`py-1.5 text-right font-semibold ${c.pct == null ? "" : c.pct >= 100 ? "text-ok" : c.pct === 0 ? "text-bad" : "text-amber-ink"}`}>{c.pct == null ? "—" : `${c.pct}%`}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </div>
  );
}

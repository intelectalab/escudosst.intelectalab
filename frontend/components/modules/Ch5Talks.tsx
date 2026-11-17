"use client";

// Charlas de 5 minutos (M-CH5): SCR-CH5-02 «Registrar charla» con la lista de trabajadores del área precargada (se desmarca a
// quien no asistió) y SCR-CH5-01 cobertura del día por área (RN-CH5-05: asistentes del área / trabajadores activos del área).

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card } from "./Summary";

type Opt = { id: string; name: string; site_id?: string | null };
type Worker = { id: string; full_name: string; document: string | null; position: string | null };
type Topic = { id: string; title: string; content: string | null; hazard_keywords: string[]; duration_minutes: number | null; is_active: boolean };
type Topics = { topics: Topic[]; hazards: string[]; suggested: { id: string; title: string; hazards: string[] }[] };
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
  const [bank, setBank] = useState<Topics | null>(null);
  const [topicId, setTopicId] = useState("");
  const [tv, setTv] = useState(0);

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
    api<{ data: Topics }>(`/ch5/topics${area ? `?area_id=${area}` : ""}`, { withCompany: true }).then((r) => setBank(r.data)).catch(() => setBank(null));
  }, [area, tv]);
  const pickTopic = (id: string) => {
    setTopicId(id);
    const t = bank?.topics.find((x) => x.id === id);
    if (t) {
      setTopic(t.title);
      setContent(t.content ?? "");
      if (t.duration_minutes) setMinutes(String(t.duration_minutes));
    }
  };
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
        body: { topic, topic_bank_id: topicId || null, content: content || null, talk_on: date, site_id: site, area_id: area, duration_minutes: minutes ? Number(minutes) : null, worker_ids: [...picked] },
      });
      setMsg({ ok: true, text: `Charla registrada como realizada con ${r.data.attendees} asistente(s). Cada trabajador puede firmar su asistencia desde su app.` });
      setTopic("");
      setContent("");
      setTopicId("");
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
            {bank && bank.topics.some((t) => t.is_active) && (
              <label className="text-sm sm:col-span-2"><span className="mb-1 block text-xs font-semibold text-muted">Tema del banco (opcional)</span>
                <select className="input" value={topicId} onChange={(e) => pickTopic(e.target.value)}>
                  <option value="">Tema libre…</option>
                  {bank.suggested.length > 0 && <optgroup label="Sugeridos por el IPERC del área">{bank.suggested.map((t) => <option key={t.id} value={t.id}>{t.title}</option>)}</optgroup>}
                  <optgroup label="Todos los temas">{bank.topics.filter((t) => t.is_active).map((t) => <option key={t.id} value={t.id}>{t.title}</option>)}</optgroup>
                </select>
                {bank.suggested.length > 0 && <span className="mt-1 block text-xs text-muted">Sugeridos porque el área tiene estos peligros significativos: {[...new Set(bank.suggested.flatMap((t) => t.hazards))].join(", ")}.</span>}
              </label>
            )}
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
      {!readOnly && <TopicBank bank={bank} onSaved={() => setTv((x) => x + 1)} />}
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

const blankTopic = { id: "", title: "", content: "", keywords: "", minutes: "5", active: true };

/** SCR-CH5-03: banco de temas con su contenido y las palabras de los peligros del IPERC a los que se vinculan. */
function TopicBank({ bank, onSaved }: { bank: Topics | null; onSaved: () => void }) {
  const [f, setF] = useState<typeof blankTopic | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const save = async () => {
    if (!f) return;
    setMsg(null);
    try {
      await api("/ch5/topics", { method: "POST", withCompany: true, body: { id: f.id || null, title: f.title, content: f.content || null,
        hazard_keywords: f.keywords.split(",").map((x) => x.trim()).filter(Boolean), duration_minutes: f.minutes ? Number(f.minutes) : null, is_active: f.active } });
      setF(null);
      onSaved();
    } catch (e) {
      const err = e as ApiError;
      setMsg(err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title));
    }
  };
  return (
    <Card title="Banco de temas">
      {!bank || bank.topics.length === 0 ? <p className="text-sm text-muted">Aún no hay temas en el banco.</p> : (
        <ul className="max-h-60 space-y-1 overflow-y-auto text-sm">
          {bank.topics.map((t) => (
            <li key={t.id} className="flex items-start justify-between gap-2 border-b border-line pb-1 last:border-0">
              <span className={t.is_active ? "" : "text-muted line-through"}><b>{t.title}</b>
                {t.hazard_keywords.length > 0 && <span className="block text-xs text-muted">Peligros: {t.hazard_keywords.join(", ")}</span>}</span>
              <button type="button" className="text-xs font-semibold text-brand-strong underline"
                onClick={() => setF({ id: t.id, title: t.title, content: t.content ?? "", keywords: t.hazard_keywords.join(", "), minutes: t.duration_minutes ? String(t.duration_minutes) : "", active: t.is_active })}>Editar</button>
            </li>
          ))}
        </ul>
      )}
      {!f ? <div className="mt-3"><Button icon="plus" onClick={() => setF({ ...blankTopic })}>Nuevo tema</Button></div> : (
        <div className="mt-3 grid gap-2 rounded-lg border border-line p-3">
          <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Título</span>
            <input className="input" value={f.title} maxLength={300} onChange={(e) => setF({ ...f, title: e.target.value })} /></label>
          <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Contenido sugerido</span>
            <textarea className="input min-h-[72px]" value={f.content} onChange={(e) => setF({ ...f, content: e.target.value })} /></label>
          <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Palabras de los peligros del IPERC (separadas por comas)</span>
            <input className="input" value={f.keywords} onChange={(e) => setF({ ...f, keywords: e.target.value })} /></label>
          <div className="flex flex-wrap items-end gap-3">
            <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Duración (minutos)</span>
              <input type="number" min={1} max={120} className="input w-28" value={f.minutes} onChange={(e) => setF({ ...f, minutes: e.target.value })} /></label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.active} onChange={(e) => setF({ ...f, active: e.target.checked })} /> Activo</label>
          </div>
          <div className="flex gap-2"><Button variant="primary" disabled={!f.title.trim()} onClick={save}>Guardar tema</Button><Button variant="ghost" onClick={() => setF(null)}>Cancelar</Button></div>
          {msg && <p role="alert" className="text-sm text-bad">{msg}</p>}
        </div>
      )}
    </Card>
  );
}

"use client";

// Ejecutar simulacro (M-SIM SCR-SIM-02): cronómetro de la alarma a la evacuación completa (RN-SIM-01, comparado con la meta de la
// sede), lista de asistencia con check de los trabajadores activos de la sede y, al guardar, quiénes no participaron (RN-SIM-08).

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card, Kpi } from "./Summary";

type DrillOpt = { id: string; code: string | null; title: string; status: string; scheduled_at: string | null };
type Worker = { id: string; full_name: string; document: string | null; area: string | null; attended: boolean | null; role: string | null };
type Run = { drill: { id: string; code: string | null; title: string; status: string; site: string | null; alarm_time: string | null; evacuation_complete_time: string | null; evacuation_time_seconds: number | null };
  target_seconds: number | null; met: boolean | null; workers: Worker[]; not_participated: string[] };

const ROLE: Record<string, string> = { participant: "Participante", brigadist: "Brigadista", observer: "Observador", evaluator: "Evaluador" };
const mmss = (s: number) => `${Math.floor(s / 60)} min ${String(s % 60).padStart(2, "0")} s`;
const local = (iso: string | null) => (iso ? new Date(new Date(iso).getTime() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 19) : "");

export function SimRun({ readOnly, onSaved }: { readOnly: boolean; onSaved: () => void }) {
  const [drills, setDrills] = useState<DrillOpt[]>([]);
  const [id, setId] = useState("");
  const [run, setRun] = useState<Run | null>(null);
  const [alarm, setAlarm] = useState("");
  const [done, setDone] = useState("");
  const [roles, setRoles] = useState<Record<string, string>>({});
  const [now, setNow] = useState(Date.now());
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  useEffect(() => {
    api<{ data: DrillOpt[] }>("/records/SIM/drills?per_page=100", { withCompany: true })
      .then((r) => setDrills((r.data ?? []).filter((d) => d.status === "planned" || d.status === "executed")))
      .catch(() => setDrills([]));
  }, []);
  useEffect(() => {
    setRun(null);
    setMsg(null);
    if (!id) return;
    api<{ data: Run }>(`/sim/drills/${id}/run`, { withCompany: true }).then((r) => {
      setRun(r.data);
      setAlarm(local(r.data.drill.alarm_time));
      setDone(local(r.data.drill.evacuation_complete_time));
      setRoles(Object.fromEntries(r.data.workers.filter((w) => w.attended).map((w) => [w.id, w.role ?? "participant"])));
    }).catch((e: ApiError) => setMsg({ ok: false, text: e.detail ?? e.title }));
  }, [id]);
  useEffect(() => {
    if (!alarm || done) return;
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, [alarm, done]);

  const elapsed = alarm ? Math.max(0, Math.round(((done ? new Date(done).getTime() : now) - new Date(alarm).getTime()) / 1000)) : null;
  const toggle = (w: string) => setRoles((r) => { const n = { ...r }; if (n[w]) delete n[w]; else n[w] = "participant"; return n; });
  const save = async () => {
    setBusy(true);
    setMsg(null);
    try {
      const r = await api<{ data: Run }>(`/sim/drills/${id}/run`, {
        method: "POST", withCompany: true,
        body: { alarm_time: new Date(alarm).toISOString(), evacuation_complete_time: new Date(done).toISOString(), attendees: Object.entries(roles).map(([worker_id, role]) => ({ worker_id, role })) },
      });
      setRun(r.data);
      setMsg({ ok: true, text: "Ejecución guardada: el simulacro quedó «Ejecutado». Luego regístrale la evaluación y el informe." });
      onSaved();
    } catch (e) {
      const err = e as ApiError;
      setMsg({ ok: false, text: err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card title="Ejecutar simulacro">
      <select className="input max-w-xl" aria-label="Simulacro" value={id} onChange={(e) => setId(e.target.value)}>
        <option value="">Elige un simulacro planificado o ejecutado…</option>
        {drills.map((d) => <option key={d.id} value={d.id}>{[d.code, d.title].filter(Boolean).join(" · ")}{d.scheduled_at ? ` (${new Date(d.scheduled_at).toLocaleDateString("es-PE")})` : ""}</option>)}
      </select>
      {run && (
        <div className="mt-4 space-y-4">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Kpi label="Tiempo de evacuación" value={elapsed == null ? "—" : mmss(elapsed)} sub={alarm && !done ? "Corriendo…" : undefined} />
            <Kpi label="Meta de la sede" value={run.target_seconds == null ? "Sin meta" : mmss(run.target_seconds)} sub={run.drill.site ?? undefined} />
            <Kpi label="Resultado" value={run.target_seconds == null || elapsed == null || !done ? "—" : elapsed <= run.target_seconds ? "Dentro de la meta" : "Fuera de la meta"}
              color={run.target_seconds != null && elapsed != null && done ? (elapsed <= run.target_seconds ? "text-ok" : "text-bad") : undefined} />
            <Kpi label="Presentes" value={`${Object.keys(roles).length} de ${run.workers.length}`} />
          </div>
          {!readOnly && (
            <div className="flex flex-wrap items-end gap-3">
              <Button variant="primary" icon="bell" disabled={!!alarm && !done} onClick={() => { setAlarm(local(new Date().toISOString())); setDone(""); }}>Iniciar alarma</Button>
              <Button icon="check" disabled={!alarm || !!done} onClick={() => setDone(local(new Date().toISOString()))}>Evacuación completa</Button>
              <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Hora de la alarma</span>
                <input type="datetime-local" step={1} className="input" value={alarm} onChange={(e) => setAlarm(e.target.value)} /></label>
              <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">Evacuación completa</span>
                <input type="datetime-local" step={1} className="input" value={done} onChange={(e) => setDone(e.target.value)} /></label>
            </div>
          )}
          <div>
            <div className="mb-1 flex items-center justify-between text-xs font-semibold text-muted">
              <span>Asistencia: trabajadores activos de la sede</span>
              {!readOnly && <span className="space-x-3">
                <button type="button" className="underline" onClick={() => setRoles(Object.fromEntries(run.workers.map((w) => [w.id, roles[w.id] ?? "participant"])))}>Marcar todos</button>
                <button type="button" className="underline" onClick={() => setRoles({})}>Desmarcar todos</button>
              </span>}
            </div>
            {run.workers.length === 0 ? <p className="text-sm text-muted">La sede del simulacro no tiene trabajadores activos.</p> : (
              <ul className="max-h-80 space-y-1 overflow-y-auto rounded-lg border border-line p-2 text-sm">
                {run.workers.map((w) => (
                  <li key={w.id} className="flex flex-wrap items-center gap-2">
                    <input type="checkbox" aria-label={`Presente: ${w.full_name}`} checked={!!roles[w.id]} disabled={readOnly} onChange={() => toggle(w.id)} />
                    <span className="min-w-0 flex-1"><b>{w.full_name}</b> <span className="text-xs text-muted">{[w.document, w.area].filter(Boolean).join(" · ")}</span></span>
                    {roles[w.id] && (
                      <select className="input h-8 w-36 py-0 text-xs" aria-label={`Rol: ${w.full_name}`} value={roles[w.id]} disabled={readOnly}
                        onChange={(e) => setRoles({ ...roles, [w.id]: e.target.value })}>
                        {Object.entries(ROLE).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                      </select>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>
          {!readOnly && <Button variant="primary" loading={busy} disabled={busy || !alarm || !done} onClick={save}>Guardar ejecución</Button>}
          {run.not_participated.length > 0 && (
            <div className="text-sm"><b>No participaron ({run.not_participated.length}):</b> <span className="text-muted">{run.not_participated.join(", ")}</span></div>
          )}
        </div>
      )}
      {msg && <p role={msg.ok ? "status" : "alert"} className={`mt-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </Card>
  );
}

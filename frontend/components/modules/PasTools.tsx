"use client";

// Programa Anual SST (M-PAS): «Mis actividades» del mes para el responsable (SCR-PAS-04), ejecuciones por validar para el Jefe SST
// (RN-PAS-01: solo cuentan las validadas), avance programado vs. ejecutado por mes y por responsable (SCR-PAS-05) y nueva versión del
// programa aprobado para agregar o quitar actividades (RN-PAS-03).

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { uploadFile } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Card } from "./Summary";

type Mine = { id: string; code: string | null; title: string; program: string; executions: { id: string; executed_on: string; status: string; validated: boolean }[] };
type Pending = { id: string; executed_on: string; status: string; description: string | null; source_module: string | null; code: string | null; activity: string; recorded_by: string | null };
type Prog = { program: { id: string; year: number; title: string }; cut_month: number; partial_weight: number; total: { planned: number; executed: number; pct: number | null };
  by_month: { month: number; planned: number; executed: number; pct: number | null }[]; by_responsible: { responsible: string; planned: number; executed: number; pct: number | null }[] };
const MONTHS = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Set", "Oct", "Nov", "Dic"];
const fmt = (d: string) => d.slice(0, 10).split("-").reverse().join("/");

export function PasTools({ readOnly }: { readOnly: boolean }) {
  const [mine, setMine] = useState<{ month: number; activities: Mine[] } | null>(null);
  const [pending, setPending] = useState<Pending[] | null>(null);
  const [prog, setProg] = useState<Prog | null>(null);
  const [form, setForm] = useState<{ id: string; status: string; description: string; file: string; fileName: string } | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const load = useCallback(() => {
    api<{ data: { month: number; activities: Mine[] } }>("/pas/mine", { withCompany: true }).then((r) => setMine(r.data)).catch(() => setMine(null));
    api<{ data: Pending[] }>("/pas/pending-validation", { withCompany: true }).then((r) => setPending(r.data)).catch(() => setPending(null));
    api<{ data: Prog | null }>("/pas/progress", { withCompany: true }).then((r) => setProg(r.data)).catch(() => setProg(null));
  }, []);
  useEffect(() => { load(); }, [load]);
  const fail = (e: unknown) => { const x = e as ApiError; setMsg({ ok: false, text: x.errors ? Object.values(x.errors).flat()[0] : (x.detail ?? x.title) }); };

  const register = async () => {
    if (!form) return;
    setMsg(null);
    try {
      await api("/records/PAS/activity_executions", { method: "POST", withCompany: true, body: { activity_id: form.id, executed_on: new Date().toISOString().slice(0, 10), quantity: 1,
        status: form.status, description: form.description, evidence_file_id: form.file || null } });
      setForm(null);
      setMsg({ ok: true, text: "Ejecución registrada." });
      load();
    } catch (e) { fail(e); }
  };
  const validate = async (id: string) => {
    setMsg(null);
    try { await api(`/pas/executions/${id}/validate`, { method: "POST", withCompany: true }); setMsg({ ok: true, text: "Ejecución validada: ya cuenta en el avance." }); load(); } catch (e) { fail(e); }
  };
  const newVersion = async () => {
    if (!prog) return;
    setMsg(null);
    try {
      const r = await api<{ data: { version_number: number } }>(`/pas/programs/${prog.program.id}/new-version`, { method: "POST", withCompany: true });
      setMsg({ ok: true, text: `Se creó la versión ${r.data.version_number} en borrador con las mismas actividades. Al aprobarla, reemplaza a la actual y conserva lo ejecutado.` });
      load();
    } catch (e) { fail(e); }
  };

  return (
    <div className="space-y-4">
      {msg && <p role={msg.ok ? "status" : "alert"} className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
      {mine && mine.activities.length > 0 && (
        <Card title={`Mis actividades de ${MONTHS[mine.month - 1].toLowerCase()}`}>
          <ul className="space-y-2 text-sm">
            {mine.activities.map((a) => (
              <li key={a.id} className="rounded-lg border border-line p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span><b>{a.code ? `${a.code} ` : ""}{a.title}</b> <span className="text-xs text-muted">· {a.program}</span></span>
                  {a.executions.length > 0
                    ? <span className="text-xs">{a.executions.map((x) => `${fmt(x.executed_on)} ${x.status === "partial" ? "(parcial)" : ""} ${x.validated ? "· validada" : "· por validar"}`).join(" / ")}</span>
                    : !readOnly && <Button size="sm" variant="primary" onClick={() => setForm({ id: a.id, status: "executed", description: "", file: "", fileName: "" })}>Registrar</Button>}
                </div>
                {form?.id === a.id && (
                  <div className="mt-2 grid gap-2 sm:grid-cols-[9rem_1fr_auto_auto] sm:items-center">
                    <select className="input" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}><option value="executed">Ejecutada</option><option value="partial">Parcial</option></select>
                    <input className="input" placeholder="Qué se hizo (evidencia)" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
                    <label className="cursor-pointer text-xs font-semibold text-brand-strong underline">{form.fileName || "Adjuntar evidencia"}
                      <input type="file" className="hidden" onChange={async (e) => { const f = e.target.files?.[0]; if (!f) return; const up = await uploadFile(f, "PAS"); setForm((x) => x && { ...x, file: up.id, fileName: up.original_name }); }} /></label>
                    <Button size="sm" variant="primary" disabled={!form.description.trim() || !form.file} onClick={register}>Guardar</Button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        </Card>
      )}

      {pending && pending.length > 0 && (
        <Card title="Ejecuciones por validar">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line text-xs text-muted"><tr><th className="py-1">Actividad</th><th>Fecha</th><th>Estado</th><th>Evidencia</th><th>Registró</th><th /></tr></thead>
            <tbody>{pending.map((x) => (
              <tr key={x.id} className="border-b border-line last:border-0">
                <td className="py-1.5">{x.code ? `${x.code} ` : ""}{x.activity}</td><td>{fmt(x.executed_on)}</td><td>{x.status === "partial" ? "Parcial" : "Ejecutada"}</td>
                <td>{x.description ?? "—"}{x.source_module && <span className="text-xs text-muted"> (desde {x.source_module})</span>}</td><td>{x.recorded_by ?? "Automática"}</td>
                <td>{!readOnly && <Button size="sm" variant="primary" icon="check" onClick={() => validate(x.id)}>Validar</Button>}</td>
              </tr>
            ))}</tbody>
          </table>
        </Card>
      )}

      {prog && (
        <Card title={`Avance del programa ${prog.program.year}`} action={!readOnly ? <Button size="sm" onClick={newVersion}>Nueva versión</Button> : undefined}>
          <p className="mb-2 text-sm">Hasta {MONTHS[Math.max(prog.cut_month, 1) - 1].toLowerCase()}: <b>{prog.total.executed} de {prog.total.planned}</b> actividades-mes ejecutadas y validadas ({prog.total.pct ?? 0} %).
            <span className="text-xs text-muted"> Una ejecución parcial cuenta {prog.partial_weight}.</span></p>
          <div className="mb-3 grid grid-cols-6 gap-1 text-center text-xs sm:grid-cols-12">
            {prog.by_month.map((m) => (
              <div key={m.month} className={`rounded border border-line p-1 ${m.month > prog.cut_month ? "opacity-50" : ""}`}>
                <p className="font-semibold">{MONTHS[m.month - 1]}</p><p>{m.planned ? `${m.executed}/${m.planned}` : "—"}</p><p className="text-muted">{m.pct != null ? `${m.pct} %` : ""}</p>
              </div>
            ))}
          </div>
          {prog.by_responsible.length > 0 && (
            <table className="w-full text-left text-sm">
              <thead className="border-b border-line text-xs text-muted"><tr><th className="py-1">Responsable</th><th>Programadas a la fecha</th><th>Ejecutadas</th><th>Avance</th></tr></thead>
              <tbody>{prog.by_responsible.map((r) => <tr key={r.responsible} className="border-b border-line last:border-0"><td className="py-1.5">{r.responsible}</td><td>{r.planned}</td><td>{r.executed}</td><td>{r.pct ?? 0} %</td></tr>)}</tbody>
            </table>
          )}
        </Card>
      )}
    </div>
  );
}

"use client";

// Capacitación (M-CAP):
//  - SCR-CAP-08 «Cumplimiento de la matriz»: trabajadores × temas que exige su cargo con semáforo (vigente, por vencer,
//    vencido, faltante o en plazo de ingreso), filtros por sede, área y cargo, y las acciones «Convocar» a una sesión
//    programada o «Matricular en el aula» si el tema tiene curso.
//  - SCR-CAP-10 bandeja «Por validar»: certificados que esperan al Jefe SST, con validación en lote (RN-CAP-08).

import { useCallback, useEffect, useMemo, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { records } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Card } from "./Summary";
import { RefSelect } from "./RefSelect";

type Item = { training_id: string; title: string; course_id: string | null; state: "valid" | "expiring" | "expired" | "missing" | "in_window"; expires_on: string | null };
type Worker = { id: string; name: string; position: string | null; area: string | null; site: string | null; items: Item[] };
type Session = { id: string; training_id: string; starts_at: string; capacity: number | null; invited: number };
type Data = { workers: Worker[]; counts: Record<string, number>; sessions: Session[] };

const STATE: Record<Item["state"], { label: string; cls: string }> = {
  valid: { label: "Vigente", cls: "bg-ok-soft text-ok" },
  expiring: { label: "Por vencer", cls: "bg-amber-soft text-amber-ink" },
  expired: { label: "Vencido", cls: "bg-bad-soft text-bad" },
  missing: { label: "Faltante", cls: "bg-bad-soft text-bad" },
  in_window: { label: "En plazo", cls: "bg-na-soft text-muted" },
};
const fmt = (d: string | null) => (d ? new Date(d.length === 10 ? `${d}T12:00:00` : d).toLocaleDateString("es-PE") : "");
const fmtDT = (d: string) => new Date(d).toLocaleString("es-PE", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" });

export function CapCompliance() {
  const [f, setF] = useState({ site_id: "", area_id: "", position_id: "" });
  const [data, setData] = useState<Data | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [training, setTraining] = useState("");
  const [only, setOnly] = useState<"gaps" | "all">("gaps");
  const [picked, setPicked] = useState<Set<string>>(new Set());
  const [session, setSession] = useState("");
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    const qs = new URLSearchParams(Object.entries(f).filter(([, v]) => v));
    api<{ data: Data }>(`/cap/compliance?${qs}`, { withCompany: true }).then((r) => setData(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [f]);
  useEffect(load, [load]);

  const trainings = useMemo(() => {
    const m = new Map<string, { title: string; course_id: string | null }>();
    data?.workers.forEach((w) => w.items.forEach((i) => m.set(i.training_id, { title: i.title, course_id: i.course_id })));
    return [...m.entries()].sort((a, b) => a[1].title.localeCompare(b[1].title));
  }, [data]);
  const rows = useMemo(
    () =>
      (data?.workers ?? [])
        .map((w) => ({ ...w, items: w.items.filter((i) => (!training || i.training_id === training) && (only === "all" || i.state !== "valid")) }))
        .filter((w) => w.items.length > 0),
    [data, training, only],
  );
  const sel = trainings.find(([id]) => id === training)?.[1];
  const sessions = (data?.sessions ?? []).filter((s) => s.training_id === training);

  const toggle = (id: string) => setPicked((p) => { const n = new Set(p); if (n.has(id)) n.delete(id); else n.add(id); return n; });
  const invite = () => {
    setBusy(true);
    api<{ data: { invited: number; errors: string[] } }>(`/cap/sessions/${session}/invite`, { method: "POST", withCompany: true, body: { worker_ids: [...picked] } })
      .then((r) => { setMsg({ text: `Convocados: ${r.data.invited}.${r.data.errors.length ? ` No se pudo: ${r.data.errors.join(" · ")}` : ""}`, ok: r.data.errors.length === 0 }); setPicked(new Set()); load(); })
      .catch((e: ApiError) => setMsg({ text: e.detail ?? e.title, ok: false }))
      .finally(() => setBusy(false));
  };
  const enroll = () => {
    if (!sel?.course_id) return;
    setBusy(true);
    api<{ data: { enrolled?: number } }>(`/aul/admin/courses/${sel.course_id}/enroll`, { method: "POST", withCompany: true, body: { worker_ids: [...picked] } })
      .then((r) => { setMsg({ text: `Matriculados en el aula: ${r.data.enrolled ?? picked.size}.`, ok: true }); setPicked(new Set()); })
      .catch((e: ApiError) => setMsg({ text: e.detail ?? e.title, ok: false }))
      .finally(() => setBusy(false));
  };

  return (
    <Card title="Cumplimiento por trabajador">
      <p className="mb-3 text-sm text-muted">Temas que exige el cargo de cada trabajador y su estado. Elige un tema para convocar a una sesión o matricular en el aula a quienes les falta.</p>
      <div className="mb-3 grid gap-3 sm:grid-cols-3">
        <RefSelect module="CAP" table="training_sessions" field="site_id" label="Sede" value={f.site_id} onChange={(id) => setF({ ...f, site_id: id })} />
        <RefSelect module="TRB" table="workers" field="current_area_id" label="Área" value={f.area_id} onChange={(id) => setF({ ...f, area_id: id })} />
        <RefSelect module="CAP" table="position_training_requirements" field="position_id" label="Cargo" value={f.position_id} onChange={(id) => setF({ ...f, position_id: id })} />
      </div>
      <div className="mb-3 flex flex-wrap items-center gap-3 text-sm">
        <select className="input max-w-xs" aria-label="Tema" value={training} onChange={(e) => { setTraining(e.target.value); setPicked(new Set()); setSession(""); }}>
          <option value="">Todos los temas</option>
          {trainings.map(([id, t]) => <option key={id} value={id}>{t.title}</option>)}
        </select>
        <label className="inline-flex items-center gap-2"><input type="checkbox" checked={only === "gaps"} onChange={(e) => setOnly(e.target.checked ? "gaps" : "all")} /> Solo brechas</label>
        {data && (
          <span className="flex flex-wrap gap-2">
            {(Object.keys(STATE) as Item["state"][]).map((k) => (
              <span key={k} className={`rounded-full px-2 py-0.5 text-xs font-semibold ${STATE[k].cls}`}>{STATE[k].label}: {data.counts[k] ?? 0}</span>
            ))}
          </span>
        )}
      </div>
      {error && <p className="text-sm text-bad">{error}</p>}
      {!data && !error && <div className="h-24 animate-pulse rounded-xl bg-bg" />}
      {data && rows.length === 0 && <p className="text-sm text-muted">No hay brechas con estos filtros.</p>}
      {data && rows.length > 0 && (
        <div className="max-h-[480px] overflow-auto" tabIndex={0} role="region" aria-label="Cumplimiento por trabajador">
          <table className="w-full text-left text-sm">
            <thead className="sticky top-0 border-b border-line bg-surface text-xs font-semibold text-muted">
              <tr>
                {training && <th className="w-8 px-2 py-2"><span className="sr-only">Elegir</span></th>}
                <th className="px-3 py-2">Trabajador</th>
                <th className="px-3 py-2">Temas exigidos por su cargo</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((w) => (
                <tr key={w.id} className="border-b border-line last:border-0">
                  {training && (
                    <td className="px-2 py-2">
                      <input type="checkbox" aria-label={`Elegir a ${w.name}`} checked={picked.has(w.id)} disabled={w.items.every((i) => i.state === "valid")} onChange={() => toggle(w.id)} />
                    </td>
                  )}
                  <td className="px-3 py-2">
                    <b>{w.name}</b>
                    <span className="block text-xs text-muted">{[w.position, w.area, w.site].filter(Boolean).join(" · ")}</span>
                  </td>
                  <td className="px-3 py-2">
                    <span className="flex flex-wrap gap-1.5">
                      {w.items.map((i) => (
                        <span key={i.training_id} className={`rounded-md px-2 py-1 text-xs ${STATE[i.state].cls}`} title={i.expires_on ? `Vence el ${fmt(i.expires_on)}` : undefined}>
                          {training ? "" : `${i.title}: `}<b>{STATE[i.state].label}</b>{i.expires_on && i.state !== "missing" ? ` · ${fmt(i.expires_on)}` : ""}
                        </span>
                      ))}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {training && (
        <div className="mt-3 flex flex-wrap items-center gap-3 border-t border-line pt-3 text-sm">
          <span className="font-semibold">{picked.size} elegido(s)</span>
          <select className="input max-w-xs" aria-label="Sesión programada" value={session} onChange={(e) => setSession(e.target.value)}>
            <option value="">{sessions.length ? "Elige una sesión programada" : "Sin sesiones programadas de este tema"}</option>
            {sessions.map((s) => <option key={s.id} value={s.id}>{fmtDT(s.starts_at)}{s.capacity ? ` · ${s.invited}/${s.capacity}` : ""}</option>)}
          </select>
          <Button variant="primary" disabled={!session || picked.size === 0 || busy} onClick={invite}>Convocar</Button>
          {sel?.course_id && <Button variant="secondary" disabled={picked.size === 0 || busy} onClick={enroll}>Matricular en el aula</Button>}
        </div>
      )}
      {msg && <p className={`mt-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </Card>
  );
}

type Cert = { id: string; certificate_code: string; holder_name: string; course_title: string; issued_on: string; hours: number | null; score: number | null };

export function CapToValidate() {
  const [rows, setRows] = useState<Cert[] | null>(null);
  const [picked, setPicked] = useState<Set<string>>(new Set());
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const [busy, setBusy] = useState(false);
  const load = useCallback(() => {
    records.list("CAP", "training_certificates", { filters: { status: "pending_validation" } })
      .then((r) => setRows(r.data as unknown as Cert[]))
      .catch(() => setRows([]));
  }, []);
  useEffect(load, [load]);
  if (!rows || rows.length === 0) return null;

  const all = picked.size === rows.length;
  const validate = () => {
    setBusy(true);
    api<{ data: { validated: number; errors: string[] } }>("/cap/certificates/validate", { method: "POST", withCompany: true, body: { ids: [...picked] } })
      .then((r) => { setMsg({ text: `Validados: ${r.data.validated}.${r.data.errors.length ? ` No se pudo: ${r.data.errors.join(" · ")}` : ""}`, ok: r.data.errors.length === 0 }); setPicked(new Set()); load(); })
      .catch((e: ApiError) => setMsg({ text: e.detail ?? e.title, ok: false }))
      .finally(() => setBusy(false));
  };

  return (
    <Card title={`Por validar (${rows.length})`}>
      <p className="mb-3 text-sm text-muted">Certificados de plantillas que exigen validación. No se descargan ni se verifican como válidos hasta que los valides.</p>
      <div className="max-h-[360px] overflow-auto" tabIndex={0} role="region" aria-label="Certificados por validar">
        <table className="w-full text-left text-sm">
          <thead className="sticky top-0 border-b border-line bg-surface text-xs font-semibold text-muted">
            <tr>
              <th className="w-8 px-2 py-2"><input type="checkbox" aria-label="Elegir todos" checked={all} onChange={() => setPicked(all ? new Set() : new Set(rows.map((r) => r.id)))} /></th>
              <th className="px-3 py-2">Código</th><th className="px-3 py-2">Titular</th><th className="px-3 py-2">Capacitación</th><th className="px-3 py-2">Emitido</th><th className="px-3 py-2">Nota</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((c) => (
              <tr key={c.id} className="border-b border-line last:border-0">
                <td className="px-2 py-2"><input type="checkbox" aria-label={`Elegir ${c.certificate_code}`} checked={picked.has(c.id)} onChange={() => setPicked((p) => { const n = new Set(p); if (n.has(c.id)) n.delete(c.id); else n.add(c.id); return n; })} /></td>
                <td className="px-3 py-2 font-mono text-xs">{c.certificate_code}</td>
                <td className="px-3 py-2">{c.holder_name}</td>
                <td className="px-3 py-2">{c.course_title}</td>
                <td className="px-3 py-2">{fmt(c.issued_on)}</td>
                <td className="px-3 py-2">{c.score ?? "—"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="mt-3 flex items-center gap-3">
        <Button variant="primary" disabled={picked.size === 0 || busy} onClick={validate}>Validar {picked.size || ""}</Button>
        {msg && <span className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</span>}
      </div>
    </Card>
  );
}

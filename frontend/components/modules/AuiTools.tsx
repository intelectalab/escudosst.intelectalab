"use client";

// Auditorías (M-AUI): SCR-AUI-01 calendario anual por mes con estado, SCR-AUI-02 preparación con el último autodiagnóstico SUNAFIL,
// y por auditoría: cargar el checklist desde la plantilla de la Ley 29783 (RN-AUI-07) y dar acceso temporal al auditor externo (RN-AUI-02).

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card } from "./Summary";

type Audit = { id: string; code: string; audit_type: string; standard: string; starts_on: string | null; ends_on: string | null; status: string; lead_auditor_name: string | null; site: string | null; ncs: number };
type Data = { year: number; audits: Audit[]; preparation: { id: string; title: string; diagnostic_on: string | null; score_pct: number | null; critical_gaps: unknown } | null };
const MONTHS = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Setiembre", "Octubre", "Noviembre", "Diciembre"];
const TYPE: Record<string, string> = { internal: "Interna", external: "Externa", legal_mandatory: "Legal obligatoria", certification: "Certificación", client: "De cliente", supplier: "A proveedor" };
const STATUS: Record<string, string> = { planned: "Planificada", in_progress: "En curso", report_draft: "Informe en borrador", completed: "Completada", closed: "Cerrada", cancelled: "Cancelada" };
const fmt = (d: string | null) => (d ? d.slice(0, 10).split("-").reverse().join("/") : "—");
const gapsText = (g: unknown): string[] => (Array.isArray(g) ? g.map((x) => (typeof x === "string" ? x : String((x as Record<string, unknown>)?.title ?? (x as Record<string, unknown>)?.question ?? JSON.stringify(x)))) : []);

export function AuiTools({ readOnly, onSaved }: { readOnly: boolean; onSaved: () => void }) {
  const [year, setYear] = useState(new Date().getFullYear());
  const [d, setD] = useState<Data | null>(null);
  const [sel, setSel] = useState("");
  const [inv, setInv] = useState({ email: "", name: "" });
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const load = useCallback(() => api<{ data: Data }>(`/aui/calendar?year=${year}`, { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null)), [year]);
  useEffect(() => { void load(); }, [load]);
  const fail = (e: unknown) => { const err = e as ApiError; setMsg({ ok: false, text: err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title) }); };

  const loadChecklist = async () => {
    setMsg(null);
    try {
      const r = await api<{ data: { added: number } }>(`/aui/audits/${sel}/checklist`, { method: "POST", withCompany: true });
      setMsg({ ok: true, text: `Se cargaron ${r.data.added} ítem(s) del checklist.` });
      onSaved();
    } catch (e) { fail(e); }
  };
  const access = async () => {
    setMsg(null);
    try {
      const r = await api<{ data: { expires_on: string } }>(`/aui/audits/${sel}/auditor-access`, { method: "POST", withCompany: true, body: { email: inv.email, full_name: inv.name } });
      setMsg({ ok: true, text: `Se envió la invitación al auditor externo. Su acceso vence el ${fmt(r.data.expires_on)}.` });
      setInv({ email: "", name: "" });
      void load();
    } catch (e) { fail(e); }
  };

  return (
    <div className="space-y-4">
      <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <Card title="Programa de auditorías">
          <input type="number" className="input mb-3 w-28" aria-label="Año" value={year} onChange={(e) => setYear(Number(e.target.value))} />
          {d && (
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {MONTHS.map((m, i) => {
                const list = d.audits.filter((a) => a.starts_on && Number(a.starts_on.slice(5, 7)) === i + 1);
                return (
                  <div key={m} className="rounded-lg border border-line p-2 text-xs">
                    <p className="mb-1 font-semibold">{m}</p>
                    {list.length === 0 ? <p className="text-muted">—</p> : list.map((a) => (
                      <p key={a.id}><b>{a.code}</b> · {TYPE[a.audit_type] ?? a.audit_type} · {STATUS[a.status] ?? a.status}{a.ncs ? ` · ${a.ncs} NC` : ""}</p>
                    ))}
                  </div>
                );
              })}
            </div>
          )}
        </Card>
        <Card title="Preparación">
          {!d?.preparation ? <p className="text-sm text-muted">Aún no hay un autodiagnóstico SUNAFIL completado. Hazlo en Modo Inspección SUNAFIL para preparar la auditoría.</p> : (
            <div className="space-y-2 text-sm">
              <p>Último autodiagnóstico: <b>{d.preparation.title}</b> del {fmt(d.preparation.diagnostic_on)}{d.preparation.score_pct != null ? ` · ${d.preparation.score_pct}%` : ""}</p>
              {gapsText(d.preparation.critical_gaps).length > 0 && (
                <><p className="font-semibold">Brechas críticas a revisar:</p><ul className="list-disc pl-5">{gapsText(d.preparation.critical_gaps).map((g, i) => <li key={i}>{g}</li>)}</ul></>
              )}
            </div>
          )}
        </Card>
      </div>
      {!readOnly && d && d.audits.length > 0 && (
        <Card title="Preparar una auditoría">
          <select className="input max-w-xl" aria-label="Auditoría" value={sel} onChange={(e) => setSel(e.target.value)}>
            <option value="">Elige la auditoría…</option>
            {d.audits.filter((a) => !["closed", "cancelled"].includes(a.status)).map((a) => <option key={a.id} value={a.id}>{a.code} · {TYPE[a.audit_type] ?? a.audit_type} · {fmt(a.starts_on)} al {fmt(a.ends_on)}</option>)}
          </select>
          {sel && (
            <div className="mt-3 grid gap-4 md:grid-cols-2">
              <div className="space-y-2 text-sm">
                <p className="font-semibold">Checklist</p>
                <p className="text-muted">Carga los ítems de la plantilla de la Ley 29783 (las preguntas base de EscudoSST). No duplica los ya cargados.</p>
                <Button onClick={loadChecklist}>Cargar checklist de la Ley 29783</Button>
              </div>
              <div className="space-y-2 text-sm">
                <p className="font-semibold">Acceso del auditor externo</p>
                <input className="input" placeholder="Nombre completo" value={inv.name} onChange={(e) => setInv({ ...inv, name: e.target.value })} />
                <input className="input" type="email" placeholder="Correo" value={inv.email} onChange={(e) => setInv({ ...inv, email: e.target.value })} />
                <Button disabled={!inv.email || !inv.name} onClick={access}>Dar acceso temporal</Button>
                <p className="text-xs text-muted">El acceso vence al terminar la auditoría (más los días de gracia configurados).</p>
              </div>
            </div>
          )}
        </Card>
      )}
      {msg && <p role={msg.ok ? "status" : "alert"} className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
    </div>
  );
}

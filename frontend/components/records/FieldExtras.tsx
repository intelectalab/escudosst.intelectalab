"use client";

// Acciones propias de algunos registros de campo:
//  - ACT: escalar un reporte a evento (RN-ACT-06) y cambiar el QR de un punto (el anterior deja de funcionar).
//  - ATS: capacitaciones exigidas que le faltan a cada participante (RN-ATS-03).
//  - INS: nueva versión de una plantilla ya usada (RN-INS-04).
//  - PTR: QR del permiso que abre su estado en el celular.

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import type { RecordRow } from "@/lib/records";
import { QrCode } from "@/components/mfa/QrCode";

const err = (e: unknown) => { const x = e as ApiError; return x.errors ? Object.values(x.errors).flat()[0] : (x.detail ?? x.title); };
const EVENT: [string, string][] = [["dangerous_incident", "Incidente peligroso"], ["incident", "Incidente"], ["minor_accident", "Accidente leve"],
  ["disabling_accident", "Accidente incapacitante"], ["fatal_accident", "Accidente mortal"], ["occupational_disease", "Enfermedad ocupacional"]];

export function EscalateReport({ row, onChanged }: { row: RecordRow; onChanged: () => void }) {
  const [type, setType] = useState("dangerous_incident");
  const [open, setOpen] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  if (row.escalated_event_id) return <p className="text-sm text-muted">Este reporte se escaló a un evento: su seguimiento continúa en Accidentes e incidentes.</p>;
  if (["rejected", "closed"].includes(String(row.status))) return null;
  const go = async () => {
    setMsg(null);
    try {
      await api(`/act/reports/${row.id}/escalate`, { method: "POST", withCompany: true, body: { event_type: type } });
      setMsg({ ok: true, text: "Escalado: se registró el evento y corren sus plazos de notificación." });
      setOpen(false);
      onChanged();
    } catch (e) { setMsg({ ok: false, text: err(e) }); }
  };
  return (
    <div className="space-y-2 text-sm">
      {!open ? <button type="button" onClick={() => setOpen(true)} className="rounded-lg border border-bad px-3 py-1.5 font-semibold text-bad">Escalar a accidente o incidente</button> : (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-line p-3">
          <select className="input !w-auto" value={type} onChange={(e) => setType(e.target.value)} aria-label="Tipo de evento">{EVENT.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
          <button type="button" onClick={go} className="rounded-lg bg-bad px-3 py-1.5 font-semibold text-white">Escalar</button>
          <button type="button" onClick={() => setOpen(false)} className="rounded-lg border border-line px-3 py-1.5 font-semibold">Cancelar</button>
        </div>
      )}
      {msg && <p role={msg.ok ? "status" : "alert"} className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </div>
  );
}

export function RotateQr({ id, onRotated }: { id: string; onRotated: (url: string) => void }) {
  const [confirm, setConfirm] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const go = async () => {
    try {
      const r = await api<{ data: { url: string } }>(`/act/report-points/${id}/rotate`, { method: "POST", withCompany: true });
      onRotated(r.data.url);
      setConfirm(false);
      setMsg("QR cambiado: imprime el cartel nuevo; el anterior ya no funciona.");
    } catch (e) { setMsg(err(e)); }
  };
  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      {!confirm ? <button type="button" onClick={() => setConfirm(true)} className="rounded-lg border border-line px-3 py-1.5 font-semibold text-brand hover:border-brand">Cambiar QR</button> : (
        <>¿El QR actual dejará de funcionar?<button type="button" onClick={go} className="rounded-lg bg-bad px-3 py-1.5 font-semibold text-white">Sí, cambiar</button>
          <button type="button" onClick={() => setConfirm(false)} className="rounded-lg border border-line px-3 py-1.5 font-semibold">No</button></>
      )}
      {msg && <span className="text-xs text-muted">{msg}</span>}
    </span>
  );
}

type Part = { participant_id: string; name: string; role: string | null; missing: string[] };

export function AtsTrainingCheck({ row }: { row: RecordRow }) {
  const [d, setD] = useState<{ block: boolean; participants: Part[] } | null>(null);
  useEffect(() => { api<{ data: { block: boolean; participants: Part[] } }>(`/ats/forms/${row.id}/training-check`, { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null)); }, [row.id]);
  if (!d || d.participants.length === 0) return null;
  const miss = d.participants.filter((p) => p.missing.length > 0);
  return (
    <div className={`rounded-lg border p-3 text-sm ${miss.length ? "border-amber-ink/40 bg-amber-soft/40" : "border-line"}`}>
      <p className="font-semibold">Capacitación exigida a los participantes</p>
      {miss.length === 0 ? <p className="text-ok">Todos tienen vigentes las capacitaciones exigidas.</p> : (
        <ul className="mt-1 space-y-1">{miss.map((p) => <li key={p.participant_id}><b>{p.name}</b>: le falta {p.missing.join(", ")}.</li>)}</ul>
      )}
      {miss.length > 0 && <p className="mt-1 text-xs text-muted">{d.block ? "Quien no tiene la capacitación no puede firmar el ATS." : "Es una advertencia: puede firmar, pero conviene capacitarlo."}</p>}
    </div>
  );
}

export function TemplateVersion({ row, onChanged }: { row: RecordRow; onChanged: () => void }) {
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const go = async () => {
    setMsg(null);
    try {
      const r = await api<{ data: { version_number: number } }>(`/ins/templates/${row.id}/new-version`, { method: "POST", withCompany: true });
      setMsg({ ok: true, text: `Se creó la versión ${r.data.version_number} en borrador con las mismas preguntas. Cámbiala y actívala; esta versión se retirará.` });
      onChanged();
    } catch (e) { setMsg({ ok: false, text: err(e) }); }
  };
  return (
    <div className="space-y-1 text-sm">
      <button type="button" onClick={go} className="rounded-lg border border-line px-3 py-1.5 font-semibold text-brand hover:border-brand">Crear nueva versión de la plantilla</button>
      <p className="text-xs text-muted">Las inspecciones ya hechas conservan la versión que usaron.</p>
      {msg && <p role={msg.ok ? "status" : "alert"} className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </div>
  );
}

export function PermitQr({ row }: { row: RecordRow }) {
  const link = typeof window === "undefined" ? "" : `${window.location.origin}/m/ptr/ver/${row.id}`;
  if (!link) return null;
  return (
    <section className="flex flex-wrap items-center gap-3 rounded-xl border border-line px-4 py-3 text-sm">
      <div className="rounded-lg bg-white p-2 ring-1 ring-line"><QrCode value={link} size={96} /></div>
      <p className="min-w-0 flex-1">QR del permiso: al escanearlo (con sesión) se ve en el celular si está vigente, suspendido o vencido, su cuadrilla y su horario.</p>
    </section>
  );
}

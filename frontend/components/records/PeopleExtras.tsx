"use client";

// Acciones propias de algunos registros de Personas:
//  - CAP: firma del participante en la pantalla, que valida su asistencia (SCR-CAP-05, RN-CAP-01).
//  - CAP: aviso si el capacitador no tiene disponibilidad registrada en el horario de la sesión (RN-CAP-16).
//  - FOT: «Registrar verificación» en campo e historial de verificaciones (SCR-FOT-03/07).

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import type { RecordRow } from "@/lib/records";
import { SignaturePad } from "@/components/addons/SignaturePad";

const err = (e: unknown) => { const x = e as ApiError; return x.errors ? Object.values(x.errors).flat()[0] : (x.detail ?? x.title); };

export function AttendanceSign({ row, onChanged }: { row: RecordRow; onChanged: () => void }) {
  const [png, setPng] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  if (row.signature_id) return <p className="text-sm text-ok">Asistencia firmada por el participante.</p>;
  const save = async () => {
    if (!png) return;
    setMsg(null);
    try {
      await api(`/cap/attendances/${row.id}/sign`, { method: "POST", withCompany: true, body: { image: png } });
      setMsg({ ok: true, text: "Firma registrada: la asistencia queda válida." });
      onChanged();
    } catch (e) { setMsg({ ok: false, text: err(e) }); }
  };
  return (
    <div className="space-y-2 rounded-xl border border-line p-3 text-sm">
      <p className="font-semibold">Firma del participante</p>
      <p className="text-xs text-muted">Pásale la pantalla al participante para que firme con el dedo. La asistencia cuenta solo con su firma.</p>
      <SignaturePad onChange={(d) => setPng(d)} height={160} />
      <button type="button" disabled={!png} onClick={save} className="rounded-lg bg-brand px-4 py-2 font-semibold text-white disabled:opacity-50">Guardar firma</button>
      {msg && <p role={msg.ok ? "status" : "alert"} className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </div>
  );
}

export function TrainerWarning({ row }: { row: RecordRow }) {
  const [w, setW] = useState<string | null>(null);
  useEffect(() => {
    if (!row.instructor_user_id) return;
    api<{ data: { warning: string | null } }>(`/cap/sessions/${row.id}/trainer-check`, { withCompany: true }).then((r) => setW(r.data.warning)).catch(() => setW(null));
  }, [row.id, row.instructor_user_id, row.starts_at]);
  if (!w) return null;
  return <p className="rounded-lg bg-amber-soft px-3 py-2 text-sm text-amber-ink">{w} Puedes mantener la sesión, pero confirma con el capacitador.</p>;
}

type Ver = { verified_at: string; channel: string; result: string; verifier: string | null };
const CH: Record<string, string> = { public_qr: "QR público", app_scan: "Verificación en campo", access_point: "Garita" };
const RES: Record<string, [string, string]> = { enabled: ["Habilitado", "text-ok"], not_enabled: ["No habilitado", "text-bad"], expired: ["Vencido", "text-bad"], revoked: ["Revocado", "text-bad"], invalid: ["Inválido", "text-bad"] };

export function CredentialVerify({ row }: { row: RecordRow }) {
  const [list, setList] = useState<Ver[]>([]);
  const [last, setLast] = useState<{ result: string; missing: string[] } | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const load = useCallback(() => api<{ data: Ver[] }>(`/fot/credentials/${row.id}/verifications`, { withCompany: true }).then((r) => setList(r.data)).catch(() => setList([])), [row.id]);
  useEffect(() => { void load(); }, [load]);
  const verify = () => {
    setMsg(null);
    const send = (pos?: GeolocationPosition) => api<{ data: { result: string; missing: string[] } }>(`/fot/credentials/${row.id}/verifications`, { method: "POST", withCompany: true,
      body: pos ? { latitude: pos.coords.latitude, longitude: pos.coords.longitude } : {} }).then((r) => { setLast(r.data); void load(); }).catch((e) => setMsg(err(e)));
    if (typeof navigator !== "undefined" && navigator.geolocation) navigator.geolocation.getCurrentPosition((p) => void send(p), () => void send(), { timeout: 4000 });
    else void send();
  };
  return (
    <div className="space-y-2 rounded-xl border border-line p-3 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-semibold">Verificaciones</p>
        <button type="button" onClick={verify} className="rounded-lg bg-brand px-3 py-1.5 font-semibold text-white">Registrar verificación</button>
      </div>
      {msg && <p className="text-bad">{msg}</p>}
      {last && <p className={RES[last.result]?.[1]}><b>{RES[last.result]?.[0] ?? last.result}</b>{last.missing.length ? ` · Le falta: ${last.missing.join(", ")}` : ""}</p>}
      {list.length === 0 ? <p className="text-muted">Aún no tiene verificaciones.</p> : (
        <ul className="max-h-48 space-y-0.5 overflow-auto">{list.map((v, k) => (
          <li key={k}>{new Date(v.verified_at).toLocaleString("es-PE")} · {CH[v.channel] ?? v.channel} · <span className={RES[v.result]?.[1]}>{RES[v.result]?.[0] ?? v.result}</span>{v.verifier ? ` · ${v.verifier}` : ""}</li>
        ))}</ul>
      )}
    </div>
  );
}

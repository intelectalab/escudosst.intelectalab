"use client";

// Firmas de un registro (M-FIR): quién debe firmar, quién ya firmó (y cómo) y el botón para pedir otra firma.
// El usuario firma confirmando su contraseña; a un trabajador sin usuario lo firma el área SST «asistido en persona».
// Si el registro cambia después de pedir la firma, la firma no procede (se vuelve a pedir sobre la versión actual).

import { useCallback, useEffect, useState } from "react";
import { useApp } from "@/components/AppContext";
import { Button } from "@/components/ui/Button";
import { api, ApiError } from "@/lib/api";
import { RefSelect } from "./ModuleRecords";

type Req = {
  id: string; role_in_document: string; status: string; signer_user_id: string | null; signer_worker_id: string | null; signer_name: string | null;
  expires_at: string | null; signed_at: string | null; declined_reason: string | null; method: string | null;
};

const ROLES: [string, string][] = [
  ["responsible", "Responsable"], ["approver", "Aprueba"], ["reviewer", "Revisa"], ["worker", "Trabajador"], ["attendee", "Asistente"],
  ["witness", "Testigo"], ["committee_member", "Miembro del comité"], ["doctor", "Médico"],
];
const ROLE = Object.fromEntries(ROLES);
const STATUS: Record<string, [string, string]> = {
  pending: ["Pendiente", "bg-amber-soft text-amber-ink"], signed: ["Firmado", "bg-ok-soft text-ok"], declined: ["Rechazado", "bg-bad-soft text-bad"],
  expired: ["Vencido", "bg-bg text-muted"], cancelled: ["Anulado", "bg-bg text-muted"],
};
const METHOD: Record<string, string> = { pin: "con contraseña", assisted_in_person: "asistida en persona", stroke: "trazo", otp: "código", scanned: "escaneada" };
const dt = (v: string | null) => (v ? new Date(v).toLocaleString("es-PE", { timeZone: "America/Lima", dateStyle: "short", timeStyle: "short" }) : "");

export function SignaturePanel({ table, id, version }: { table: string; id: string; version: number }) {
  const { user, modules } = useApp();
  const enabled = modules.some((m) => m.code === "FIR" && m.mode === "enabled");
  const [items, setItems] = useState<Req[] | null>(null);
  const [asking, setAsking] = useState(false);
  const [who, setWho] = useState<{ kind: "user" | "worker"; id: string; role: string }>({ kind: "user", id: "", role: "responsible" });
  const [pwd, setPwd] = useState<Record<string, string>>({});
  const [msg, setMsg] = useState<string | null>(null);

  const load = useCallback(() => {
    if (!enabled) return;
    api<{ data: Req[] }>(`/fir/subject/${table}/${id}`, { withCompany: true }).then((r) => setItems(r.data)).catch(() => setItems([]));
  }, [enabled, table, id]);
  useEffect(load, [load, version]);

  if (!enabled || items === null) return null;
  const err = (e: unknown) => setMsg(Object.values((e as ApiError).errors ?? {})[0]?.[0] ?? (e as ApiError).detail ?? (e as ApiError).title);

  async function ask() {
    setMsg(null);
    try {
      await api("/fir/requests", { method: "POST", withCompany: true, body: { subject_table: table, subject_id: id, role_in_document: who.role,
        signer_user_id: who.kind === "user" ? who.id : null, signer_worker_id: who.kind === "worker" ? who.id : null } });
      setAsking(false);
      setWho({ ...who, id: "" });
      load();
    } catch (e) {
      err(e);
    }
  }
  async function sign(r: Req) {
    setMsg(null);
    try {
      await api(`/fir/requests/${r.id}/sign`, { method: "POST", withCompany: true, body: r.signer_user_id ? { password: pwd[r.id] ?? "" } : { assisted: true } });
      load();
    } catch (e) {
      err(e);
    }
  }

  return (
    <section className="space-y-2 rounded-xl border border-line px-4 py-3 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-semibold">Firmas {items.length > 0 && <span className="font-normal text-muted">· {items.filter((i) => i.status === "signed").length} de {items.length}</span>}</p>
        {!asking && <Button size="sm" variant="secondary" icon="pen" onClick={() => setAsking(true)}>Pedir firma</Button>}
      </div>
      {items.map((r) => {
        const [label, cls] = STATUS[r.status] ?? [r.status, "bg-bg"];
        const mine = r.signer_user_id === user.id;
        return (
          <div key={r.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-bg px-3 py-2">
            <span>
              <b>{r.signer_name}</b> · {ROLE[r.role_in_document] ?? r.role_in_document}
              {r.signed_at && <span className="text-muted"> · {dt(r.signed_at)} {r.method ? `(${METHOD[r.method] ?? r.method})` : ""}</span>}
              {r.declined_reason && <span className="block text-xs text-bad">Motivo: {r.declined_reason}</span>}
            </span>
            <span className="flex flex-wrap items-center gap-2">
              {r.status === "pending" && mine && (
                <>
                  <input type="password" className="input !h-8 !w-40" placeholder="Tu contraseña" aria-label="Contraseña para firmar" value={pwd[r.id] ?? ""}
                    onChange={(e) => setPwd({ ...pwd, [r.id]: e.target.value })} />
                  <Button size="sm" variant="primary" onClick={() => sign(r)}>Firmar</Button>
                </>
              )}
              {r.status === "pending" && !r.signer_user_id && (
                <Button size="sm" variant="secondary" onClick={() => sign(r)} title="El trabajador está presente y está de acuerdo con firmar">Firma asistida (presente)</Button>
              )}
              <span className={`rounded-md px-2 py-0.5 text-xs font-semibold ${cls}`}>{label}</span>
            </span>
          </div>
        );
      })}
      {asking && (
        <div className="flex flex-wrap items-end gap-2 rounded-lg border border-dashed border-line p-3">
          <label className="text-xs">
            Firma
            <select className="input mt-1 !w-36" value={who.kind} onChange={(e) => setWho({ ...who, kind: e.target.value as "user" | "worker", id: "" })}>
              <option value="user">Un usuario</option>
              <option value="worker">Un trabajador</option>
            </select>
          </label>
          <div className="min-w-[14rem] flex-1 text-xs">
            {who.kind === "user" ? "Usuario" : "Trabajador"}
            <RefSelect key={who.kind} code="FIR" table="signature_requests"
              f={{ name: who.kind === "user" ? "signer_user_id" : "signer_worker_id", type: "ref", ref: who.kind === "user" ? "users" : "workers", label: "Firmante" } as never}
              value={who.id} disabled={false} invalid={false} onChange={(v) => setWho({ ...who, id: String(v ?? "") })} />
          </div>
          <label className="text-xs">
            En calidad de
            <select className="input mt-1 !w-44" value={who.role} onChange={(e) => setWho({ ...who, role: e.target.value })}>
              {ROLES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </label>
          <Button size="sm" variant="primary" onClick={ask} disabled={!who.id}>Pedir</Button>
          <Button size="sm" variant="ghost" onClick={() => setAsking(false)}>Cancelar</Button>
        </div>
      )}
      {msg && <p className="text-bad">{msg}</p>}
    </section>
  );
}

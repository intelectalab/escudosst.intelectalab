"use client";

// Firma (M-FIR): sobre la lista de solicitudes, «Verificar una firma» por su código (SCR-FIR-04: huella del registro hoy
// vs. la firmada → «Íntegro» o «Alterado desde la firma», con firmante, fecha y evidencia) y las firmas pendientes con
// «Reenviar recordatorio» (SCR-FIR-03). VerifyResult también se usa en la pestaña «Firmas» de cada registro.

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { recordsHref } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Chip, EmptyState } from "@/components/ui/Display";
import { Card } from "./Summary";

export type Verification = {
  signature_id: string; code: string; result: "intact" | "altered" | "missing"; signer_name: string; signer_document_masked: string | null;
  role_in_document: string | null; method: string; signed_at: string; subject_type: string; subject_id: string; subject_tab: string;
  subject_module: string | null; subject_label: string | null; signed_hash: string; current_hash: string | null;
  evidence: { ip: string | null; user_agent: string | null; method: string; at: string; geo: string | null };
};
type PendingReq = {
  id: string; subject_type: string; subject_id: string; subject_module: string | null; subject_tab: string; subject_label: string; role_in_document: string;
  signer_name: string | null; requested_by_name: string | null; created_at: string; expires_at: string | null; reminder_count: number; last_reminder_at: string | null; can_remind: boolean;
};

const ROLE: Record<string, string> = {
  responsible: "Responsable", approver: "Aprueba", reviewer: "Revisa", worker: "Trabajador", attendee: "Asistente", witness: "Testigo", committee_member: "Miembro del comité", doctor: "Médico",
};
const METHOD: Record<string, string> = { pin: "Con contraseña", assisted_in_person: "Asistida en persona", stroke: "Trazo", otp: "Código", scanned: "Escaneada" };
const fmt = (v: string | null) => (v ? new Date(v).toLocaleString("es-PE", { timeZone: "America/Lima", dateStyle: "short", timeStyle: "short" }) : "—");
const errText = (e: unknown) => {
  const x = e as ApiError;
  return Object.values(x.errors ?? {})[0]?.[0] ?? x.detail ?? x.title;
};

/** Resultado de la verificación de una firma. */
export function VerifyResult({ v, showSubject = true }: { v: Verification; showSubject?: boolean }) {
  const ok = v.result === "intact";
  return (
    <div className={`space-y-2 rounded-xl border p-3 text-sm ${ok ? "border-ok/40" : "border-bad/40"}`}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Chip tone={ok ? "ok" : "bad"} dot>{ok ? "Íntegro" : v.result === "altered" ? "Alterado desde la firma" : "Registro no disponible"}</Chip>
        <span className="font-mono text-xs text-muted">Código {v.code}</span>
      </div>
      <p className="text-muted">
        {ok
          ? "El registro no cambió desde que se firmó: su huella actual coincide con la firmada."
          : v.result === "altered"
            ? "El registro cambió después de la firma: la huella actual no coincide con la firmada. La firma no respalda el contenido actual."
            : "El registro firmado ya no existe o fue archivado."}
      </p>
      <dl className="grid gap-x-4 gap-y-1 sm:grid-cols-[9rem_1fr]">
        <dt className="text-muted">Firmante</dt>
        <dd>
          <b>{v.signer_name}</b>
          {v.signer_document_masked ? ` · ${v.signer_document_masked}` : ""}
          {v.role_in_document ? ` · ${ROLE[v.role_in_document] ?? v.role_in_document}` : ""}
        </dd>
        <dt className="text-muted">Fecha</dt>
        <dd>{fmt(v.signed_at)}</dd>
        <dt className="text-muted">Método</dt>
        <dd>{METHOD[v.method] ?? v.method}</dd>
        {showSubject && (
          <>
            <dt className="text-muted">Documento</dt>
            <dd>
              {v.subject_tab}: {v.subject_label ?? "—"}
              {v.subject_module && v.result !== "missing" && (
                <Link href={recordsHref(v.subject_module, v.subject_type, { open: v.subject_id })} className="ml-2 font-semibold text-brand hover:underline">Abrir</Link>
              )}
            </dd>
          </>
        )}
        <dt className="text-muted">Evidencia</dt>
        <dd>
          {[v.evidence.ip && `IP ${v.evidence.ip}`, v.evidence.geo && `Ubicación ${v.evidence.geo}`].filter(Boolean).join(" · ") || "Sin IP registrada"}
          {v.evidence.user_agent && <span className="block break-all text-xs text-muted">{v.evidence.user_agent}</span>}
        </dd>
        <dt className="text-muted">Huella firmada</dt>
        <dd className="break-all font-mono text-[11px]">{v.signed_hash}</dd>
        <dt className="text-muted">Huella actual</dt>
        <dd className={`break-all font-mono text-[11px] ${ok ? "" : "text-bad"}`}>{v.current_hash ?? "—"}</dd>
      </dl>
    </div>
  );
}

export function FirTools({ readOnly }: { readOnly: boolean }) {
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <VerifyTool />
      <PendingRequests readOnly={readOnly} />
    </div>
  );
}

function VerifyTool() {
  const [code, setCode] = useState("");
  const [res, setRes] = useState<Verification | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const check = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setMsg(null);
    setRes(null);
    try {
      setRes((await api<{ data: Verification }>(`/fir/verify?code=${encodeURIComponent(code.trim())}`, { withCompany: true })).data);
    } catch (err) {
      setMsg(errText(err));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Card title="Verificar una firma">
      <form onSubmit={check} className="flex flex-wrap gap-2">
        <input className="input min-w-0 flex-1 font-mono uppercase" placeholder="Código, p. ej. A1B2-C3D4-E5F6" aria-label="Código de verificación" maxLength={40}
          value={code} onChange={(e) => setCode(e.target.value)} />
        <Button type="submit" variant="primary" icon="shield" loading={busy} disabled={code.trim().length < 12}>Verificar</Button>
      </form>
      <p className="mt-2 text-xs text-muted">El código aparece en la pestaña «Firmas» de cada registro firmado. Se vuelve a calcular la huella del registro y se compara con la firmada.</p>
      {msg && <p role="alert" className="mt-3 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{msg}</p>}
      {res && <div className="mt-3"><VerifyResult v={res} /></div>}
    </Card>
  );
}

function PendingRequests({ readOnly }: { readOnly: boolean }) {
  const [rows, setRows] = useState<PendingReq[] | null>(null);
  const [msg, setMsg] = useState<{ id: string; text: string; tone: "ok" | "bad" } | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ data: PendingReq[] }>("/fir/pending", { withCompany: true }).then((r) => setRows(r.data)).catch(() => setRows([]));
  }, []);
  useEffect(load, [load]);
  const remind = async (r: PendingReq) => {
    setBusy(r.id);
    setMsg(null);
    try {
      const out = await api<{ data: { reminder_count: number } }>(`/fir/requests/${r.id}/remind`, { method: "POST", withCompany: true });
      setMsg({ id: r.id, tone: "ok", text: `Recordatorio enviado (${out.data.reminder_count}.º).` });
      load();
    } catch (e) {
      setMsg({ id: r.id, tone: "bad", text: errText(e) });
    } finally {
      setBusy(null);
    }
  };
  return (
    <Card title={`Firmas pendientes${rows ? ` · ${rows.length}` : ""}`}>
      {!rows ? (
        <div className="h-24 animate-pulse rounded-lg bg-bg" />
      ) : rows.length === 0 ? (
        <EmptyState icon="check" text="No hay firmas pendientes." />
      ) : (
        <ul className="max-h-[26rem] divide-y divide-line overflow-y-auto text-sm">
          {rows.map((r) => (
            <li key={r.id} className="space-y-1 py-2">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <span className="min-w-0">
                  <Link href={recordsHref(r.subject_module ?? "FIR", r.subject_type, { open: r.subject_id })} className="font-semibold hover:text-brand">
                    {r.subject_tab}: {r.subject_label}
                  </Link>
                  <span className="block text-xs text-muted">
                    {r.signer_name ?? "—"} · {ROLE[r.role_in_document] ?? r.role_in_document} · Pedida el {fmt(r.created_at)}
                    {r.reminder_count > 0 ? ` · ${r.reminder_count} recordatorio(s), último ${fmt(r.last_reminder_at)}` : ""}
                  </span>
                </span>
                {!readOnly && r.can_remind && (
                  <Button size="sm" icon="bell" loading={busy === r.id} onClick={() => remind(r)}>Reenviar recordatorio</Button>
                )}
              </div>
              {msg?.id === r.id && <p role={msg.tone === "bad" ? "alert" : "status"} className={`text-xs ${msg.tone === "bad" ? "text-bad" : "text-ok"}`}>{msg.text}</p>}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

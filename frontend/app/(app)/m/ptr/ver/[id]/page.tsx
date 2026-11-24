"use client";

// QR del PETAR (M-PTR): al escanearlo con la sesión iniciada se ve en el celular si el permiso está vigente, suspendido o vencido,
// su horario y su cuadrilla, para verificarlo en el lugar del trabajo. SCR-PTR-05 / RN-PTR-06: quien participa en el permiso
// (ejecutor, vigía, solicitante) o la supervisión SST lo suspende aquí con un motivo; el servidor decide quién puede.

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { records, type RecordRow } from "@/lib/records";

const STATUS: Record<string, [string, string]> = { draft: ["Borrador", "bg-bg text-muted"], pending_approval: ["Por aprobar", "bg-amber-soft text-amber-ink"], approved: ["Aprobado, sin iniciar", "bg-amber-soft text-amber-ink"],
  active: ["Vigente", "bg-ok-soft text-ok"], suspended: ["Suspendido", "bg-bad-soft text-bad"], closed: ["Cerrado", "bg-bg text-muted"], expired: ["Vencido", "bg-bad-soft text-bad"], rejected: ["Rechazado", "bg-bad-soft text-bad"] };
const dt = (v: unknown) => (v ? new Date(String(v)).toLocaleString("es-PE", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" }) : "—");

export default function VerPermiso() {
  const { id } = useParams<{ id: string }>();
  const [p, setP] = useState<RecordRow | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [canSuspend, setCanSuspend] = useState(false);
  const [asking, setAsking] = useState(false);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ text: string; bad: boolean } | null>(null);
  useEffect(() => { records.get("PTR", "work_permits", id).then((r) => setP(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title)); }, [id]);
  useEffect(() => {
    api<{ data: { can_suspend: boolean } }>(`/ptr/work-permits/${id}/context`, { withCompany: true }).then((r) => setCanSuspend(r.data.can_suspend)).catch(() => setCanSuspend(false));
  }, [id]);
  const suspend = () => {
    setBusy(true);
    setMsg(null);
    api<{ data: RecordRow }>(`/ptr/work-permits/${id}/suspend`, { method: "POST", withCompany: true, body: { reason: reason.trim() } })
      .then((r) => { setP(r.data); setAsking(false); setReason(""); setCanSuspend(false); setMsg({ text: "Permiso suspendido: se avisó al solicitante y a SST.", bad: false }); })
      .catch((e: ApiError) => setMsg({ text: Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title, bad: true }))
      .finally(() => setBusy(false));
  };
  if (error) return <p className="p-4 text-sm text-bad">{error}</p>;
  if (!p) return <p className="p-4 text-sm text-muted">Cargando…</p>;
  const st = STATUS[String(p.status)] ?? [String(p.status), "bg-bg text-muted"];
  const crew = Array.isArray(p.crew) ? (p.crew as { name?: string; role?: string }[]) : [];
  return (
    <div className="mx-auto max-w-xl space-y-4 p-4">
      <div>
        <p className="text-xs text-muted">{String(p.code ?? "")} · {String(p.permit_type_id__label ?? "")}</p>
        <h1 className="text-lg font-bold">{String(p.work_description ?? "")}</h1>
        <span className={`mt-1 inline-block rounded-full px-3 py-1 text-sm font-bold ${st[1]}`}>{st[0]}</span>
      </div>
      <dl className="grid grid-cols-2 gap-2 text-sm">
        <div><dt className="text-xs text-muted">Desde</dt><dd>{dt(p.valid_from)}</dd></div>
        <div><dt className="text-xs text-muted">Hasta</dt><dd>{dt(p.valid_until)}</dd></div>
        <div className="col-span-2"><dt className="text-xs text-muted">Lugar</dt><dd>{[p.site_id__label, p.location_text].filter(Boolean).map(String).join(" · ") || "—"}</dd></div>
        {p.suspended_reason ? <div className="col-span-2"><dt className="text-xs text-muted">Motivo de suspensión</dt><dd className="text-bad">{String(p.suspended_reason)}</dd></div> : null}
      </dl>
      {p.status === "active" && canSuspend && !asking && (
        <Button variant="danger" full onClick={() => setAsking(true)}>Suspender</Button>
      )}
      {asking && (
        <div className="space-y-2 rounded-xl border border-bad p-3">
          <label className="block text-sm">
            <span className="mb-1 block text-xs font-semibold text-muted">Motivo de la suspensión (condición insegura)</span>
            <textarea autoFocus className="input min-h-[5rem] py-2" maxLength={1000} value={reason} onChange={(e) => setReason(e.target.value)} />
          </label>
          <div className="flex gap-2">
            <Button variant="danger" loading={busy} disabled={!reason.trim()} onClick={suspend}>Suspender el trabajo</Button>
            <Button onClick={() => { setAsking(false); setReason(""); }}>Cancelar</Button>
          </div>
        </div>
      )}
      {msg && <p role={msg.bad ? "alert" : "status"} className={`rounded-lg px-3 py-2 text-sm ${msg.bad ? "bg-bad-soft text-bad" : "bg-ok-soft text-ok"}`}>{msg.text}</p>}
      {crew.length > 0 && <div><p className="text-xs font-semibold uppercase text-muted">Cuadrilla</p><ul className="text-sm">{crew.map((c, k) => <li key={k}>{c.name ?? "—"}{c.role ? ` · ${c.role}` : ""}</li>)}</ul></div>}
      <Link href={`/imprimir/petar/${id}`} className="inline-block text-sm font-semibold text-brand-strong underline">Ver el permiso completo (PDF)</Link>
    </div>
  );
}

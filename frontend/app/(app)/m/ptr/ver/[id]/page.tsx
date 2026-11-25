"use client";

// QR del PETAR (M-PTR): al escanearlo con la sesión iniciada se ve en el celular si el permiso está vigente, suspendido o vencido,
// su horario y su cuadrilla, para verificarlo en el lugar del trabajo. SCR-PTR-05 / RN-PTR-06: quien participa en el permiso
// (ejecutor, vigía, solicitante) o la supervisión SST lo suspende aquí con un motivo; el servidor decide quién puede.
// docs/16 §2 (PETAR en campo sin señal): con el complemento OFF se muestra la copia guardada en el equipo (la traen los cambios
// del servidor, GET /sync/changes) y la suspensión queda en la cola con la versión del permiso; al volver la señal se envía y,
// si el permiso cambió en el servidor, queda un conflicto para revisar (no se pisa nada).

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { records, type RecordRow } from "@/lib/records";
import { canQueueUpdate, enqueue, localRecord, syncNow } from "@/lib/offline";

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
  const [offline, setOffline] = useState(false);
  useEffect(() => {
    records.get("PTR", "work_permits", id).then((r) => setP(r.data)).catch(async (e: ApiError) => {
      // Sin señal: la copia del permiso guardada en el equipo.
      const local = e.status === 0 ? await localRecord<RecordRow>("work_permits", id).catch(() => null) : null;
      if (local) { setP(local); setOffline(true); } else setError(e.detail ?? e.title);
    });
  }, [id]);
  useEffect(() => {
    api<{ data: { can_suspend: boolean } }>(`/ptr/work-permits/${id}/context`, { withCompany: true }).then((r) => setCanSuspend(r.data.can_suspend))
      // Sin señal no se puede consultar quién suspende: se deja intentarlo y el servidor lo decide al sincronizar.
      .catch((e: ApiError) => setCanSuspend(e.status === 0 && canQueueUpdate("work_permits")));
  }, [id]);
  const queueSuspend = async () => {
    await enqueue({ entity: "work_permits", op: "update", entity_id: id, base_version: typeof p?.version === "number" ? p.version : null, payload: { status: "suspended", suspended_reason: reason.trim() } });
    setP((x) => (x ? { ...x, status: "suspended", suspended_reason: reason.trim() } : x));
    setAsking(false); setReason(""); setCanSuspend(false);
    setMsg({ text: "Sin señal: la suspensión quedó guardada en el equipo y se enviará al volver la conexión. Detén el trabajo ahora.", bad: false });
    void syncNow();
  };
  const suspend = () => {
    setBusy(true);
    setMsg(null);
    api<{ data: RecordRow }>(`/ptr/work-permits/${id}/suspend`, { method: "POST", withCompany: true, body: { reason: reason.trim() } })
      .then((r) => { setP(r.data); setAsking(false); setReason(""); setCanSuspend(false); setMsg({ text: "Permiso suspendido: se avisó al solicitante y a SST.", bad: false }); })
      .catch(async (e: ApiError) => {
        if (e.status === 0 && canQueueUpdate("work_permits") && typeof p?.version === "number") {
          await queueSuspend().catch(() => setMsg({ text: "No se pudo guardar la suspensión en el equipo.", bad: true }));
          return;
        }
        setMsg({ text: Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title, bad: true });
      })
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
        {offline && <p className="mt-2 rounded-lg bg-amber-soft px-3 py-2 text-xs text-amber-ink">Sin señal: estás viendo la copia guardada en este equipo en la última sincronización.</p>}
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

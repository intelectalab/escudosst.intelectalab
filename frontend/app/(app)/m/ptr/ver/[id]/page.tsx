"use client";

// QR del PETAR (M-PTR): al escanearlo con la sesión iniciada se ve en el celular si el permiso está vigente, suspendido o vencido,
// su horario y su cuadrilla, para verificarlo en el lugar del trabajo.

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { type ApiError } from "@/lib/api";
import { records, type RecordRow } from "@/lib/records";

const STATUS: Record<string, [string, string]> = { draft: ["Borrador", "bg-bg text-muted"], pending_approval: ["Por aprobar", "bg-amber-soft text-amber-ink"], approved: ["Aprobado, sin iniciar", "bg-amber-soft text-amber-ink"],
  active: ["Vigente", "bg-ok-soft text-ok"], suspended: ["Suspendido", "bg-bad-soft text-bad"], closed: ["Cerrado", "bg-bg text-muted"], expired: ["Vencido", "bg-bad-soft text-bad"], rejected: ["Rechazado", "bg-bad-soft text-bad"] };
const dt = (v: unknown) => (v ? new Date(String(v)).toLocaleString("es-PE", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" }) : "—");

export default function VerPermiso() {
  const { id } = useParams<{ id: string }>();
  const [p, setP] = useState<RecordRow | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => { records.get("PTR", "work_permits", id).then((r) => setP(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title)); }, [id]);
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
      {crew.length > 0 && <div><p className="text-xs font-semibold uppercase text-muted">Cuadrilla</p><ul className="text-sm">{crew.map((c, k) => <li key={k}>{c.name ?? "—"}{c.role ? ` · ${c.role}` : ""}</li>)}</ul></div>}
      <Link href={`/imprimir/petar/${id}`} className="inline-block text-sm font-semibold text-brand-strong underline">Ver el permiso completo (PDF)</Link>
    </div>
  );
}

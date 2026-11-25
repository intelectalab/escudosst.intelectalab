"use client";

// RN-SUN-07 (M-SUN §4): el médico ocupacional aprueba si la constancia de aptitud de cada trabajador inspeccionado entra al
// expediente SUNAFIL. Solo se ve la aptitud y su vigencia, nunca informes clínicos ni diagnósticos. Sin el permiso
// sun.dossier_items.approve (o sin el módulo SUN) el panel no se muestra.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card, Chip } from "./Summary";

type Pending = { id: string; dossier_title: string; inspection_order_number: string | null; appearance_at: string | null; worker_name: string; fitness_label: string | null; valid_until: string | null };

const day = (v: string | null) => (v ? v.slice(0, 10).split("-").reverse().join("/") : "—");

export function SunMedicalApprovals() {
  const [rows, setRows] = useState<Pending[] | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const [rejecting, setRejecting] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const load = useCallback(() => {
    api<{ data: Pending[] }>("/sun/medical-approvals", { withCompany: true }).then((r) => setRows(r.data)).catch(() => setRows(null));
  }, []);
  useEffect(() => { load(); }, [load]);
  if (!rows || rows.length === 0) return null;
  const act = (id: string, path: "approve" | "reject", body?: Record<string, unknown>) =>
    api(`/sun/dossier-items/${id}/${path}`, { method: "POST", withCompany: true, body })
      .then(() => { setMsg(path === "approve" ? "Constancia incluida en el expediente." : "La constancia no se incluye; queda el motivo en el índice."); setRejecting(null); setReason(""); load(); })
      .catch((e: ApiError) => setMsg(Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title));
  return (
    <Card title="Constancias de aptitud por aprobar para expedientes SUNAFIL">
      <p className="mb-3 text-sm text-muted">El expediente para la inspección incluye la constancia de aptitud solo si la apruebas. Se muestra únicamente la aptitud y su vigencia.</p>
      <ul className="divide-y divide-line">
        {rows.map((r) => (
          <li key={r.id} className="space-y-2 py-2.5 text-sm">
            <div className="flex flex-wrap items-center gap-2">
              <span className="min-w-0 flex-1">
                <b className="block">{r.worker_name}</b>
                <span className="block text-xs text-muted">{r.dossier_title}{r.appearance_at ? ` · comparecencia ${day(r.appearance_at)}` : ""}</span>
              </span>
              <Chip tone="ok">{r.fitness_label ?? "Apto"}</Chip>
              <span className="text-xs text-muted">Vigente hasta {day(r.valid_until)}</span>
              <Button size="sm" variant="primary" onClick={() => void act(r.id, "approve")}>Aprobar</Button>
              <Button size="sm" onClick={() => { setRejecting(r.id); setReason(""); }}>No incluir</Button>
            </div>
            {rejecting === r.id && (
              <div className="flex flex-wrap gap-2">
                <input autoFocus className="input flex-1" placeholder="Motivo para no incluirla" aria-label="Motivo" maxLength={400} value={reason} onChange={(e) => setReason(e.target.value)} />
                <Button variant="danger" disabled={reason.trim().length < 5} onClick={() => void act(r.id, "reject", { reason: reason.trim() })}>No incluir</Button>
                <Button onClick={() => setRejecting(null)}>Cancelar</Button>
              </div>
            )}
          </li>
        ))}
      </ul>
      {msg && <p role="status" className="mt-2 text-sm font-semibold text-brand-strong">{msg}</p>}
    </Card>
  );
}

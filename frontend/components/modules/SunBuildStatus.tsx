"use client";

// Generación del expediente SUNAFIL en segundo plano (docs/08 §12): POST /sun/dossiers/{id}/build responde 202 y el estado
// (en cola → generando → listo / con brechas / falló) se consulta con GET /sun/dossiers/{id}/status hasta que termina.

import { useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";

export type DossierItemRow = { code: string; title: string; module: string; table: string | null; id: string | null; status: string; gap: string | null; worker_id?: string | null };
export type DossierStatus = {
  id: string;
  status: "queued" | "building" | "ready" | "ready_with_gaps" | "failed" | "archived";
  pending: boolean;
  error_message: string | null;
  built_at: string | null;
  items: DossierItemRow[];
  total: number;
  gaps: number;
  workers: { id: string; name: string }[];
};

const POLL_MS = 2000;

export const dossierStatus = (id: string) => api<{ data: DossierStatus }>(`/sun/dossiers/${id}/status`, { withCompany: true }).then((r) => r.data);

/** Pide armar el expediente (202) y espera a que la cola termine; onTick recibe cada estado consultado. */
export async function buildDossier(id: string, body: Record<string, unknown>, onTick?: (s: DossierStatus) => void): Promise<DossierStatus> {
  await api(`/sun/dossiers/${id}/build`, { method: "POST", withCompany: true, body });
  return waitDossier(id, onTick);
}

export async function waitDossier(id: string, onTick?: (s: DossierStatus) => void): Promise<DossierStatus> {
  for (;;) {
    const s = await dossierStatus(id);
    onTick?.(s);
    if (!s.pending) return s;
    await new Promise((ok) => setTimeout(ok, POLL_MS));
  }
}

/** Resultado del asistente: «Generando…» hasta que el expediente queda listo; luego el resumen. */
export function DossierBuilding({ id, children }: { id: string; children: (s: DossierStatus) => React.ReactNode }) {
  const [s, setS] = useState<DossierStatus | null>(null);
  const [error, setError] = useState<string | null>(null);
  const alive = useRef(true);
  useEffect(() => {
    alive.current = true;
    waitDossier(id, (x) => alive.current && setS(x)).catch((e: { detail?: string; title?: string }) => alive.current && setError(e.detail ?? e.title ?? "No se pudo consultar el expediente."));
    return () => { alive.current = false; };
  }, [id]);
  if (error) return <p role="alert" className="text-sm text-bad">{error}</p>;
  if (!s || s.pending) {
    return (
      <div role="status" className="flex items-center gap-3 rounded-xl border border-line p-4 text-sm">
        <span className="h-4 w-4 animate-spin rounded-full border-2 border-brand border-t-transparent" aria-hidden />
        <span><b>Generando…</b> El expediente se arma en segundo plano con los datos de la empresa; esta pantalla se actualiza sola.</span>
      </div>
    );
  }
  if (s.status === "failed") return <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{s.error_message ?? "No se pudo armar el expediente."}</p>;
  return <>{children(s)}</>;
}

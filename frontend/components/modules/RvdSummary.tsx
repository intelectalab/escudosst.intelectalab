"use client";

// Indicadores de la revisión por la dirección (M-RVD): revisiones del año, última cerrada, próxima según la periodicidad y decisiones.

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Kpi } from "./Summary";

type Data = { this_year: number; last: { id: string; title: string; review_on: string | null } | null; months: number; next_due: string | null;
  outputs: { open: number; done: number; with_action: number; total: number } };
const fmt = (d: string | null) => (d ? d.slice(0, 10).split("-").reverse().join("/") : "—");

export function RvdSummary() {
  const [d, setD] = useState<Data | null>(null);
  useEffect(() => { api<{ data: Data }>("/rvd/summary", { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null)); }, []);
  if (!d) return null;
  const late = d.next_due && d.next_due < new Date().toISOString().slice(0, 10);
  return (
    <div className="space-y-2">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Kpi label="Revisiones este año" value={String(d.this_year)} />
        <Kpi label="Última revisión cerrada" value={fmt(d.last?.review_on ?? null)} sub={d.last?.title} />
        <Kpi label="Próxima revisión" value={fmt(d.next_due)} sub={`Cada ${d.months} meses`} color={late ? "text-bad" : undefined} />
        <Kpi label="Decisiones abiertas" value={String(d.outputs.open)} sub={`${d.outputs.done} cumplidas · ${d.outputs.with_action} con acción`} />
      </div>
      <p className="text-xs text-muted">Cada decisión con responsable crea su acción correctiva al guardarse. La revisión se cierra con la firma de gerencia y queda bloqueada.</p>
    </div>
  );
}

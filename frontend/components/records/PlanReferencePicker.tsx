"use client";

// Componente del Plan Anual de tipo programa (M-PLA §5, RN-PLA-07): se elige el PASST o el programa de capacitación del mismo
// año; la lista solo trae versiones vigentes (no cerradas ni reemplazadas).

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { FormField } from "@/components/ui/Form";

type Opt = { id: string; label: string };
const PROGRAM_TYPES: [string, string][] = [["annual_programs", "Programa Anual SST (PASST)"], ["training_programs", "Programa de capacitación"]];

export function PlanReferencePicker({ planId, type, id, disabled, error, onChange }: { planId: string; type: string; id: string; disabled: boolean; error?: string; onChange: (type: string, id: string) => void }) {
  const [rows, setRows] = useState<Opt[]>([]);
  useEffect(() => {
    if (!type || !planId) { setRows([]); return; }
    api<{ data: Opt[] }>(`/pla/plans/${planId}/reference-options?type=${encodeURIComponent(type)}`, { withCompany: true }).then((r) => setRows(r.data)).catch(() => setRows([]));
  }, [planId, type]);
  return (
    <FormField label="Programa vinculado" error={error} hint="Del mismo año del plan; solo aparecen las versiones vigentes.">
      <div className="grid gap-2 sm:grid-cols-[16rem_1fr]">
        <select className="input" aria-label="Tipo de programa" disabled={disabled} value={type} onChange={(e) => onChange(e.target.value, "")}>
          <option value="">Elige el tipo…</option>
          {PROGRAM_TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select className="input" aria-label="Programa" disabled={disabled || !type} value={id} onChange={(e) => onChange(type, e.target.value)}>
          <option value="">{type ? (rows.length ? "Elige el programa…" : "No hay programas vigentes de ese año") : "—"}</option>
          {rows.map((r) => <option key={r.id} value={r.id}>{r.label}</option>)}
        </select>
      </div>
    </FormField>
  );
}

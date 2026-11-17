"use client";

// Evidencia de un requisito legal con un registro del sistema (M-MRL SCR-MRL-04, RN-MRL-08): se elige el tipo (capacitación,
// inspección, simulacro, sesión del comité…) y luego el registro de la empresa.

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { FormField } from "@/components/ui/Form";

type Opt = { id: string; label: string };

export function EvidencePicker({ type, id, disabled, error, onChange }: { type: string; id: string; disabled: boolean; error?: string; onChange: (type: string, id: string) => void }) {
  const [types, setTypes] = useState<{ type: string; label: string }[]>([]);
  const [rows, setRows] = useState<Opt[]>([]);
  useEffect(() => { api<{ data: { type: string; label: string }[] }>("/mrl/evidence-options", { withCompany: true }).then((r) => setTypes(r.data)).catch(() => setTypes([])); }, []);
  useEffect(() => {
    if (!type) { setRows([]); return; }
    api<{ data: Opt[] }>(`/mrl/evidence-options?type=${encodeURIComponent(type)}`, { withCompany: true }).then((r) => setRows(r.data)).catch(() => setRows([]));
  }, [type]);
  return (
    <FormField label="Registro del sistema como evidencia" error={error} hint="Opcional: una capacitación, inspección, simulacro u otro registro que respalde el cumplimiento.">
      <div className="grid gap-2 sm:grid-cols-[14rem_1fr]">
        <select className="input" aria-label="Tipo de registro" disabled={disabled} value={type} onChange={(e) => onChange(e.target.value, "")}>
          <option value="">Sin registro</option>
          {types.map((t) => <option key={t.type} value={t.type}>{t.label}</option>)}
        </select>
        <select className="input" aria-label="Registro" disabled={disabled || !type} value={id} onChange={(e) => onChange(type, e.target.value)}>
          <option value="">{type ? (rows.length ? "Elige el registro…" : "No hay registros de este tipo") : "—"}</option>
          {rows.map((r) => <option key={r.id} value={r.id}>{r.label}</option>)}
        </select>
      </div>
    </FormField>
  );
}

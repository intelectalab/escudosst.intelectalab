"use client";

// Selector de una referencia (sede, trabajador, puesto…) con las opciones del motor común de registros
// (GET /records/{módulo}/{tabla}/options/{campo}): respeta los permisos y el alcance del usuario. Busca al escribir.

import { useEffect, useState } from "react";
import { records } from "@/lib/records";

export type RefOption = { id: string; label: string };

export function RefSelect({
  module,
  table,
  field,
  value,
  onChange,
  placeholder = "Elige una opción",
  label,
  exclude = [],
}: {
  module: string;
  table: string;
  field: string;
  value: string;
  onChange: (id: string, label: string) => void;
  placeholder?: string;
  label: string;
  exclude?: string[];
}) {
  const [q, setQ] = useState("");
  const [opts, setOpts] = useState<RefOption[] | null>(null);

  useEffect(() => {
    let cancel = false;
    const t = setTimeout(() => {
      records
        .options(module, table, field, { q: q.trim() || undefined })
        .then((r) => !cancel && setOpts(r.data))
        .catch(() => !cancel && setOpts([]));
    }, q ? 250 : 0);
    return () => {
      cancel = true;
      clearTimeout(t);
    };
  }, [module, table, field, q]);

  const list = (opts ?? []).filter((o) => !exclude.includes(o.id) || o.id === value);
  const many = (opts?.length ?? 0) >= 15 || q !== "";
  return (
    <div className="space-y-1.5">
      {many && (
        <input className="input" type="search" placeholder="Buscar…" value={q} onChange={(e) => setQ(e.target.value)} aria-label={`Buscar ${label.toLowerCase()}`} />
      )}
      <select
        className="input"
        aria-label={label}
        value={value}
        onChange={(e) => onChange(e.target.value, e.target.selectedOptions[0]?.text ?? "")}
      >
        <option value="">{opts === null ? "Cargando…" : list.length === 0 ? "No hay opciones" : placeholder}</option>
        {list.map((o) => (
          <option key={o.id} value={o.id}>
            {o.label}
          </option>
        ))}
      </select>
    </div>
  );
}

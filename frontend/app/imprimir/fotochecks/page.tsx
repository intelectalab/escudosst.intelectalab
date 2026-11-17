"use client";

// Impresión por lote de fotochecks en tamaño tarjeta (M-FOT SCR-FOT-04): los activos del tipo elegido, uno por hoja de 54 × 86 mm.

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { CredentialCard } from "@/components/records/PrintSheets";

export default function ImprimirLote() {
  const [ids, setIds] = useState<string[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    const q = new URLSearchParams(window.location.search);
    const p = new URLSearchParams();
    const pairs: [string, string | null][] = [["credential_type_id", q.get("type")], ["site_id", q.get("site")], ["area_id", q.get("area")]];
    for (const [k, v] of pairs) if (v) p.set(k, v);
    api<{ data: string[] }>(`/fot/credentials/batch?${p.toString()}`, { withCompany: true }).then((r) => setIds(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);
  if (error) return <p className="p-8">{error}</p>;
  if (!ids) return <p className="p-8">Preparando los fotochecks…</p>;
  return (
    <div className="min-h-screen bg-gray-100 p-4 print:bg-white print:p-0">
      <style>{`@page { size: 54mm 86mm; margin: 0 } @media print { .no-print { display: none } }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-md items-center justify-between gap-3 rounded-xl bg-white p-3 text-sm shadow">
        <span>{ids.length} fotocheck(s) activo(s). Imprime en tarjetas de 54 × 86 mm.</span>
        <button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 font-semibold text-white">Imprimir</button>
      </div>
      {ids.length === 0 ? <p className="text-center">No hay fotochecks activos con ese filtro.</p> : ids.map((id) => <CredentialCard key={id} id={id} />)}
    </div>
  );
}

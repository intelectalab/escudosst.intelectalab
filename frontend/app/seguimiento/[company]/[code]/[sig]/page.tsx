"use client";

// Seguimiento público de un reporte de acto o condición (M-ACT RN-ACT-09): con el código y su enlace se ve solo el estado del reporte,
// nunca su contenido.

import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";

type St = { code: string; status: string; label: string; reported_at: string | null; triaged_at: string | null; closed_at: string | null };
const d = (v: string | null) => (v ? new Date(v).toLocaleDateString("es-PE") : null);

export default function Seguimiento() {
  const { company, code, sig } = useParams<{ company: string; code: string; sig: string }>();
  const [s, setS] = useState<St | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => { api<{ data: St }>(`/public/report-status/${company}/${code}/${sig}`).then((r) => setS(r.data)).catch((e: ApiError) => setError(e.detail ?? "El código de seguimiento no es válido.")); }, [company, code, sig]);
  return (
    <main className="min-h-screen bg-[#EEF4FB] px-4 py-6 text-[#0b2440]">
      <div className="mx-auto max-w-md space-y-4">
        <p className="text-center text-sm font-bold uppercase tracking-wider text-[#1b6fb7]">Seguimiento de tu reporte</p>
        <div className="space-y-3 rounded-2xl bg-white p-6 text-center shadow">
          {error ? <p>{error}</p> : !s ? <p>Cargando…</p> : (
            <>
              <p className="text-sm text-[#5b6b7c]">Reporte {s.code}</p>
              <p className="text-2xl font-bold">{s.label}</p>
              <ul className="text-left text-sm">
                {d(s.reported_at) && <li>Recibido el {d(s.reported_at)}</li>}
                {d(s.triaged_at) && <li>Revisado el {d(s.triaged_at)}</li>}
                {d(s.closed_at) && <li>Cerrado el {d(s.closed_at)}</li>}
              </ul>
            </>
          )}
        </div>
        <p className="text-center text-xs text-[#5b6b7c]">Con EscudoSST · Solo se muestra el estado del reporte, no su contenido.</p>
      </div>
    </main>
  );
}

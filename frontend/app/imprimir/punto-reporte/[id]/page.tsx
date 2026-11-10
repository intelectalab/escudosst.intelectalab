"use client";

// Cartel imprimible del punto de reporte (A5 vertical): QR grande y explicación simple para pegarlo en el lugar.

import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { QrCode } from "@/components/mfa/QrCode";
import { api, ApiError } from "@/lib/api";
import { records, type RecordRow } from "@/lib/records";

export default function PuntoReporteCartel() {
  const { id } = useParams<{ id: string }>();
  const [link, setLink] = useState<string | null>(null);
  const [row, setRow] = useState<RecordRow | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: { url: string } }>(`/act/report-points/${id}/link`, { withCompany: true }).then((r) => setLink(r.data.url)).catch((e: ApiError) => setError(e.detail ?? e.title));
    records.get("ACT", "report_points", id).then((r) => setRow(r.data)).catch(() => undefined);
  }, [id]);
  if (error) return <p className="p-8">{error}</p>;
  if (!link) return <p className="p-8">Preparando el cartel…</p>;
  return (
    <div className="min-h-screen bg-gray-100 p-4 print:bg-white print:p-0">
      <style>{`@page { size: A5 portrait; margin: 10mm } @media print { .no-print { display: none } }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-md items-center justify-between rounded-xl bg-white p-3 shadow">
        <span className="text-sm text-gray-600">Imprime en A5 o A4 y pégalo a la altura de la vista.</span>
        <button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">Imprimir</button>
      </div>
      <div className="mx-auto flex max-w-md flex-col items-center gap-4 rounded-2xl border-4 border-[#F4B90F] bg-white p-8 text-center text-[#0b2440]">
        <p className="text-sm font-bold uppercase tracking-[0.25em] text-[#1f7fd1]">Seguridad primero</p>
        <h1 className="text-3xl font-extrabold leading-tight">¿Viste algo inseguro?</h1>
        <p className="text-lg">Escanea con la cámara de tu celular y repórtalo. <b>No necesitas instalar nada.</b></p>
        <div className="rounded-xl bg-white p-2 ring-2 ring-[#0d3b6e]"><QrCode value={link} size={220} /></div>
        <p className="text-base font-semibold">{String(row?.name ?? "")}{row?.site_id__label ? ` · ${String(row.site_id__label)}` : ""}</p>
        <p className="text-sm text-gray-600">Puedes reportar sin tu nombre. Tu reporte llega al área de Seguridad y Salud en el Trabajo.</p>
      </div>
    </div>
  );
}

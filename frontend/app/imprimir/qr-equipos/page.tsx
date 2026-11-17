"use client";

// Hoja de QR de los equipos de emergencia (M-EQE flujo 1 y SCR-EQE-02): una etiqueta por equipo con su código, tipo, ubicación y el QR
// que abre su inspección. El QR solo lleva el enlace; para ver o inspeccionar el equipo hay que iniciar sesión (RN-EQE-05).

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { QrCode } from "@/components/mfa/QrCode";

type Eq = { id: string; code: string; equipment_type: string; location_text: string | null; status: string; site_id: string | null };
const TYPE: Record<string, string> = { fire_extinguisher: "Extintor", emergency_light: "Luz de emergencia", alarm: "Alarma", hose_cabinet: "Gabinete contra incendio", eyewash: "Lavaojos",
  shower: "Ducha de emergencia", stretcher: "Camilla", aed: "Desfibrilador", signage: "Señalización", other: "Otro" };

export default function QrEquipos() {
  const [rows, setRows] = useState<Eq[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const origin = typeof window === "undefined" ? "" : window.location.origin;

  useEffect(() => {
    (async () => {
      const all: Eq[] = [];
      for (let page = 1; page <= 50; page++) {
        const r = await api<{ data: Eq[] }>(`/records/EQE/emergency_equipment?per_page=100&page=${page}`, { withCompany: true });
        all.push(...(r.data ?? []));
        if ((r.data ?? []).length < 100) break;
      }
      setRows(all.filter((e) => e.status !== "retired"));
    })().catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);

  if (!rows) return <p className="p-8">{error ?? "Preparando las etiquetas…"}</p>;
  return (
    <div className="min-h-screen bg-gray-100 p-4 text-gray-900 print:bg-white print:p-0">
      <style>{"@page { size: A4; margin: 10mm } @media print { .no-print { display: none } } .lbl { break-inside: avoid }"}</style>
      <div className="no-print mx-auto mb-4 flex max-w-4xl flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3 shadow">
        <span className="text-sm text-gray-600">{rows.length} equipo(s). Imprime en papel adhesivo y pega cada etiqueta en su equipo.</span>
        <button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">Imprimir</button>
      </div>
      {rows.length === 0 ? <p className="mx-auto max-w-4xl">No hay equipos registrados.</p> : (
        <div className="mx-auto grid max-w-4xl grid-cols-3 gap-3 bg-white p-4 print:max-w-none print:p-0">
          {rows.map((e) => (
            <div key={e.id} className="lbl flex flex-col items-center rounded border border-gray-400 p-2 text-center text-xs">
              <b className="text-sm">{e.code}</b>
              <span>{TYPE[e.equipment_type] ?? e.equipment_type}</span>
              <QrCode value={`${origin}/m/eqe/revisar/${e.id}`} size={120} alt={`QR del equipo ${e.code}`} />
              <span className="text-gray-600">{e.location_text ?? ""}</span>
              <span className="text-[10px] text-gray-500">Escanea para inspeccionar (requiere iniciar sesión)</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

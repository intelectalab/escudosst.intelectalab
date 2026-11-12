"use client";

// Afiche del mapa de riesgos para exhibir (M-MAP SCR-MAP-05): A3 o A2 horizontal, plano con sus pictogramas
// numerados, leyenda por tipo (NTP 399.010-1), datos de aprobación, firmas y QR a la versión digital.

import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { QrCode } from "@/components/mfa/QrCode";
import { MARKER_TYPES, Pictogram } from "@/components/records/RiskMapEditor";
import { MAP_STATUS, mapApi, planUrl, type Legend } from "@/lib/map";

const fmt = (d: string | null) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}` : "—");

export default function AficheMapa() {
  const { id } = useParams<{ id: string }>();
  const [data, setData] = useState<Legend | null>(null);
  const [img, setImg] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [size, setSize] = useState<"A3" | "A2">("A3");

  useEffect(() => {
    let url: string | null = null;
    mapApi
      .legend(id)
      .then(async (r) => {
        setData(r.data);
        url = await planUrl(r.data.map.plan_file_id);
        setImg(url);
      })
      .catch((e: { detail?: string; title?: string; message?: string }) => setError(e.detail ?? e.title ?? e.message ?? "No se pudo abrir el mapa."));
    return () => {
      if (url) URL.revokeObjectURL(url);
    };
  }, [id]);

  if (error) return <p className="p-8">{error}</p>;
  if (!data) return <p className="p-8">Preparando el afiche…</p>;
  const { map, markers } = data;
  const types = Object.keys(MARKER_TYPES).map((t) => [t, markers.filter((m) => m.marker_type === t)] as const).filter(([, l]) => l.length > 0);
  const link = typeof window === "undefined" ? "" : `${window.location.origin}/m/map?mapa=${map.id}`;
  const official = map.status === "current" || map.status === "approved";

  return (
    <div className="min-h-screen bg-gray-100 p-4 text-[#0b2440] print:bg-white print:p-0">
      <style>{`@page { size: ${size} landscape; margin: 10mm } @media print { .no-print { display: none } }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-6xl flex-wrap items-center justify-between gap-2 rounded-xl bg-white p-3 shadow">
        <span className="text-sm text-gray-600">Elige el tamaño, imprime o guarda en PDF y exhíbelo en la sede.</span>
        <div className="flex items-center gap-2">
          {(["A3", "A2"] as const).map((s) => (
            <button key={s} onClick={() => setSize(s)} aria-pressed={size === s} className={`rounded-lg border px-3 py-2 text-sm font-semibold ${size === s ? "border-[#0d3b6e] bg-[#e8eef6]" : "border-gray-300"}`}>{s}</button>
          ))}
          <button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">Imprimir</button>
        </div>
      </div>

      <div className="mx-auto grid max-w-6xl gap-4 bg-white p-6 print:max-w-none print:p-0" style={{ gridTemplateColumns: "minmax(0,1fr) 17rem" }}>
        <header className="col-span-2 flex flex-wrap items-end justify-between gap-3 border-b-4 border-[#F4B90F] pb-2">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.25em] text-[#1b6fb7]">Seguridad y Salud en el Trabajo</p>
            <h1 className="text-3xl font-extrabold">MAPA DE RIESGOS</h1>
            <p className="text-lg font-semibold">{map.title} · {map.place}</p>
          </div>
          <p className="text-right text-sm">
            Versión {map.version_number} · {MAP_STATUS[map.status] ?? map.status}<br />
            {map.effective_on ? `Vigente desde ${fmt(map.effective_on)}` : "Sin fecha de vigencia"}
          </p>
        </header>
        {!official && <p className="col-span-2 rounded bg-[#FDF0C9] px-3 py-1 text-sm font-semibold">Borrador: este mapa aún no está aprobado.</p>}

        <div className="relative overflow-hidden rounded border-2 border-[#0b2440]">
          {img ? (
            <>
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={img} alt={`Plano de ${map.title}`} className="block w-full" />
              {markers.map((m, i) => (
                <span key={m.id} className="absolute -translate-x-1/2 -translate-y-1/2" style={{ left: `${m.x_pct}%`, top: `${m.y_pct}%` }}>
                  <Pictogram type={m.marker_type} size={30} />
                  <span className="absolute -right-2 -top-2 grid h-4 min-w-4 place-items-center rounded-full bg-[#0b2440] px-1 text-[9px] font-bold text-white">{i + 1}</span>
                </span>
              ))}
            </>
          ) : (
            <p className="p-8 text-center text-gray-500">Cargando el plano…</p>
          )}
        </div>

        <aside className="space-y-3 text-[12px]">
          <section>
            <h2 className="mb-1 border-b border-[#0b2440] text-sm font-bold uppercase">Leyenda</h2>
            <ul className="space-y-1">
              {types.map(([t, list]) => (
                <li key={t} className="flex items-center gap-2"><Pictogram type={t} size={22} /> <span className="flex-1">{MARKER_TYPES[t]}</span> <b>{list.length}</b></li>
              ))}
            </ul>
          </section>
          <section>
            <h2 className="mb-1 border-b border-[#0b2440] text-sm font-bold uppercase">Ubicaciones</h2>
            <ol className="space-y-0.5">
              {markers.map((m, i) => (
                <li key={m.id}>
                  <b>{i + 1}.</b> {m.label || MARKER_TYPES[m.marker_type]}
                  {m.iperc && <span className="text-gray-600"> · {m.iperc.level ?? ""} (IPERC {m.iperc.matrix_code})</span>}
                </li>
              ))}
            </ol>
          </section>
          <section className="flex items-center gap-2">
            {link && <QrCode value={link} size={84} />}
            <span className="text-[10px] text-gray-600">Escanea para ver la versión digital con el detalle de cada riesgo.</span>
          </section>
          <section className="space-y-6 pt-4">
            <div className="border-t border-[#0b2440] pt-1 text-center">Aprobó: {map.approved_by_name ?? "________________"}<br /><span className="text-gray-500">{fmt(map.approved_at)}</span></div>
            <div className="border-t border-[#0b2440] pt-1 text-center">Comité / Supervisor SST</div>
          </section>
        </aside>
      </div>
    </div>
  );
}

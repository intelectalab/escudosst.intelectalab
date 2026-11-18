"use client";

// Mapa de riesgos de mi sede (M-MAP SCR-MAP-04): el plano vigente con sus pictogramas; al tocar uno se ve el riesgo,
// su nivel y los controles que hay que aplicar. Se puede acercar el plano con los dedos (zoom del navegador).

import { useEffect, useState } from "react";
import { Alert, Empty, Loading, Title } from "@/components/worker/WorkerUi";
import { MARKER_TYPES as TYPE, Pictogram } from "@/components/records/RiskMapEditor";
import { planUrl, type Legend, type LegendMarker } from "@/lib/map";
import { errorText, workerApi } from "@/lib/workerApp";

function MapCard({ map }: { map: Legend }) {
  const [src, setSrc] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);
  const [open, setOpen] = useState<LegendMarker | null>(null);
  useEffect(() => {
    let url: string | null = null;
    if (!map.map.plan_file_id) return;
    planUrl(map.map.plan_file_id).then((u) => { url = u; setSrc(u); }).catch(() => setFailed(true));
    return () => { if (url) URL.revokeObjectURL(url); };
  }, [map.map.plan_file_id]);

  return (
    <section className="space-y-3">
      <div>
        <b className="block leading-tight">{map.map.title}</b>
        <span className="text-sm text-muted">{map.map.place} · versión {map.map.version_number}</span>
      </div>
      {failed && <Alert>No pudimos abrir el plano. Inténtalo de nuevo más tarde.</Alert>}
      {src && (
        <div className="relative overflow-hidden rounded-2xl border border-line bg-surface">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={src} alt={`Plano de ${map.map.title}`} className="block h-auto w-full" />
          {map.markers.map((m) => (
            <button key={m.id} type="button" onClick={() => setOpen(m)} aria-label={m.label ?? TYPE[m.marker_type] ?? "Marcador"}
              className={`absolute -translate-x-1/2 -translate-y-1/2 rounded-full p-0.5 ${open?.id === m.id ? "ring-4 ring-brand" : ""}`}
              style={{ left: `${m.x_pct}%`, top: `${m.y_pct}%` }}>
              <Pictogram type={m.marker_type} size={26} />
            </button>
          ))}
        </div>
      )}
      {open && (
        <div className="space-y-1.5 rounded-2xl border border-line bg-surface p-4" role="status">
          <div className="flex items-start justify-between gap-2">
            <b className="leading-tight">{open.label ?? TYPE[open.marker_type] ?? "Marcador"}</b>
            <button type="button" onClick={() => setOpen(null)} className="text-sm font-semibold text-brand">Cerrar</button>
          </div>
          {open.iperc ? (
            <>
              <p className="text-sm"><b>Peligro:</b> {open.iperc.hazard}</p>
              <p className="text-sm"><b>Riesgo:</b> {open.iperc.risk}</p>
              {open.iperc.level && <p className="text-sm"><b>Nivel:</b> {open.iperc.level}</p>}
              {open.iperc.controls.length > 0 && (
                <div className="text-sm">
                  <b>Cómo cuidarte:</b>
                  <ul className="ml-4 list-disc">{open.iperc.controls.map((c, i) => <li key={i}>{c.description}</li>)}</ul>
                </div>
              )}
            </>
          ) : open.equipment ? (
            <p className="text-sm">{TYPE[open.marker_type] ?? "Equipo"} {open.equipment.code}{open.equipment.location ? ` · ${open.equipment.location}` : ""}</p>
          ) : (
            <p className="text-sm">{TYPE[open.marker_type] ?? ""}{open.notes ? ` · ${open.notes}` : ""}</p>
          )}
        </div>
      )}
    </section>
  );
}

export default function MyMapPage() {
  const [maps, setMaps] = useState<Legend[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    workerApi<Legend[]>("/maps").then(setMaps).catch((e) => setError(errorText(e)));
  }, []);

  return (
    <>
      <Title sub="Toca un pictograma para ver el riesgo y cómo cuidarte.">Mapa de riesgos</Title>
      {error && <Alert>{error}</Alert>}
      {!maps && !error && <Loading />}
      {maps && maps.length === 0 && <Empty text="Tu sede aún no tiene un mapa de riesgos vigente. Consulta al área de SST." />}
      {maps?.map((m) => <MapCard key={m.map.id} map={m} />)}
    </>
  );
}

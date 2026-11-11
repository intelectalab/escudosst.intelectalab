"use client";

// Mapa de riesgos (M-MAP, prototipo m-modulos.js R.MAP): selector de sede y plano, el plano con sus pictogramas
// (RiskMapEditor) y la «Leyenda» al lado. Tocar un pictograma muestra su ubicación, tipo e IPERC vinculado.
// «Imprimir afiche» abre la versión A3/A2 con la leyenda para exhibir. La lista de mapas queda debajo (ModuleRecords).

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";
import { ApiError } from "@/lib/api";
import { MAP_STATUS, mapApi, type Legend, type LegendMarker } from "@/lib/map";
import { records, recordsHref, type RecordRow } from "@/lib/records";
import { MARKER_TYPES, Pictogram, RiskMapEditor } from "@/components/records/RiskMapEditor";
import { Chip, EmptyState } from "@/components/ui/Display";

const fmt = (d: string | null) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}` : "—");
const LEVEL_CLS: Record<string, string> = { green: "bg-ok-soft text-ok", amber: "bg-amber-soft text-amber-ink", red: "bg-bad-soft text-bad" };

export function MapModule({ readOnly }: { readOnly: boolean }) {
  const [maps, setMaps] = useState<RecordRow[] | null>(null);
  const [canUpdate, setCanUpdate] = useState(false);
  const [site, setSite] = useState("");
  const [mapId, setMapId] = useState("");
  const [legend, setLegend] = useState<Legend | null>(null);
  const [sel, setSel] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    records.schema("MAP").then((s) => setCanUpdate(!!s.resources.find((r) => r.table === "risk_maps")?.can.update)).catch(() => undefined);
    records
      .list("MAP", "risk_maps", { page: 1 })
      .then((r) => {
        const rows = r.data.filter((m) => m.status !== "obsolete");
        setMaps(rows);
        const q = new URLSearchParams(window.location.search).get("mapa");
        const first = rows.find((m) => m.id === q) ?? rows.find((m) => m.status === "current") ?? rows[0];
        if (first) {
          setMapId(first.id);
          setSite(String(first.site_id ?? ""));
        }
      })
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);

  const loadLegend = useCallback(() => {
    if (!mapId) return;
    mapApi.legend(mapId).then((r) => setLegend(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [mapId]);
  useEffect(loadLegend, [loadLegend, tick]);

  const sites = useMemo(() => {
    const out = new Map<string, string>();
    maps?.forEach((m) => out.set(String(m.site_id), String(m.site_id__label ?? "Sede")));
    return [...out.entries()];
  }, [maps]);
  const inSite = (maps ?? []).filter((m) => !site || String(m.site_id) === site);
  const row = maps?.find((m) => m.id === mapId) ?? null;
  const marker = legend?.markers.find((m) => m.id === sel) ?? null;

  if (error) return <p className="rounded-xl border border-line bg-surface p-6 text-muted">{error}</p>;
  if (!maps) return <p className="text-muted">Cargando mapas…</p>;
  if (maps.length === 0) return <EmptyState icon="map" text="Aún no hay mapas de riesgos. Crea el primero en la lista de abajo con el plano de la sede." />;

  return (
    <section className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
      <div className="min-w-0 space-y-3 rounded-xl border border-line bg-surface p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 className="font-display text-base font-semibold">{legend ? `${legend.map.title}` : String(row?.title ?? "")}</h2>
            {legend && (
              <p className="text-xs text-muted">
                {legend.map.place} · v{legend.map.version_number} · {MAP_STATUS[legend.map.status] ?? legend.map.status}
                {legend.map.effective_on && ` desde ${fmt(legend.map.effective_on)}`}
              </p>
            )}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <label className="sr-only" htmlFor="map-site">Sede</label>
            <select id="map-site" className="filter-control h-9 !w-auto" value={site} onChange={(e) => {
              setSite(e.target.value);
              const first = maps.find((m) => !e.target.value || String(m.site_id) === e.target.value);
              if (first) { setMapId(first.id); setSel(null); }
            }}>
              <option value="">Todas las sedes</option>
              {sites.map(([id, name]) => <option key={id} value={id}>{name}</option>)}
            </select>
            <label className="sr-only" htmlFor="map-plan">Plano</label>
            <select id="map-plan" className="filter-control h-9 !w-auto" value={mapId} onChange={(e) => { setMapId(e.target.value); setSel(null); }}>
              {inSite.map((m) => (
                <option key={m.id} value={m.id}>
                  {String(m.title)}{m.floor_label ? ` · ${String(m.floor_label)}` : ""} · {MAP_STATUS[String(m.status)] ?? String(m.status)}
                </option>
              ))}
            </select>
            <a href={`/imprimir/mapa/${mapId}`} target="_blank" rel="noreferrer" className="inline-flex h-9 items-center rounded-lg bg-brand px-3 text-sm font-semibold text-white hover:bg-brand-strong">Imprimir afiche</a>
            <Link href={recordsHref("MAP", "risk_maps", { open: mapId })} className="inline-flex h-9 items-center rounded-lg border border-line px-3 text-sm font-semibold hover:border-brand">Ficha y aprobación</Link>
          </div>
        </div>
        {row && <RiskMapEditor key={row.id} row={row} canEdit={canUpdate && !readOnly} onSelect={setSel} focusId={sel} onChange={() => setTick((t) => t + 1)} />}
      </div>

      <aside className="space-y-4">
        {marker && <MarkerCard m={marker} onClose={() => setSel(null)} />}
        <section aria-label="Leyenda" className="rounded-xl border border-line bg-surface p-4">
          <div className="mb-2 flex items-center justify-between">
            <h2 className="font-display text-base font-semibold">Leyenda</h2>
            <span className="text-xs text-muted">{legend?.markers.length ?? 0} pictograma(s)</span>
          </div>
          {!legend ? (
            <p className="text-sm text-muted">Cargando…</p>
          ) : legend.markers.length === 0 ? (
            <p className="text-sm text-muted">El plano aún no tiene pictogramas.</p>
          ) : (
            <ul className="divide-y divide-line">
              {legend.markers.map((m) => (
                <li key={m.id}>
                  <button type="button" onClick={() => setSel(m.id)} aria-pressed={sel === m.id}
                    className={`flex w-full items-center gap-3 py-2 text-left text-sm hover:text-brand ${sel === m.id ? "font-semibold text-brand" : ""}`}>
                    <Pictogram type={m.marker_type} size={22} />
                    <span className="min-w-0 flex-1">
                      <b className="block truncate">{m.label || MARKER_TYPES[m.marker_type]}</b>
                      <span className="text-xs text-muted">
                        {MARKER_TYPES[m.marker_type]}{m.iperc ? ` · IPERC ${m.iperc.matrix_code} fila ${m.iperc.row_number}` : m.equipment ? ` · ${m.equipment.code}` : ""}
                      </span>
                    </span>
                    {m.iperc?.stale && <span className="h-2 w-2 rounded-full bg-amber-ink" title="La fila IPERC ya no está vigente" aria-label="Revisar vínculo" />}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </section>
      </aside>
    </section>
  );
}

function MarkerCard({ m, onClose }: { m: LegendMarker; onClose: () => void }) {
  return (
    <section aria-label="Pictograma seleccionado" className="space-y-3 rounded-xl border border-brand/40 bg-surface p-4 text-sm">
      <div className="flex items-start gap-3">
        <Pictogram type={m.marker_type} size={36} />
        <div className="min-w-0 flex-1">
          <p className="font-semibold">{m.label || MARKER_TYPES[m.marker_type]}</p>
          <p className="text-xs text-muted">{MARKER_TYPES[m.marker_type]}</p>
        </div>
        <button onClick={onClose} className="text-muted hover:text-fg" aria-label="Cerrar">×</button>
      </div>
      <dl className="space-y-2">
        <div><dt className="text-xs font-semibold text-muted">Ubicación</dt><dd>{m.location}{m.equipment?.location ? ` · ${m.equipment.location}` : ""}</dd></div>
        <div><dt className="text-xs font-semibold text-muted">Tipo</dt><dd>{MARKER_TYPES[m.marker_type] ?? m.marker_type}{m.pictogram_code ? ` · ${m.pictogram_code}` : ""}</dd></div>
        <div>
          <dt className="text-xs font-semibold text-muted">IPERC vinculado</dt>
          <dd>
            {m.iperc ? (
              <Link href={`/m/ipc?t=matrices&matriz=${m.iperc.matrix_id}`} className="block hover:text-brand">
                <b>{m.iperc.matrix_code} v{m.iperc.version_number} · fila {m.iperc.row_number}</b>
                <span className="block">{m.iperc.hazard} — {m.iperc.risk}</span>
                {m.iperc.level && <span className={`mt-1 inline-block rounded-md px-2 py-0.5 text-xs font-semibold ${LEVEL_CLS[m.iperc.color ?? ""] ?? "bg-bg text-muted"}`}>{m.iperc.level}</span>}
                {m.iperc.stale && <span className="mt-1 block text-xs text-amber-ink">Esta fila ya no está en la versión vigente: revisa el vínculo.</span>}
              </Link>
            ) : (
              <span className="text-muted">—</span>
            )}
          </dd>
        </div>
        {m.equipment && <div><dt className="text-xs font-semibold text-muted">Equipo de emergencia</dt><dd>{m.equipment.code}</dd></div>}
        {m.notes && <div><dt className="text-xs font-semibold text-muted">Notas</dt><dd>{m.notes}</dd></div>}
      </dl>
      {!m.iperc && m.marker_type === "hazard" && <Chip tone="amber">Sin fila IPERC vinculada</Chip>}
    </section>
  );
}

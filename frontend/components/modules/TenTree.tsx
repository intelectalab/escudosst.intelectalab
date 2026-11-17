"use client";

// Estructura de la empresa (M-TEN SCR-TEN-02/03): árbol de sedes y áreas que se reordena arrastrando (el servidor valida
// misma sede, ciclos y profundidad máxima), mapa de la sede con sus coordenadas y la ficha de cada puesto.

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";
import { ApiError } from "@/lib/api";
import { records, recordsHref, type RecordRow } from "@/lib/records";

async function all(code: string, table: string): Promise<RecordRow[]> {
  const out: RecordRow[] = [];
  for (let page = 1; page <= 40; page++) {
    const r = await records.list(code, table, { page });
    out.push(...r.data);
    if (page >= r.meta.pages) break;
  }
  return out;
}

const s = (v: unknown) => (v == null ? "" : String(v));

export function TenTree({ readOnly }: { readOnly: boolean }) {
  const [sites, setSites] = useState<RecordRow[] | null>(null);
  const [areas, setAreas] = useState<RecordRow[]>([]);
  const [positions, setPositions] = useState<RecordRow[]>([]);
  const [drag, setDrag] = useState<string | null>(null);
  const [over, setOver] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [mapSite, setMapSite] = useState<RecordRow | null>(null);
  const [open, setOpen] = useState<Record<string, boolean>>({});

  const load = useCallback(() => {
    Promise.all([all("TEN", "sites"), all("TEN", "areas"), all("TEN", "positions")])
      .then(([si, ar, po]) => { setSites(si.filter((x) => !x.deleted_at)); setAreas(ar); setPositions(po); })
      .catch(() => setSites([]));
  }, []);
  useEffect(() => { load(); }, [load]);

  const children = useMemo(() => {
    const m: Record<string, RecordRow[]> = {};
    for (const a of areas) (m[s(a.parent_area_id) || `site:${s(a.site_id)}`] ??= []).push(a);
    Object.values(m).forEach((l) => l.sort((x, y) => s(x.name).localeCompare(s(y.name))));
    return m;
  }, [areas]);
  const byArea = useMemo(() => {
    const m: Record<string, RecordRow[]> = {};
    for (const p of positions) (m[s(p.area_id)] ??= []).push(p);
    return m;
  }, [positions]);

  if (!sites || sites.length === 0) return null;

  const move = async (areaId: string, target: { site: string; parent: string | null }) => {
    setOver(null);
    setDrag(null);
    const a = areas.find((x) => x.id === areaId);
    if (!a || (s(a.parent_area_id) === s(target.parent) && s(a.site_id) === target.site)) return;
    if (target.parent === areaId) return;
    try {
      await records.update("TEN", "areas", areaId, { site_id: target.site, parent_area_id: target.parent });
      setMsg({ ok: true, text: `«${s(a.name)}» se movió.` });
      load();
    } catch (e) {
      setMsg({ ok: false, text: e instanceof ApiError ? Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title : "No se pudo mover el área." });
    }
  };
  const dropProps = (key: string, target: { site: string; parent: string | null }) => readOnly ? {} : {
    onDragOver: (e: React.DragEvent) => { if (drag) { e.preventDefault(); setOver(key); } },
    onDragLeave: () => setOver((o) => (o === key ? null : o)),
    onDrop: (e: React.DragEvent) => { e.preventDefault(); if (drag) void move(drag, target); },
  };

  const renderArea = (a: RecordRow, depth: number): React.ReactNode => {
    const kids = children[a.id] ?? [];
    const pos = byArea[a.id] ?? [];
    const isOpen = open[a.id] ?? depth < 1;
    return (
      <li key={a.id}>
        <div
          draggable={!readOnly}
          onDragStart={(e) => { setDrag(a.id); e.dataTransfer.effectAllowed = "move"; }}
          onDragEnd={() => { setDrag(null); setOver(null); }}
          {...dropProps(a.id, { site: s(a.site_id), parent: a.id })}
          className={`flex items-center gap-2 rounded-lg border px-2 py-1.5 ${over === a.id ? "border-brand bg-brand-soft" : "border-line bg-surface"} ${readOnly ? "" : "cursor-grab"} ${drag === a.id ? "opacity-50" : ""}`}
        >
          <button type="button" className="w-4 text-xs text-muted" aria-label={isOpen ? "Cerrar" : "Abrir"} onClick={() => setOpen({ ...open, [a.id]: !isOpen })}>{kids.length || pos.length ? (isOpen ? "▾" : "▸") : "·"}</button>
          <span className="flex-1">{s(a.name)} {a.code ? <span className="text-xs text-muted">({s(a.code)})</span> : null}</span>
          <span className="text-xs text-muted">{pos.length} puesto(s)</span>
          <Link href={recordsHref("TEN", "areas", { open: a.id })} className="text-xs text-brand underline">Ver</Link>
        </div>
        {isOpen && (
          <ul className="ml-5 mt-1 space-y-1 border-l border-line pl-3">
            {pos.map((p) => (
              <li key={p.id} className="flex items-center gap-2 text-xs">
                <span className="flex-1">Puesto: <b>{s(p.name)}</b>{p.is_critical ? " · crítico" : ""}</span>
                <a href={`/imprimir/puesto/${p.id}`} target="_blank" rel="noreferrer" className="text-brand underline">Ficha del puesto</a>
              </li>
            ))}
            {kids.map((k) => renderArea(k, depth + 1))}
          </ul>
        )}
      </li>
    );
  };

  const site = mapSite;
  const lat = Number(site?.latitude);
  const lng = Number(site?.longitude);
  const hasMap = site && Number.isFinite(lat) && Number.isFinite(lng) && (lat !== 0 || lng !== 0);

  return (
    <section className="space-y-3 rounded-xl border border-line bg-surface p-4 text-sm">
      <div>
        <p className="font-semibold">Sedes y áreas</p>
        <p className="text-xs text-muted">{readOnly ? "Estructura de la empresa." : "Arrastra un área sobre otra para que dependa de ella, o sobre el nombre de una sede para dejarla en el primer nivel."}</p>
      </div>
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
      <div className="grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <ul className="space-y-3">
          {sites.map((si) => (
            <li key={si.id}>
              <div {...dropProps(`site:${si.id}`, { site: si.id, parent: null })}
                className={`flex items-center gap-2 rounded-lg px-2 py-1.5 font-semibold ${over === `site:${si.id}` ? "bg-brand-soft" : "bg-bg"}`}>
                <span className="flex-1">{s(si.name)}{si.is_main ? " · principal" : ""}{si.status !== "active" ? ` · ${s(si.status)}` : ""}</span>
                <button type="button" className="text-xs font-normal text-brand underline" onClick={() => records.get("TEN", "sites", si.id).then((r) => setMapSite(r.data)).catch(() => setMapSite(si))}>Mapa</button>
              </div>
              <ul className="ml-2 mt-1 space-y-1">{(children[`site:${si.id}`] ?? []).map((a) => renderArea(a, 0))}</ul>
            </li>
          ))}
        </ul>
        <div className="rounded-lg border border-line p-2">
          {!site ? (
            <p className="p-3 text-xs text-muted">Elige «Mapa» en una sede para ver su ubicación.</p>
          ) : hasMap ? (
            <>
              <p className="mb-1 text-xs font-semibold">{s(site.name)}{site.address ? ` · ${s(site.address)}` : ""}</p>
              <iframe title={`Mapa de ${s(site.name)}`} className="h-64 w-full rounded" loading="lazy"
                src={`https://www.openstreetmap.org/export/embed.html?bbox=${lng - 0.01}%2C${lat - 0.006}%2C${lng + 0.01}%2C${lat + 0.006}&layer=mapnik&marker=${lat}%2C${lng}`} />
              <a className="text-xs text-brand underline" href={`https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=17/${lat}/${lng}`} target="_blank" rel="noreferrer">Abrir el mapa grande</a>
            </>
          ) : (
            <p className="p-3 text-xs text-muted">«{s(site.name)}» no tiene latitud y longitud registradas. <Link className="text-brand underline" href={recordsHref("TEN", "sites", { open: site.id })}>Complétalas en la sede</Link>.</p>
          )}
        </div>
      </div>
    </section>
  );
}

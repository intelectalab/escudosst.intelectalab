"use client";

// Mapa de riesgos: el plano de la sede con sus pictogramas (formas y colores de la NTP 399.010-1).
// En borrador o revisión: toca el plano para poner un pictograma; tócalo para verlo o quitarlo.
// La leyenda cuenta cada tipo y, al tocarla, resalta solo ese tipo. «Imprimir» saca el mapa para publicarlo.
// M-MAP: el peligro se liga a una fila del IPERC vigente de la sede (RN-MAP-01) y el equipo de emergencia a su registro, que muestra
// su estado (vencido en rojo, RN-MAP-08); los pictogramas se arrastran y el plano tiene zoom (SCR-MAP-02); se listan los riesgos
// significativos sin pictograma con su justificación (RN-MAP-02) y se avisa si existe una versión vigente más reciente (RN-MAP-06).

import { useCallback, useEffect, useRef, useState } from "react";
import { API_URL, ApiError, api } from "@/lib/api";
import { records, type RecordRow } from "@/lib/records";
import { session } from "@/lib/session";

type Marker = { id: string; marker_type: string; label: string | null; x_pct: number; y_pct: number; notes: string | null; iperc_row_id: string | null; emergency_equipment_id: string | null };
type CtxRow = { id: string; row_number: number; task: string; hazard: string | null; risk: string | null; is_significant: boolean; matrix: string; marked: boolean; justification: string | null };
type CtxEq = { id: string; code: string; equipment_type: string; location_text: string | null; status: string; expired: boolean };
type Ctx = { rows: CtxRow[]; unmarked: number; equipment: CtxEq[]; newer: { id: string; version_number: number; title: string } | null };
const EQUIP_TYPES = new Set(["fire", "emergency_equipment", "first_aid"]);

export const MARKER_TYPES: Record<string, string> = {
  hazard: "Peligro / riesgo",
  warning: "Advertencia",
  prohibition: "Prohibición",
  mandatory: "Obligación (EPP)",
  fire: "Equipo contra incendio",
  emergency_equipment: "Equipo de emergencia",
  first_aid: "Primeros auxilios",
  evacuation: "Ruta de evacuación",
  assembly_point: "Punto de reunión",
};

/** Pictograma por tipo: triángulo amarillo (advertencia), círculo rojo con barra (prohibición), círculo azul (obligación),
 *  cuadrado rojo (incendio) y cuadrado verde (emergencia, primeros auxilios, evacuación, reunión). */
export function Pictogram({ type, size = 28 }: { type: string; size?: number }) {
  const s = size;
  const common = { width: s, height: s, viewBox: "0 0 40 40", "aria-hidden": true } as const;
  switch (type) {
    case "hazard":
    case "warning":
      return (
        <svg {...common}><path d="M20 3 38 36H2Z" fill="#FFD100" stroke="#111" strokeWidth="3" strokeLinejoin="round" />
          <text x="20" y="31" textAnchor="middle" fontSize="18" fontWeight="900" fill="#111">!</text></svg>
      );
    case "prohibition":
      return (
        <svg {...common}><circle cx="20" cy="20" r="16" fill="#fff" stroke="#D52B1E" strokeWidth="5" /><path d="M9 9 31 31" stroke="#D52B1E" strokeWidth="5" /></svg>
      );
    case "mandatory":
      return (
        <svg {...common}><circle cx="20" cy="20" r="18" fill="#005BBB" /><path d="M20 9a4 4 0 1 1 0 .1M14 18h12l-2 15h-8Z" fill="#fff" /></svg>
      );
    case "fire":
      return (
        <svg {...common}><rect x="2" y="2" width="36" height="36" rx="3" fill="#D52B1E" /><path d="M20 7c4 6 9 9 9 16a9 9 0 0 1-18 0c0-4 2-6 4-8 0 3 1 5 3 5 0-5-1-8 2-13Z" fill="#fff" /></svg>
      );
    case "first_aid":
      return (
        <svg {...common}><rect x="2" y="2" width="36" height="36" rx="3" fill="#00843D" /><path d="M16 9h8v7h7v8h-7v7h-8v-7H9v-8h7Z" fill="#fff" /></svg>
      );
    case "evacuation":
      return (
        <svg {...common}><rect x="2" y="2" width="36" height="36" rx="3" fill="#00843D" /><path d="M8 20h17l-6-6 3-3 11 11-11 11-3-3 6-6H8Z" fill="#fff" /></svg>
      );
    case "assembly_point":
      return (
        <svg {...common}><rect x="2" y="2" width="36" height="36" rx="3" fill="#00843D" /><circle cx="20" cy="13" r="3" fill="#fff" /><circle cx="12" cy="22" r="3" fill="#fff" /><circle cx="28" cy="22" r="3" fill="#fff" /><circle cx="20" cy="29" r="3" fill="#fff" /><path d="M6 6l6 6M34 6l-6 6M6 34l6-6M34 34l-6-6" stroke="#fff" strokeWidth="2.5" /></svg>
      );
    default:
      return (
        <svg {...common}><rect x="2" y="2" width="36" height="36" rx="3" fill="#00843D" /><text x="20" y="28" textAnchor="middle" fontSize="20" fontWeight="900" fill="#fff">+</text></svg>
      );
  }
}

/** onSelect / focusId / onChange: la vista principal del módulo (MapModule) sigue el marcador tocado y refresca su leyenda. */
export function RiskMapEditor({ row, canEdit, onSelect, focusId, onChange }: { row: RecordRow; canEdit: boolean; onSelect?: (id: string) => void; focusId?: string | null; onChange?: () => void }) {
  const [img, setImg] = useState<string | null>(null);
  const [markers, setMarkers] = useState<Marker[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [draft, setDraft] = useState<{ x: number; y: number; type: string; label: string; row: string; eq: string } | null>(null);
  const [ctx, setCtx] = useState<Ctx | null>(null);
  const [zoom, setZoom] = useState(1);
  const [drag, setDrag] = useState<{ id: string; moved: boolean } | null>(null);
  const [just, setJust] = useState<Record<string, string>>({});
  const [selected, setSelected] = useState<Marker | null>(null);
  const [only, setOnly] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  const editable = canEdit && (row.status === "draft" || row.status === "in_review");

  const load = useCallback(() => {
    records
      .list("MAP", "risk_map_markers", { filters: { risk_map_id: String(row.id) }, page: 1 })
      .then((r) => setMarkers(r.data.map((m) => ({ ...(m as unknown as Marker), x_pct: Number(m.x_pct), y_pct: Number(m.y_pct) }))))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
    api<{ data: Ctx }>(`/map/maps/${row.id}/context`, { withCompany: true }).then((r) => setCtx(r.data)).catch(() => setCtx(null));
  }, [row.id]);

  useEffect(() => {
    load();
    if (!row.plan_file_id) return;
    let url: string | null = null;
    fetch(`${API_URL}/api/v1/files/${row.plan_file_id}`, { headers: { Authorization: `Bearer ${session.getToken()}`, "X-Company-Id": session.getCompanyId() ?? "" } })
      .then((r) => (r.ok ? r.blob() : Promise.reject()))
      .then((b) => {
        url = URL.createObjectURL(b);
        setImg(url);
      })
      .catch(() => setError("No pudimos abrir el plano. Revisa que el archivo sea una imagen (PNG o JPG)."));
    return () => {
      if (url) URL.revokeObjectURL(url);
    };
  }, [row.id, row.plan_file_id, load]);

  function place(e: React.MouseEvent) {
    if (!editable || !box.current) return;
    const r = box.current.getBoundingClientRect();
    const x = Math.round(((e.clientX - r.left) / r.width) * 1000) / 10;
    const y = Math.round(((e.clientY - r.top) / r.height) * 1000) / 10;
    setSelected(null);
    setDraft({ x, y, type: draft?.type ?? "hazard", label: "", row: "", eq: "" });
  }

  function pos(e: React.PointerEvent) {
    const r = box.current!.getBoundingClientRect();
    return { x: Math.min(100, Math.max(0, Math.round(((e.clientX - r.left) / r.width) * 1000) / 10)), y: Math.min(100, Math.max(0, Math.round(((e.clientY - r.top) / r.height) * 1000) / 10)) };
  }
  function onMove(e: React.PointerEvent) {
    if (!drag || !box.current) return;
    const p = pos(e);
    setMarkers((ms) => ms.map((m) => (m.id === drag.id ? { ...m, x_pct: p.x, y_pct: p.y } : m)));
    if (!drag.moved) setDrag({ ...drag, moved: true });
  }
  async function onUp() {
    if (!drag) return;
    const m = markers.find((x) => x.id === drag.id);
    setDrag(null);
    if (!drag.moved || !m) return;
    try { await records.update("MAP", "risk_map_markers", m.id, { x_pct: m.x_pct, y_pct: m.y_pct }); } catch (e) { setError(e instanceof ApiError ? (e.detail ?? e.title) : "No se pudo mover."); load(); }
  }
  async function saveJust(rowId: string) {
    try {
      await api(`/map/maps/${row.id}/justify`, { method: "POST", withCompany: true, body: { row_id: rowId, reason: just[rowId] ?? "" } });
      load();
    } catch (e) { setError(e instanceof ApiError ? (e.detail ?? e.title) : "No se pudo guardar."); }
  }

  async function saveDraft() {
    if (!draft) return;
    try {
      await records.create("MAP", "risk_map_markers", { risk_map_id: row.id, marker_type: draft.type, x_pct: draft.x, y_pct: draft.y, label: draft.label || null,
        iperc_row_id: draft.type === "hazard" ? draft.row || null : null, emergency_equipment_id: EQUIP_TYPES.has(draft.type) ? draft.eq || null : null });
      setDraft(null);
      load();
      onChange?.();
    } catch (e) {
      setError(e instanceof ApiError ? (e.detail ?? e.title) : "No se pudo guardar.");
    }
  }

  async function remove(m: Marker) {
    try {
      await records.remove("MAP", "risk_map_markers", m.id);
      setSelected(null);
      setConfirmDelete(false);
      load();
      onChange?.();
    } catch (e) {
      setError(e instanceof ApiError ? (e.detail ?? e.title) : "No se pudo quitar.");
    }
  }

  if (!row.status) return null; // el registro aún se está cargando

  const counts = Object.keys(MARKER_TYPES).map((t) => [t, markers.filter((m) => m.marker_type === t).length] as const).filter(([, n]) => n > 0);
  const eqOf = (m: Marker) => (m.emergency_equipment_id ? ctx?.equipment.find((x) => x.id === m.emergency_equipment_id) : undefined);
  const expiredCount = markers.filter((m) => eqOf(m)?.expired).length;
  const rowOf = (m: Marker) => (m.iperc_row_id ? ctx?.rows.find((x) => x.id === m.iperc_row_id) : undefined);
  const pending = (ctx?.rows ?? []).filter((r) => r.is_significant && !r.marked);

  return (
    <section className="risk-map-print space-y-3 rounded-xl border border-line px-4 py-3 text-sm">
      <style>{`@media print { body * { visibility: hidden } .risk-map-print, .risk-map-print * { visibility: visible } .risk-map-print { position: absolute; inset: 0; border: 0 } .no-print { display: none !important } }`}</style>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-semibold">
          Plano con pictogramas · {markers.length} ubicados
          <span className="block text-xs font-normal text-muted">
            {editable ? "Toca el plano donde va un pictograma. Toca un pictograma para verlo o quitarlo." : "Mapa aprobado o vigente: para cambiarlo crea una nueva versión."}
          </span>
        </p>
        <button onClick={() => window.print()} className="no-print rounded-lg border border-line px-3 py-1.5 font-semibold text-brand hover:border-brand">Imprimir mapa</button>
      </div>
      {error && <p className="no-print text-bad">{error}</p>}
      {ctx?.newer && (
        <p className="rounded-lg bg-amber-soft px-3 py-2 text-amber-ink">Este mapa no es la versión vigente. Hay una más reciente: <a className="font-semibold underline" href={`/m/map?mapa=${ctx.newer.id}`}>versión {ctx.newer.version_number}</a>.</p>
      )}
      {img && (
        <div className="no-print flex items-center gap-2 text-xs">
          <span className="text-muted">Zoom</span>
          <button type="button" className="rounded border border-line px-2 font-bold" aria-label="Alejar" onClick={() => setZoom((z) => Math.max(1, z - 0.5))}>−</button>
          <span>{Math.round(zoom * 100)} %</span>
          <button type="button" className="rounded border border-line px-2 font-bold" aria-label="Acercar" onClick={() => setZoom((z) => Math.min(3, z + 0.5))}>+</button>
          {editable && <span className="text-muted">· Arrastra un pictograma para moverlo.</span>}
        </div>
      )}
      {!row.plan_file_id && <p className="text-muted">Sube primero el plano (imagen PNG o JPG) en «Plano» y guarda.</p>}

      {img && (
        <div className="overflow-auto rounded-lg border border-line">
        <div ref={box} onClick={(e) => { if (!drag) place(e); }} onPointerMove={onMove} onPointerUp={onUp} onPointerLeave={onUp}
          style={{ width: `${zoom * 100}%` }} className={`relative select-none overflow-hidden ${editable ? "cursor-crosshair" : ""}`}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={img} alt={`Plano de ${String(row.title ?? "la sede")}`} className="block w-full" draggable={false} />
          {markers.map((m) => (
            <button
              key={m.id}
              type="button"
              onPointerDown={(e) => { if (editable) { e.stopPropagation(); setDrag({ id: m.id, moved: false }); } }}
              onClick={(e) => {
                e.stopPropagation();
                if (drag?.moved) return;
                setDraft(null);
                setConfirmDelete(false);
                setSelected(m);
                onSelect?.(m.id);
              }}
              title={`${MARKER_TYPES[m.marker_type]}${m.label ? `: ${m.label}` : ""}${eqOf(m)?.expired ? " (vencido o fuera de servicio)" : ""}`}
              aria-label={`${MARKER_TYPES[m.marker_type]}${m.label ? `: ${m.label}` : ""}${eqOf(m)?.expired ? " (vencido o fuera de servicio)" : ""}`}
              className={`absolute -translate-x-1/2 -translate-y-1/2 touch-none rounded drop-shadow transition ${only && only !== m.marker_type ? "opacity-20" : ""} ${selected?.id === m.id || focusId === m.id ? "ring-2 ring-brand" : eqOf(m)?.expired ? "ring-4 ring-bad" : ""}`}
              style={{ left: `${m.x_pct}%`, top: `${m.y_pct}%` }}
            >
              <Pictogram type={m.marker_type} />
            </button>
          ))}
          {draft && (
            <span className="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 animate-pulse" style={{ left: `${draft.x}%`, top: `${draft.y}%` }}>
              <Pictogram type={draft.type} />
            </span>
          )}
        </div>
        </div>
      )}

      {draft && (
        <div className="no-print flex flex-wrap items-end gap-2 rounded-lg bg-bg p-3">
          <label className="flex flex-col gap-1 text-xs font-semibold text-muted">
            Tipo
            <select className="filter-control" value={draft.type} onChange={(e) => setDraft({ ...draft, type: e.target.value })}>
              {Object.entries(MARKER_TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </label>
          <label className="flex min-w-[12rem] flex-1 flex-col gap-1 text-xs font-semibold text-muted">
            Qué indica (opcional)
            <input className="filter-control" value={draft.label} onChange={(e) => setDraft({ ...draft, label: e.target.value })} placeholder="Ej.: Riesgo eléctrico – tablero" />
          </label>
          {draft.type === "hazard" && (
            <label className="flex min-w-[16rem] flex-1 flex-col gap-1 text-xs font-semibold text-muted">
              Fila del IPERC vigente *
              <select className="filter-control" value={draft.row} onChange={(e) => setDraft({ ...draft, row: e.target.value })}>
                <option value="">Elige el peligro del IPERC…</option>
                {(ctx?.rows ?? []).map((r) => <option key={r.id} value={r.id}>{r.matrix} · {r.row_number}. {r.hazard ?? r.task}{r.is_significant ? " (significativo)" : ""}</option>)}
              </select>
            </label>
          )}
          {EQUIP_TYPES.has(draft.type) && (ctx?.equipment.length ?? 0) > 0 && (
            <label className="flex min-w-[12rem] flex-col gap-1 text-xs font-semibold text-muted">
              Equipo
              <select className="filter-control" value={draft.eq} onChange={(e) => setDraft({ ...draft, eq: e.target.value })}>
                <option value="">Sin vincular</option>
                {ctx!.equipment.map((x) => <option key={x.id} value={x.id}>{x.code}{x.location_text ? ` · ${x.location_text}` : ""}{x.expired ? " (vencido)" : ""}</option>)}
              </select>
            </label>
          )}
          <button onClick={saveDraft} disabled={draft.type === "hazard" && !draft.row} className="rounded-lg bg-brand px-4 py-2 font-semibold text-white disabled:opacity-50">Poner pictograma</button>
          <button onClick={() => setDraft(null)} className="rounded-lg border border-line px-4 py-2 font-semibold">Cancelar</button>
        </div>
      )}

      {selected && (
        <div className="no-print flex flex-wrap items-center gap-3 rounded-lg bg-bg p-3">
          <Pictogram type={selected.marker_type} size={36} />
          <div className="flex-1">
            <p className="font-semibold">{MARKER_TYPES[selected.marker_type]}</p>
            <p className="text-muted">{selected.label || "Sin descripción"}</p>
            {rowOf(selected) && <p className="text-xs">IPERC {rowOf(selected)!.matrix} · fila {rowOf(selected)!.row_number}: {rowOf(selected)!.hazard} → {rowOf(selected)!.risk}</p>}
            {eqOf(selected) && <p className={`text-xs ${eqOf(selected)!.expired ? "font-semibold text-bad" : "text-ok"}`}>Equipo {eqOf(selected)!.code}: {eqOf(selected)!.expired ? "vencido o fuera de servicio" : "vigente"}</p>}
          </div>
          {editable && !confirmDelete && <button onClick={() => setConfirmDelete(true)} className="rounded-lg border border-line px-3 py-1.5 font-semibold text-bad">Quitar</button>}
          {editable && confirmDelete && (
            <span className="flex items-center gap-2">
              ¿Quitar este pictograma?
              <button onClick={() => remove(selected)} className="rounded-lg bg-bad px-3 py-1.5 font-semibold text-white">Sí, quitar</button>
              <button onClick={() => setConfirmDelete(false)} className="rounded-lg border border-line px-3 py-1.5 font-semibold">No</button>
            </span>
          )}
          <button onClick={() => setSelected(null)} className="text-muted hover:text-fg" aria-label="Cerrar">×</button>
        </div>
      )}

      {counts.length > 0 && (
        <div className="flex flex-wrap gap-2" aria-label="Leyenda">
          {counts.map(([t, n]) => (
            <button key={t} onClick={() => setOnly(only === t ? null : t)} aria-pressed={only === t}
              className={`flex items-center gap-2 rounded-lg border px-2 py-1 ${only === t ? "border-brand ring-2 ring-brand/30" : "border-line"}`}>
              <Pictogram type={t} size={20} /> {MARKER_TYPES[t]} <b>{n}</b>
            </button>
          ))}
          {only && <button onClick={() => setOnly(null)} className="no-print text-xs font-semibold text-brand hover:underline">Ver todos</button>}
          {expiredCount > 0 && <span className="flex items-center gap-2 rounded-lg border border-bad px-2 py-1 text-bad"><span className="inline-block h-3 w-3 rounded-full ring-4 ring-bad" /> Equipos vencidos o fuera de servicio <b>{expiredCount}</b></span>}
        </div>
      )}

      {editable && pending.length > 0 && (
        <div className="no-print rounded-lg border border-amber-ink/30 bg-amber-soft/40 p-3">
          <p className="font-semibold text-amber-ink">Riesgos significativos del IPERC sin pictograma ({pending.filter((r) => !r.justification).length} sin justificar)</p>
          <p className="mb-2 text-xs text-muted">Para aprobar el mapa, ubica cada uno o escribe por qué no va en el plano.</p>
          <ul className="space-y-1">
            {pending.map((r) => (
              <li key={r.id} className="flex flex-wrap items-center gap-2">
                <span className="min-w-0 flex-1">{r.matrix} · {r.row_number}. {r.hazard} → {r.risk}</span>
                <input className="filter-control !w-64" placeholder="Justificación" value={just[r.id] ?? r.justification ?? ""} onChange={(e) => setJust({ ...just, [r.id]: e.target.value })} />
                <button type="button" className="rounded-lg border border-line px-2 py-1 text-xs font-semibold" onClick={() => saveJust(r.id)}>Guardar</button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}

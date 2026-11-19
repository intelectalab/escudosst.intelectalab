"use client";

// Tableros de indicadores (M-DSH): cada empresa elige qué indicadores ver en cada tablero y para qué rol. El selector filtra la grilla
// de indicadores del inicio; el editor crea, edita o quita tableros propios (los de fábrica no se borran).

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";

import type { Widget } from "./BoardCharts";

export type Board = { id: string; name: string; audience_role: string | null; is_default: boolean; kpis: string[]; widgets?: Widget[]; mine: boolean };
const TYPES: [Widget["type"], string][] = [["card", "Tarjeta"], ["line", "Línea"], ["bar", "Barras"], ["traffic_light", "Semáforo"]];
type Data = { boards: Board[]; can_edit: boolean; role: string; kpis: { code: string; name: string }[] };
const ROLES: Record<string, string> = { sst_manager: "Jefe SST", sst_supervisor: "Supervisor SST", management: "Gerencia", committee_member: "Comité SST", area_lead: "Jefe de área", hr: "Recursos Humanos" };

export function useBoards() {
  const [data, setData] = useState<Data | null>(null);
  const reload = useCallback(() => api<{ data: Data }>("/dsh/boards", { withCompany: true }).then((r) => setData(r.data)).catch(() => setData(null)), []);
  useEffect(() => { void reload(); }, [reload]);
  return { data, reload };
}

export function BoardEditor({ data, onSaved }: { data: Data; onSaved: () => void }) {
  const [f, setF] = useState<{ id: string; name: string; role: string; kpis: string[]; types: Record<string, Widget["type"]> } | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const err = (e: unknown) => { const x = e as ApiError; setMsg(x.errors ? Object.values(x.errors).flat()[0] : (x.detail ?? x.title)); };
  const save = async () => {
    if (!f) return;
    setMsg(null);
    try {
      await api("/dsh/boards", { method: "POST", withCompany: true, body: { id: f.id || null, name: f.name, audience_role: f.role || null, kpi_codes: f.kpis, widget_types: Object.fromEntries(f.kpis.map((k) => [k, f.types[k] ?? "card"])) } });
      setF(null);
      onSaved();
    } catch (e) { err(e); }
  };
  const remove = async (id: string) => {
    setMsg(null);
    try { await api(`/dsh/boards/${id}`, { method: "DELETE", withCompany: true }); onSaved(); } catch (e) { err(e); }
  };
  const move = (i: number, d: number) => f && setF({ ...f, kpis: f.kpis.map((k, j) => (j === i ? f.kpis[i + d] : j === i + d ? f.kpis[i] : k)) });

  return (
    <details className="rounded-xl border border-line bg-surface">
      <summary className="cursor-pointer px-4 py-3 text-sm font-semibold">Editar tableros</summary>
      <div className="space-y-3 border-t border-line p-4 text-sm">
        <ul className="space-y-1">
          {data.boards.map((b) => (
            <li key={b.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-line pb-1 last:border-0">
              <span><b>{b.name}</b> <span className="text-xs text-muted">{b.audience_role ? ROLES[b.audience_role] ?? b.audience_role : "Todos los roles"} · {b.kpis.length} indicador(es){b.is_default ? " · de fábrica" : ""}</span></span>
              <span className="flex gap-3 text-xs">
                <button type="button" className="font-semibold text-brand-strong underline" onClick={() => setF({ id: b.id, name: b.name, role: b.audience_role ?? "", kpis: [...b.kpis], types: Object.fromEntries((b.widgets ?? []).map((w) => [w.code, w.type])) })}>Editar</button>
                {!b.is_default && <button type="button" className="font-semibold text-bad underline" onClick={() => remove(b.id)}>Quitar</button>}
              </span>
            </li>
          ))}
        </ul>
        {!f ? <button type="button" className="font-semibold text-brand-strong underline" onClick={() => setF({ id: "", name: "", role: "", kpis: [], types: {} })}>Nuevo tablero</button> : (
          <div className="space-y-2 rounded-lg border border-line p-3">
            <div className="grid gap-2 sm:grid-cols-2">
              <input className="input" placeholder="Nombre del tablero" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
              <select className="input" aria-label="Rol del tablero" value={f.role} onChange={(e) => setF({ ...f, role: e.target.value })}>
                <option value="">Todos los roles</option>{Object.entries(ROLES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </div>
            <p className="text-xs font-semibold text-muted">Indicadores (marca, ordena y elige cómo se ve cada uno)</p>
            <ul className="grid gap-1 sm:grid-cols-2">
              {data.kpis.map((k) => (
                <li key={k.code}><label className="flex items-center gap-2"><input type="checkbox" checked={f.kpis.includes(k.code)}
                  onChange={() => setF({ ...f, kpis: f.kpis.includes(k.code) ? f.kpis.filter((x) => x !== k.code) : [...f.kpis, k.code] })} />{k.name}</label></li>
              ))}
            </ul>
            {f.kpis.length > 0 && (
              <ol className="space-y-1 text-xs">
                {f.kpis.map((c, i) => (
                  <li key={c} className="flex flex-wrap items-center gap-2">{i + 1}. {data.kpis.find((k) => k.code === c)?.name ?? c}
                    <select className="input !h-7 !w-auto !py-0 text-xs" aria-label={`Cómo se muestra: ${data.kpis.find((k) => k.code === c)?.name ?? c}`} value={f.types[c] ?? "card"}
                      onChange={(e) => setF({ ...f, types: { ...f.types, [c]: e.target.value as Widget["type"] } })}>
                      {TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                    </select>
                    <button type="button" disabled={i === 0} className="underline disabled:opacity-30" onClick={() => move(i, -1)}>Subir</button>
                    <button type="button" disabled={i === f.kpis.length - 1} className="underline disabled:opacity-30" onClick={() => move(i, 1)}>Bajar</button></li>
                ))}
              </ol>
            )}
            <div className="flex gap-3"><button type="button" className="rounded-lg bg-brand px-3 py-1.5 font-semibold text-white disabled:opacity-50" disabled={!f.name.trim() || f.kpis.length === 0} onClick={save}>Guardar tablero</button>
              <button type="button" className="underline" onClick={() => setF(null)}>Cancelar</button></div>
          </div>
        )}
        {msg && <p role="alert" className="text-bad">{msg}</p>}
      </div>
    </details>
  );
}

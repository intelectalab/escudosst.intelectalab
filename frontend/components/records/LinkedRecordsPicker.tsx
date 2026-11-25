"use client";

// Registros del sistema vinculados como evidencia de un ítem del checklist de auditoría (M-AUI RN-AUI-07): se elige el tipo de
// registro (por módulo), se busca y se vincula; cada vínculo abre la ficha del registro en su módulo. Sin módulos de salud.

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";
import { records, type RecordRow } from "@/lib/records";
import { FormField } from "@/components/ui/Form";

export type LinkedRecord = { module: string; table: string; id: string; label: string };
type RecordType = { module: string; table: string; title: string; label: string };

export const linkedHref = (l: LinkedRecord) => `/m/${l.module.toLowerCase()}?t=${l.table}&open=${l.id}`;

export function asLinked(v: unknown): LinkedRecord[] {
  const list = typeof v === "string" ? (() => { try { return JSON.parse(v); } catch { return []; } })() : v;
  return Array.isArray(list) ? list.filter((x): x is LinkedRecord => !!x && typeof x === "object" && typeof (x as LinkedRecord).id === "string" && typeof (x as LinkedRecord).table === "string") : [];
}

/** Lista de los registros vinculados con enlace a su ficha (también para quien solo lee la auditoría). */
export function LinkedRecordsList({ value, titles, onRemove }: { value: LinkedRecord[]; titles?: Record<string, string>; onRemove?: (l: LinkedRecord) => void }) {
  if (value.length === 0) return <p className="text-sm text-muted">Sin registros del sistema vinculados.</p>;
  return (
    <ul className="divide-y divide-line rounded-lg border border-line text-sm">
      {value.map((l) => (
        <li key={`${l.table}:${l.id}`} className="flex items-center justify-between gap-2 px-3 py-2">
          <span><span className="text-xs text-muted">{titles?.[l.table] ?? l.table} · {l.module}</span>
            <Link href={linkedHref(l)} className="block font-semibold text-brand hover:underline">{l.label || l.id}</Link></span>
          {onRemove && <button type="button" className="text-xs text-bad hover:underline" onClick={() => onRemove(l)}>Quitar</button>}
        </li>
      ))}
    </ul>
  );
}

export function LinkedRecordsPicker({ value, disabled, error, onChange }: { value: unknown; disabled: boolean; error?: string; onChange: (v: LinkedRecord[]) => void }) {
  const links = asLinked(value);
  const [types, setTypes] = useState<RecordType[]>([]);
  const [type, setType] = useState("");
  const [q, setQ] = useState("");
  const [rows, setRows] = useState<RecordRow[]>([]);
  useEffect(() => { api<{ data: RecordType[] }>("/aui/record-types", { withCompany: true }).then((r) => setTypes(r.data)).catch(() => setTypes([])); }, []);
  const t = types.find((x) => x.table === type);
  useEffect(() => {
    if (!t) { setRows([]); return; }
    const h = setTimeout(() => {
      records.list(t.module, t.table, { q: q.trim() || undefined }).then((r) => setRows(r.data.slice(0, 20))).catch(() => setRows([]));
    }, 250);
    return () => clearTimeout(h);
  }, [t, q]);
  const titles = useMemo(() => Object.fromEntries(types.map((x) => [x.table, x.title])), [types]);
  const labelOf = (r: RecordRow) => {
    const main = String(r[t?.label ?? "id"] ?? r.id);
    return r.code && t?.label !== "code" ? `${String(r.code)} · ${main}` : main;
  };
  const add = (r: RecordRow) => {
    if (!t || links.some((l) => l.table === t.table && l.id === r.id)) return;
    onChange([...links, { module: t.module, table: t.table, id: r.id, label: labelOf(r) }]);
  };
  return (
    <FormField label="Registros del sistema como evidencia" error={error} hint="Vincula capacitaciones, inspecciones, actas, documentos u otros registros que respaldan el ítem.">
      <div className="space-y-2">
        <LinkedRecordsList value={links} titles={titles} onRemove={disabled ? undefined : (l) => onChange(links.filter((x) => !(x.table === l.table && x.id === l.id)))} />
        {!disabled && (
          <div className="space-y-2 rounded-lg border border-line p-2">
            <div className="grid gap-2 sm:grid-cols-[16rem_1fr]">
              <select className="input" aria-label="Tipo de registro" value={type} onChange={(e) => { setType(e.target.value); setQ(""); }}>
                <option value="">Elige el tipo de registro…</option>
                {types.map((x) => <option key={x.table} value={x.table}>{x.title} ({x.module})</option>)}
              </select>
              <input className="input" aria-label="Buscar registro" placeholder="Buscar por código o nombre" disabled={!t} value={q} onChange={(e) => setQ(e.target.value)} />
            </div>
            {t && (rows.length === 0 ? <p className="text-xs text-muted">No hay registros que coincidan.</p> : (
              <ul className="max-h-48 divide-y divide-line overflow-y-auto text-sm">
                {rows.map((r) => {
                  const linked = links.some((l) => l.table === t.table && l.id === r.id);
                  return (
                    <li key={r.id} className="flex items-center justify-between gap-2 py-1">
                      <span>{labelOf(r)}</span>
                      <button type="button" disabled={linked} className="text-xs font-semibold text-brand hover:underline disabled:text-muted" onClick={() => add(r)}>{linked ? "Vinculado" : "Vincular"}</button>
                    </li>
                  );
                })}
              </ul>
            ))}
          </div>
        )}
      </div>
    </FormField>
  );
}

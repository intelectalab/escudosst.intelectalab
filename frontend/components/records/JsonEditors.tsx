"use client";

// Editores de los datos estructurados que la empresa configura en formularios del motor común:
//  - permit_types.checklist: preguntas de verificación del PETAR (antes de iniciar / al cerrar).
//  - permit_types.approval_chain: cadena de aprobación por rol, en orden (M-PTR: «Configura tipos y cadena de aprobación»).

import { Icon } from "@/components/ui/Icon";

type Check = { code: string; phase: "pre_start" | "closure"; question: string };
type Step = { step: number; role: string; label: string; optional?: boolean };

const ROLES: [string, string][] = [
  ["area_lead", "Jefe o supervisor del área"],
  ["sst_supervisor", "Supervisor SST"],
  ["sst_manager", "Jefe SST"],
  ["management", "Gerencia"],
  ["committee_member", "Miembro del comité"],
];

export const JSON_EDITORS: Record<string, (p: { value: unknown; onChange: (v: unknown) => void; disabled: boolean }) => React.ReactElement> = {
  "permit_types.checklist": ChecklistEditor,
  "permit_types.approval_chain": ChainEditor,
};

const arr = <T,>(v: unknown): T[] => (Array.isArray(v) ? (v as T[]) : typeof v === "string" && v.trim().startsWith("[") ? (JSON.parse(v) as T[]) : []);

function ChecklistEditor({ value, onChange, disabled }: { value: unknown; onChange: (v: unknown) => void; disabled: boolean }) {
  const rows = arr<Check>(value);
  const set = (next: Check[]) => {
    let pre = 0;
    let clo = 0;
    onChange(next.map((r) => ({ ...r, code: r.phase === "closure" ? `C${++clo}` : String(++pre).padStart(2, "0") })));
  };
  return (
    <div className="space-y-2">
      {rows.length === 0 && <p className="text-sm text-muted">Agrega las preguntas que se verifican antes de iniciar y al cerrar el permiso.</p>}
      {rows.map((r, i) => (
        <div key={i} className="flex flex-wrap items-center gap-2">
          <select className="input !w-auto" disabled={disabled} value={r.phase} onChange={(e) => set(rows.map((x, k) => (k === i ? { ...x, phase: e.target.value as Check["phase"] } : x)))} aria-label="Momento">
            <option value="pre_start">Antes de iniciar</option>
            <option value="closure">Al cerrar</option>
          </select>
          <input className="input min-w-0 flex-1" disabled={disabled} value={r.question} maxLength={200} placeholder="Ej.: Punto de anclaje certificado"
            onChange={(e) => set(rows.map((x, k) => (k === i ? { ...x, question: e.target.value } : x)))} aria-label={`Pregunta ${i + 1}`} />
          {!disabled && (
            <button type="button" onClick={() => set(rows.filter((_, k) => k !== i))} className="grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-bad-soft hover:text-bad" aria-label="Quitar pregunta">
              <Icon name="trash" size={16} />
            </button>
          )}
        </div>
      ))}
      {!disabled && (
        <button type="button" onClick={() => set([...rows, { code: "", phase: "pre_start", question: "" }])} className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand hover:underline">
          <Icon name="plus" size={15} /> Agregar pregunta
        </button>
      )}
    </div>
  );
}

function ChainEditor({ value, onChange, disabled }: { value: unknown; onChange: (v: unknown) => void; disabled: boolean }) {
  const rows = arr<Step>(value);
  const set = (next: Step[]) => onChange(next.map((r, i) => ({ ...r, step: i + 1 })));
  return (
    <div className="space-y-2">
      {rows.length === 0 && <p className="text-sm text-muted">Define quién aprueba el permiso y en qué orden.</p>}
      {rows.map((r, i) => (
        <div key={i} className="flex flex-wrap items-center gap-2">
          <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-brand-soft text-xs font-bold text-brand-strong">{i + 1}</span>
          <select className="input !w-auto" disabled={disabled} value={r.role} onChange={(e) => set(rows.map((x, k) => (k === i ? { ...x, role: e.target.value, label: x.label || (ROLES.find(([c]) => c === e.target.value)?.[1] ?? "") } : x)))} aria-label="Rol que aprueba">
            {ROLES.map(([c, l]) => <option key={c} value={c}>{l}</option>)}
          </select>
          <input className="input min-w-0 flex-1" disabled={disabled} value={r.label} maxLength={80} placeholder="Nombre del paso"
            onChange={(e) => set(rows.map((x, k) => (k === i ? { ...x, label: e.target.value } : x)))} aria-label={`Paso ${i + 1}`} />
          <label className="flex items-center gap-1 text-xs text-muted">
            <input type="checkbox" disabled={disabled} checked={!!r.optional} onChange={(e) => set(rows.map((x, k) => (k === i ? { ...x, optional: e.target.checked } : x)))} /> Opcional
          </label>
          {!disabled && (
            <button type="button" onClick={() => set(rows.filter((_, k) => k !== i))} className="grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-bad-soft hover:text-bad" aria-label="Quitar paso">
              <Icon name="trash" size={16} />
            </button>
          )}
        </div>
      ))}
      {!disabled && (
        <button type="button" onClick={() => set([...rows, { step: rows.length + 1, role: "area_lead", label: "Jefe o supervisor del área" }])} className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand hover:underline">
          <Icon name="plus" size={15} /> Agregar paso
        </button>
      )}
    </div>
  );
}

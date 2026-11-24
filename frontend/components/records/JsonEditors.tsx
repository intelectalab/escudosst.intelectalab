"use client";

// Editores de los datos estructurados que la empresa configura en formularios del motor común:
//  - permit_types.checklist: preguntas de verificación del PETAR (antes de iniciar / al cerrar).
//  - permit_types.approval_chain: cadena de aprobación por rol, en orden (M-PTR: «Configura tipos y cadena de aprobación»).
//  - risk_criteria.level_bands / severity_scale: bandas de nivel con su rango y color y escala de severidad (M-IPC SCR-IPC-02).
//  - work_permits.gas_measurements: medición de gases del PETAR con su rango permitido (M-PTR SCR-PTR-03, RN-PTR-03).

import { Icon } from "@/components/ui/Icon";
import { GasEditor } from "./GasEditor";

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
  "risk_criteria.level_bands": BandsEditor,
  "risk_criteria.severity_scale": ScaleEditor,
  "work_permits.gas_measurements": GasMeasurementsEditor,
};

function GasMeasurementsEditor({ value, onChange, disabled }: { value: unknown; onChange: (v: unknown) => void; disabled: boolean }) {
  return <GasEditor value={value} onChange={onChange} disabled={disabled} />;
}

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

type Band = { code?: string; label: string; min: number | ""; max: number | ""; color: "green" | "amber" | "red" };
const BAND_COLORS: [Band["color"], string, string][] = [["green", "Verde", "bg-ok-soft text-ok"], ["amber", "Ámbar", "bg-amber-soft text-amber-ink"], ["red", "Rojo", "bg-bad-soft text-bad"]];

function BandsEditor({ value, onChange, disabled }: { value: unknown; onChange: (v: unknown) => void; disabled: boolean }) {
  const rows = arr<Band>(value);
  const set = (i: number, patch: Partial<Band>) => onChange(rows.map((x, k) => (k === i ? { ...x, ...patch } : x)));
  const num = (v: string): number | "" => (v === "" ? "" : Number(v));
  return (
    <div className="space-y-2">
      <p className="text-xs text-muted">Cada banda va desde un puntaje hasta otro, sin cruzarse. Su color se usa en la matriz y en el mapa de calor.</p>
      {rows.map((r, i) => {
        const cls = BAND_COLORS.find((c) => c[0] === r.color)?.[2] ?? "bg-bg text-muted";
        return (
          <div key={i} className="flex flex-wrap items-center gap-2">
            <input className="input !w-40" disabled={disabled} placeholder="Nombre (p. ej. Moderado)" aria-label="Nombre de la banda" value={r.label} onChange={(e) => set(i, { label: e.target.value })} />
            <input type="number" className="input !w-20" disabled={disabled} aria-label="Desde" placeholder="Desde" value={r.min} onChange={(e) => set(i, { min: num(e.target.value) })} />
            <input type="number" className="input !w-20" disabled={disabled} aria-label="Hasta" placeholder="Hasta" value={r.max} onChange={(e) => set(i, { max: num(e.target.value) })} />
            <select className="input !w-auto" disabled={disabled} aria-label="Color" value={r.color} onChange={(e) => set(i, { color: e.target.value as Band["color"] })}>
              {BAND_COLORS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
            <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${cls}`}>{r.label || "Vista previa"}</span>
            {!disabled && <button type="button" className="text-muted hover:text-bad" aria-label="Quitar banda" onClick={() => onChange(rows.filter((_, k) => k !== i))}><Icon name="trash" size={16} /></button>}
          </div>
        );
      })}
      {!disabled && <button type="button" className="text-sm font-semibold text-brand-strong" onClick={() => onChange([...rows, { label: "", min: "", max: "", color: "green" }])}>+ Agregar banda</button>}
    </div>
  );
}

type Scale = { value: number | ""; label: string };

function ScaleEditor({ value, onChange, disabled }: { value: unknown; onChange: (v: unknown) => void; disabled: boolean }) {
  const rows = arr<Scale>(value);
  const set = (i: number, patch: Partial<Scale>) => onChange(rows.map((x, k) => (k === i ? { ...x, ...patch } : x)));
  return (
    <div className="space-y-2">
      {rows.map((r, i) => (
        <div key={i} className="flex flex-wrap items-center gap-2">
          <input type="number" className="input !w-20" disabled={disabled} aria-label="Valor" value={r.value} onChange={(e) => set(i, { value: e.target.value === "" ? "" : Number(e.target.value) })} />
          <input className="input min-w-0 flex-1" disabled={disabled} aria-label="Descripción" placeholder="Descripción del nivel de severidad" value={r.label} onChange={(e) => set(i, { label: e.target.value })} />
          {!disabled && <button type="button" className="text-muted hover:text-bad" aria-label="Quitar nivel" onClick={() => onChange(rows.filter((_, k) => k !== i))}><Icon name="trash" size={16} /></button>}
        </div>
      ))}
      {!disabled && <button type="button" className="text-sm font-semibold text-brand-strong" onClick={() => onChange([...rows, { value: "", label: "" }])}>+ Agregar nivel</button>}
    </div>
  );
}

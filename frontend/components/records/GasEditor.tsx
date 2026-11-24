"use client";

// Medición de gases del PETAR (M-PTR SCR-PTR-03, RN-PTR-03): O₂, LEL, CO y H₂S con su unidad y el rango permitido
// (parámetros legales vigentes de la empresa, a confirmar). Marca en rojo lo que está fuera de rango; la regla del servidor decide.

import { useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";

export type GasLimit = { code: string; label: string; unit: string; min: number | null; max: number; max_exclusive: boolean };
export type GasValues = Record<string, number>;

// Valores de respaldo iguales a los del servidor, por si los parámetros aún no cargan.
const FALLBACK: GasLimit[] = [
  { code: "o2", label: "O₂", unit: "%", min: 19.5, max: 22.5, max_exclusive: false },
  { code: "lel", label: "LEL", unit: "%", min: null, max: 10, max_exclusive: true },
  { code: "co", label: "CO", unit: "ppm", min: null, max: 25, max_exclusive: true },
  { code: "h2s", label: "H₂S", unit: "ppm", min: null, max: 10, max_exclusive: true },
];

let cached: Promise<GasLimit[]> | null = null;
export function useGasLimits(given?: GasLimit[] | null): GasLimit[] {
  const [limits, setLimits] = useState<GasLimit[]>(given ?? FALLBACK);
  useEffect(() => {
    if (given) {
      setLimits(given);
      return;
    }
    cached ??= api<{ data: GasLimit[] }>("/ptr/gas-limits", { withCompany: true }).then((r) => r.data).catch(() => { cached = null; return FALLBACK; });
    let live = true;
    void cached.then((l) => live && setLimits(l));
    return () => {
      live = false;
    };
  }, [given]);
  return limits;
}

export const rangeText = (l: GasLimit) => (l.min !== null ? `${l.min}–${l.max} ${l.unit}` : `menos de ${l.max} ${l.unit}`);
export const outOfRange = (l: GasLimit, v: number) => (l.min !== null && v < l.min) || (l.max_exclusive ? v >= l.max : v > l.max);

/** Lee lo guardado: objeto {o2: 20.9, …} o lista [{gas, value}]. */
export function gasValues(v: unknown): GasValues {
  const raw = typeof v === "string" ? (() => { try { return JSON.parse(v) as unknown; } catch { return null; } })() : v;
  const out: GasValues = {};
  if (Array.isArray(raw)) {
    for (const r of raw as Record<string, unknown>[]) {
      const k = String(r?.gas ?? r?.code ?? "").toLowerCase().replace("₂", "2");
      if (k && Number.isFinite(Number(r?.value))) out[k] = Number(r.value);
    }
  } else if (raw && typeof raw === "object") {
    for (const [k, x] of Object.entries(raw as Record<string, unknown>)) if (Number.isFinite(Number(x)) && x !== null && x !== "") out[k] = Number(x);
  }
  return out;
}

const toText = (g: GasValues) => Object.fromEntries(Object.entries(g).map(([k, x]) => [k, String(x)]));

/** Editor de la medición: una fila por gas con valor, unidad y rango; avisa lo que está fuera de rango. */
export function GasEditor({ value, onChange, disabled = false, limits: given }: { value: unknown; onChange: (v: GasValues | null) => void; disabled?: boolean; limits?: GasLimit[] | null }) {
  const limits = useGasLimits(given);
  const [text, setText] = useState<Record<string, string>>(() => toText(gasValues(value)));
  // Si el valor guardado cambia desde fuera (otra lectura de la ficha), se vuelve a mostrar.
  const key = JSON.stringify(gasValues(value));
  const emitted = useRef(key);
  useEffect(() => {
    if (key !== emitted.current) {
      emitted.current = key;
      setText(toText(JSON.parse(key) as GasValues));
    }
  }, [key]);

  const set = (code: string, s: string) => {
    const next = { ...text, [code]: s };
    setText(next);
    const v: GasValues = {};
    for (const [k, x] of Object.entries(next)) {
      const n = Number(x.replace(",", "."));
      if (x.trim() !== "" && Number.isFinite(n)) v[k] = n;
    }
    emitted.current = JSON.stringify(v);
    onChange(Object.keys(v).length ? v : null);
  };

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[22rem] text-sm">
        <thead>
          <tr className="text-left text-xs text-muted">
            <th className="py-1 pr-2 font-semibold">Gas</th>
            <th className="py-1 pr-2 font-semibold">Valor medido</th>
            <th className="py-1 pr-2 font-semibold">Unidad</th>
            <th className="py-1 pr-2 font-semibold">Rango permitido</th>
            <th className="py-1 font-semibold">Resultado</th>
          </tr>
        </thead>
        <tbody>
          {limits.map((l) => {
            const s = text[l.code] ?? "";
            const n = Number(s.replace(",", "."));
            const bad = s.trim() !== "" && !Number.isFinite(n);
            const out = !bad && s.trim() !== "" && outOfRange(l, n);
            return (
              <tr key={l.code} className="border-t border-line">
                <td className="py-1.5 pr-2 font-semibold">{l.label}</td>
                <td className="py-1.5 pr-2">
                  <input className={`input !h-9 !w-24 ${out || bad ? "!border-bad" : ""}`} inputMode="decimal" disabled={disabled} aria-label={`${l.label} (${l.unit})`} aria-invalid={out || bad || undefined}
                    value={s} onChange={(e) => set(l.code, e.target.value)} />
                </td>
                <td className="py-1.5 pr-2 text-muted">{l.unit}</td>
                <td className="py-1.5 pr-2 text-muted">{rangeText(l)}</td>
                <td className="py-1.5">
                  {s.trim() === "" ? <span className="text-muted">Sin medir</span>
                    : bad ? <span className="font-semibold text-bad">Valor no válido</span>
                    : out ? <span className="rounded-md bg-bad-soft px-2 py-0.5 font-semibold text-bad">Fuera de rango</span>
                    : <span className="rounded-md bg-ok-soft px-2 py-0.5 font-semibold text-ok">En rango</span>}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
      {limits.some((l) => { const s = text[l.code] ?? ""; const n = Number(s.replace(",", ".")); return s.trim() !== "" && Number.isFinite(n) && outOfRange(l, n); }) && (
        <p role="alert" className="mt-2 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">Hay gases fuera del rango permitido: con esta medición el permiso no pasa a aprobación ni se inicia el trabajo.</p>
      )}
    </div>
  );
}

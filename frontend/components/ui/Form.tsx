"use client";

// Piezas de formulario con un mismo estilo: campo con etiqueta, sección, campo con prefijo/sufijo,
// fila con interruptor y selector de opciones tipo «chips».

import { Icon } from "./Icon";
import { Switch } from "./Switch";

export function FormField({
  label,
  hint,
  error,
  counter,
  children,
}: {
  label: string;
  hint?: string;
  error?: string;
  counter?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="mb-1.5 flex items-baseline justify-between gap-2">
        <span className="text-sm font-semibold text-fg">{label}</span>
        {counter && <span className="text-xs tabular-nums text-muted">{counter}</span>}
      </span>
      {children}
      {error ? (
        <span className="mt-1.5 flex items-center gap-1 text-xs font-medium text-bad">
          <span aria-hidden="true">●</span> {error}
        </span>
      ) : (
        hint && <span className="mt-1.5 block text-xs text-muted">{hint}</span>
      )}
    </label>
  );
}

/** Sección de un formulario: tarjeta con un ícono, título, descripción y su contenido. */
export function FormSection({ title, description, icon = "pen", children }: { title: string; description?: string; icon?: string; children: React.ReactNode }) {
  return (
    <section className="form-section space-y-4">
      <div className="flex items-start gap-3">
        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-strong" aria-hidden="true">
          <Icon name={icon} size={18} />
        </span>
        <div className="min-w-0 pt-0.5">
          <h3 className="font-display text-[15px] font-semibold leading-tight text-fg">{title}</h3>
          {description && <p className="mt-0.5 text-xs leading-relaxed text-muted">{description}</p>}
        </div>
      </div>
      {children}
    </section>
  );
}

/** Campo con prefijo («S/») o sufijo («GB») dentro del borde. */
export function AffixInput({
  prefix,
  suffix,
  invalid,
  ...props
}: React.InputHTMLAttributes<HTMLInputElement> & { prefix?: string; suffix?: string; invalid?: boolean }) {
  return (
    <span className="relative block">
      {prefix && <span className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm font-semibold text-muted">{prefix}</span>}
      <input
        {...props}
        aria-invalid={invalid || undefined}
        className={`input tabular-nums ${prefix ? "pl-10" : ""} ${suffix ? "pr-12" : ""} ${props.className ?? ""}`}
      />
      {suffix && <span className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-xs font-semibold text-muted">{suffix}</span>}
    </span>
  );
}

/** Fila con título, explicación e interruptor. */
export function SwitchRow({ label, hint, checked, onChange }: { label: string; hint: string; checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <div className={`flex items-start justify-between gap-4 rounded-xl border p-4 transition-colors ${checked ? "border-brand/40 bg-brand-soft/40" : "border-line"}`}>
      <div>
        <p className="text-sm font-semibold">{label}</p>
        <p className="mt-0.5 text-xs text-muted">{hint}</p>
      </div>
      <Switch checked={checked} onChange={onChange} label={label} />
    </div>
  );
}

/** Selección múltiple con «chips» (por ejemplo, periodos de cobro). */
export function ChipToggleGroup<T extends string>({
  options,
  value,
  onChange,
  label,
}: {
  options: [T, string][];
  value: T[];
  onChange: (v: T[]) => void;
  label: string;
}) {
  return (
    <div role="group" aria-label={label} className="flex flex-wrap gap-2">
      {options.map(([v, l]) => {
        const on = value.includes(v);
        return (
          <button
            key={v}
            type="button"
            aria-pressed={on}
            onClick={() => onChange(on ? value.filter((x) => x !== v) : [...value, v])}
            className={`inline-flex h-9 items-center gap-2 rounded-full border px-4 text-sm font-semibold transition-colors ${
              on ? "border-brand bg-brand-soft text-brand-strong" : "border-line text-muted hover:border-brand/50 hover:text-fg"
            }`}
          >
            <span className={`grid h-4 w-4 place-items-center rounded-full border text-[10px] ${on ? "border-brand bg-brand text-white" : "border-line"}`} aria-hidden="true">
              {on ? "✓" : ""}
            </span>
            {l}
          </button>
        );
      })}
    </div>
  );
}

/** Elección única con tarjetas (título, detalle y un color opcional). */
export function ChoiceCards<T extends string>({
  options,
  value,
  onChange,
  label,
  columns = 2,
}: {
  options: { value: T; title: string; detail?: string; accent?: string; disabled?: boolean; badge?: string }[];
  value: T | null;
  onChange: (v: T) => void;
  label: string;
  columns?: 2 | 3;
}) {
  return (
    <div role="radiogroup" aria-label={label} className={`grid gap-2.5 ${columns === 3 ? "sm:grid-cols-3" : "sm:grid-cols-2"}`}>
      {options.map((o) => {
        const on = value === o.value;
        return (
          <button
            key={o.value}
            type="button"
            role="radio"
            aria-checked={on}
            aria-label={o.detail ? `${o.title}: ${o.detail}` : o.title}
            disabled={o.disabled}
            onClick={() => onChange(o.value)}
            className={`relative flex min-w-0 items-start gap-3 rounded-xl border p-3.5 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
              on ? "border-brand bg-brand-soft/50 ring-1 ring-brand" : "border-line hover:border-brand/50"
            }`}
          >
            <span className={`mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full border-2 ${on ? "border-brand" : "border-line"}`}>
              {on && <span className="h-2 w-2 rounded-full bg-brand" />}
            </span>
            <span className="min-w-0">
              <span className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                {o.accent && <span className="h-2.5 w-2.5 rounded-full" style={{ background: o.accent }} />}
                {o.title}
                {o.badge && <span className="rounded-full bg-bg px-2 py-0.5 text-[11px] font-semibold text-muted">{o.badge}</span>}
              </span>
              {o.detail && <span className="mt-0.5 block text-xs text-muted">{o.detail}</span>}
            </span>
          </button>
        );
      })}
    </div>
  );
}

/** Elección múltiple con tarjetas y casilla de verificación. */
export function CheckCards<T extends string>({
  options,
  value,
  onChange,
  label,
}: {
  options: { value: T; title: string; detail?: string; disabled?: boolean; badge?: string }[];
  value: T[];
  onChange: (v: T[]) => void;
  label: string;
}) {
  return (
    <div role="group" aria-label={label} className="grid gap-2.5 sm:grid-cols-2">
      {options.map((o) => {
        const on = value.includes(o.value);
        return (
          <button
            key={o.value}
            type="button"
            role="checkbox"
            aria-checked={on}
            aria-label={o.detail ? `${o.title}: ${o.detail}` : o.title}
            disabled={o.disabled}
            onClick={() => onChange(on ? value.filter((x) => x !== o.value) : [...value, o.value])}
            className={`flex min-w-0 items-start gap-3 rounded-xl border p-3.5 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
              on ? "border-brand bg-brand-soft/50 ring-1 ring-brand" : "border-line hover:border-brand/50"
            }`}
          >
            <span className={`mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded border-2 ${on ? "border-brand bg-brand text-white" : "border-line"}`}>
              {on && (
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                  <path d="M5 12l5 5L20 7" />
                </svg>
              )}
            </span>
            <span className="min-w-0">
              <span className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                {o.title}
                {o.badge && <span className="rounded-full bg-bg px-2 py-0.5 text-[11px] font-semibold text-muted">{o.badge}</span>}
              </span>
              {o.detail && <span className="mt-0.5 block text-xs text-muted">{o.detail}</span>}
            </span>
          </button>
        );
      })}
    </div>
  );
}

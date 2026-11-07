"use client";

import { Icon } from "./Icon";

/**
 * Barra de búsqueda y filtros para listas. En escritorio va en una fila; en celular cada control
 * ocupa el ancho completo y el contador queda abajo.
 */
export function FilterBar({ children, count, onClear }: { children: React.ReactNode; count?: string; onClear?: () => void }) {
  return (
    <div className="rounded-2xl border border-line bg-surface p-3 shadow-sm sm:p-4">
      <div className="grid gap-3 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-end">{children}</div>
      {(count || onClear) && (
        <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3">
          <span className="inline-flex items-center gap-1.5 text-sm text-muted" aria-live="polite">
            <Icon name="grid" size={14} />
            {count}
          </span>
          {onClear && (
            <button type="button" onClick={onClear} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-sm font-semibold text-brand hover:bg-brand-soft">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" aria-hidden="true">
                <path d="M6 6l12 12M18 6 6 18" />
              </svg>
              Limpiar filtros
            </button>
          )}
        </div>
      )}
    </div>
  );
}

export function SearchInput({ value, onChange, placeholder, label }: { value: string; onChange: (v: string) => void; placeholder: string; label: string }) {
  return (
    <label className="flex min-w-0 flex-col gap-1.5 sm:col-span-2 lg:min-w-[16rem] lg:flex-1">
      <span className="text-xs font-semibold text-muted">Buscar</span>
      <span className="relative block">
        <Icon name="search" size={16} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-muted" />
        <input
          type="text"
          aria-label={label}
          placeholder={placeholder}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          onKeyDown={(e) => e.key === "Escape" && onChange("")}
          className="filter-control pl-10 pr-9"
        />
        {value && (
          <button
            type="button"
            onClick={() => onChange("")}
            aria-label="Borrar búsqueda"
            className="absolute right-2 top-1/2 grid h-6 w-6 -translate-y-1/2 place-items-center rounded-md text-muted hover:bg-bg hover:text-fg"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" aria-hidden="true">
              <path d="M6 6l12 12M18 6 6 18" />
            </svg>
          </button>
        )}
      </span>
    </label>
  );
}

export function FilterSelect({
  label,
  value,
  onChange,
  options,
  neutral = "",
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  options: [string, string][];
  /** Valor que significa «sin filtrar» (no se resalta). */
  neutral?: string;
}) {
  return (
    <label className="flex min-w-0 flex-col gap-1.5 lg:w-auto lg:min-w-[11rem]">
      <span className="text-xs font-semibold text-muted">{label}</span>
      <select aria-label={label} value={value} onChange={(e) => onChange(e.target.value)} className={`filter-control ${value !== neutral ? "is-active" : ""}`}>
        {options.map(([v, l]) => (
          <option key={v} value={v}>
            {l}
          </option>
        ))}
      </select>
    </label>
  );
}

"use client";

// Gráficos simples con el estilo de la aplicación.
// - Barras finas con extremo redondeado sobre la línea base; rejilla tenue.
// - Al pasar el mouse (o enfocar con teclado) aparece una burbuja con el detalle sobre la barra.
// - El color lo da quien usa el gráfico (por gráfico o por categoría); el texto siempre va en tinta,
//   así el nombre y el número se leen aunque el color sea claro.

import { useState } from "react";

export type BarDatum = {
  key: string;
  label: string;
  value: number;
  display: string;
  detail?: string;
  color?: string;
};

/** Paleta categórica validada (8 tonos en orden fijo; nunca se repite ni se genera un 9.º). */
export const SERIES_COLORS = [
  "#2a78d6",
  "#eb6834",
  "#1baf7a",
  "#eda100",
  "#e87ba4",
  "#008300",
  "#6250d6",
  "#e34948",
];

function Bubble({ title, text }: { title: string; text?: string }) {
  return (
    <span
      role="tooltip"
      className="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 w-max max-w-[16rem] -translate-x-1/2 rounded-lg bg-side px-3 py-2 text-left text-xs text-white shadow-xl"
    >
      <b className="block">{title}</b>
      {text && <span className="mt-0.5 block text-white/80">{text}</span>}
      <span className="absolute left-1/2 top-full -translate-x-1/2 border-4 border-transparent border-t-side" />
    </span>
  );
}

/** Barras verticales: cambio en el tiempo (pocos periodos), un color por gráfico. */
export function ColumnChart({
  data,
  label,
  color = SERIES_COLORS[0],
  height = 190,
}: {
  data: BarDatum[];
  label: string;
  color?: string;
  height?: number;
}) {
  const [hover, setHover] = useState<string | null>(null);
  const max = Math.max(1, ...data.map((d) => d.value));

  return (
    <figure aria-label={label}>
      <div className="relative" style={{ height }}>
        {[0, 0.5, 1].map((t) => (
          <div
            key={t}
            className="pointer-events-none absolute inset-x-0 border-t border-dashed border-line"
            style={{ bottom: `${t * 82}%` }}
          />
        ))}
        <div className="absolute inset-0 flex items-end gap-2 sm:gap-3">
          {data.map((d) => {
            const on = hover === d.key;
            return (
              <button
                key={d.key}
                type="button"
                onMouseEnter={() => setHover(d.key)}
                onMouseLeave={() => setHover(null)}
                onFocus={() => setHover(d.key)}
                onBlur={() => setHover(null)}
                aria-label={`${d.label}: ${d.display}${d.detail ? `. ${d.detail}` : ""}`}
                className="relative flex h-full flex-1 flex-col items-center justify-end rounded-md outline-none focus-visible:ring-2 focus-visible:ring-brand"
              >
                <span className="mb-1 text-[11px] font-semibold tabular-nums text-fg">
                  {d.display}
                </span>
                <span
                  className="relative w-full max-w-11 rounded-t-[4px] transition-all duration-150"
                  style={{
                    height: `${Math.max(d.value > 0 ? 3 : 0, (d.value / max) * 78)}%`,
                    background: d.color ?? color,
                    opacity: hover && !on ? 0.45 : 1,
                  }}
                >
                  {on && (
                    <Bubble title={d.label} text={d.detail ?? d.display} />
                  )}
                </span>
                {on && d.value === 0 && (
                  <Bubble title={d.label} text={d.detail ?? d.display} />
                )}
              </button>
            );
          })}
        </div>
      </div>
      <div className="mt-2 flex gap-2 border-t border-line pt-2 sm:gap-3">
        {data.map((d) => (
          <span
            key={d.key}
            className={`flex-1 truncate text-center text-xs ${hover === d.key ? "font-semibold text-fg" : "text-muted"}`}
          >
            {d.label}
          </span>
        ))}
      </div>
    </figure>
  );
}

/**
 * Barras horizontales: comparar magnitudes entre categorías. Cada fila puede tener su color (categoría),
 * muestra la burbuja con el detalle al pasar el mouse y, si se da `renderDetail`, se despliega al presionarla.
 */
export function BarList({
  data,
  label,
  onSelect,
  renderDetail,
  columns = 1,
}: {
  data: BarDatum[];
  label: string;
  onSelect?: (d: BarDatum) => void;
  renderDetail?: (d: BarDatum) => React.ReactNode;
  columns?: 1 | 2;
}) {
  const [hover, setHover] = useState<string | null>(null);
  const [open, setOpen] = useState<string | null>(null);
  const max = Math.max(1, ...data.map((d) => d.value));

  return (
    <ul
      className={`-mx-2 grid items-start gap-y-1 ${columns === 2 ? "gap-x-6 md:grid-cols-2" : ""}`}
      aria-label={label}
    >
      {data.map((d) => {
        const on = hover === d.key;
        const expanded = open === d.key;
        const clickable = !!onSelect || !!renderDetail;
        const body = (
          <>
            <span className="flex items-center justify-between gap-3 text-sm">
              <span className="flex min-w-0 items-center gap-2">
                {d.color && (
                  <span
                    className="h-2.5 w-2.5 shrink-0 rounded-full"
                    style={{ background: d.color }}
                  />
                )}
                <span
                  className={`truncate ${on || expanded ? "font-semibold" : ""}`}
                >
                  {d.label}
                </span>
              </span>
              <span className="shrink-0 font-semibold tabular-nums">
                {d.display}
              </span>
            </span>
            <span className="relative mt-1.5 block h-2.5 rounded-full bg-bg">
              <span
                className="relative block h-full rounded-full transition-all duration-150"
                style={{
                  width: `${Math.max(d.value > 0 ? 2 : 0, (d.value / max) * 100)}%`,
                  background: d.color ?? SERIES_COLORS[0],
                  opacity: hover && !on ? 0.5 : 1,
                }}
              >
                {on && !expanded && d.detail && (
                  <Bubble title={d.label} text={d.detail} />
                )}
              </span>
            </span>
          </>
        );
        return (
          <li
            key={d.key}
            onMouseEnter={() => setHover(d.key)}
            onMouseLeave={() => setHover(null)}
          >
            {clickable ? (
              <button
                type="button"
                aria-expanded={renderDetail ? expanded : undefined}
                onFocus={() => setHover(d.key)}
                onBlur={() => setHover(null)}
                onClick={() =>
                  renderDetail
                    ? setOpen(expanded ? null : d.key)
                    : onSelect?.(d)
                }
                className={`block w-full rounded-lg px-2 py-2 text-left hover:bg-bg focus-visible:outline-2 focus-visible:outline-brand ${expanded ? "bg-bg" : ""}`}
              >
                {body}
              </button>
            ) : (
              <div className="px-2 py-2">{body}</div>
            )}
            {expanded && renderDetail && (
              <div className="mx-2 mb-2 rounded-lg border border-line bg-surface p-3">
                {renderDetail(d)}
              </div>
            )}
          </li>
        );
      })}
    </ul>
  );
}

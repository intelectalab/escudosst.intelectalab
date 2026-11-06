"use client";

// Piezas de presentación compartidas por las pantallas del panel (encabezado, indicadores, etiquetas).

import Link from "next/link";
import { Button } from "./Button";
import { Icon } from "./Icon";

export type ChipTone = "ok" | "na" | "amber" | "brand" | "bad";

export function Chip({ tone, dot, children }: { tone: ChipTone; dot?: boolean; children: React.ReactNode }) {
  const cls = {
    ok: "bg-ok-soft text-ok",
    na: "bg-na-soft text-muted",
    amber: "bg-amber-soft text-amber-ink",
    brand: "bg-brand-soft text-brand-strong",
    bad: "bg-bad-soft text-bad",
  }[tone];
  return (
    <span className={`inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-bold ${cls}`}>
      {dot && <span className="h-1.5 w-1.5 rounded-full bg-current" />}
      {children}
    </span>
  );
}

export function Code({ children }: { children: React.ReactNode }) {
  return <span className="rounded-md border border-line bg-bg px-1.5 py-0.5 font-mono text-[11px] font-semibold text-muted">{children}</span>;
}

export function Kpi({ label, value }: { label: string; value: number | string }) {
  return (
    <div className="min-w-0 rounded-xl border border-line bg-surface px-3 py-2.5 shadow-sm sm:px-4 lg:min-w-[8.5rem]">
      <dt className="truncate text-xs text-muted">{label}</dt>
      <dd className="font-display text-xl font-bold">{value}</dd>
    </div>
  );
}

export function EmptyState({ text, onClear, icon = "search" }: { text: string; onClear?: () => void; icon?: string }) {
  return (
    <div className="flex flex-col items-center gap-3 p-12 text-center text-muted">
      <Icon name={icon} size={28} />
      <p>{text}</p>
      {onClear && (
        <Button variant="ghost" size="sm" onClick={onClear}>
          Limpiar filtros
        </Button>
      )}
    </div>
  );
}

/** Encabezado de pantalla: ruta, ícono, título, descripción y un espacio a la derecha (indicadores o acciones). */
export function PageHeader({
  crumbs,
  icon,
  title,
  description,
  aside,
  children,
}: {
  crumbs: [string, string?][];
  icon: string;
  title: React.ReactNode;
  description?: React.ReactNode;
  aside?: React.ReactNode;
  children?: React.ReactNode;
}) {
  // La raíz sin enlace («Panel de la plataforma», «Mi empresa») ya se ve en el menú y la barra superior: no se repite.
  // Solo se muestra la ruta en subpáginas, con un botón para volver al nivel anterior.
  const path = crumbs.length > 1 && !crumbs[0][1] ? crumbs.slice(1) : crumbs;
  const back = [...path.slice(0, -1)].reverse().find(([, href]) => !!href);
  return (
    <header className="space-y-4">
      {path.length > 1 && (
        <nav className="flex flex-wrap items-center gap-2 text-sm" aria-label="Ruta">
          {back && (
            <Link href={back[1]!} className="grid h-8 w-8 place-items-center rounded-lg border border-line bg-surface text-muted shadow-sm transition-colors hover:border-brand hover:text-brand" aria-label={`Volver a ${back[0]}`} title={`Volver a ${back[0]}`}>
              <Icon name="back" size={16} />
            </Link>
          )}
          <ol className="flex min-w-0 flex-wrap items-center gap-1 text-muted">
            {path.map(([label, href], i) => (
              <li key={label} className="inline-flex min-w-0 items-center gap-1">
                {i > 0 && <span aria-hidden="true" className="text-line">/</span>}
                {href && i < path.length - 1 ? (
                  <Link href={href} className="rounded-md px-1.5 py-0.5 hover:bg-brand-soft hover:text-brand-strong">
                    {label}
                  </Link>
                ) : (
                  <span aria-current={i === path.length - 1 ? "page" : undefined} className={`truncate px-1.5 py-0.5 ${i === path.length - 1 ? "font-semibold text-fg" : ""}`}>
                    {label}
                  </span>
                )}
              </li>
            ))}
          </ol>
        </nav>
      )}
      <div className="flex flex-wrap items-start justify-between gap-6">
        <div className="flex min-w-0 gap-4">
          <span className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-brand text-white shadow-md shadow-brand/30">
            <Icon name={icon} size={24} />
          </span>
          <div className="min-w-0">
            <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
            {description && <div className="mt-1 max-w-2xl text-muted">{description}</div>}
            {children}
          </div>
        </div>
        {aside}
      </div>
    </header>
  );
}

const STAT_TONES: Record<ChipTone, { tile: string; tileOn: string; on: string }> = {
  brand: { tile: "bg-brand-soft text-brand-strong", tileOn: "bg-brand text-white", on: "border-brand bg-brand-soft shadow-lg shadow-brand/25" },
  ok: { tile: "bg-ok-soft text-ok", tileOn: "bg-ok text-white", on: "border-ok bg-ok-soft shadow-lg shadow-ok/25" },
  amber: { tile: "bg-amber-soft text-amber-ink", tileOn: "bg-amber-ink text-white", on: "border-amber-ink bg-amber-soft shadow-lg shadow-amber-ink/25" },
  bad: { tile: "bg-bad-soft text-bad", tileOn: "bg-bad text-white", on: "border-bad bg-bad-soft shadow-lg shadow-bad/25" },
  na: { tile: "bg-na-soft text-muted", tileOn: "bg-muted text-white", on: "border-muted bg-na-soft shadow-lg shadow-muted/25" },
};

/**
 * Tarjeta de resumen que también filtra: al presionarla muestra solo lo que cuenta
 * (y al presionarla otra vez quita el filtro). La seleccionada toma el color de la tarjeta, sombreado.
 */
export function StatCard({
  label,
  value,
  icon,
  tone = "brand",
  hint,
  active,
  onClick,
}: {
  label: string;
  value: number | string;
  icon: string;
  tone?: ChipTone;
  hint?: string;
  active?: boolean;
  onClick?: () => void;
}) {
  const t = STAT_TONES[tone];
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={`flex min-w-0 items-center gap-3 rounded-2xl border p-3.5 text-left transition-all duration-200 hover:-translate-y-0.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:p-4 ${
        active ? `-translate-y-0.5 ${t.on}` : "border-line bg-surface shadow-sm hover:shadow-md"
      }`}
    >
      <span className={`grid h-11 w-11 shrink-0 place-items-center rounded-xl transition-colors ${active ? t.tileOn : t.tile}`}>
        <Icon name={icon} size={21} />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate font-display text-2xl font-bold leading-none tabular-nums">{value}</span>
        <span className="mt-1 block truncate text-sm font-semibold">{label}</span>
        {hint && <span className="block truncate text-xs text-muted">{hint}</span>}
      </span>
    </button>
  );
}

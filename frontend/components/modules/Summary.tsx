"use client";

// Piezas comunes de los resúmenes de módulo (prototipo app.js: kpi, anillo, sem, barra, chip): tarjetas planas con
// borde, números grandes de color y semáforo verde / ámbar / rojo (docs/07 §1).

import Link from "next/link";

export type Tone = "ok" | "warn" | "bad" | "na";

/** Semáforo por porcentaje: 90 % o más verde, 70 % a 89 % ámbar, menos rojo. */
export const toneOf = (pct: number | null | undefined): Tone => (pct == null ? "na" : pct >= 90 ? "ok" : pct >= 70 ? "warn" : "bad");

export const TONE_DOT: Record<Tone, string> = { ok: "bg-ok", warn: "bg-[#F4B90F]", bad: "bg-bad", na: "bg-na" };
export const TONE_TEXT: Record<Tone, string> = { ok: "text-ok", warn: "text-amber-ink", bad: "text-bad", na: "text-muted" };
export const TONE_CHIP: Record<Tone, string> = { ok: "bg-ok-soft text-ok", warn: "bg-amber-soft text-amber-ink", bad: "bg-bad-soft text-bad", na: "bg-na-soft text-muted" };

export const fmtNum = (n: number | null | undefined, digits = 0) =>
  n == null ? "—" : n.toLocaleString("es-PE", { minimumFractionDigits: digits, maximumFractionDigits: digits });

export function Kpi({ label, value, color = "text-brand", sub, href }: { label: string; value: string; color?: string; sub?: string; href?: string }) {
  const body = (
    <>
      <p className="text-sm text-muted">{label}</p>
      <p className={`font-display text-3xl font-bold ${color}`}>{value}</p>
      {sub && <p className="text-xs text-muted">{sub}</p>}
    </>
  );
  return href ? (
    <Link href={href} className="block rounded-xl border border-line bg-surface p-4 hover:border-brand/40">{body}</Link>
  ) : (
    <div className="rounded-xl border border-line bg-surface p-4">{body}</div>
  );
}

export function Dot({ tone }: { tone: Tone }) {
  return <span className={`inline-block h-2.5 w-2.5 shrink-0 rounded-full ${TONE_DOT[tone]}`} aria-hidden="true" />;
}

export function Chip({ tone, children }: { tone: Tone; children: React.ReactNode }) {
  return (
    <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold ${TONE_CHIP[tone]}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${TONE_DOT[tone]}`} aria-hidden="true" />
      {children}
    </span>
  );
}

export function Bar({ pct, tone }: { pct: number; tone?: Tone }) {
  return (
    <div className="h-2 w-full overflow-hidden rounded-full bg-bg" role="presentation">
      <div className={`h-full ${TONE_DOT[tone ?? toneOf(pct)]}`} style={{ width: `${Math.max(0, Math.min(100, pct))}%` }} />
    </div>
  );
}

/** Anillo de puntaje (prototipo «anillo»). */
export function Ring({ pct, label, size = 112 }: { pct: number | null; label: string; size?: number }) {
  const r = 42;
  const c = 2 * Math.PI * r;
  const tone = toneOf(pct);
  const stroke = tone === "ok" ? "var(--ok)" : tone === "warn" ? "#F4B90F" : tone === "bad" ? "var(--bad)" : "var(--na)";
  return (
    <div className="relative shrink-0" style={{ width: size, height: size }} role="img" aria-label={`${label}: ${pct ?? "sin datos"}`}>
      <svg viewBox="0 0 100 100" width={size} height={size} className="-rotate-90">
        <circle cx="50" cy="50" r={r} fill="none" stroke="var(--line)" strokeWidth="10" />
        <circle cx="50" cy="50" r={r} fill="none" stroke={stroke} strokeWidth="10" strokeLinecap="round" strokeDasharray={`${((pct ?? 0) / 100) * c} ${c}`} />
      </svg>
      <div className="absolute inset-0 grid place-items-center text-center">
        <div>
          <p className="font-display text-2xl font-bold leading-none">{pct ?? "—"}</p>
          <p className="text-[11px] text-muted">{label}</p>
        </div>
      </div>
    </div>
  );
}

export function Card({ title, action, children, className = "" }: { title?: string; action?: React.ReactNode; children: React.ReactNode; className?: string }) {
  return (
    <section className={`rounded-xl border border-line bg-surface p-4 ${className}`}>
      {(title || action) && (
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          {title && <h3 className="font-display text-base font-semibold">{title}</h3>}
          {action}
        </div>
      )}
      {children}
    </section>
  );
}

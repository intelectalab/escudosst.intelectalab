// Piezas de sección reutilizables del sitio: encabezado de sección, CTA final y tarjetas de planes.

import Link from "next/link";
import { Icon } from "@/components/ui/Icon";
import type { BillingPeriod, PublicPlan, SiteSettings } from "@/lib/site";
import { contactHref, periodPrice, soles } from "@/lib/site";

export function SectionTitle({ eyebrow, title, text, center = true }: { eyebrow?: string; title: string; text?: string; center?: boolean }) {
  return (
    <div className={`max-w-3xl space-y-3 ${center ? "mx-auto text-center" : ""}`}>
      {eyebrow && <p className="site-eyebrow">{eyebrow}</p>}
      <h2 className="font-display text-[28px] font-bold leading-tight text-[var(--site-navy-title)] sm:text-[36px]">{title}</h2>
      {text && <p className="text-[17px] leading-relaxed text-[var(--site-text-2)]">{text}</p>}
    </div>
  );
}

export function PageHero({ eyebrow, title, text, children }: { eyebrow: string; title: string; text: string; children?: React.ReactNode }) {
  return (
    <section className="relative overflow-hidden bg-[var(--site-navy)] text-white">
      <div className="site-dots absolute inset-0" aria-hidden="true" />
      <div className="absolute -right-24 -top-24 h-80 w-80 rounded-full bg-[#1F7FD1]/40 blur-3xl" aria-hidden="true" />
      <div className="relative mx-auto max-w-[1360px] px-4 py-16 sm:py-20 lg:px-8">
        <p className="site-eyebrow !text-[var(--site-amber)]">{eyebrow}</p>
        <h1 className="mt-3 max-w-3xl font-display text-[32px] font-bold leading-[1.15] sm:text-[44px]">{title}</h1>
        <p className="mt-4 max-w-2xl text-[17px] leading-relaxed text-white/80">{text}</p>
        {children}
      </div>
      <div className="site-stripe h-1.5" aria-hidden="true" />
    </section>
  );
}

export function FinalCta({ s }: { s: SiteSettings }) {
  return (
    <section className="px-4 py-16 lg:px-8">
      <div className="relative mx-auto max-w-[1360px] overflow-hidden rounded-3xl bg-gradient-to-br from-[#0D3B6E] via-[#0f4a8a] to-[#1F7FD1] px-6 py-14 text-center text-white sm:px-12">
        <div className="site-dots absolute inset-0" aria-hidden="true" />
        <div className="site-stripe absolute inset-x-0 top-0 h-1.5" aria-hidden="true" />
        <div className="relative mx-auto max-w-2xl space-y-5">
          <h2 className="font-display text-[28px] font-bold leading-tight sm:text-[38px]">Deja el Excel y el papel. Ordena tu SST en un solo lugar.</h2>
          <p className="text-[17px] text-white/80">Regístrate con tu RUC y empieza con tu línea base. Te acompañamos en el camino.</p>
          <div className="flex flex-col justify-center gap-3 sm:flex-row">
            <a href={contactHref(s, "trial")} data-track="cta-final-prueba" className="site-btn site-btn-light">
              Prueba gratis <Icon name="chevron" size={16} />
            </a>
            <a href={contactHref(s, "demo")} data-track="cta-final-demo" className="site-btn site-btn-ghost-light">
              Agenda una demo
            </a>
          </div>
        </div>
      </div>
    </section>
  );
}

const PLAN_STYLE: Record<string, { ring: string; badge?: string; bar: string }> = {
  E: { ring: "", bar: "#22A35A" },
  P: { ring: "ring-2 ring-[var(--site-action)]", badge: "Recomendado", bar: "#1F7FD1" },
  C: { ring: "", bar: "#0D3B6E" },
  K: { ring: "", bar: "#F4B90F" },
};

const LIMIT_TEXT: [string, string, string][] = [
  ["max_workers", "trabajadores", "Sin límite de trabajadores"],
  ["max_sites", "sedes", "Sedes sin límite"],
  ["max_companies", "empresas", "Empresas sin límite"],
];

export function PlanCards({
  plans,
  s,
  compact = false,
  period = "monthly",
  discount = 0,
}: {
  plans: PublicPlan[];
  s: SiteSettings;
  compact?: boolean;
  period?: BillingPeriod;
  discount?: number;
}) {
  return (
    <div className="grid-cols-1 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
      {plans.map((p) => {
        const st = PLAN_STYLE[p.code] ?? PLAN_STYLE.C;
        return (
          <article key={p.code} className={`site-card site-card-hover relative flex flex-col overflow-hidden p-6 ${st.ring}`}>
            <span className="absolute inset-x-0 top-0 h-1.5" style={{ background: st.bar }} aria-hidden="true" />
            <div className="flex items-start justify-between gap-2">
              <h3 className="font-display text-xl font-bold text-[var(--site-navy-title)]">{p.name}</h3>
              {st.badge && <span className="rounded-md bg-[var(--site-action)] px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-white">{st.badge}</span>}
              {p.code === "K" && <span className="rounded-md bg-[var(--site-amber)]/25 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-[#8A6704]">Consultoras</span>}
            </div>
            <p className="mt-2 min-h-[3rem] text-sm leading-relaxed text-[var(--site-text-2)]">{p.description}</p>
            <div className="my-5">
              {p.price ? (
                <>
                  <p className="font-display text-3xl font-bold text-[var(--site-navy-title)]">
                    {soles(periodPrice(Number(p.price), period, discount))}
                    <span className="text-sm font-normal text-[var(--site-text-2)]">
                      {" "}
                      /{period === "annual" ? "año" : "mes"}
                      {p.code === "K" ? " por empresa" : ""}
                    </span>
                  </p>
                  {period === "annual" && (
                    <p className="mt-1 text-sm text-[var(--site-text-2)]">
                      Equivale a {soles(periodPrice(Number(p.price), "annual", discount) / 12)} al mes
                      {discount > 0 && <span className="ml-1.5 rounded bg-[#22A35A]/12 px-1.5 py-0.5 text-xs font-bold text-[#167A42]">Ahorras {discount}%</span>}
                    </p>
                  )}
                </>
              ) : (
                <p className="font-display text-2xl font-bold text-[var(--site-navy-title)]">{p.price_label ?? "Solicitar cotización"}</p>
              )}
            </div>
            {!compact && (
              <ul className="mb-6 space-y-2 text-sm">
                <li className="flex gap-2">
                  <Icon name="check" size={16} className="mt-0.5 shrink-0 text-[#22A35A]" />
                  {p.modules_count} módulos incluidos
                </li>
                {LIMIT_TEXT.filter(([k]) => p.code === "K" || k !== "max_companies").map(([k, unit, none]) => (
                  <li key={k} className="flex gap-2">
                    <Icon name="check" size={16} className="mt-0.5 shrink-0 text-[#22A35A]" />
                    {p.limits[k] ? `Hasta ${p.limits[k]} ${unit}` : none}
                  </li>
                ))}
              </ul>
            )}
            <a href={contactHref(s, "quote", p.code)} data-track={`plan-${p.code}`} className={`site-btn mt-auto w-full ${p.code === "P" ? "site-btn-primary" : "site-btn-outline"}`}>
              {p.price ? "Contratar" : "Solicitar cotización"}
            </a>
          </article>
        );
      })}
    </div>
  );
}

export function TrustSeals() {
  // Solo los sellos veraces del kit (docs/07 §1.5, corrección 1).
  const seals: [string, string][] = [
    ["shield", "Pensado para la Ley 29783"],
    ["lock", "Datos personales según la Ley 29733"],
    ["database", "Datos cifrados en la nube"],
    ["people", "Soporte desde Perú"],
  ];
  return (
    <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      {seals.map(([icon, text]) => (
        <li key={text} className="flex items-center gap-3 rounded-xl border border-[var(--site-border)] bg-white px-4 py-3 text-sm font-semibold text-[var(--site-navy)]">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--site-surface)] text-[var(--site-action)]">
            <Icon name={icon} size={18} />
          </span>
          {text}
        </li>
      ))}
    </ul>
  );
}

export function TextLink({ href, children }: { href: string; children: React.ReactNode }) {
  return (
    <Link href={href} className="inline-flex items-center gap-1 font-bold text-[var(--site-action)] hover:underline">
      {children} <Icon name="chevron" size={15} />
    </Link>
  );
}

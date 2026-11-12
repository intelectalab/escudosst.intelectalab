// SCR-WEB-07 · Complementos: se contratan aparte sobre cualquier plan. Precio del panel o «Solicitar cotización».

import type { Metadata } from "next";
import { FinalCta, PageHero } from "@/components/site/Sections";
import { Icon } from "@/components/ui/Icon";
import { contactHref, getPricing, getSite } from "@/lib/site";

export const metadata: Metadata = {
  title: "Complementos · EscudoSST",
  description: "Asistente IA, app móvil offline, paquetes sectoriales, gestión del cambio, sistema integrado ISO, medio ambiente y homologación de proveedores.",
};

const ADDON_ART: Record<string, { icon: string; from: string; to: string }> = {
  IAA: { icon: "star", from: "#6250D6", to: "#1b6fb7" },
  OFF: { icon: "eye", from: "#1FB5A3", to: "#0D3B6E" },
  PSE: { icon: "box", from: "#F4B90F", to: "#EB6834" },
  MOC: { icon: "swap", from: "#1b6fb7", to: "#0D3B6E" },
  ISO: { icon: "shield", from: "#0D3B6E", to: "#1b6fb7" },
  AMB: { icon: "globe", from: "#22A35A", to: "#1FB5A3" },
  HOM: { icon: "building", from: "#EB6834", to: "#E2453C" },
};

export default async function ComplementosPage() {
  const [site, pricing] = await Promise.all([getSite(), getPricing()]);
  const s = site.settings;

  return (
    <>
      <PageHero eyebrow="Complementos" title="Suma lo que tu empresa necesita" text="Los complementos se agregan a cualquier plan y se activan al momento." />
      <section className="bg-[var(--site-surface)] px-4 py-14 lg:px-8">
        <ul className="grid-cols-1 mx-auto grid max-w-[1360px] gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {pricing.addons.map((a) => {
            const art = ADDON_ART[a.code] ?? { icon: "box", from: "#1b6fb7", to: "#0D3B6E" };
            return (
              <li key={a.code} className="site-card site-card-hover flex flex-col overflow-hidden">
                <div className="relative flex h-28 items-end p-5 text-white" style={{ background: `linear-gradient(135deg, ${art.from}, ${art.to})` }}>
                  <div className="site-dots absolute inset-0" aria-hidden="true" />
                  <span className="relative grid h-12 w-12 place-items-center rounded-xl bg-white/20 backdrop-blur">
                    <Icon name={art.icon} size={24} />
                  </span>
                  {!a.available && <span className="absolute right-4 top-4 rounded-md bg-white/90 px-2 py-0.5 text-[11px] font-bold text-[var(--site-navy)]">Próximamente</span>}
                </div>
                <div className="flex flex-1 flex-col p-5">
                  <h2 className="font-display text-lg font-bold text-[var(--site-navy-title)]">{a.name}</h2>
                  <p className="mt-1.5 flex-1 text-sm leading-relaxed text-[var(--site-text-2)]">{a.description}</p>
                  <div className="mt-5 flex items-center justify-between gap-3 border-t border-[var(--site-border)] pt-4">
                    <span className="font-display font-bold text-[var(--site-navy-title)]">
                      {a.price ? `S/ ${Number(a.price).toLocaleString("es-PE")} /mes` : (a.price_label ?? "Solicitar cotización")}
                    </span>
                    <a href={contactHref(s, "quote", a.name)} className="inline-flex items-center gap-1 text-sm font-bold text-[var(--site-action)] hover:underline">
                      Consultar <Icon name="chevron" size={14} />
                    </a>
                  </div>
                </div>
              </li>
            );
          })}
        </ul>
      </section>
      <FinalCta s={s} />
    </>
  );
}

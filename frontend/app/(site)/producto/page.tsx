// SCR-WEB-02 · Producto y módulos: todos los módulos por grupo del catálogo, con qué resuelve cada uno y desde qué plan.

import type { Metadata } from "next";
import { ModuleExplorer } from "@/components/site/ModuleExplorer";
import { FinalCta, PageHero } from "@/components/site/Sections";
import { CATALOG_GROUPS } from "@/lib/plans";
import { getModules, getPricing, getSite } from "@/lib/site";

export const metadata: Metadata = {
  title: "Producto y módulos · EscudoSST",
  description: "Todos los módulos de EscudoSST para gestionar la seguridad y salud en el trabajo: planificación, riesgos, campo, personas, eventos, contratistas y SUNAFIL.",
};

export default async function ProductoPage() {
  const [site, modules, pricing] = await Promise.all([getSite(), getModules(), getPricing()]);
  const visible = modules.filter((m) => !["TEN", "USR", "AUD", "SUS", "WEB"].includes(m.code));
  const planNames = Object.fromEntries(pricing.plans.map((p) => [p.code, p.name]));

  return (
    <>
      <PageHero
        eyebrow="Producto"
        title="Todo lo que necesitas para gestionar la SST de tu empresa"
        text={`${visible.length} módulos organizados por área. Busca el que necesitas o filtra por plan para ver qué incluye cada uno.`}
      />
      <section className="bg-[var(--site-surface)] px-4 py-14 lg:px-8">
        <div className="mx-auto max-w-[1360px]">
          <ModuleExplorer modules={visible} groups={CATALOG_GROUPS} planNames={planNames} />
        </div>
      </section>
      <FinalCta s={site.settings} />
    </>
  );
}

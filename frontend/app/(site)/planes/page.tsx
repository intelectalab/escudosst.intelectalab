// SCR-WEB-06 · Planes y comparativa. Todo sale de Planes y módulos del panel: lo que el dueño cambia ahí,
// cambia aquí. Sin precio configurado = «Solicitar cotización» (RN-SUS-01).

import type { Metadata } from "next";
import Link from "next/link";
import { PlanComparison } from "@/components/site/PlanComparison";
import { PlanPricing } from "@/components/site/PlanPricing";
import { FinalCta, PageHero, SectionTitle } from "@/components/site/Sections";
import { Icon } from "@/components/ui/Icon";
import { CATALOG_GROUPS } from "@/lib/plans";
import { getPricing, getSite } from "@/lib/site";

export const metadata: Metadata = {
  title: "Planes · EscudoSST",
  description: "Planes Esencial, Profesional, Corporativo y Consultora de EscudoSST. Compara qué incluye cada uno, módulo por módulo.",
};

export default async function PlanesPage() {
  const [site, pricing] = await Promise.all([getSite(), getPricing()]);
  const s = site.settings;

  return (
    <>
      <PageHero eyebrow="Planes" title="Elige el plan según el tamaño de tu empresa" text="Cada plan incluye todo lo del anterior. Si tu empresa crece, subes de plan sin perder nada de lo registrado.">
        <p className="mt-6 inline-flex items-center gap-2 rounded-lg bg-white/10 px-3 py-2 text-sm text-white/85">
          <Icon name="shield" size={16} /> Los rangos de trabajadores son referenciales.
        </p>
      </PageHero>

      <section className="bg-[var(--site-surface)] px-4 py-14 lg:px-8">
        <div className="mx-auto max-w-[1360px] space-y-6">
          {pricing.plans.length ? <PlanPricing plans={pricing.plans} s={s} discount={pricing.annual_discount_pct ?? 0} /> : <p className="text-center text-[var(--site-text-2)]">Los planes se publican pronto.</p>}
          <p className="text-center text-sm text-[var(--site-text-2)]">
            ¿Necesitas IA, app offline o plantillas de tu sector?{" "}
            <Link href="/complementos" className="font-bold text-[var(--site-action)] hover:underline">
              Mira los complementos
            </Link>
          </p>
        </div>
      </section>

      {pricing.comparison.length > 0 && (
        <section id="comparativa" className="scroll-mt-24 px-4 py-16 lg:px-8">
          <div className="mx-auto max-w-[1360px] space-y-10">
            <SectionTitle eyebrow="Comparativa" title="Qué incluye cada plan, módulo por módulo" />
            <PlanComparison pricing={pricing} groups={CATALOG_GROUPS} />
          </div>
        </section>
      )}

      <FinalCta s={s} />
    </>
  );
}

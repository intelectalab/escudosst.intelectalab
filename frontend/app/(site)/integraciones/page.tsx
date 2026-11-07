// SCR-WEB-73 · Integraciones: con qué se conecta EscudoSST, con su estado real (Disponible, Beta o Planificada).
// Se editan en el panel: Sitio web › Integraciones.

import type { Metadata } from "next";
import { IntegrationsBrowser } from "@/components/site/IntegrationsBrowser";
import { FinalCta, PageHero } from "@/components/site/Sections";
import { getIntegrations, getSite } from "@/lib/site";

export const metadata: Metadata = {
  title: "Integraciones · EscudoSST",
  description: "Con qué herramientas se conecta EscudoSST: correo, calendario, pagos, CRM y más, con su estado real.",
};

export default async function IntegracionesPage() {
  const [items, site] = await Promise.all([getIntegrations(), getSite()]);
  return (
    <>
      <PageHero eyebrow="Integraciones" title="EscudoSST se conecta con lo que ya usas" text="Cada integración indica su estado real: disponible hoy, en prueba (beta) o planificada." />
      <section className="px-4 py-12 lg:px-8">
        <div className="mx-auto max-w-[1200px]">
          <IntegrationsBrowser items={items} />
        </div>
      </section>
      <FinalCta s={site.settings} />
    </>
  );
}

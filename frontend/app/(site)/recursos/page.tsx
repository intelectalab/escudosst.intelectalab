// Biblioteca de recursos (SCR-WEB-30): plantillas, guías, listas de verificación y formatos SST para descargar.

import type { Metadata } from "next";
import { type PublicResource, ResourcesBrowser } from "@/components/site/ResourcesBrowser";
import { FinalCta, PageHero } from "@/components/site/Sections";
import { getResources, getSite } from "@/lib/site";

export const metadata: Metadata = {
  title: "Recursos SST gratis · EscudoSST",
  description: "Plantillas, guías, listas de verificación y formatos de seguridad y salud en el trabajo para descargar gratis.",
};

export default async function RecursosPage() {
  const [items, site] = await Promise.all([getResources(), getSite()]);
  return (
    <>
      <PageHero eyebrow="Recursos" title="Plantillas y guías de SST gratis" text="Formatos listos para usar en tu empresa. Algunos se descargan al momento y otros solo te piden tus datos." />
      <section className="px-4 py-12 lg:px-8">
        <div className="mx-auto max-w-[1200px]">
          <ResourcesBrowser items={items as PublicResource[]} />
        </div>
      </section>
      <FinalCta s={site.settings} />
    </>
  );
}

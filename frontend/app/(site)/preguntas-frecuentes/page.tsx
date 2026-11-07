// SCR-WEB-11 · Preguntas frecuentes: acordeón por tema y datos estructurados FAQPage.

import type { Metadata } from "next";
import { FaqJsonLd, FaqList } from "@/components/site/FaqTestimonials";
import { FinalCta, PageHero } from "@/components/site/Sections";
import { getFaqs, getSite } from "@/lib/site";

export const metadata: Metadata = {
  title: "Preguntas frecuentes · EscudoSST",
  description: "Respuestas a las dudas más comunes sobre EscudoSST: producto, planes, pagos, seguridad y datos personales.",
};

export default async function FaqPage() {
  const [faqs, site] = await Promise.all([getFaqs(), getSite()]);
  return (
    <>
      <PageHero eyebrow="Preguntas frecuentes" title="Resolvemos tus dudas" text="Lo que más nos preguntan sobre EscudoSST. Si no encuentras la tuya, escríbenos." />
      <section className="px-4 py-14 lg:px-8">
        <div className="mx-auto max-w-3xl">
          {faqs.length > 0 ? (
            <>
              <FaqJsonLd faqs={faqs} />
              <FaqList faqs={faqs} grouped />
            </>
          ) : (
            <p className="text-center text-[var(--site-text-2)]">Pronto publicaremos las preguntas frecuentes. Mientras tanto, escríbenos desde «Contacto».</p>
          )}
        </div>
      </section>
      <FinalCta s={site.settings} />
    </>
  );
}

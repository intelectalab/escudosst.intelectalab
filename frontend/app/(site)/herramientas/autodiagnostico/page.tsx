// Autodiagnóstico Ley 29783 (SCR-WEB-29): mide el nivel de cumplimiento en SST de la empresa del visitante.

import type { Metadata } from "next";
import { PageHero } from "@/components/site/Sections";
import { SelfAssessment } from "@/components/site/SelfAssessment";

export const metadata: Metadata = {
  title: "Autodiagnóstico Ley 29783 · EscudoSST",
  description: "Responde unas preguntas y conoce el nivel de cumplimiento en seguridad y salud en el trabajo de tu empresa, con lo que te falta trabajar.",
};

export default function AutodiagnosticoPage() {
  return (
    <>
      <PageHero eyebrow="Herramientas gratis" title="Autodiagnóstico de la Ley 29783" text="En pocos minutos conoce cómo está tu empresa en seguridad y salud en el trabajo y qué te falta para cumplir." />
      <section className="px-4 py-12 lg:px-8">
        <div className="mx-auto max-w-[860px]">
          <SelfAssessment />
        </div>
      </section>
    </>
  );
}

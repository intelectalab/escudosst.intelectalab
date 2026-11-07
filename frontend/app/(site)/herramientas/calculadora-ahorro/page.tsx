// SCR-WEB-82 · Calculadora de ahorro frente a Excel y papel. Los supuestos se definen en el panel
// (Sitio web › Calculadora de ahorro) y se muestran junto al resultado.

import type { Metadata } from "next";
import { SavingsCalculator } from "@/components/site/SavingsCalculator";
import { PageHero } from "@/components/site/Sections";
import { getSavingsCalculator } from "@/lib/site";

export const metadata: Metadata = {
  title: "Calculadora de ahorro en SST · EscudoSST",
  description: "Estima cuántas horas al mes podrías ahorrar llevando tu SST en EscudoSST en lugar de Excel y papel. Estimación referencial.",
};

export default async function CalculadoraAhorroPage() {
  const calc = await getSavingsCalculator();
  return (
    <>
      <PageHero eyebrow="Herramienta gratuita" title="¿Cuánto tiempo podrías ahorrar en tu SST?" text="Ingresa unos datos de tu empresa y te mostramos una estimación de las horas al mes que hoy se van en registros manuales." />
      <section className="px-4 py-12 lg:px-8">
        <div className="mx-auto max-w-[1000px]">
          {calc?.available ? (
            <SavingsCalculator calc={calc} />
          ) : (
            <div className="site-card mx-auto max-w-xl p-8 text-center text-[var(--site-text-2)]">Esta calculadora estará disponible pronto. Mientras tanto, puedes usar la calculadora de multas o el autodiagnóstico.</div>
          )}
        </div>
      </section>
    </>
  );
}

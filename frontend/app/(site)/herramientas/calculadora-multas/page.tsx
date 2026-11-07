// Calculadora de multas SUNAFIL (SCR-WEB-28): muestra el riesgo económico de no cumplir la Ley 29783.

import type { Metadata } from "next";
import { FineCalculator } from "@/components/site/FineCalculator";
import { PageHero } from "@/components/site/Sections";

export const metadata: Metadata = {
  title: "Calculadora de multas SUNAFIL · EscudoSST",
  description: "Calcula el rango referencial de una multa SUNAFIL por incumplir la Ley 29783 según el tamaño de tu empresa, los trabajadores afectados y la gravedad.",
};

export default function CalculadoraMultasPage() {
  return (
    <>
      <PageHero eyebrow="Herramientas gratis" title="Calculadora de multas SUNAFIL" text="Descubre cuánto podría costarle a tu empresa no cumplir la Ley 29783 de seguridad y salud en el trabajo." />
      <section className="px-4 py-12 lg:px-8">
        <div className="mx-auto max-w-[1100px]">
          <FineCalculator />
        </div>
      </section>
    </>
  );
}

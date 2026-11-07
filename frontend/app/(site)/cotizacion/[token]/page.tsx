// Cotización del cliente (enlace personal que le llega por correo): la revisa, la acepta o no, ve los datos
// para pagar y envía su constancia. No se indexa: cada enlace es privado.

import type { Metadata } from "next";
import { QuoteClient } from "@/components/site/QuoteClient";

export const metadata: Metadata = {
  title: "Tu cotización · EscudoSST",
  robots: { index: false, follow: false },
  referrer: "no-referrer",
};

export default async function CotizacionPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  return (
    <section className="bg-[var(--site-surface)] py-10 sm:py-14">
      <div className="mx-auto max-w-[920px] px-4 lg:px-8">
        <QuoteClient token={token} />
      </div>
    </section>
  );
}

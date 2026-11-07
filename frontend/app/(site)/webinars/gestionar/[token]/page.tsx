// Cancelar la inscripción a un webinar con el enlace del correo (SCR-WEB-78). No se indexa.

import type { Metadata } from "next";
import { WebinarCancel } from "@/components/site/WebinarForms";

export const metadata: Metadata = { title: "Tu inscripción · EscudoSST", robots: { index: false, follow: false }, referrer: "no-referrer" };

export default async function GestionarWebinarPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  return (
    <section className="px-4 py-16 lg:px-8">
      <div className="site-card mx-auto max-w-lg space-y-3 p-8">
        <h1 className="text-center font-display text-2xl font-bold text-[var(--site-navy-title)]">Tu inscripción al webinar</h1>
        <WebinarCancel token={token} />
      </div>
    </section>
  );
}

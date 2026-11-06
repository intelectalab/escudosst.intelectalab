// Retiro de la autorización de un caso de éxito (enlace del correo de confirmación). Oculta el caso al momento.

import type { Metadata } from "next";
import { StoryWithdrawForm } from "@/components/site/StoryShareForm";

export const metadata: Metadata = { title: "Retirar autorización · EscudoSST", robots: { index: false, follow: false } };

export default async function RetirarCasoPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  return (
    <section className="bg-gradient-to-b from-[var(--site-surface)] to-white px-4 py-14 sm:py-20">
      <div className="mx-auto max-w-xl space-y-6">
        <div className="space-y-2 text-center">
          <p className="site-eyebrow">Casos de éxito</p>
          <h1 className="font-display text-[30px] font-bold text-[var(--site-navy-title)]">Retirar mi autorización</h1>
        </div>
        <StoryWithdrawForm token={token} />
      </div>
    </section>
  );
}

// SCR-WEB-18 · Seguimiento de una hoja del Libro de Reclamaciones con código + clave de la constancia.

import type { Metadata } from "next";
import Link from "next/link";
import { TrackingForm } from "@/components/site/ComplaintForms";

export const metadata: Metadata = { title: "Estado de mi reclamo · EscudoSST", robots: { index: false } };

export default async function SeguimientoPage({ searchParams }: { searchParams: Promise<{ codigo?: string }> }) {
  const { codigo } = await searchParams;
  return (
    <section className="bg-gradient-to-b from-[var(--site-surface)] to-white px-4 py-12 sm:py-16 lg:px-8">
      <div className="mx-auto max-w-2xl space-y-6">
        <div className="space-y-3 text-center">
          <p className="site-eyebrow">Libro de Reclamaciones</p>
          <h1 className="font-display text-[32px] font-bold leading-tight text-[var(--site-navy-title)] sm:text-[40px]">Consulta el estado de tu hoja</h1>
          <p className="text-[16px] text-[var(--site-text-2)]">Usa el código y la clave de seguimiento de tu constancia.</p>
        </div>
        <TrackingForm initialCode={(codigo ?? "").toUpperCase().slice(0, 20)} />
        <p className="text-center text-sm">
          <Link href="/libro-de-reclamaciones" className="font-bold text-[var(--site-action)] hover:underline">
            Registrar una hoja nueva
          </Link>
        </p>
      </div>
    </section>
  );
}

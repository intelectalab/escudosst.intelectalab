// Agenda pública de demos (SCR-WEB-51): elegir un horario libre y reservar.

import type { Metadata } from "next";
import Link from "next/link";
import { DemoBooking } from "@/components/site/DemoBooking";

export const metadata: Metadata = {
  title: "Agenda tu demo · EscudoSST",
  description: "Elige el día y la hora de tu demostración de EscudoSST. Te confirmamos por correo.",
};

export default function AgendarDemoPage() {
  return (
    <section className="relative overflow-hidden bg-gradient-to-b from-[var(--site-surface)] to-white px-4 py-10 sm:py-14 lg:px-8">
      <div className="absolute -right-32 top-10 h-72 w-72 rounded-full bg-[#1b6fb7]/12 blur-3xl" aria-hidden="true" />
      <div className="relative mx-auto max-w-[1200px] space-y-8">
        <div className="max-w-2xl space-y-2">
          <Link href="/demo" className="text-sm font-bold text-[var(--site-action)] hover:underline">
            ← Otras formas de contactarnos
          </Link>
          <h1 className="font-display text-[30px] font-bold leading-tight text-[var(--site-navy-title)] sm:text-[40px]">Agenda tu demo</h1>
          <p className="text-[17px] text-[var(--site-text-2)]">Elige el horario que te acomode. Te atiende un asesor comercial o un especialista SST de nuestro equipo.</p>
        </div>
        <DemoBooking />
      </div>
    </section>
  );
}

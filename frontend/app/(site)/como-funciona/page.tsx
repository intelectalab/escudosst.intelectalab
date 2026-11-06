// SCR-WEB-05 · Cómo funciona: el recorrido en pasos numerados con ilustraciones propias.
// Sin tiempos prometidos (docs/07 §1.5, corrección 2).

import type { Metadata } from "next";
import { StepArt } from "@/components/site/Mockups";
import { FinalCta, PageHero } from "@/components/site/Sections";
import { Icon } from "@/components/ui/Icon";
import { getSite } from "@/lib/site";

export const metadata: Metadata = {
  title: "Cómo funciona · EscudoSST",
  description: "Del registro con tu RUC al expediente para SUNAFIL: así se implementa la gestión de SST con EscudoSST.",
};

const STEPS = [
  {
    title: "Regístrate con tu RUC",
    text: "Crea tu cuenta y completa la razón social y la dirección con tu RUC. Invita a tu equipo con el rol que corresponde a cada uno.",
    points: ["Cuenta para tu empresa (o varias, si eres un grupo)", "Usuarios con roles: jefe SST, comité, supervisores", "Verificación en dos pasos para proteger el acceso"],
  },
  {
    title: "Conoce tu punto de partida",
    text: "La línea base de la Ley 29783 te muestra qué cumples hoy y qué te falta, con un plan de acción para cerrar las brechas.",
    points: ["Diagnóstico por lineamiento", "Porcentaje de cumplimiento", "Brechas convertidas en tareas con responsable"],
  },
  {
    title: "Configura tu empresa",
    text: "Registra sedes, áreas, puestos y trabajadores, o carga tu Excel. Si tienes 20 trabajadores o más, EscudoSST te propone Comité SST; si no, Supervisor SST.",
    points: ["Sedes y áreas con su organigrama", "Carga masiva de trabajadores", "Comité o Supervisor según tu tamaño"],
  },
  {
    title: "Opera el día a día",
    text: "Inspecciones, charlas, capacitaciones, entrega de EPP y reportes desde la web o el celular. Los vencimientos te avisan antes.",
    points: ["Inspecciones y reportes desde el celular", "Avisos de EMO, SCTR, EPP y capacitaciones", "Reportes del trabajador por QR o WhatsApp"],
  },
  {
    title: "Llega preparado a SUNAFIL",
    text: "Con el Modo Inspección generas el expediente por trabajador y periodo, ves qué falta y lo compartes con un enlace que vence.",
    points: ["Evidencias juntas por trabajador", "Lo que falta, con enlace para corregirlo", "Registros obligatorios al día"],
  },
];

export default async function ComoFuncionaPage() {
  const { settings } = await getSite();
  return (
    <>
      <PageHero eyebrow="Cómo funciona" title="Tu sistema de gestión SST, paso a paso" text="Así pasas del Excel y el papel a un sistema ordenado que te avisa, te guía y te deja listo para una inspección." />
      <section className="px-4 py-16 lg:px-8">
        <ol className="relative mx-auto max-w-5xl space-y-8 before:absolute before:bottom-10 before:left-[27px] before:top-10 before:hidden before:w-0.5 before:bg-gradient-to-b before:from-[#1F7FD1] before:to-[#1FB5A3] md:before:block">
          {STEPS.map((s, i) => (
            <li key={s.title} className="grid-cols-1 relative grid gap-5 md:grid-cols-[56px_1fr]">
              <span className="relative z-10 hidden h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-[#1F7FD1] to-[#0D3B6E] font-display text-xl font-bold text-white shadow-lg md:grid">{i + 1}</span>
              <article className="grid-cols-1 site-card site-card-hover grid items-center gap-6 p-6 sm:grid-cols-[1fr_auto] sm:p-8">
                <div className="space-y-3">
                  <p className="site-eyebrow md:hidden">Paso {i + 1}</p>
                  <h2 className="font-display text-2xl font-bold text-[var(--site-navy-title)]">{s.title}</h2>
                  <p className="text-[16px] leading-relaxed text-[var(--site-text-2)]">{s.text}</p>
                  <ul className="space-y-2 pt-1">
                    {s.points.map((p) => (
                      <li key={p} className="flex items-center gap-2.5 text-[15px] font-semibold">
                        <span className="grid h-5 w-5 place-items-center rounded-full bg-[#22A35A]/15 text-[#187A42]">
                          <Icon name="check" size={12} />
                        </span>
                        {p}
                      </li>
                    ))}
                  </ul>
                </div>
                <div className="grid place-items-center rounded-2xl bg-[var(--site-surface)] p-4">
                  <StepArt step={i + 1} />
                </div>
              </article>
            </li>
          ))}
        </ol>
      </section>
      <FinalCta s={settings} />
    </>
  );
}

// SCR-WEB-14 · «Prueba gratis»: registro autoservicio con RUC (docs/03 §9 y §10).
// Crea la cuenta en prueba (plan Profesional completo) y envía el correo para crear la contraseña.

import type { Metadata } from "next";
import { TrialForm } from "@/components/site/PublicForms";
import { Icon } from "@/components/ui/Icon";

export const metadata: Metadata = {
  title: "Prueba gratis · EscudoSST",
  description: "Crea tu cuenta de prueba de EscudoSST con el RUC de tu empresa y empieza a ordenar tu SST.",
};

const STEPS: [string, string, string][] = [
  ["building", "Registra tu empresa", "Con tu RUC y cuántos trabajadores tiene."],
  ["mail", "Crea tu contraseña", "Te llega un enlace a tu correo."],
  ["check", "Empieza tu línea base", "Te guiamos paso a paso con la R.M. 050-2013-TR."],
];

export default function PruebaGratisPage() {
  return (
    <section className="relative overflow-hidden bg-gradient-to-b from-[var(--site-surface)] to-white px-4 py-12 sm:py-16 lg:px-8">
      <div className="absolute -left-32 top-10 h-72 w-72 rounded-full bg-[#1F7FD1]/10 blur-3xl" aria-hidden="true" />
      <div className="relative mx-auto grid max-w-[1200px] grid-cols-1 gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-14">
        <div className="space-y-7 lg:pt-6">
          <div className="space-y-3">
            <p className="site-eyebrow">Prueba gratis</p>
            <h1 className="font-display text-[32px] font-bold leading-tight text-[var(--site-navy-title)] sm:text-[42px]">Prueba EscudoSST con tu propia empresa</h1>
            <p className="text-[17px] leading-relaxed text-[var(--site-text-2)]">Usas el plan Profesional completo con tus datos reales. Si luego contratas, todo lo que registres se queda.</p>
          </div>
          <ol className="space-y-3">
            {STEPS.map(([icon, title, text], i) => (
              <li key={title} className="flex items-start gap-4 rounded-2xl border border-[var(--site-border)] bg-white p-4 shadow-sm">
                <span className="relative grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[var(--site-navy)] text-white">
                  <Icon name={icon} size={20} />
                  <span className="absolute -right-1.5 -top-1.5 grid h-5 w-5 place-items-center rounded-full bg-[var(--site-amber)] text-[11px] font-bold text-[var(--site-navy-title)]">{i + 1}</span>
                </span>
                <span>
                  <b className="block font-display text-[var(--site-navy-title)]">{title}</b>
                  <span className="text-sm text-[var(--site-text-2)]">{text}</span>
                </span>
              </li>
            ))}
          </ol>
          <p className="flex items-center gap-2 text-sm text-[var(--site-text-2)]">
            <Icon name="lock" size={15} /> Una prueba por RUC. Tus datos se guardan cifrados.
          </p>
        </div>
        <TrialForm />
      </div>
    </section>
  );
}

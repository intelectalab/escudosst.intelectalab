// SCR-WEB-10 y SCR-WEB-12 · «Agenda una demo», cotización y contacto. La solicitud llega al panel
// (Sitio web › Solicitudes) y, si está configurado, al correo de ventas.

import type { Metadata } from "next";
import Link from "next/link";
import { DemoForm, type RequestType } from "@/components/site/PublicForms";
import { Icon } from "@/components/ui/Icon";
import { getDemoAgenda, getPricing, getSite, whatsappLink } from "@/lib/site";

export const metadata: Metadata = {
  title: "Agenda una demo · EscudoSST",
  description: "Pide una demostración de EscudoSST, una cotización o haz tu consulta. Te respondemos desde Perú.",
};

export default async function DemoPage({ searchParams }: { searchParams: Promise<{ tipo?: string; plan?: string; interes?: string }> }) {
  const q = await searchParams;
  const [site, pricing, agenda] = await Promise.all([getSite(), getPricing(), getDemoAgenda()]);
  const type: RequestType = q.tipo === "cotizacion" ? "quote" : q.tipo === "contacto" ? "contact" : "demo";
  const plans = pricing.plans.map((p) => [p.code, p.name] as [string, string]);
  const wa = whatsappLink(site.settings, "Hola, quiero conocer EscudoSST.");

  return (
    <section className="relative overflow-hidden bg-gradient-to-b from-[var(--site-surface)] to-white px-4 py-12 sm:py-16 lg:px-8">
      <div className="absolute -right-32 top-10 h-72 w-72 rounded-full bg-[#F4B90F]/15 blur-3xl" aria-hidden="true" />
      <div className="relative mx-auto grid max-w-[1200px] grid-cols-1 gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-14">
        <div className="space-y-7 lg:pt-6">
          <div className="space-y-3">
            <p className="site-eyebrow">Hablemos</p>
            <h1 className="font-display text-[32px] font-bold leading-tight text-[var(--site-navy-title)] sm:text-[42px]">Te mostramos EscudoSST con casos de tu sector</h1>
            <p className="text-[17px] leading-relaxed text-[var(--site-text-2)]">En una demo vemos juntos cómo llevar tu IPERC, capacitaciones, EPP e inspecciones, y resolvemos tus dudas.</p>
          </div>
          <ul className="space-y-3 text-[15px]">
            {[
              "Revisamos tu situación actual en SST",
              "Te mostramos los módulos que necesitas",
              "Te recomendamos el plan adecuado",
            ].map((t) => (
              <li key={t} className="flex items-start gap-3">
                <span className="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-[#22A35A]/12 text-[#167A42]">
                  <Icon name="check" size={14} />
                </span>
                {t}
              </li>
            ))}
          </ul>
          {site.settings.interactive_demo_url && (
            <a
              href={site.settings.interactive_demo_url}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-4 rounded-2xl bg-[var(--site-navy)] p-5 text-white shadow-md transition hover:-translate-y-0.5"
            >
              <span className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-white/15">
                <Icon name="play" size={22} />
              </span>
              <span>
                <b className="block font-display">Ver la demo ahora</b>
                <span className="text-sm text-white/75">Un recorrido por el sistema con datos de ejemplo, sin registrarte.</span>
              </span>
            </a>
          )}
          {agenda.result === "ok" && (
            <Link href="/demo/agendar" data-track="demo-elegir-horario" className="flex items-center gap-4 rounded-2xl bg-gradient-to-br from-[#1F7FD1] to-[#0D3B6E] p-5 text-white shadow-md transition hover:-translate-y-0.5">
              <span className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-white/15">
                <Icon name="clock" size={22} />
              </span>
              <span>
                <b className="block font-display">Elige tú el día y la hora</b>
                <span className="text-sm text-white/80">Mira los horarios libres y reserva tu demo al momento.</span>
              </span>
            </Link>
          )}
          <div className="rounded-2xl border border-[var(--site-border)] bg-white p-5 shadow-sm">
            <p className="font-display font-bold text-[var(--site-navy-title)]">¿Prefieres empezar ya?</p>
            <p className="mt-1 text-sm text-[var(--site-text-2)]">Crea tu cuenta de prueba y explora por tu cuenta.</p>
            <div className="mt-4 flex flex-wrap gap-3">
              <Link href="/prueba-gratis" className="site-btn site-btn-outline">
                Probar gratis
              </Link>
              {wa && (
                <a href={wa} target="_blank" rel="noopener noreferrer" className="site-btn site-btn-outline">
                  Escríbenos por WhatsApp
                </a>
              )}
            </div>
          </div>
        </div>
        <DemoForm
          initialType={type}
          initialPlan={q.plan && plans.some(([c]) => c === q.plan) ? q.plan : ""}
          interest={q.interes ?? ""}
          plans={plans}
          addons={pricing.addons.map((a) => ({ code: a.code, name: a.name, available: a.available }))}
        />
      </div>
    </section>
  );
}

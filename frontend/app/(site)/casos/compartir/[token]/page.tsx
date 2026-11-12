// Formulario del cliente para contar su caso de éxito y autorizar su publicación (enlace personal por correo).

import type { Metadata } from "next";
import { StoryShareForm } from "@/components/site/StoryShareForm";
import { Icon } from "@/components/ui/Icon";

export const metadata: Metadata = { title: "Cuéntanos tu historia · EscudoSST", robots: { index: false, follow: false } };

const STEPS: [string, string, string][] = [
  ["pen", "Cuentas tu historia", "Qué problema tenían y cómo lo resolvieron. Unos 5 minutos."],
  ["shield", "Autorizas la publicación", "Con tu nombre, cargo y documento. Te llega una copia."],
  ["check", "Revisamos y publicamos", "Nada sale sin revisión. Puedes retirarlo cuando quieras."],
];

export default async function CompartirCasoPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  return (
    <section className="relative overflow-hidden bg-gradient-to-b from-[var(--site-surface)] to-white px-4 py-12 sm:py-16 lg:px-8">
      <div className="absolute -left-32 top-10 h-72 w-72 rounded-full bg-[#1b6fb7]/10 blur-3xl" aria-hidden="true" />
      <div className="relative mx-auto grid max-w-[1200px] grid-cols-1 gap-10 lg:grid-cols-[minmax(0,4fr)_minmax(0,8fr)] lg:gap-12">
        <div className="space-y-7 lg:sticky lg:top-24 lg:self-start lg:pt-6">
          <div className="space-y-3">
            <p className="site-eyebrow">Casos de éxito</p>
            <h1 className="font-display text-[32px] font-bold leading-tight text-[var(--site-navy-title)] sm:text-[40px]">Cuéntanos tu historia con EscudoSST</h1>
            <p className="text-[17px] leading-relaxed text-[var(--site-text-2)]">Tu experiencia ayuda a otras empresas a ordenar su seguridad y salud en el trabajo.</p>
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
        </div>
        <StoryShareForm token={token} />
      </div>
    </section>
  );
}

// Programa de partners y referidos (SCR-WEB-35): beneficios, cómo funciona el código y formulario de solicitud.
// La recompensa dice «por definir» hasta que el dueño la configure (RN-WEB-47): aquí no se inventan montos.

import type { Metadata } from "next";
import { PartnerForm } from "@/components/site/PartnerForm";
import { PageHero } from "@/components/site/Sections";
import { Icon } from "@/components/ui/Icon";

export const metadata: Metadata = {
  title: "Programa de partners · EscudoSST",
  description: "Consultoras SST, profesionales independientes e institutos: recomienda EscudoSST a tus clientes con tu código de referido.",
};

const BENEFITS: [string, string, string, string][] = [
  ["people", "Ordena a todos tus clientes", "Con EscudoSST cada empresa que asesoras tiene su SST al día y tú lo ves desde un solo lugar.", "from-[#EAF3FC] text-[#1F7FD1]"],
  ["tag", "Recompensa por referido", "Por cada empresa que se registra con tu código y contrata. El tipo y el monto están por definir y te los confirmamos al aprobarte.", "from-[#FFF5D6] text-[#B88700]"],
  ["star", "Tu propio código y enlace", "Lo compartes con tus clientes y cada registro con él queda a tu nombre.", "from-[#E8F7EE] text-[#22A35A]"],
];

const STEPS: [string, string][] = [
  ["Postula", "Llena el formulario. Revisamos tus datos y te respondemos por correo."],
  ["Recibe tu código", "Si te aprobamos, te enviamos tu código y tu enlace personal."],
  ["Compártelo", "La empresa se registra con tu enlace o escribe tu código al crear su cuenta."],
  ["Sigue a tus referidos", "Registramos a cada empresa como tu referido: en prueba, cliente o cancelada."],
];

export default function PartnersPage() {
  return (
    <>
      <PageHero eyebrow="Programa de partners" title="Crece junto a EscudoSST" text="Si eres consultora SST, profesional independiente o instituto, recomienda EscudoSST a las empresas con las que trabajas.">
        <a href="#solicitud" className="site-btn site-btn-primary mt-7 inline-flex min-h-[52px] px-6">
          Quiero ser partner
        </a>
      </PageHero>

      <section className="px-4 py-14 lg:px-8">
        <div className="mx-auto max-w-[1360px] space-y-14">
          <ul className="grid grid-cols-1 gap-4 md:grid-cols-3">
            {BENEFITS.map(([icon, title, text, tone]) => (
              <li key={title} className="site-card min-w-0 p-6">
                <span className={`grid h-12 w-12 place-items-center rounded-2xl bg-gradient-to-br to-white ${tone}`}>
                  <Icon name={icon} size={22} />
                </span>
                <h2 className="mt-4 font-display text-[19px] font-bold text-[var(--site-navy-title)]">{title}</h2>
                <p className="mt-1.5 text-[var(--site-text-2)]">{text}</p>
              </li>
            ))}
          </ul>

          <div>
            <p className="site-eyebrow">Cómo funciona</p>
            <h2 className="mt-2 font-display text-[28px] font-bold text-[var(--site-navy-title)] sm:text-[34px]">Tu código de referido, en 4 pasos</h2>
            <ol className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
              {STEPS.map(([title, text], i) => (
                <li key={title} className="site-card relative min-w-0 overflow-hidden p-6">
                  <span className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-[#1F7FD1] to-[#1FB5A3]" aria-hidden="true" />
                  <b className="font-display text-[34px] leading-none text-[#1F7FD1]/25">0{i + 1}</b>
                  <h3 className="mt-2 font-display text-[17px] font-bold text-[var(--site-navy-title)]">{title}</h3>
                  <p className="mt-1 text-sm text-[var(--site-text-2)]">{text}</p>
                </li>
              ))}
            </ol>
            <p className="mt-4 max-w-3xl text-sm text-[var(--site-text-2)]">
              Cuenta el primer código con el que se registra la empresa. No cuenta si la empresa tiene el mismo RUC que el partner. El pago de la recompensa se coordina aparte; el sistema solo deja constancia.
            </p>
          </div>

          <div id="solicitud" className="grid scroll-mt-24 grid-cols-1 gap-8 lg:grid-cols-[minmax(0,380px)_minmax(0,1fr)]">
            <div>
              <p className="site-eyebrow">Solicitud</p>
              <h2 className="mt-2 font-display text-[28px] font-bold text-[var(--site-navy-title)]">Postula al programa</h2>
              <p className="mt-3 text-[var(--site-text-2)]">Toma 2 minutos. Revisamos cada solicitud y te respondemos por correo.</p>
              <ul className="mt-5 space-y-2 text-sm text-[var(--site-text)]">
                {["Te respondemos por correo", "Si te aprobamos, recibes tu código y tu enlace", "Enviar la solicitud no te compromete a nada"].map((t) => (
                  <li key={t} className="flex items-center gap-2">
                    <span className="grid h-5 w-5 place-items-center rounded-full bg-[#E8F7EE] text-[#22A35A]">
                      <Icon name="check" size={13} />
                    </span>
                    {t}
                  </li>
                ))}
              </ul>
            </div>
            <PartnerForm />
          </div>
        </div>
      </section>
    </>
  );
}

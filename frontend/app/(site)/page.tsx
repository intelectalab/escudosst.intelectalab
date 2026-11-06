// SCR-WEB-01 · Portada del sitio público. Textos propios basados en docs/00 (problema, producto y diferenciadores);
// sin promesas de tiempos ni condiciones comerciales no decididas (docs/07 §1.5).

import type { Metadata } from "next";
import { DashboardMock, FotocheckMock, ReportMock, StepArt, SunafilMock } from "@/components/site/Mockups";
import { FinalCta, PlanCards, SectionTitle, TextLink, TrustSeals } from "@/components/site/Sections";
import { FaqJsonLd, FaqList, TestimonialCards } from "@/components/site/FaqTestimonials";
import { Reveal } from "@/components/site/Reveal";
import { Icon } from "@/components/ui/Icon";
import { contactHref, getFaqs, getModules, getPricing, getSite, getTestimonials, whatsappLink } from "@/lib/site";

export const metadata: Metadata = {
  title: "EscudoSST · Cumple la Ley 29783 y llega preparado a SUNAFIL",
  description: "Gestión de seguridad y salud en el trabajo para empresas peruanas: IPERC, inspecciones, capacitaciones, EPP, accidentes y el expediente para SUNAFIL en un solo lugar.",
};

// Antes (Excel, Word y papel) → con EscudoSST.
const PAINS = [
  ["file", "Registros obligatorios armados a mano y siempre incompletos", "Los registros se llenan solos con lo que ya registras"],
  ["clock", "Vencimientos de EMO, SCTR, EPP y capacitaciones que se pasan", "Te avisamos antes de que venza cada uno"],
  ["people", "Trabajadores que no reportan porque no quieren instalar otra app", "Reportan escaneando un QR, sin instalar nada"],
  ["history", "Días juntando evidencias cuando llega la inspección", "El expediente por trabajador se arma en minutos"],
];

const PILLARS = [
  ["tag", "Planificar", "Línea base, política, requisitos legales, plan y programa anual, IPERC y comité."],
  ["shield", "Operar en campo", "Inspecciones, actos y condiciones, charlas de 5 minutos, ATS, PETAR, EPP y emergencias."],
  ["heart", "Personas y salud", "Capacitaciones con certificados verificables, fotocheck con QR, EMO y aptitud en una base médica separada."],
  ["history", "Eventos y mejora", "Accidentes, investigación, acciones correctivas, auditorías, estadísticas y los registros obligatorios."],
  ["building", "Contratistas", "Acreditación, SCTR vigente e ingreso a obra o planta con QR."],
  ["check", "Demostrar cumplimiento", "Modo Inspección SUNAFIL: el expediente por trabajador y periodo se arma solo."],
];

const STEPS = [
  ["Regístrate con tu RUC", "Crea tu cuenta con los datos de tu empresa en pocos pasos."],
  ["Conoce tu punto de partida", "La línea base te dice qué cumples hoy y qué te falta."],
  ["Configura tu empresa", "Sedes, áreas, puestos y trabajadores; o carga tu Excel."],
  ["Opera el día a día", "Inspecciones, capacitaciones y reportes desde la web o el celular."],
  ["Llega preparado", "Genera el expediente de SUNAFIL cuando lo necesites."],
];

export default async function HomePage() {
  const [site, pricing, modules, faqs, testimonials] = await Promise.all([getSite(), getPricing(), getModules(), getFaqs(), getTestimonials()]);
  const s = site.settings;
  const planOf = (code: string) => modules.find((m) => m.code === code)?.from_plan;
  const planName = (code?: string | null) => pricing.plans.find((p) => p.code === code)?.name;
  const totalModules = modules.filter((m) => !m.is_addon).length;

  const FEATURES = [
    {
      eyebrow: "Modo Inspección SUNAFIL",
      title: "El expediente para SUNAFIL, listo cuando lo pidan",
      text: "Elige al trabajador y el periodo: EscudoSST junta inducción, EPP, EMO, capacitaciones e IPERC, te dice qué falta y te deja corregirlo. Compártelo con un enlace que vence.",
      bullets: ["Evidencias por trabajador y periodo", "Lo que falta, con enlace para corregir", "Enlace temporal para el inspector"],
      module: "SUN",
      art: <SunafilMock />,
    },
    {
      eyebrow: "Fotocheck SST con QR",
      title: "En campo se sabe al instante quién está habilitado",
      text: "El fotocheck se habilita solo si el trabajador tiene sus requisitos al día. El supervisor escanea el QR y ve si puede ingresar.",
      bullets: ["Requisitos configurables por tipo de fotocheck", "Verificación pública por QR", "Vencimientos que avisan antes"],
      module: "FOT",
      art: <FotocheckMock />,
    },
    {
      eyebrow: "Reporte sin app",
      title: "Tu equipo reporta por QR o WhatsApp, sin instalar nada",
      text: "Cualquier trabajador reporta un acto o condición subestándar escaneando un QR o escribiendo por WhatsApp. El reporte llega al responsable con foto y ubicación.",
      bullets: ["Sin descargas ni contraseñas", "Foto y ubicación del reporte", "Seguimiento hasta cerrarlo"],
      module: "ACT",
      art: <ReportMock />,
    },
  ];

  return (
    <>
      {/* Héroe: claro y cercano (fondo suave, maqueta del panel y avisos flotantes) */}
      <section className="relative overflow-hidden bg-gradient-to-b from-[#EAF3FC] via-[#F5F9FE] to-white">
        <div className="absolute -right-40 -top-40 h-[30rem] w-[30rem] rounded-full bg-[#1F7FD1]/12 blur-3xl" aria-hidden="true" />
        <div className="absolute -left-24 bottom-0 h-72 w-72 rounded-full bg-[#F4B90F]/15 blur-3xl" aria-hidden="true" />
        <div className="relative mx-auto grid max-w-[1360px] grid-cols-1 items-center gap-12 px-4 py-14 sm:py-20 lg:grid-cols-[1.05fr_1fr] lg:px-8 lg:py-24">
          <div className="space-y-6">
            <p className="inline-flex items-center gap-2 rounded-full bg-white px-3.5 py-1.5 text-xs font-bold uppercase tracking-wider text-[var(--site-action)] shadow-sm ring-1 ring-[var(--site-border)]">
              <Icon name="shield" size={14} /> Seguridad y salud en el trabajo · Perú
            </p>
            <h1 className="font-display text-[34px] font-bold leading-[1.12] text-[var(--site-navy-title)] sm:text-[50px]">
              Cumple la Ley 29783 y{" "}
              <span className="relative text-[var(--site-action)]">
                llega preparado
                <svg className="absolute -bottom-2 left-0 w-full" viewBox="0 0 200 12" preserveAspectRatio="none" aria-hidden="true">
                  <path d="M2 9c40-6 120-8 196-3" stroke="#F4B90F" strokeWidth="5" fill="none" strokeLinecap="round" />
                </svg>
              </span>{" "}
              a SUNAFIL
            </h1>
            <p className="max-w-xl text-[18px] leading-relaxed text-[var(--site-text-2)]">
              IPERC, inspecciones, capacitaciones, EPP, accidentes y el expediente para SUNAFIL en un solo lugar. Para empresas peruanas y consultoras SST.
            </p>
            <ul className="grid grid-cols-1 gap-2.5 text-[15px] text-[var(--site-text)] sm:grid-cols-2">
              {["Registros obligatorios ordenados", "Avisos antes de cada vencimiento", "Reportes de tu equipo sin instalar apps", "Datos médicos en una base separada"].map((b) => (
                <li key={b} className="flex items-center gap-2">
                  <span className="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-[#22A35A] text-white">
                    <Icon name="check" size={12} />
                  </span>
                  {b}
                </li>
              ))}
            </ul>
            <div className="flex flex-col gap-3 pt-2 sm:flex-row">
              <a href={contactHref(s, "trial")} className="site-btn site-btn-primary shadow-[0_10px_24px_-10px_#1F7FD1]">
                Prueba gratis <Icon name="chevron" size={16} />
              </a>
              <a href={contactHref(s, "demo")} className="site-btn site-btn-outline bg-white">
                Agenda una demo
              </a>
            </div>
          </div>
          <div className="relative px-2 sm:px-6">
            <div className="absolute inset-4 -rotate-2 rounded-[28px] bg-[var(--site-navy)]/90 shadow-xl" aria-hidden="true" />
            <div className="relative rotate-1 transition-transform duration-500 hover:rotate-0">
              <DashboardMock />
            </div>
            {/* Avisos flotantes: ejemplos del tipo de alertas que da el sistema */}
            <div className="site-float absolute -left-2 top-6 hidden items-center gap-2.5 rounded-2xl bg-white px-3.5 py-2.5 text-sm shadow-lg ring-1 ring-[var(--site-border)] sm:flex">
              <span className="grid h-8 w-8 place-items-center rounded-xl bg-[#22A35A]/12 text-[#167A42]">
                <Icon name="check" size={16} />
              </span>
              <span>
                <b className="block leading-tight text-[var(--site-navy-title)]">Expediente listo</b>
                <span className="text-xs text-[var(--site-text-2)]">Sin evidencias faltantes</span>
              </span>
            </div>
            <div className="site-float-late absolute -bottom-4 right-0 hidden items-center gap-2.5 rounded-2xl bg-white px-3.5 py-2.5 text-sm shadow-lg ring-1 ring-[var(--site-border)] sm:flex">
              <span className="grid h-8 w-8 place-items-center rounded-xl bg-[#F4B90F]/20 text-[#8A6704]">
                <Icon name="bell" size={16} />
              </span>
              <span>
                <b className="block leading-tight text-[var(--site-navy-title)]">3 vencimientos esta semana</b>
                <span className="text-xs text-[var(--site-text-2)]">Te avisamos antes</span>
              </span>
            </div>
          </div>
        </div>
        <div className="site-stripe h-1.5" aria-hidden="true" />
      </section>

      {/* Problema */}
      <section className="bg-[var(--site-surface)] px-4 py-16 lg:px-8">
        <div className="mx-auto max-w-[1360px] space-y-10">
          <Reveal>
            <SectionTitle eyebrow="El problema" title="La SST no debería vivir en Excel, Word y papel" text="Así se gestiona hoy en muchas empresas. Con EscudoSST cambia así:" />
          </Reveal>
          <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {PAINS.map(([icon, before, after], i) => (
              <li key={before}>
                <Reveal delay={i * 90} className="h-full">
                  <div className="site-card site-card-hover flex h-full flex-col overflow-hidden">
                    <div className="flex flex-1 flex-col gap-3 p-5">
                      <span className="grid h-11 w-11 place-items-center rounded-xl bg-[var(--site-red)]/10 text-[var(--site-red)]">
                        <Icon name={icon} size={20} />
                      </span>
                      <p className="text-xs font-bold uppercase tracking-wider text-[var(--site-text-2)]">Hoy</p>
                      <p className="font-semibold text-[var(--site-text)]">{before}</p>
                    </div>
                    <div className="flex items-start gap-2.5 border-t border-[#22A35A]/15 bg-[#22A35A]/8 p-5">
                      <span className="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-[#22A35A] text-white">
                        <Icon name="check" size={13} />
                      </span>
                      <p className="text-sm font-semibold text-[#14653A]">{after}</p>
                    </div>
                  </div>
                </Reveal>
              </li>
            ))}
          </ul>
        </div>
      </section>

      {/* Pilares */}
      <section className="px-4 py-20 lg:px-8">
        <div className="mx-auto max-w-[1360px] space-y-12">
          <SectionTitle eyebrow="El producto" title="Todo el ciclo de tu sistema de gestión SST" text={`${totalModules} módulos agrupados en seis frentes. Contratas el plan que necesitas y creces cuando lo necesites.`} />
          <div className="grid-cols-1 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {PILLARS.map(([icon, title, text], i) => (
              <article key={title} className="site-card site-card-hover group relative overflow-hidden p-6">
                <span className="absolute right-4 top-3 font-display text-5xl font-bold text-[var(--site-surface)] transition-colors group-hover:text-[#1F7FD1]/10" aria-hidden="true">
                  0{i + 1}
                </span>
                <span className="relative grid h-12 w-12 place-items-center rounded-xl bg-gradient-to-br from-[#1F7FD1] to-[#0D3B6E] text-white shadow-md">
                  <Icon name={icon} size={22} />
                </span>
                <h3 className="relative mt-4 font-display text-lg font-bold text-[var(--site-navy-title)]">{title}</h3>
                <p className="relative mt-1.5 text-[15px] leading-relaxed text-[var(--site-text-2)]">{text}</p>
              </article>
            ))}
          </div>
          <p className="text-center">
            <TextLink href="/producto">Ver todos los módulos</TextLink>
          </p>
        </div>
      </section>

      {/* Diferenciadores con maqueta */}
      <section className="bg-[var(--site-surface)] px-4 py-20 lg:px-8">
        <div className="mx-auto max-w-[1360px] space-y-24">
          {FEATURES.map((f, i) => {
            const plan = planName(planOf(f.module));
            return (
              <div key={f.module} className={`grid items-center gap-10 lg:grid-cols-2 lg:gap-16 ${i % 2 ? "lg:[&>*:first-child]:order-2" : ""}`}>
                <div className="space-y-5">
                  <p className="site-eyebrow">{f.eyebrow}</p>
                  <h2 className="font-display text-[28px] font-bold leading-tight text-[var(--site-navy-title)] sm:text-[34px]">{f.title}</h2>
                  <p className="text-[17px] leading-relaxed text-[var(--site-text-2)]">{f.text}</p>
                  <ul className="space-y-2.5">
                    {f.bullets.map((b) => (
                      <li key={b} className="flex items-center gap-2.5 font-semibold text-[var(--site-text)]">
                        <span className="grid h-6 w-6 place-items-center rounded-full bg-[#22A35A]/15 text-[#187A42]">
                          <Icon name="check" size={13} />
                        </span>
                        {b}
                      </li>
                    ))}
                  </ul>
                  {plan && (
                    <p className="text-sm text-[var(--site-text-2)]">
                      Incluido desde el plan <b className="text-[var(--site-navy)]">{plan}</b>
                    </p>
                  )}
                  <div className="flex flex-col gap-3 sm:flex-row">
                    <a href={contactHref(s, "trial")} className="site-btn site-btn-primary">
                      Probar gratis
                    </a>
                    <a href={contactHref(s, "demo")} className="site-btn site-btn-outline">
                      Ver demo
                    </a>
                  </div>
                </div>
                <Reveal delay={120}>{f.art}</Reveal>
              </div>
            );
          })}
        </div>
      </section>

      {/* Cómo funciona */}
      <section className="px-4 py-20 lg:px-8">
        <div className="mx-auto max-w-[1360px] space-y-12">
          <SectionTitle eyebrow="Cómo funciona" title="De cero a tu SST ordenada, paso a paso" />
          <ol className="grid-cols-1 grid gap-5 sm:grid-cols-2 lg:grid-cols-5">
            {STEPS.map(([title, text], i) => (
              <li key={title} className="site-card relative flex flex-col items-center p-5 text-center">
                <span className="absolute -top-3 left-1/2 grid h-7 w-7 -translate-x-1/2 place-items-center rounded-full bg-[var(--site-action)] text-sm font-bold text-white shadow-md">{i + 1}</span>
                <StepArt step={i + 1} />
                <h3 className="mt-2 font-display font-bold text-[var(--site-navy-title)]">{title}</h3>
                <p className="mt-1 text-sm text-[var(--site-text-2)]">{text}</p>
              </li>
            ))}
          </ol>
          <p className="text-center">
            <TextLink href="/como-funciona">Ver el recorrido completo</TextLink>
          </p>
        </div>
      </section>

      {/* Demostración */}
      <section className="px-4 pb-20 lg:px-8">
        <Reveal className="mx-auto max-w-[1360px]">
          <div className="relative grid grid-cols-1 items-center gap-8 overflow-hidden rounded-3xl border border-[var(--site-border)] bg-gradient-to-br from-[#EAF3FC] to-white p-8 shadow-sm sm:p-12 lg:grid-cols-[1.3fr_1fr]">
            <div className="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-[#F4B90F]/20 blur-2xl" aria-hidden="true" />
            <div className="relative space-y-4">
              <p className="site-eyebrow">Demostración</p>
              <h2 className="font-display text-[28px] font-bold leading-tight text-[var(--site-navy-title)] sm:text-[34px]">¿Quieres verlo con un caso como el tuyo?</h2>
              <p className="text-[17px] leading-relaxed text-[var(--site-text-2)]">
                Agenda una demo y te mostramos EscudoSST con ejemplos de tu sector. Si prefieres explorar solo, crea tu cuenta de prueba.
              </p>
              <div className="flex flex-col gap-3 pt-1 sm:flex-row">
                <a href={contactHref(s, "demo")} className="site-btn site-btn-primary">
                  Agenda una demo <Icon name="chevron" size={16} />
                </a>
                {s.interactive_demo_url ? (
                  <a href={s.interactive_demo_url} target="_blank" rel="noopener noreferrer" className="site-btn site-btn-outline bg-white">
                    <Icon name="play" size={16} /> Ver la demo ahora
                  </a>
                ) : (
                  <a href={contactHref(s, "trial")} className="site-btn site-btn-outline bg-white">
                    Probar gratis
                  </a>
                )}
              </div>
            </div>
            <ul className="relative space-y-3">
              {(
                [
                  ["clock", "Te escribimos para coordinar el día y la hora"],
                  ["people", "Un asesor te muestra el sistema en vivo"],
                  ["tag", "Te recomendamos el plan según tu empresa"],
                ] as const
              ).map(([icon, text]) => (
                <li key={text} className="flex items-center gap-3 rounded-2xl bg-white p-4 text-[15px] font-semibold text-[var(--site-navy-title)] shadow-sm ring-1 ring-[var(--site-border)]">
                  <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-[var(--site-surface)] text-[var(--site-action)]">
                    <Icon name={icon} size={18} />
                  </span>
                  {text}
                </li>
              ))}
              {whatsappLink(s) && (
                <li className="text-sm text-[var(--site-text-2)]">
                  ¿Una duda rápida?{" "}
                  <a href={whatsappLink(s)!} target="_blank" rel="noopener noreferrer" className="font-bold text-[#167A42] hover:underline">
                    Escríbenos por WhatsApp
                  </a>
                </li>
              )}
            </ul>
          </div>
        </Reveal>
      </section>

      {/* Planes */}
      {pricing.plans.length > 0 && (
        <section className="bg-[var(--site-surface)] px-4 py-20 lg:px-8">
          <div className="mx-auto max-w-[1360px] space-y-12">
            <SectionTitle eyebrow="Planes" title="Un plan para cada etapa de tu empresa" text="Cada plan incluye todo lo del anterior. Los rangos son referenciales." />
            <PlanCards plans={pricing.plans} s={s} compact />
            <p className="text-center">
              <TextLink href="/planes">Comparar planes módulo por módulo</TextLink>
            </p>
          </div>
        </section>
      )}

      {/* Testimonios (solo los aprobados con permiso del autor o rotulados «Ejemplo») */}
      {testimonials.length > 0 && (
        <section className="px-4 py-20 lg:px-8">
          <div className="mx-auto max-w-[1360px] space-y-10">
            <Reveal>
              <SectionTitle eyebrow="Testimonios" title="Lo que dicen quienes ya ordenaron su SST" />
            </Reveal>
            <Reveal>
              <TestimonialCards items={testimonials.slice(0, 6)} />
            </Reveal>
          </div>
        </section>
      )}

      {/* Preguntas frecuentes */}
      {faqs.length > 0 && (
        <section className="bg-[var(--site-surface)] px-4 py-20 lg:px-8">
          <FaqJsonLd faqs={faqs} />
          <div className="mx-auto grid max-w-[1200px] grid-cols-1 gap-10 lg:grid-cols-[1fr_1.6fr]">
            <Reveal className="space-y-4">
              <SectionTitle eyebrow="Preguntas frecuentes" title="Lo que suelen preguntarnos" center={false} />
              <p className="text-[var(--site-text-2)]">¿No encuentras tu duda? Escríbenos y te respondemos.</p>
              <div className="flex flex-col gap-3 sm:flex-row">
                <a href="/demo?tipo=contacto" className="site-btn site-btn-outline bg-white">
                  Hacer una consulta
                </a>
                <TextLink href="/preguntas-frecuentes">Ver todas</TextLink>
              </div>
            </Reveal>
            <Reveal>
              <FaqList faqs={faqs.slice(0, 6)} />
            </Reveal>
          </div>
        </section>
      )}

      {/* Sellos */}
      <section className="px-4 py-14 lg:px-8">
        <div className="mx-auto max-w-[1360px]">
          <TrustSeals />
        </div>
      </section>

      <FinalCta s={s} />
    </>
  );
}

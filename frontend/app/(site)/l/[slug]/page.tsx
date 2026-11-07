// SCR-WEB-69 · Landing de campaña /l/{slug}: la página del CMS (tipo «Landing de campaña») con el formulario elegido
// en la campaña. Solo se muestra mientras la campaña está activa y en fechas; después, un aviso y enlace a la portada.
// No se indexa en buscadores.

import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { CampaignTracker } from "@/components/site/CampaignTracker";
import { PageBlocks } from "@/components/site/PageBlocks";
import { DemoForm } from "@/components/site/PublicForms";
import { Icon } from "@/components/ui/Icon";
import { getCampaign, getModules, getPricing } from "@/lib/site";

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const c = await getCampaign((await params).slug);
  return {
    title: c?.result === "ok" ? c.seo?.meta_title || `${c.title} · EscudoSST` : "EscudoSST",
    description: c?.result === "ok" ? c.seo?.meta_description || c.summary || undefined : undefined,
    robots: { index: false, follow: true },
  };
}

const LINKS: Record<string, [string, string, string]> = {
  resource: ["Descarga recursos gratis", "Plantillas y guías de SST listas para usar.", "/recursos"],
  assessment: ["Haz el autodiagnóstico", "Mide en minutos qué tan preparada está tu empresa frente a la Ley 29783.", "/herramientas/autodiagnostico"],
  calculator: ["Calcula una posible multa", "Estima, de forma referencial, cuánto podría costar una multa de SUNAFIL.", "/herramientas/calculadora-multas"],
};

export default async function CampaignPage({ params }: Props) {
  const { slug } = await params;
  const c = await getCampaign(slug);
  if (!c) notFound();
  if (c.result === "ended") {
    return (
      <section className="px-4 py-20 lg:px-8">
        <div className="site-card mx-auto max-w-xl space-y-4 p-8 text-center">
          <span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-[var(--site-surface)] text-[var(--site-text-2)]">
            <Icon name="clock" size={26} />
          </span>
          <h1 className="font-display text-2xl font-bold text-[var(--site-navy-title)]">Esta campaña ya terminó</h1>
          <p className="text-[15px] text-[var(--site-text-2)]">La oferta de esta página ya no está vigente. Puedes conocer EscudoSST y lo que ofrece hoy en nuestra portada.</p>
          <Link href="/" className="site-btn site-btn-primary inline-flex">
            Ir a la portada
          </Link>
        </div>
      </section>
    );
  }
  const [modules, pricing] = await Promise.all([getModules(), getPricing()]);
  const form = c.campaign?.form_type ?? null;
  const plans = pricing.plans.map((p) => [p.code, p.name] as [string, string]);

  return (
    <>
      <CampaignTracker utm={{ utm_source: c.campaign?.utm_source ?? null, utm_medium: c.campaign?.utm_medium ?? null, utm_campaign: c.campaign?.utm_campaign ?? null }} />
      <PageBlocks blocks={c.blocks ?? []} modules={modules} />
      {form && ["demo", "quote", "contact"].includes(form) && (
        <section id="formulario" className="bg-[var(--site-surface)] px-4 py-14 lg:px-8">
          <div className="mx-auto max-w-[760px]">
            <DemoForm initialType={form as "demo" | "quote" | "contact"} plans={plans} addons={pricing.addons.map((a) => ({ code: a.code, name: a.name, available: a.available }))} />
          </div>
        </section>
      )}
      {form && LINKS[form] && (
        <section className="bg-[var(--site-surface)] px-4 py-14 lg:px-8">
          <div className="site-card mx-auto flex max-w-[760px] flex-col items-start gap-4 p-8 sm:flex-row sm:items-center">
            <div className="flex-1">
              <h2 className="font-display text-2xl font-bold text-[var(--site-navy-title)]">{LINKS[form][0]}</h2>
              <p className="mt-1 text-[15px] text-[var(--site-text-2)]">{LINKS[form][1]}</p>
            </div>
            <Link href={LINKS[form][2]} className="site-btn site-btn-primary">
              Empezar <Icon name="chevron" size={16} />
            </Link>
          </div>
        </section>
      )}
    </>
  );
}

// Ficha de un caso de éxito (SCR-WEB-09): sector, año, resultados, reto, solución, cita y módulos usados.
// La galería sale de la biblioteca de medios (cada imagen con su texto alternativo y leyenda).

import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { FinalCta } from "@/components/site/Sections";
import { ExampleBadge, StoryLogo } from "@/components/site/Stories";
import { Icon } from "@/components/ui/Icon";
import { assetUrl, getModules, getSite, getStory, STORY_SECTORS } from "@/lib/site";

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const s = await getStory((await params).slug);
  return s ? { title: `${s.title} · Casos de éxito · EscudoSST`, description: s.challenge.slice(0, 160) } : { title: "EscudoSST" };
}

export default async function CasoPage({ params }: Props) {
  const { slug } = await params;
  const [s, modules, site] = await Promise.all([getStory(slug), getModules(), getSite()]);
  if (!s) notFound();
  const used = s.modules.map((c) => modules.find((m) => m.code.trim() === c)).filter((m) => !!m);

  return (
    <>
      <section className="relative overflow-hidden bg-gradient-to-b from-[#EAF3FC] to-white px-4 pb-12 pt-10 sm:pt-14 lg:px-8">
        <div className="absolute -right-32 -top-32 h-80 w-80 rounded-full bg-[#1F7FD1]/12 blur-3xl" aria-hidden="true" />
        <div className="relative mx-auto max-w-[1000px] space-y-5">
          <Link href="/casos" className="inline-flex items-center gap-1 text-sm font-bold text-[var(--site-action)] hover:underline">
            ← Todos los casos
          </Link>
          <div className="flex flex-wrap items-center gap-3">
            <StoryLogo s={s} size={52} />
            <span>
              <b className="block font-display text-[var(--site-navy-title)]">{s.client_name}</b>
              <span className="text-sm text-[var(--site-text-2)]">
                {STORY_SECTORS[s.sector] ?? s.sector}
                {s.project_year && ` · ${s.project_year}`}
              </span>
            </span>
            {s.is_example && <ExampleBadge />}
          </div>
          <h1 className="max-w-3xl font-display text-[30px] font-bold leading-[1.15] text-[var(--site-navy-title)] sm:text-[44px]">{s.title}</h1>
          {s.is_example && (
            <p className="max-w-2xl rounded-xl border border-[#F4B90F]/40 bg-[#FFF8E1] px-4 py-3 text-sm text-[#5C4500]">
              Este es un caso ilustrativo para mostrar cómo se usa EscudoSST. No corresponde a un cliente real.
            </p>
          )}
        </div>
      </section>

      {s.results.length > 0 && (
        <section className="px-4 lg:px-8">
          <ul className="mx-auto flex max-w-[1000px] flex-wrap gap-3">
            {s.results.map((r) => (
              <li key={r.label} className="site-card min-w-[140px] flex-1 basis-[calc(50%-0.75rem)] p-5 text-center md:basis-0">
                <b className="block font-display text-[32px] leading-none text-[var(--site-action)]">{r.value}</b>
                <span className="mt-2 block text-sm text-[var(--site-text-2)]">{r.label}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="px-4 py-12 lg:px-8">
        <div className="mx-auto grid max-w-[1000px] grid-cols-1 gap-5 md:grid-cols-2">
          <Story icon="search" title="El reto" text={s.challenge} />
          <Story icon="check" title="Cómo lo resolvió" text={s.solution} />
        </div>
      </section>

      {s.quote && (
        <section className="px-4 pb-12 lg:px-8">
          <figure className="mx-auto max-w-[800px] rounded-3xl bg-[var(--site-navy)] px-6 py-10 text-center text-white sm:px-12">
            <blockquote className="font-display text-xl leading-relaxed sm:text-2xl">«{s.quote}»</blockquote>
            <figcaption className="mt-4 text-sm text-white/70">{s.client_name}</figcaption>
          </figure>
        </section>
      )}

      {s.gallery && s.gallery.length > 0 && (
        <section className="px-4 pb-12 lg:px-8" aria-label="Galería del proyecto">
          <div className="mx-auto max-w-[1000px] space-y-4">
            <h2 className="font-display text-[24px] font-bold text-[var(--site-navy-title)]">Galería del proyecto</h2>
            <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {s.gallery.map((g) => (
                <li key={g.file_id}>
                  <figure className="site-card overflow-hidden">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={assetUrl(g.file_id) ?? ""} alt={g.alt_text ?? ""} width={g.width ?? undefined} height={g.height ?? undefined} loading="lazy" className="aspect-[4/3] w-full object-cover" />
                    {(g.caption || g.is_example) && (
                      <figcaption className="flex items-center gap-2 px-4 py-3 text-sm text-[var(--site-text-2)]">
                        {g.is_example && <ExampleBadge />}
                        {g.caption}
                      </figcaption>
                    )}
                  </figure>
                </li>
              ))}
            </ul>
          </div>
        </section>
      )}

      {used.length > 0 && (
        <section className="bg-[var(--site-surface)] px-4 py-12 lg:px-8">
          <div className="mx-auto max-w-[1000px] space-y-4">
            <h2 className="font-display text-[24px] font-bold text-[var(--site-navy-title)]">Módulos que usó</h2>
            <ul className="flex flex-wrap gap-2">
              {used.map((m) => (
                <li key={m.code} className="rounded-full border border-[var(--site-border)] bg-white px-4 py-2 text-sm font-semibold text-[var(--site-text)]">
                  {m.name}
                </li>
              ))}
            </ul>
            <Link href="/producto" className="inline-block font-bold text-[var(--site-action)] hover:underline">
              Ver todos los módulos →
            </Link>
          </div>
        </section>
      )}

      <FinalCta s={site.settings} />
    </>
  );
}

function Story({ icon, title, text }: { icon: string; title: string; text: string }) {
  return (
    <div className="site-card space-y-3 p-6">
      <span className="grid h-10 w-10 place-items-center rounded-xl bg-[var(--site-surface)] text-[var(--site-action)]">
        <Icon name={icon} size={18} />
      </span>
      <h2 className="font-display text-xl font-bold text-[var(--site-navy-title)]">{title}</h2>
      <p className="whitespace-pre-line leading-relaxed text-[var(--site-text)]">{text}</p>
    </div>
  );
}

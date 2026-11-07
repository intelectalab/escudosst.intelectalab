// Casos de éxito (SCR-WEB-09): listado filtrable por sector. Solo casos publicados con autorización escrita del
// cliente o rotulados «Ejemplo» (RN-WEB-41).

import type { Metadata } from "next";
import Link from "next/link";
import { ReviewsBlock } from "@/components/site/ReviewsBlock";
import { FinalCta, PageHero } from "@/components/site/Sections";
import { StoryCard } from "@/components/site/Stories";
import { getSite, getStories, STORY_SECTORS } from "@/lib/site";

export const metadata: Metadata = {
  title: "Casos de éxito · EscudoSST",
  description: "Cómo empresas de distintos rubros ordenaron su seguridad y salud en el trabajo con EscudoSST.",
};

export default async function CasosPage({ searchParams }: { searchParams: Promise<{ sector?: string }> }) {
  const { sector } = await searchParams;
  const valid = sector && STORY_SECTORS[sector] ? sector : undefined;
  const [all, list, site] = await Promise.all([getStories(), valid ? getStories(valid) : null, getSite()]);
  const stories = list ?? all;
  const sectors = [...new Set(all.map((s) => s.sector))];

  return (
    <>
      <PageHero eyebrow="Casos de éxito" title="Empresas que ya ordenaron su SST" text="Lo que lograron, con qué módulos y en cuánto tiempo. Contado con la autorización de cada cliente." />
      <section className="px-4 py-14 lg:px-8">
        <div className="mx-auto max-w-[1200px] space-y-8">
          {sectors.length > 1 && (
            <nav aria-label="Filtrar por sector" className="flex flex-wrap justify-center gap-2">
              <FilterChip href="/casos" on={!valid}>
                Todos
              </FilterChip>
              {sectors.map((s) => (
                <FilterChip key={s} href={`/casos?sector=${s}`} on={valid === s}>
                  {STORY_SECTORS[s] ?? s}
                </FilterChip>
              ))}
            </nav>
          )}
          {stories.length ? (
            <ul className="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3">
              {stories.map((s) => (
                <li key={s.slug}>
                  <StoryCard s={s} />
                </li>
              ))}
            </ul>
          ) : (
            <p className="mx-auto max-w-md rounded-2xl bg-[var(--site-surface)] p-8 text-center text-[var(--site-text-2)]">
              Pronto publicaremos los primeros casos. Mientras tanto, <Link href="/demo" className="font-bold text-[var(--site-action)] hover:underline">agenda una demo</Link> y te mostramos cómo se usa en tu rubro.
            </p>
          )}
        </div>
      </section>
      <ReviewsBlock />
      <FinalCta s={site.settings} />
    </>
  );
}

function FilterChip({ href, on, children }: { href: string; on: boolean; children: React.ReactNode }) {
  return (
    <Link
      href={href}
      scroll={false}
      aria-current={on ? "page" : undefined}
      className={`rounded-full border px-4 py-2 text-sm font-bold transition-colors ${on ? "border-[var(--site-action)] bg-[var(--site-action)] text-white" : "border-[var(--site-border)] bg-white text-[var(--site-text)] hover:border-[var(--site-action)]"}`}
    >
      {children}
    </Link>
  );
}

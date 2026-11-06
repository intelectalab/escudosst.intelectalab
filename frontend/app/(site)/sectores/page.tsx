// Índice de páginas por sector publicadas.
import type { Metadata } from "next";
import Link from "next/link";
import { FinalCta, PageHero } from "@/components/site/Sections";
import { Icon } from "@/components/ui/Icon";
import { getPages, getSite } from "@/lib/site";

export const metadata: Metadata = { title: "Sectores · EscudoSST", description: "EscudoSST para construcción, minería, agroindustria, manufactura, retail, oficinas y clínicas." };

export default async function SectoresPage() {
  const [pages, site] = await Promise.all([getPages("sector"), getSite()]);
  return (
    <>
      <PageHero eyebrow="Sectores" title="SST pensada para tu rubro" text="Cada sector tiene sus riesgos y sus normas. Mira cómo te ayuda EscudoSST en el tuyo." />
      <section className="px-4 py-14 lg:px-8">
        <div className="mx-auto max-w-[1200px]">
          {pages.length ? (
            <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {pages.map((p) => (
                <li key={p.slug}>
                  <Link href={`/${p.slug}`} className="site-card site-card-hover flex h-full flex-col gap-2 p-6">
                    <span className="grid h-11 w-11 place-items-center rounded-xl bg-[var(--site-surface)] text-[var(--site-action)]">
                      <Icon name="building" size={20} />
                    </span>
                    <b className="font-display text-lg text-[var(--site-navy-title)]">{p.title}</b>
                    {p.summary && <span className="text-sm text-[var(--site-text-2)]">{p.summary}</span>}
                    <span className="mt-auto pt-2 text-sm font-bold text-[var(--site-action)]">Ver más →</span>
                  </Link>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-center text-[var(--site-text-2)]">Pronto publicaremos las páginas por sector.</p>
          )}
        </div>
      </section>
      <FinalCta s={site.settings} />
    </>
  );
}

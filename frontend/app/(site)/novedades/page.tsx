// SCR-WEB-75 · Novedades del producto: qué cambió en EscudoSST (nuevo, mejora, corrección). Se redactan en
// el panel: Sitio web › Novedades. Al pie, suscripción al boletín.

import type { Metadata } from "next";
import Link from "next/link";
import { PageHero } from "@/components/site/Sections";
import { Icon } from "@/components/ui/Icon";
import { getProductUpdates, UPDATE_CATEGORIES } from "@/lib/site";

export const metadata: Metadata = {
  title: "Novedades de EscudoSST",
  description: "Lo nuevo, las mejoras y las correcciones de EscudoSST, con su fecha.",
};

const fecha = (d: string) => new Intl.DateTimeFormat("es-PE", { dateStyle: "long", timeZone: "America/Lima" }).format(new Date(d));

export default async function NovedadesPage() {
  const items = await getProductUpdates();
  return (
    <>
      <PageHero eyebrow="Novedades" title="Qué hay de nuevo en EscudoSST" text="Lo que agregamos, mejoramos y corregimos, con su fecha." />
      <section className="px-4 py-12 lg:px-8">
        <div className="mx-auto max-w-[860px] space-y-4">
          {items.length === 0 ? (
            <div className="site-card p-8 text-center text-[var(--site-text-2)]">Pronto publicaremos aquí las novedades del producto.</div>
          ) : (
            items.map((u) => (
              <article key={u.slug} className="site-card p-6">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                  <span className={`rounded-full px-3 py-1 text-xs font-bold ${UPDATE_CATEGORIES[u.category][1]}`}>{UPDATE_CATEGORIES[u.category][0]}</span>
                  <time dateTime={u.published_at} className="text-[var(--site-text-2)]">
                    {fecha(u.published_at)}
                  </time>
                </div>
                <h2 className="mt-2 font-display text-xl font-bold text-[var(--site-navy-title)]">
                  <Link href={`/novedades/${u.slug}`} className="hover:underline">
                    {u.title}
                  </Link>
                </h2>
                {u.excerpt && <p className="mt-1 line-clamp-3 whitespace-pre-line text-[15px] text-[var(--site-text-2)]">{u.excerpt}</p>}
                <Link href={`/novedades/${u.slug}`} className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-[var(--site-action)]">
                  Leer más <Icon name="chevron" size={14} />
                </Link>
              </article>
            ))
          )}
          <div className="site-card flex flex-col items-start gap-3 bg-[var(--site-surface)] p-6 sm:flex-row sm:items-center">
            <p className="flex-1 text-[15px] text-[var(--site-text-2)]">¿Quieres enterarte de las novedades y de los cambios en la normativa SST? Suscríbete al boletín.</p>
            <Link href="/boletin" className="site-btn site-btn-outline">
              Suscribirme
            </Link>
          </div>
        </div>
      </section>
    </>
  );
}

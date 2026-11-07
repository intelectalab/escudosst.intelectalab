// Artículo del centro de ayuda (SCR-WEB-33): migas, cuerpo, video (solo con cookies de marketing), relacionados
// publicados, «¿Te sirvió?» y datos estructurados para Google.

import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { HelpBody } from "@/components/site/HelpBody";
import { HelpFeedback } from "@/components/site/HelpFeedback";
import { VideoBlock } from "@/components/site/VideoBlock";
import { Icon } from "@/components/ui/Icon";
import { getHelpArticle } from "@/lib/help";
import { siteUrl } from "@/lib/seo";
import { getSite, whatsappLink } from "@/lib/site";

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const a = await getHelpArticle((await params).slug);
  if (!a) return { title: "Centro de ayuda · EscudoSST" };
  const description = a.seo_description ?? a.body.replace(/^#+\s*|^-\s+/gm, "").replace(/\s+/g, " ").slice(0, 160);
  return { title: `${a.seo_title} · Ayuda EscudoSST`, description, alternates: { canonical: `/ayuda/${a.slug}` } };
}

export default async function AyudaArticuloPage({ params }: Props) {
  const { slug } = await params;
  const [a, site] = await Promise.all([getHelpArticle(slug), getSite()]);
  if (!a) notFound();
  const base = siteUrl(site.settings.primary_domain);
  const wa = whatsappLink(site.settings, `Hola, tengo una duda sobre «${a.title}»`);
  const ld = [
    {
      "@context": "https://schema.org",
      "@type": "BreadcrumbList",
      itemListElement: [
        { "@type": "ListItem", position: 1, name: "Centro de ayuda", item: `${base}/ayuda` },
        { "@type": "ListItem", position: 2, name: a.category.name, item: `${base}/ayuda?categoria=${a.category.slug}` },
        { "@type": "ListItem", position: 3, name: a.title, item: `${base}/ayuda/${a.slug}` },
      ],
    },
    { "@context": "https://schema.org", "@type": "Article", headline: a.title, datePublished: a.published_at, dateModified: a.updated_at, mainEntityOfPage: `${base}/ayuda/${a.slug}` },
  ];
  const updated = new Date(a.updated_at).toLocaleDateString("es-PE", { day: "numeric", month: "long", year: "numeric", timeZone: "America/Lima" });

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(ld).replace(/</g, "\\u003c") }} />
      <section className="relative overflow-hidden bg-gradient-to-b from-[#EAF3FC] to-white px-4 pb-8 pt-8 sm:pt-12 lg:px-8">
        <div className="absolute -right-32 -top-32 h-80 w-80 rounded-full bg-[#1F7FD1]/12 blur-3xl" aria-hidden="true" />
        <div className="relative mx-auto max-w-[1200px]">
          <nav aria-label="Migas de pan" className="text-sm">
            <ol className="flex flex-wrap items-center gap-1 text-[var(--site-text-2)]">
              <li>
                <Link href="/ayuda" className="font-semibold text-[var(--site-action)] hover:underline">
                  Centro de ayuda
                </Link>
              </li>
              <li aria-hidden="true">›</li>
              <li>
                <Link href={`/ayuda?categoria=${a.category.slug}`} className="font-semibold text-[var(--site-action)] hover:underline">
                  {a.category.name}
                </Link>
              </li>
            </ol>
          </nav>
          <h1 className="mt-3 max-w-4xl font-display text-[28px] font-bold leading-[1.15] text-[var(--site-navy-title)] sm:text-[40px]">{a.title}</h1>
          <p className="mt-2 text-sm text-[var(--site-text-2)]">Actualizado el {updated}</p>
        </div>
      </section>

      <section className="px-4 pb-16 lg:px-8">
        <div className="mx-auto grid max-w-[1200px] grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
          <article className="min-w-0 space-y-6">
            {a.video_url && <VideoBlock url={a.video_url} title={a.title} />}
            <div className="site-card p-5 sm:p-8">
              <HelpBody body={a.body} />
            </div>
            <HelpFeedback slug={a.slug} />
          </article>

          <aside className="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start">
            {a.related.length > 0 && (
              <div className="site-card p-5">
                <p className="font-display font-bold text-[var(--site-navy-title)]">Artículos relacionados</p>
                <ul className="mt-3 space-y-1">
                  {a.related.map((r) => (
                    <li key={r.slug}>
                      <Link href={`/ayuda/${r.slug}`} className="flex items-start gap-2 rounded-lg px-2 py-2 text-sm font-semibold text-[var(--site-action)] hover:bg-[#EAF3FC]">
                        <Icon name="file" size={15} className="mt-0.5 shrink-0" /> <span className="min-w-0">{r.title}</span>
                      </Link>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            <div className="site-card bg-gradient-to-br from-[#0D3B6E] to-[#1F7FD1] p-5 text-white">
              <p className="font-display font-bold">¿Sigues con dudas?</p>
              <p className="mt-1 text-sm text-white/80">Escríbenos y una persona del equipo te ayuda.</p>
              <div className="mt-3 flex flex-wrap gap-2">
                {wa && (
                  <a href={wa} target="_blank" rel="noopener" className="site-btn site-btn-light min-h-11">
                    WhatsApp
                  </a>
                )}
                <Link href="/demo" className="site-btn min-h-11 border border-white/40 text-white hover:bg-white/10">
                  Contacto
                </Link>
              </div>
            </div>
          </aside>
        </div>
      </section>
    </>
  );
}

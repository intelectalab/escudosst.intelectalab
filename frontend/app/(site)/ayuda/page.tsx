// Centro de ayuda (SCR-WEB-32): buscador, categorías con icono, artículos y salida a WhatsApp o contacto.
// ?q= busca en título y cuerpo; ?categoria= filtra. Solo se ve lo publicado de categorías activas (RN-WEB-43).

import type { Metadata } from "next";
import Link from "next/link";
import { FinalCta, PageHero } from "@/components/site/Sections";
import { Icon } from "@/components/ui/Icon";
import { getHelpArticles, getHelpCategories } from "@/lib/help";
import { getSite, whatsappLink } from "@/lib/site";

export const metadata: Metadata = {
  title: "Centro de ayuda · EscudoSST",
  description: "Guías paso a paso para usar EscudoSST: primeros pasos, usuarios, registros obligatorios y más.",
};

type Props = { searchParams: Promise<{ q?: string; categoria?: string }> };

const TONES = ["from-[#EAF3FC] text-[#1F7FD1]", "from-[#E8F7EE] text-[#22A35A]", "from-[#FFF5D6] text-[#B88700]", "from-[#E5F7F4] text-[#16897b]", "from-[#FDECEB] text-[#C8372F]", "from-[#EEEAFB] text-[#5B47C9]"];

export default async function AyudaPage({ searchParams }: Props) {
  const sp = await searchParams;
  const q = typeof sp.q === "string" ? sp.q.trim().slice(0, 100) : "";
  const cat = typeof sp.categoria === "string" ? sp.categoria : "";
  const [cats, articles, site] = await Promise.all([getHelpCategories(), getHelpArticles({ q: q || undefined, categoria: cat || undefined }), getSite()]);
  const current = cats.find((c) => c.slug === cat);
  const wa = whatsappLink(site.settings, "Hola, necesito ayuda con EscudoSST");
  const catName = (slug: string) => cats.find((c) => c.slug === slug)?.name ?? "";
  const filtered = !!(q || current);

  return (
    <>
      <PageHero eyebrow="Centro de ayuda" title="¿En qué te ayudamos?" text="Guías cortas, paso a paso, para sacarle provecho a EscudoSST.">
        <form action="/ayuda" className="mt-7 flex max-w-2xl flex-col gap-2 sm:flex-row" role="search">
          {cat && <input type="hidden" name="categoria" value={cat} />}
          <label className="relative min-w-0 flex-1">
            <span className="sr-only">Buscar en la ayuda</span>
            <Icon name="search" size={18} className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-[#6b7684]" />
            <input
              name="q"
              defaultValue={q}
              maxLength={100}
              placeholder="Ej.: invitar usuarios, matriz IPERC, cambiar contraseña"
              className="h-13 min-h-[52px] w-full rounded-xl border-0 bg-white pl-11 pr-4 text-[15px] text-[#262b33] shadow-lg outline-none ring-[#F4B90F] focus:ring-4"
            />
          </label>
          <button type="submit" className="site-btn site-btn-primary min-h-[52px] px-6">
            Buscar
          </button>
        </form>
      </PageHero>

      <section className="px-4 py-12 lg:px-8">
        <div className="mx-auto max-w-[1360px] space-y-10">
          {cats.length === 0 ? (
            <div className="site-card mx-auto max-w-2xl p-10 text-center">
              <span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-[#EAF3FC] text-[#1F7FD1]">
                <Icon name="book" size={26} />
              </span>
              <h2 className="mt-4 font-display text-xl font-bold text-[var(--site-navy-title)]">Estamos preparando las guías</h2>
              <p className="mt-2 text-[var(--site-text-2)]">Mientras tanto, escríbenos y te ayudamos directamente.</p>
            </div>
          ) : (
            <div>
              <h2 className="font-display text-[22px] font-bold text-[var(--site-navy-title)]">Temas</h2>
              <ul className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                {cats.map((c, i) => {
                  const active = c.slug === cat;
                  return (
                    <li key={c.slug} className="min-w-0">
                      <Link
                        href={active ? "/ayuda" : `/ayuda?categoria=${c.slug}`}
                        aria-current={active ? "true" : undefined}
                        className={`site-card site-card-hover flex h-full items-start gap-3 p-4 ${active ? "ring-2 ring-[#1F7FD1]" : ""}`}
                      >
                        <span className={`grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br to-white ${TONES[i % TONES.length]}`}>
                          <Icon name={c.icon ?? "book"} size={20} />
                        </span>
                        <span className="min-w-0">
                          <b className="block font-display text-[16px] text-[var(--site-navy-title)]">{c.name}</b>
                          {c.description && <span className="mt-0.5 block text-sm text-[var(--site-text-2)]">{c.description}</span>}
                          <span className="mt-1 block text-xs font-semibold text-[var(--site-action)]">
                            {c.articles_count} artículo{c.articles_count === 1 ? "" : "s"}
                          </span>
                        </span>
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </div>
          )}

          {cats.length > 0 && (
            <div>
              <div className="flex flex-wrap items-end justify-between gap-2">
                <h2 className="font-display text-[22px] font-bold text-[var(--site-navy-title)]">
                  {q ? `Resultados para «${q}»` : current ? current.name : "Artículos"}
                  {current && q && <span className="text-[var(--site-text-2)]"> en {current.name}</span>}
                </h2>
                {filtered && (
                  <Link href="/ayuda" className="text-sm font-bold text-[var(--site-action)] hover:underline">
                    Ver todo
                  </Link>
                )}
              </div>
              {articles.length === 0 ? (
                <p className="site-card mt-4 p-6 text-[var(--site-text-2)]">No encontramos artículos{q ? " con esas palabras" : ""}. Prueba con otras palabras o escríbenos.</p>
              ) : (
                <ul className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                  {articles.map((a) => (
                    <li key={a.slug} className="min-w-0">
                      <Link href={`/ayuda/${a.slug}`} className="site-card site-card-hover group flex h-full gap-3 p-5">
                        <span className="min-w-0 flex-1">
                          <span className="flex flex-wrap items-center gap-2 text-xs font-semibold text-[var(--site-text-2)]">
                            {catName(a.category_slug)}
                            {a.video_url && (
                              <span className="inline-flex items-center gap-1 rounded-full bg-[#FDECEB] px-2 py-0.5 text-[#C8372F]">
                                <Icon name="play" size={12} /> Con video
                              </span>
                            )}
                          </span>
                          <b className="mt-1 block font-display text-[17px] leading-snug text-[var(--site-navy-title)] group-hover:text-[var(--site-action)]">{a.title}</b>
                          <span className="mt-1 block text-sm text-[var(--site-text-2)]">{a.excerpt}</span>
                        </span>
                        <Icon name="chevron" size={18} className="mt-1 shrink-0 text-[var(--site-action)]" />
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}

          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div className="site-card flex items-start gap-3 p-5">
              <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[#E8F7EE] text-[#22A35A]">
                <Icon name="mail" size={20} />
              </span>
              <span className="min-w-0">
                <b className="block font-display text-[var(--site-navy-title)]">¿No encontraste lo que buscabas?</b>
                <span className="block text-sm text-[var(--site-text-2)]">Escríbenos y te respondemos en horario de oficina.</span>
                <span className="mt-3 flex flex-wrap gap-2">
                  {wa && (
                    <a href={wa} target="_blank" rel="noopener" className="site-btn site-btn-primary min-h-11">
                      WhatsApp
                    </a>
                  )}
                  <Link href="/demo" className="site-btn site-btn-outline min-h-11">
                    Contacto
                  </Link>
                </span>
              </span>
            </div>
            <div className="site-card flex items-start gap-3 p-5">
              <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[#FFF5D6] text-[#B88700]">
                <Icon name="help" size={20} />
              </span>
              <span className="min-w-0">
                <b className="block font-display text-[var(--site-navy-title)]">¿Dudas antes de contratar?</b>
                <span className="block text-sm text-[var(--site-text-2)]">Planes, pagos, datos y migración: revisa las preguntas frecuentes.</span>
                <Link href="/preguntas-frecuentes" className="mt-3 inline-flex text-sm font-bold text-[var(--site-action)] hover:underline">
                  Ver preguntas frecuentes →
                </Link>
              </span>
            </div>
          </div>
        </div>
      </section>
      <FinalCta s={site.settings} />
    </>
  );
}

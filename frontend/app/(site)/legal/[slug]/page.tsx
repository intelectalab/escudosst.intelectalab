// SCR-WEB-15 · Documento legal (público): versión vigente, índice por secciones, historial y otras políticas.
// Si aún no hay versión publicada, muestra el borrador con el aviso «Documento en preparación».

import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { LegalBody } from "@/components/LegalBody";
import { parseLegal, slugify, TEST_MARK } from "@/lib/legalText";

const API = process.env.API_INTERNAL_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

type Page = {
  status: "published" | "draft";
  title: string;
  slug: string;
  version_label: string | null;
  effective_from?: string | null;
  content: string;
  history: { version_label: string; effective_from: string; status: string }[];
  others: { title: string; slug: string }[];
};

async function load(slug: string): Promise<Page | null> {
  const res = await fetch(`${API}/api/v1/public/legal/${slug}`, process.env.NODE_ENV === "production" ? { next: { revalidate: 30 } } : { cache: "no-store" });
  if (!res.ok) return null;
  return ((await res.json()) as { data: Page }).data;
}

const date = (d?: string | null) => (d ? new Intl.DateTimeFormat("es-PE", { day: "2-digit", month: "2-digit", year: "numeric", timeZone: "America/Lima" }).format(new Date(d)) : "");

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const page = await load((await params).slug);
  return { title: page ? `${page.title} · EscudoSST` : "Documento legal · EscudoSST", robots: page?.status === "draft" ? { index: false } : undefined };
}

export default async function LegalPage({ params }: { params: Promise<{ slug: string }> }) {
  const page = await load((await params).slug);
  if (!page) notFound();
  // Mientras no haya versión vigente, el público ve solo la estructura (sin el contenido de prueba interno).
  const raw = parseLegal(page.content);
  const doc =
    page.status === "draft"
      ? {
          intro: raw.intro.includes(TEST_MARK) ? "" : raw.intro,
          sections: raw.sections.map((s) => ({ title: s.title, body: s.body.includes(TEST_MARK) ? "Contenido en preparación." : s.body })),
        }
      : raw;

  return (
    <>
      <section className="border-b border-[var(--site-border)] bg-gradient-to-b from-[var(--site-surface)] to-white">
        <div className="mx-auto max-w-[1360px] px-4 py-10 sm:py-14 lg:px-8">
          <nav aria-label="Ruta" className="mb-4 flex flex-wrap items-center gap-2 text-sm text-[var(--site-text-2)]">
            <Link href="/" className="underline-offset-2 hover:underline">
              Inicio
            </Link>
            <span aria-hidden="true">/</span>
            <span>Legal</span>
            <span aria-hidden="true">/</span>
            <span className="font-semibold text-[var(--site-navy)]">{page.title}</span>
          </nav>
          <h1 className="font-display text-[32px] font-bold leading-tight text-[var(--site-navy-title)] sm:text-[44px]">{page.title}</h1>
          <p className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-[15px] text-[var(--site-text-2)]">
            {page.status === "published" ? (
              <>
                <span>
                  Versión vigente <b className="text-[var(--site-text)]">{page.version_label}</b>
                </span>
                <span>Desde el {date(page.effective_from)}</span>
              </>
            ) : (
              <span>Aún no vigente</span>
            )}
            {page.history.length > 0 && (
              <a href="#historial" className="text-[var(--site-action)] underline underline-offset-2">
                Historial de versiones
              </a>
            )}
          </p>
        </div>
      </section>

      <div className="mx-auto grid max-w-[1360px] grid-cols-1 gap-10 px-4 py-10 lg:grid-cols-[17rem_minmax(0,1fr)] lg:px-8">
        <aside className="space-y-6 lg:sticky lg:top-24 lg:self-start">
          <details open className="rounded-xl border border-[var(--site-border)] bg-white p-4">
            <summary className="cursor-pointer font-display font-bold text-[var(--site-navy-title)]">Contenido</summary>
            <ol className="mt-3 space-y-1.5 text-sm">
              {doc.sections.map((s, i) => (
                <li key={i}>
                  <a href={`#${slugify(s.title) || `seccion-${i + 1}`}`} className="text-[var(--site-text-2)] hover:text-[var(--site-action)]">
                    {i + 1}. {s.title}
                  </a>
                </li>
              ))}
            </ol>
          </details>
          {page.others.length > 0 && (
            <div>
              <p className="mb-2 text-xs font-bold uppercase tracking-wider text-[var(--site-text-2)]">Otras políticas</p>
              <ul className="space-y-1.5 text-sm">
                {page.others.map((o) => (
                  <li key={o.slug}>
                    <Link href={`/legal/${o.slug}`} className="font-semibold text-[var(--site-action)] hover:underline">
                      {o.title}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </aside>

        <article className="min-w-0 max-w-3xl space-y-8">
          {page.status === "draft" && (
            <div role="note" className="rounded-r-xl border-l-4 border-[var(--site-amber)] bg-[#F4B90F]/12 px-5 py-4">
              <p className="font-display font-bold text-[#8A6704]">Documento en preparación</p>
              <p className="mt-1 text-[15px] text-[#6B5200]">Esta política se publicará próximamente.</p>
            </div>
          )}
          <LegalBody doc={doc} />
          {page.history.length > 0 && (
            <section id="historial" className="scroll-mt-28 border-t border-[var(--site-border)] pt-8">
              <h2 className="mb-3 font-display text-xl font-bold text-[var(--site-navy-title)]">Historial de versiones</h2>
              <ul className="divide-y divide-[var(--site-border)] rounded-xl border border-[var(--site-border)] text-sm">
                {page.history.map((h) => (
                  <li key={h.version_label} className="flex flex-wrap justify-between gap-2 px-4 py-3">
                    <b>Versión {h.version_label}</b>
                    <span className="text-[var(--site-text-2)]">
                      {h.status === "published" ? "Vigente" : "Reemplazada"} · desde el {date(h.effective_from)}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </article>
      </div>
    </>
  );
}

// SCR-VTA-15 · Ficha pública del curso: temario, horas, modalidad, certificado, precio y acceso; «Solicitar compra» para
// una persona o cupos para una empresa (SCR-VTA-16, pago manual). Al final, otros cursos de la misma empresa (SCR-VTA-13).

import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { CatalogUnavailable, CoursePurchaseForm, OfferCard } from "@/components/site/CourseCatalog";
import { Icon } from "@/components/ui/Icon";
import { getCatalogOffer, MODALITY, soles } from "@/lib/courseCatalog";

type Props = { params: Promise<{ empresa: string; oferta: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { empresa, oferta } = await params;
  const r = await getCatalogOffer(empresa, oferta);
  return r.ok
    ? { title: `${r.data.offer.title} · ${r.data.company.name}`, description: (r.data.offer.short_description ?? r.data.offer.summary ?? "").slice(0, 160) || undefined }
    : { title: "Curso", robots: { index: false } };
}

export default async function CourseOfferPage({ params }: Props) {
  const { empresa, oferta } = await params;
  const r = await getCatalogOffer(empresa, oferta);
  if (!r.ok) {
    if (r.reason === "not_found") notFound();
    return <CatalogUnavailable reason={r.reason} />;
  }
  const { company, offer: o, others } = r.data;
  const soldOut = o.seats_left !== null && o.seats_left <= 0;
  const facts = [
    o.hours ? { label: "Duración", value: `${o.hours} horas` } : null,
    { label: "Modalidad", value: MODALITY[o.delivery_mode] ?? "Virtual" },
    { label: "Acceso", value: o.access_days ? `${o.access_days} días desde la matrícula` : "Mientras el curso esté publicado" },
    o.certificate ? { label: "Certificado", value: "Verificable con código y QR" } : null,
  ].filter((x): x is { label: string; value: string } => !!x);

  return (
    <section className="bg-gradient-to-b from-[var(--site-surface)] to-white px-4 py-12 lg:px-8">
      <div className="mx-auto max-w-[1200px] space-y-12">
        <div className="grid grid-cols-1 gap-10 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
          <div className="min-w-0 space-y-6">
            <Link href={`/cursos/${company.catalog}`} className="flex w-fit items-center gap-1 text-sm font-semibold text-[var(--site-action)]">
              <Icon name="back" size={14} /> Cursos de {company.name}
            </Link>
            <div className="space-y-3">
              {o.category && <p className="site-eyebrow">{o.category}</p>}
              <h1 className="font-display text-[30px] font-bold leading-tight text-[var(--site-navy-title)] sm:text-[40px]">{o.title}</h1>
              {(o.short_description || o.summary) && <p className="text-[17px] leading-relaxed text-[var(--site-text)]">{o.short_description || o.summary}</p>}
            </div>
            <div className="flex flex-wrap gap-3">
              {facts.map((f) => (
                <div key={f.label} className="rounded-2xl border border-[var(--site-border)] bg-white px-5 py-3">
                  <span className="block text-xs uppercase tracking-wide text-[var(--site-text-2)]">{f.label}</span>
                  <span className="font-display font-bold text-[var(--site-navy-title)]">{f.value}</span>
                </div>
              ))}
            </div>
            {o.description && <p className="whitespace-pre-line leading-relaxed text-[var(--site-text)]">{o.description}</p>}
            {o.objectives && (
              <div className="site-card p-6">
                <h2 className="mb-2 font-display text-lg font-bold text-[var(--site-navy-title)]">Qué aprenderás</h2>
                <p className="whitespace-pre-line text-[var(--site-text)]">{o.objectives}</p>
              </div>
            )}
            {o.sections.length > 0 && (
              <div className="site-card p-6">
                <h2 className="mb-3 font-display text-lg font-bold text-[var(--site-navy-title)]">Temario</h2>
                <ol className="space-y-3">
                  {o.sections.map((s, i) => (
                    <li key={i} className="flex gap-3">
                      <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-[var(--site-surface)] text-sm font-bold text-[var(--site-navy-title)]">{i + 1}</span>
                      <span>
                        <b className="block text-[var(--site-navy-title)]">{s.title}</b>
                        {s.description && <span className="text-sm text-[var(--site-text-2)]">{s.description}</span>}
                      </span>
                    </li>
                  ))}
                </ol>
              </div>
            )}
            {o.target_audience && (
              <div className="site-card p-6">
                <h2 className="mb-2 font-display text-lg font-bold text-[var(--site-navy-title)]">Dirigido a</h2>
                <p className="whitespace-pre-line text-[var(--site-text)]">{o.target_audience}</p>
              </div>
            )}
          </div>
          <div className="site-card h-fit space-y-5 p-6 sm:p-8 lg:sticky lg:top-24">
            <div>
              {o.sale_price !== null && o.sale_price < o.list_price && <s className="mr-2 text-[var(--site-text-2)]">{soles(o.list_price)}</s>}
              <b className="font-display text-3xl text-[var(--site-navy-title)]">{soles(o.price)}</b>
              <span className="block text-xs text-[var(--site-text-2)]">
                {o.price_includes_igv ? "IGV incluido" : "Precio sin IGV"} · precio definido por {company.name}
                {o.seats_left !== null && !soldOut ? ` · quedan ${o.seats_left} cupos` : ""}
              </span>
            </div>
            {soldOut ? (
              <div className="space-y-3 text-center">
                <Icon name="people" size={28} className="mx-auto text-[var(--site-text-2)]" />
                <p className="font-semibold text-[var(--site-navy-title)]">Ya no quedan cupos en este curso.</p>
              </div>
            ) : (
              <CoursePurchaseForm catalog={company.catalog} company={company.name} offer={o} />
            )}
          </div>
        </div>
        {others.length > 0 && (
          <div className="space-y-5">
            <h2 className="font-display text-2xl font-bold text-[var(--site-navy-title)]">También te puede interesar</h2>
            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
              {others.map((x) => (
                <OfferCard key={x.slug} catalog={company.catalog} o={x} />
              ))}
            </div>
          </div>
        )}
      </div>
    </section>
  );
}

// SCR-VTA-12 · Catálogo público de cursos de una empresa (sin sesión): cursos en venta con horas, precio definido por la
// empresa y certificado; búsqueda y filtro por categoría. Si la empresa no tiene la venta de cursos activa, «no disponible».

import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { CatalogGrid, CatalogUnavailable } from "@/components/site/CourseCatalog";
import { getCatalog } from "@/lib/courseCatalog";

type Props = { params: Promise<{ empresa: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const r = await getCatalog((await params).empresa);
  return r.ok
    ? { title: `Cursos de ${r.data.company.name}`, description: `Cursos de seguridad y salud en el trabajo de ${r.data.company.name}, con certificado verificable.` }
    : { title: "Catálogo de cursos", robots: { index: false } };
}

export default async function CourseCatalogPage({ params }: Props) {
  const r = await getCatalog((await params).empresa);
  if (!r.ok) {
    if (r.reason === "not_found") notFound();
    return <CatalogUnavailable reason={r.reason} />;
  }
  const { company, offers } = r.data;

  return (
    <section className="bg-gradient-to-b from-[var(--site-surface)] to-white px-4 py-12 lg:px-8">
      <div className="mx-auto max-w-[1200px] space-y-8">
        <header className="flex items-center gap-4">
          <span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-[var(--site-navy)] font-display text-lg font-bold text-white" aria-hidden="true">
            {company.name.split(/\s+/).slice(0, 2).map((w) => w.charAt(0)).join("").toUpperCase()}
          </span>
          <div className="min-w-0">
            <p className="site-eyebrow">Cursos de {company.name}</p>
            <h1 className="font-display text-[28px] font-bold leading-tight text-[var(--site-navy-title)] sm:text-[36px]">Cursos de Seguridad y Salud en el Trabajo</h1>
          </div>
        </header>
        <p className="max-w-2xl text-[17px] text-[var(--site-text-2)]">
          Compra para ti o cupos para tu empresa. Los precios los define {company.name} e incluyen IGV.
        </p>
        <CatalogGrid catalog={company.catalog} offers={offers} />
      </div>
    </section>
  );
}

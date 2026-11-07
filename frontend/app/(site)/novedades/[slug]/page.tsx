// SCR-WEB-75 · Ficha de una novedad del producto.

import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Icon } from "@/components/ui/Icon";
import { getProductUpdate, UPDATE_CATEGORIES } from "@/lib/site";

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const u = await getProductUpdate((await params).slug);
  return { title: u ? `${u.title} · Novedades de EscudoSST` : "Novedades de EscudoSST", description: u?.body?.slice(0, 160) };
}

export default async function NovedadPage({ params }: Props) {
  const u = await getProductUpdate((await params).slug);
  if (!u) notFound();
  return (
    <section className="px-4 py-12 lg:px-8">
      <article className="mx-auto max-w-[760px] space-y-5">
        <Link href="/novedades" className="flex w-fit items-center gap-1 text-sm font-semibold text-[var(--site-action)]">
          <Icon name="back" size={14} /> Todas las novedades
        </Link>
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span className={`rounded-full px-3 py-1 text-xs font-bold ${UPDATE_CATEGORIES[u.category][1]}`}>{UPDATE_CATEGORIES[u.category][0]}</span>
          <time dateTime={u.published_at} className="text-[var(--site-text-2)]">
            {new Intl.DateTimeFormat("es-PE", { dateStyle: "long", timeZone: "America/Lima" }).format(new Date(u.published_at))}
          </time>
        </div>
        <h1 className="font-display text-[32px] font-bold leading-tight text-[var(--site-navy-title)]">{u.title}</h1>
        <div className="whitespace-pre-line text-[17px] leading-relaxed text-[var(--site-text)]">{u.body}</div>
      </article>
    </section>
  );
}

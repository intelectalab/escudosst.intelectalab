// Una edición enviada del boletín, para leerla en el navegador o compartirla.

import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { HelpBody } from "@/components/site/HelpBody";
import { getNlIssue, NL_TOPICS, nlDate } from "@/lib/newsletter";

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const i = await getNlIssue((await params).slug);
  return i ? { title: `${i.subject} · Boletín EscudoSST`, description: i.preheader ?? undefined } : { title: "Boletín · EscudoSST" };
}

export default async function BoletinEdicionPage({ params }: Props) {
  const i = await getNlIssue((await params).slug);
  if (!i) notFound();
  return (
    <>
      <section className="bg-gradient-to-b from-[#EAF3FC] to-white px-4 pb-8 pt-8 sm:pt-12 lg:px-8">
        <div className="mx-auto max-w-[860px]">
          <Link href="/boletin" className="text-sm font-bold text-[var(--site-action)] hover:underline">
            ← Boletín normativo
          </Link>
          <h1 className="mt-3 font-display text-[28px] font-bold leading-[1.15] text-[var(--site-navy-title)] sm:text-[40px]">{i.subject}</h1>
          <p className="mt-2 flex flex-wrap items-center gap-2 text-sm text-[var(--site-text-2)]">
            Enviado el {nlDate(i.sent_at)}
            {i.topics_json.map((t) => (
              <span key={t} className="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-[#1F7FD1]">
                {NL_TOPICS[t] ?? t}
              </span>
            ))}
          </p>
        </div>
      </section>
      <section className="px-4 pb-16 lg:px-8">
        <div className="mx-auto max-w-[860px] space-y-5">
          <article className="site-card p-5 sm:p-8">
            <HelpBody body={i.body} />
            <p className="mt-6 text-sm text-[var(--site-text-2)]">Información referencial; no reemplaza la lectura de la norma ni la asesoría legal.</p>
          </article>
          <div className="site-card flex flex-col items-start gap-3 bg-gradient-to-br from-[#0D3B6E] to-[#1F7FD1] p-6 text-white sm:flex-row sm:items-center">
            <p className="flex-1 font-display text-lg font-bold">¿Quieres recibir la próxima edición en tu correo?</p>
            <Link href="/boletin#suscribirme" className="site-btn site-btn-light min-h-11">
              Suscribirme
            </Link>
          </div>
        </div>
      </section>
    </>
  );
}

// Boletín normativo SST (SCR-WEB-36): suscripción con doble confirmación y archivo de ediciones enviadas.

import type { Metadata } from "next";
import Link from "next/link";
import { NewsletterForm } from "@/components/site/NewsletterForm";
import { PageHero } from "@/components/site/Sections";
import { Icon } from "@/components/ui/Icon";
import { getNlIssues, NL_TOPICS, nlDate } from "@/lib/newsletter";

export const metadata: Metadata = {
  title: "Boletín normativo SST · EscudoSST",
  description: "Recibe en tu correo los cambios en la normativa de seguridad y salud en el trabajo, SUNAFIL y novedades por sector.",
};

const WHAT: [string, string, string][] = [
  ["file", "Cambios en la normativa", "Leyes, reglamentos y resoluciones de SST explicados en simple."],
  ["shield", "SUNAFIL e inspecciones", "Criterios, campañas y lo que suelen revisar en una inspección."],
  ["building", "Novedades por sector", "Lo que cambia para construcción, minería, industria y otros rubros."],
];

export default async function BoletinPage() {
  const issues = await getNlIssues();
  return (
    <>
      <PageHero eyebrow="Boletín normativo SST" title="La normativa SST, en tu correo" text="Te contamos qué cambió y qué debes hacer, sin lenguaje complicado. Te das de baja cuando quieras con un clic." />
      <section className="px-4 py-14 lg:px-8">
        <div className="mx-auto grid max-w-[1360px] grid-cols-1 gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,560px)]">
          <div className="min-w-0 space-y-4">
            <h2 className="font-display text-[26px] font-bold text-[var(--site-navy-title)]">Qué recibirás</h2>
            <ul className="space-y-3">
              {WHAT.map(([icon, t, d]) => (
                <li key={t} className="site-card flex items-start gap-3 p-4">
                  <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-[#EAF3FC] to-white text-[#1F7FD1]">
                    <Icon name={icon} size={20} />
                  </span>
                  <span className="min-w-0">
                    <b className="block font-display text-[var(--site-navy-title)]">{t}</b>
                    <span className="text-sm text-[var(--site-text-2)]">{d}</span>
                  </span>
                </li>
              ))}
            </ul>
            <p className="text-sm text-[var(--site-text-2)]">Eliges los temas. Solo te escribimos sobre lo que marcaste, y tu correo no se usa para otra cosa salvo que lo autorices aparte.</p>
          </div>
          <div id="suscribirme" className="min-w-0 scroll-mt-24">
            <NewsletterForm />
          </div>
        </div>
      </section>

      <section className="bg-[var(--site-surface)] px-4 py-14 lg:px-8">
        <div className="mx-auto max-w-[1360px]">
          <h2 className="font-display text-[26px] font-bold text-[var(--site-navy-title)]">Ediciones anteriores</h2>
          {issues.length === 0 ? (
            <p className="site-card mt-4 p-6 text-[var(--site-text-2)]">Todavía no enviamos ninguna edición. Suscríbete para recibir la primera.</p>
          ) : (
            <ul className="mt-5 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
              {issues.map((i) => (
                <li key={i.slug} className="min-w-0">
                  <Link href={`/boletin/${i.slug}`} className="site-card site-card-hover group flex h-full flex-col p-5">
                    <span className="text-xs font-semibold text-[var(--site-text-2)]">{nlDate(i.sent_at)}</span>
                    <b className="mt-1 font-display text-[17px] leading-snug text-[var(--site-navy-title)] group-hover:text-[var(--site-action)]">{i.subject}</b>
                    <span className="mt-1.5 text-sm text-[var(--site-text-2)]">{i.preheader ?? i.excerpt}</span>
                    <span className="mt-3 flex flex-wrap gap-1.5">
                      {i.topics_json.map((t) => (
                        <span key={t} className="rounded-full bg-[#EAF3FC] px-2.5 py-0.5 text-xs font-semibold text-[#1F7FD1]">
                          {NL_TOPICS[t] ?? t}
                        </span>
                      ))}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
      </section>
    </>
  );
}

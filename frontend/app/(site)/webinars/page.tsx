// SCR-WEB-78 · Webinars: próximos (con inscripción) y grabados (solo si se avisó que se grababa).
// Se organizan en el panel: Sitio web › Webinars.

import type { Metadata } from "next";
import Link from "next/link";
import { PageHero } from "@/components/site/Sections";
import { Icon } from "@/components/ui/Icon";
import { assetUrl, getWebinars, type Webinar } from "@/lib/site";

export const metadata: Metadata = {
  title: "Webinars de SST · EscudoSST",
  description: "Charlas en vivo sobre seguridad y salud en el trabajo en el Perú. Inscríbete gratis.",
};

function WebinarCard({ w }: { w: Webinar }) {
  const d = new Date(w.starts_at);
  const cover = assetUrl(w.cover_file_id);
  return (
    <li className="site-card flex flex-col overflow-hidden">
      {cover && (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={cover} alt="" className="h-40 w-full object-cover" />
      )}
      <div className="flex flex-1 gap-4 p-5">
        <div className="w-16 shrink-0 rounded-xl bg-[var(--site-navy)] py-2 text-center text-white">
          <span className="block text-xs uppercase">{new Intl.DateTimeFormat("es-PE", { month: "short", timeZone: "America/Lima" }).format(d)}</span>
          <span className="block font-display text-2xl font-bold">{new Intl.DateTimeFormat("es-PE", { day: "numeric", timeZone: "America/Lima" }).format(d)}</span>
        </div>
        <div className="min-w-0 flex-1 space-y-1">
          <h2 className="font-display text-lg font-bold leading-snug text-[var(--site-navy-title)]">
            <Link href={`/webinars/${w.slug}`} className="hover:underline">
              {w.title}
            </Link>
          </h2>
          <p className="text-sm text-[var(--site-text-2)]">
            {new Intl.DateTimeFormat("es-PE", { weekday: "long", hour: "numeric", minute: "2-digit", timeZone: "America/Lima" }).format(d)} (hora de Lima) · {w.duration_minutes} min
          </p>
          <p className="text-sm text-[var(--site-text-2)]">Con {w.speaker_name}</p>
          {w.status !== "finished" && w.seats_left !== null && <p className="text-xs font-semibold text-[var(--site-action)]">{w.seats_left > 0 ? `Quedan ${w.seats_left} cupos` : "Sin cupos"}</p>}
        </div>
      </div>
    </li>
  );
}

export default async function WebinarsPage() {
  const { upcoming, recorded } = await getWebinars();
  return (
    <>
      <PageHero eyebrow="Webinars" title="Aprende SST con expertos, en vivo" text="Charlas cortas y prácticas sobre la Ley 29783 y la gestión de la seguridad en tu empresa. La inscripción es gratis." />
      <section className="px-4 py-12 lg:px-8">
        <div className="mx-auto max-w-[1200px] space-y-10">
          <div className="space-y-4">
            <h2 className="font-display text-2xl font-bold text-[var(--site-navy-title)]">Próximos</h2>
            {upcoming.length === 0 ? (
              <div className="site-card flex flex-col items-start gap-3 p-6 sm:flex-row sm:items-center">
                <Icon name="clock" size={22} className="text-[var(--site-text-2)]" />
                <p className="flex-1 text-[15px] text-[var(--site-text-2)]">No hay webinars programados por ahora. Suscríbete al boletín y te avisamos del próximo.</p>
                <Link href="/boletin" className="site-btn site-btn-outline">
                  Suscribirme
                </Link>
              </div>
            ) : (
              <ul className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                {upcoming.map((w) => (
                  <WebinarCard key={w.slug} w={w} />
                ))}
              </ul>
            )}
          </div>
          {recorded.length > 0 && (
            <div className="space-y-4">
              <h2 className="font-display text-2xl font-bold text-[var(--site-navy-title)]">Grabados</h2>
              <ul className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                {recorded.map((w) => (
                  <WebinarCard key={w.slug} w={w} />
                ))}
              </ul>
            </div>
          )}
        </div>
      </section>
    </>
  );
}

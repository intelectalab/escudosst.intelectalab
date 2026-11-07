// SCR-WEB-78 · Ficha de un webinar: ponente, fecha y hora de Lima, cupos e inscripción. El enlace de la reunión no
// se publica: llega por correo a los inscritos. Si terminó y se publicó la grabación, se puede ver aquí.

import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { WebinarRegisterForm } from "@/components/site/WebinarForms";
import { Icon } from "@/components/ui/Icon";
import { assetUrl, getWebinar } from "@/lib/site";

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const w = await getWebinar((await params).slug);
  return { title: w ? `${w.title} · Webinar de EscudoSST` : "Webinars de EscudoSST", description: w?.description?.slice(0, 160) ?? undefined };
}

const PROVIDERS: Record<string, string> = { meet: "Google Meet", teams: "Microsoft Teams", zoom: "Zoom", other: "En línea" };

export default async function WebinarPage({ params }: Props) {
  const w = await getWebinar((await params).slug);
  if (!w) notFound();
  const d = new Date(w.starts_at);
  const recording = assetUrl(w.recording_file_id);
  const full = w.seats_left !== null && w.seats_left <= 0;

  return (
    <section className="bg-gradient-to-b from-[var(--site-surface)] to-white px-4 py-12 lg:px-8">
      <div className="mx-auto grid max-w-[1100px] grid-cols-1 gap-10 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
        <div className="space-y-5">
          <Link href="/webinars" className="flex w-fit items-center gap-1 text-sm font-semibold text-[var(--site-action)]">
            <Icon name="back" size={14} /> Todos los webinars
          </Link>
          <p className="site-eyebrow">{w.status === "finished" ? "Webinar grabado" : w.status === "live" ? "En vivo ahora" : "Webinar gratuito"}</p>
          <h1 className="font-display text-[32px] font-bold leading-tight text-[var(--site-navy-title)] sm:text-[40px]">{w.title}</h1>
          <div className="flex flex-wrap gap-3">
            <div className="rounded-2xl bg-[var(--site-navy)] px-5 py-3 text-white">
              <span className="block text-xs uppercase tracking-wide text-white/70">Fecha (hora de Lima)</span>
              <span className="font-display text-lg font-bold">
                {new Intl.DateTimeFormat("es-PE", { weekday: "long", day: "numeric", month: "long", hour: "numeric", minute: "2-digit", timeZone: "America/Lima" }).format(d)}
              </span>
            </div>
            <div className="rounded-2xl border border-[var(--site-border)] bg-white px-5 py-3">
              <span className="block text-xs uppercase tracking-wide text-[var(--site-text-2)]">Duración</span>
              <span className="font-display text-lg font-bold text-[var(--site-navy-title)]">{w.duration_minutes} minutos</span>
            </div>
            <div className="rounded-2xl border border-[var(--site-border)] bg-white px-5 py-3">
              <span className="block text-xs uppercase tracking-wide text-[var(--site-text-2)]">Plataforma</span>
              <span className="font-display text-lg font-bold text-[var(--site-navy-title)]">{PROVIDERS[w.meeting_provider] ?? "En línea"}</span>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <span className="grid h-11 w-11 place-items-center rounded-full bg-[var(--site-surface)] font-display font-bold text-[var(--site-navy-title)]">{w.speaker_name.charAt(0)}</span>
            <span>
              <b className="block text-[var(--site-navy-title)]">{w.speaker_name}</b>
              {w.speaker_title && <span className="text-sm text-[var(--site-text-2)]">{w.speaker_title}</span>}
            </span>
          </div>
          {w.description && <p className="whitespace-pre-line text-[17px] leading-relaxed text-[var(--site-text)]">{w.description}</p>}
          {recording && (
            <video controls preload="metadata" className="w-full rounded-2xl bg-black">
              <source src={recording} />
            </video>
          )}
        </div>
        <div className="site-card h-fit p-6 sm:p-8">
          {w.registration_open && !full ? (
            <>
              <h2 className="mb-1 font-display text-xl font-bold text-[var(--site-navy-title)]">Inscríbete gratis</h2>
              <p className="mb-5 text-sm text-[var(--site-text-2)]">{w.seats_left !== null ? `Quedan ${w.seats_left} cupos.` : "Cupos disponibles."} Te enviamos el enlace por correo.</p>
              <WebinarRegisterForm slug={w.slug} />
            </>
          ) : (
            <div className="space-y-3 text-center">
              <Icon name={full ? "people" : "clock"} size={28} className="mx-auto text-[var(--site-text-2)]" />
              <p className="font-semibold text-[var(--site-navy-title)]">{full ? "Ya no quedan cupos." : w.status === "finished" ? "Este webinar ya se realizó." : "Las inscripciones están cerradas."}</p>
              <Link href="/webinars" className="site-btn site-btn-outline inline-flex">
                Ver otros webinars
              </Link>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}

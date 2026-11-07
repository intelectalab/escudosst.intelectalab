// Piezas de los casos de éxito: tarjeta del listado y rótulo «Ejemplo» (RN-WEB-41: siempre visible en los ejemplos).

import Link from "next/link";
import { Icon } from "@/components/ui/Icon";
import { assetUrl, STORY_SECTORS, type SuccessStory } from "@/lib/site";

export function ExampleBadge() {
  return (
    <span className="inline-flex items-center rounded-md bg-[#F4B90F]/25 px-2 py-0.5 text-xs font-bold text-[#7A5B00]" title="Caso ilustrativo: no corresponde a un cliente real">
      Ejemplo
    </span>
  );
}

export function StoryLogo({ s, size = 48 }: { s: Pick<SuccessStory, "logo_file_id" | "client_name">; size?: number }) {
  const url = assetUrl(s.logo_file_id);
  return url ? (
    // eslint-disable-next-line @next/next/no-img-element
    <img src={url} alt={`Logo de ${s.client_name}`} style={{ height: size, maxWidth: size * 3 }} className="w-auto object-contain" />
  ) : (
    <span style={{ height: size, width: size }} className="grid shrink-0 place-items-center rounded-xl bg-[var(--site-surface)] font-display text-lg font-bold text-[var(--site-action)]" aria-hidden="true">
      {s.client_name.trim().charAt(0).toUpperCase()}
    </span>
  );
}

export function StoryCard({ s }: { s: SuccessStory }) {
  const first = s.results[0];
  return (
    <Link href={`/casos/${s.slug}`} className="site-card site-card-hover group flex h-full flex-col gap-4 p-6">
      <span className="flex items-center justify-between gap-3">
        <StoryLogo s={s} size={40} />
        {s.is_example && <ExampleBadge />}
      </span>
      <span className="flex flex-wrap gap-x-2 text-xs font-bold uppercase tracking-wide text-[var(--site-action)]">
        <span>{STORY_SECTORS[s.sector] ?? s.sector}</span>
        {s.project_year && <span className="text-[var(--site-text-2)]">· {s.project_year}</span>}
      </span>
      <span className="space-y-1">
        <b className="block font-display text-lg leading-snug text-[var(--site-navy-title)]">{s.title}</b>
        <span className="block text-sm text-[var(--site-text-2)]">{s.client_name}</span>
      </span>
      {first && (
        <span className="mt-auto flex items-baseline gap-2 rounded-xl bg-[var(--site-surface)] px-4 py-3">
          <b className="font-display text-2xl text-[var(--site-navy-title)]">{first.value}</b>
          <span className="text-sm text-[var(--site-text-2)]">{first.label}</span>
        </span>
      )}
      <span className="inline-flex items-center gap-1 text-sm font-bold text-[var(--site-action)]">
        Leer el caso <Icon name="chevron" size={14} className="transition-transform group-hover:translate-x-0.5" />
      </span>
    </Link>
  );
}

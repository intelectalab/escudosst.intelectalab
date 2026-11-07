// Muestra los bloques de una página del sitio. Sin datos del servidor: recibe los módulos ya cargados, así sirve
// igual en el sitio público y en la vista previa del panel.
// Usa consultas de contenedor (@container): las columnas dependen del ancho de la página y no de la pantalla, así
// la vista previa «Celular» del panel se ve igual que en un celular real.

import Link from "next/link";
import { VideoBlock } from "@/components/site/VideoBlock";
import { Icon } from "@/components/ui/Icon";
import { type Block, CTA_BUTTONS } from "@/lib/pages";
import { assetUrl } from "@/lib/site";

type Mod = { code: string; name: string; solves: string | null; from_plan?: string | null; is_addon?: boolean };

const PLAN = { E: "Esencial", P: "Profesional", C: "Corporativo", K: "Consultora" } as Record<string, string>;

export function PageBlocks({ blocks, modules }: { blocks: Block[]; modules: Mod[] }) {
  return (
    <div className="@container">
      {blocks.map((b, i) => (
        <BlockView key={i} b={b} modules={modules} alt={i % 2 === 1} />
      ))}
    </div>
  );
}

function Wrap({ alt, children, narrow = false }: { alt: boolean; children: React.ReactNode; narrow?: boolean }) {
  return (
    <section className={`px-5 py-12 @2xl:py-14 @5xl:px-8 ${alt ? "bg-[var(--site-surface)]" : ""}`}>
      <div className={`mx-auto space-y-6 ${narrow ? "max-w-3xl" : "max-w-[1200px]"}`}>{children}</div>
    </section>
  );
}

function Title({ children }: { children: React.ReactNode }) {
  return children ? <h2 className="font-display text-[26px] font-bold leading-tight text-[var(--site-navy-title)] @2xl:text-[32px]">{children}</h2> : null;
}

function BlockView({ b, modules, alt }: { b: Block; modules: Mod[]; alt: boolean }) {
  switch (b.type) {
    case "hero":
      return (
        <section className="relative overflow-hidden bg-gradient-to-b from-[#EAF3FC] to-white px-5 py-14 @2xl:py-20 @5xl:px-8">
          <div className="absolute -right-32 -top-32 h-80 w-80 rounded-full bg-[#1F7FD1]/12 blur-3xl" aria-hidden="true" />
          <div className="relative mx-auto max-w-[1200px] space-y-4">
            {b.eyebrow && <p className="site-eyebrow">{b.eyebrow}</p>}
            <h1 className="max-w-3xl font-display text-[30px] font-bold leading-[1.15] text-[var(--site-navy-title)] @2xl:text-[46px]">{b.title}</h1>
            {b.text && <p className="max-w-2xl text-[18px] leading-relaxed text-[var(--site-text-2)]">{b.text}</p>}
          </div>
        </section>
      );
    case "text":
      return (
        <Wrap alt={alt} narrow>
          <Title>{b.title}</Title>
          <p className="whitespace-pre-line text-[17px] leading-relaxed text-[var(--site-text)]">{b.body}</p>
        </Wrap>
      );
    case "bullets":
      return (
        <Wrap alt={alt}>
          <Title>{b.title}</Title>
          <ul className="grid grid-cols-1 gap-3 @xl:grid-cols-2 @4xl:grid-cols-3">
            {b.items.map((t) => (
              <li key={t} className="site-card flex items-start gap-3 p-4">
                <span className="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-[#22A35A] text-white">
                  <Icon name="check" size={13} />
                </span>
                <span className="font-semibold text-[var(--site-text)]">{t}</span>
              </li>
            ))}
          </ul>
        </Wrap>
      );
    case "norms":
      return (
        <Wrap alt={alt}>
          <div className="flex flex-wrap items-center gap-3">
            <Title>{b.title}</Title>
            <span className="rounded-md bg-[#F4B90F]/20 px-2 py-0.5 text-xs font-bold text-[#8A6704]">Referencial · a validar con un especialista</span>
          </div>
          <ul className="space-y-2">
            {b.items.map((t) => (
              <li key={t} className="flex items-start gap-3 rounded-xl border border-[var(--site-border)] bg-white px-4 py-3">
                <Icon name="shield" size={18} className="mt-0.5 shrink-0 text-[var(--site-action)]" />
                <span className="text-[var(--site-text)]">{t}</span>
              </li>
            ))}
          </ul>
        </Wrap>
      );
    case "modules": {
      const list = b.items.map((c) => modules.find((m) => m.code === c)).filter(Boolean) as Mod[];
      return list.length ? (
        <Wrap alt={alt}>
          <Title>{b.title}</Title>
          <ul className="grid grid-cols-1 gap-4 @xl:grid-cols-2 @4xl:grid-cols-3">
            {list.map((m) => (
              <li key={m.code} className="site-card site-card-hover flex flex-col p-5">
                <p className="font-display font-bold text-[var(--site-navy-title)]">{m.name}</p>
                {m.solves && <p className="mt-1.5 flex-1 text-sm leading-relaxed text-[var(--site-text-2)]">{m.solves}</p>}
                <p className="mt-3">
                  <span className={`rounded-md px-2 py-0.5 text-xs font-bold ${m.is_addon ? "bg-[#F4B90F]/20 text-[#8A6704]" : "bg-[var(--site-action)]/10 text-[var(--site-navy)]"}`}>
                    {m.is_addon ? "Complemento (se agrega aparte)" : m.from_plan ? `Incluido desde el plan ${PLAN[m.from_plan] ?? m.from_plan}` : "Plan Consultora"}
                  </span>
                </p>
              </li>
            ))}
          </ul>
          <p className="flex flex-wrap gap-x-5 gap-y-1">
            <Link href="/planes#comparativa" className="font-bold text-[var(--site-action)] hover:underline">
              Ver qué incluye cada plan →
            </Link>
            <Link href="/producto" className="font-bold text-[var(--site-action)] hover:underline">
              Ver todos los módulos →
            </Link>
          </p>
        </Wrap>
      ) : null;
    }
    case "video":
      return b.url ? (
        <Wrap alt={alt} narrow>
          <Title>{b.title}</Title>
          <VideoBlock url={b.url} title={b.title || "Video"} />
        </Wrap>
      ) : null;
    case "faq":
      return b.items.length ? (
        <Wrap alt={alt} narrow>
          <Title>{b.title}</Title>
          <div className="space-y-3">
            {b.items.map((f) => (
              <details key={f.q} className="site-card group overflow-hidden">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 font-display font-semibold text-[var(--site-navy-title)] [&::-webkit-details-marker]:hidden">
                  {f.q}
                  <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-[var(--site-surface)] text-[var(--site-action)] transition-transform group-open:rotate-45">
                    <Icon name="plus" size={16} />
                  </span>
                </summary>
                <p className="whitespace-pre-line px-5 pb-5 text-[15px] leading-relaxed text-[var(--site-text-2)]">{f.a}</p>
              </details>
            ))}
          </div>
        </Wrap>
      ) : null;
    case "image":
      return b.file_id ? (
        <Wrap alt={alt} narrow>
          <Title>{b.title}</Title>
          <figure className="space-y-2">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={assetUrl(b.file_id) ?? ""} alt={b.alt ?? ""} width={b.width ?? undefined} height={b.height ?? undefined} loading="lazy" className="w-full rounded-2xl shadow-lg" />
            {b.caption && <figcaption className="text-center text-sm text-[var(--site-text-2)]">{b.caption}</figcaption>}
          </figure>
        </Wrap>
      ) : null;
    case "cta": {
      const [label, href] = CTA_BUTTONS[b.button] ?? CTA_BUTTONS.demo;
      return (
        <section className="px-5 py-12 @5xl:px-8">
          <div className="relative mx-auto max-w-[1200px] overflow-hidden rounded-3xl bg-gradient-to-br from-[#0D3B6E] via-[#0f4a8a] to-[#1F7FD1] px-6 py-12 text-center text-white @2xl:px-12">
            <div className="site-dots absolute inset-0" aria-hidden="true" />
            <div className="relative mx-auto max-w-2xl space-y-4">
              <h2 className="font-display text-[26px] font-bold leading-tight @2xl:text-[34px]">{b.title}</h2>
              {b.text && <p className="text-[17px] text-white/80">{b.text}</p>}
              <a href={href} className="site-btn site-btn-light">
                {label} <Icon name="chevron" size={16} />
              </a>
            </div>
          </div>
        </section>
      );
    }
  }
}

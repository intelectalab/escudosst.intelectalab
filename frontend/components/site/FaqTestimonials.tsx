// Preguntas frecuentes (acordeón nativo, accesible sin JavaScript) y testimonios para el sitio público.
// Contenido editado en el panel: Sitio web › Preguntas y testimonios.

import { Icon } from "@/components/ui/Icon";
import { topicName } from "@/lib/faq";
import type { Faq, Testimonial } from "@/lib/site";

export function FaqList({ faqs, grouped = false }: { faqs: Faq[]; grouped?: boolean }) {
  const groups = grouped ? Array.from(new Set(faqs.map((f) => f.topic))) : [null];
  return (
    <div className="space-y-8">
      {groups.map((g) => (
        <div key={g ?? "all"} className="space-y-3">
          {g && <h2 className="font-display text-xl font-bold text-[var(--site-navy-title)]">{topicName(g)}</h2>}
          {faqs
            .filter((f) => !g || f.topic === g)
            .map((f) => (
              <details key={f.question} className="site-card group overflow-hidden open:shadow-md">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 font-display font-semibold text-[var(--site-navy-title)] [&::-webkit-details-marker]:hidden">
                  {f.question}
                  <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-[var(--site-surface)] text-[var(--site-action)] transition-transform group-open:rotate-45">
                    <Icon name="plus" size={16} />
                  </span>
                </summary>
                <p className="whitespace-pre-line px-5 pb-5 text-[15px] leading-relaxed text-[var(--site-text-2)]">{f.answer}</p>
              </details>
            ))}
        </div>
      ))}
    </div>
  );
}

/** Datos estructurados FAQPage para buscadores (SCR-WEB-11). */
export function FaqJsonLd({ faqs }: { faqs: Faq[] }) {
  const data = {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: faqs.map((f) => ({ "@type": "Question", name: f.question, acceptedAnswer: { "@type": "Answer", text: f.answer } })),
  };
  return <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(data).replace(/</g, "\\u003c") }} />;
}

export function TestimonialCards({ items }: { items: Testimonial[] }) {
  return (
    <ul className="flex flex-wrap justify-center gap-5">
      {items.map((t, i) => (
        <li key={i} className="site-card relative flex w-full flex-col p-6 md:w-[calc(50%-0.625rem)] lg:w-[calc(33.333%-0.84rem)]">
          <span className="absolute right-5 top-3 font-display text-6xl leading-none text-[var(--site-action)]/15" aria-hidden="true">
            “
          </span>
          {t.rating && (
            <p className="mb-3 text-lg leading-none text-[#F4B90F]" aria-label={`${t.rating} de 5 estrellas`}>
              {"★".repeat(t.rating)}
              <span className="text-[#d5dde7]">{"★".repeat(5 - t.rating)}</span>
            </p>
          )}
          <blockquote className="flex-1 text-[16px] leading-relaxed text-[var(--site-text)]">«{t.quote}»</blockquote>
          <footer className="mt-5 flex items-center gap-3 border-t border-[var(--site-border)] pt-4">
            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-[var(--site-surface)] font-display font-bold text-[var(--site-action)]">{t.author_name.slice(0, 1)}</span>
            <span className="min-w-0 flex-1 text-sm">
              <b className="block text-[var(--site-navy-title)]">{t.author_name}</b>
              <span className="text-[var(--site-text-2)]">{[t.author_role, t.company_name].filter(Boolean).join(" · ")}</span>
            </span>
            {t.is_example && <span className="rounded-md bg-[#F4B90F]/20 px-2 py-0.5 text-[11px] font-bold uppercase text-[#8A6704]">Ejemplo</span>}
          </footer>
        </li>
      ))}
    </ul>
  );
}

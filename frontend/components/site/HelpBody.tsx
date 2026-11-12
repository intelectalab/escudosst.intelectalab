// Cuerpo de un artículo de ayuda: subtítulos, párrafos y listas, sin HTML.

import { parseHelpBody } from "@/lib/help";

export function HelpBody({ body, compact = false }: { body: string; compact?: boolean }) {
  return (
    <div className={compact ? "space-y-3 text-[14px] leading-relaxed" : "space-y-4 text-[16px] leading-relaxed text-[var(--site-text)]"}>
      {parseHelpBody(body).map((n, i) =>
        n.t === "h" ? (
          <h2 key={i} className={compact ? "pt-1 font-display text-[15px] font-bold" : "pt-3 font-display text-[22px] font-bold text-[var(--site-navy-title)]"}>
            {n.text}
          </h2>
        ) : n.t === "ul" ? (
          <ul key={i} className="space-y-1.5 pl-1">
            {n.items.map((it, j) => (
              <li key={j} className="flex gap-2">
                <span className="mt-[0.6em] h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--site-action,#1b6fb7)]" aria-hidden="true" />
                <span className="min-w-0">{it}</span>
              </li>
            ))}
          </ul>
        ) : (
          <p key={i}>{n.text}</p>
        ),
      )}
    </div>
  );
}

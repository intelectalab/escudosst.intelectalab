"use client";

// Tarjetas de respuesta del asistente del sitio: planes, horarios de demo, guía paso a paso, lista, estado del
// servicio, opciones para elegir (de ahí aprende sinónimos), botones de atajo y fuente de una pregunta frecuente.

import Link from "next/link";
import { Icon } from "@/components/ui/Icon";

export type CardItem = { title?: string; desc?: string; href?: string; answer?: string; target_type?: string; target_key?: string; label?: string; price?: string; starts_at?: string; name?: string; status?: string; ask?: string; whatsapp?: boolean };
export type Card = { type: "plans" | "slots" | "guide" | "list" | "status" | "choices" | "buttons" | "source"; items?: CardItem[]; href?: string; title?: string; steps?: string[]; summary?: string | null; more?: { title: string; href: string }[]; overall?: string; term?: string; label?: string };

const TONE: Record<string, string> = { operational: "bg-[#22A35A]", maintenance: "bg-[#1b6fb7]", degraded: "bg-[#F4B90F]", partial_outage: "bg-[#F08A24]", major_outage: "bg-[#E2453C]" };
const when = (iso: string) => {
  const t = new Date(iso).toLocaleString("es-PE", { weekday: "short", day: "numeric", month: "short", hour: "numeric", minute: "2-digit", timeZone: "America/Lima" });
  return t.charAt(0).toUpperCase() + t.slice(1);
};

function Go({ href, children, onGo, className }: { href: string; children: React.ReactNode; onGo: () => void; className: string }) {
  return href.startsWith("/") ? (
    <Link href={href} onClick={onGo} className={className}>
      {children}
    </Link>
  ) : (
    <a href={href} target="_blank" rel="noopener noreferrer" className={className}>
      {children}
    </a>
  );
}

const pill = "mt-2 inline-flex items-center gap-1.5 rounded-lg bg-[var(--site-action)] px-3 py-1.5 text-xs font-bold text-white";

export function ChatCard({ card, onGo, onAsk, onPick, whatsapp }: { card: Card; onGo: () => void; onAsk: (text: string, ask?: string) => void; onPick: (it: CardItem, term: string) => void; whatsapp: string | null }) {
  switch (card.type) {
    case "plans":
      return (
        <div className="mt-2 space-y-1.5">
          {card.items?.map((p) => (
            <div key={p.title} className="rounded-xl border border-[#e3e8ef] p-2.5">
              <div className="flex items-baseline justify-between gap-2">
                <b className="text-[13px]">{p.title}</b>
                <span className="text-xs font-bold text-[var(--site-action)]">{p.price}</span>
              </div>
              {p.desc && <p className="mt-0.5 text-[11px] leading-snug text-[var(--site-text-2)]">{p.desc}</p>}
            </div>
          ))}
          {card.href && (
            <Go href={card.href} onGo={onGo} className={pill}>
              Ver planes y comparar
            </Go>
          )}
        </div>
      );
    case "slots":
      return (
        <div className="mt-2 space-y-1.5">
          {card.items?.map((s) => (
            <Go key={s.starts_at} href={card.href ?? "/demo/agendar"} onGo={onGo} className="flex items-center gap-2 rounded-xl border border-[var(--site-action)]/30 px-3 py-2 text-xs font-semibold text-[var(--site-action)] hover:bg-[#EAF3FC]">
              <Icon name="clock" size={14} /> {when(s.starts_at!)}
            </Go>
          ))}
          <Go href={card.href ?? "/demo/agendar"} onGo={onGo} className={pill}>
            Elegir mi horario
          </Go>
        </div>
      );
    case "guide":
      return (
        <div className="mt-2 rounded-xl border border-[#e3e8ef] p-2.5">
          {card.steps && card.steps.length > 0 ? (
            <ol className="space-y-1">
              {card.steps.map((s, i) => (
                <li key={i} className="flex gap-2 text-xs">
                  <span className="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-[#EAF3FC] text-[10px] font-bold text-[var(--site-action)]">{i + 1}</span>
                  <span className="min-w-0">{s}</span>
                </li>
              ))}
            </ol>
          ) : (
            card.summary && <p className="text-xs text-[var(--site-text-2)]">{card.summary}</p>
          )}
          {card.href && (
            <Go href={card.href} onGo={onGo} className={pill}>
              Leer la guía completa
            </Go>
          )}
          {card.more && card.more.length > 0 && (
            <p className="mt-2 text-[11px] text-[var(--site-text-2)]">
              También:{" "}
              {card.more.map((m, i) => (
                <span key={m.href}>
                  {i > 0 && " · "}
                  <Go href={m.href} onGo={onGo} className="font-semibold text-[var(--site-action)] hover:underline">
                    {m.title}
                  </Go>
                </span>
              ))}
            </p>
          )}
        </div>
      );
    case "list":
      return (
        <div className="mt-2 space-y-1.5">
          {card.items?.map((it) => (
            <div key={it.title} className="rounded-xl border border-[#e3e8ef] p-2.5">
              <b className="block text-[13px]">{it.title}</b>
              {it.desc && <p className="text-[11px] leading-snug text-[var(--site-text-2)]">{it.desc}</p>}
            </div>
          ))}
          {card.href && (
            <Go href={card.href} onGo={onGo} className={pill}>
              Ver todo
            </Go>
          )}
        </div>
      );
    case "status":
      return (
        <div className="mt-2 space-y-1">
          {card.items?.map((c) => (
            <div key={c.name} className="flex items-center justify-between gap-2 text-xs">
              <span>{c.name}</span>
              <span className="inline-flex items-center gap-1.5 font-semibold">
                <span className={`h-2 w-2 rounded-full ${TONE[c.status ?? "operational"]}`} /> {c.label}
              </span>
            </div>
          ))}
          <Go href={card.href ?? "/estado"} onGo={onGo} className={pill}>
            Ver el estado del servicio
          </Go>
        </div>
      );
    case "choices":
      return (
        <div className="mt-2 space-y-1.5">
          {card.items?.map((it) => (
            <button key={`${it.target_type}-${it.target_key}`} onClick={() => onPick(it, card.term ?? "")} className="block w-full rounded-xl border border-[#e3e8ef] p-2.5 text-left hover:border-[var(--site-action)]">
              <span className="text-[10px] font-semibold uppercase tracking-wide text-[var(--site-text-2)]">{it.label}</span>
              <b className="block text-[13px] text-[var(--site-navy-title)]">{it.title}</b>
              {it.desc && <span className="block text-[11px] leading-snug text-[var(--site-text-2)]">{it.desc}</span>}
            </button>
          ))}
        </div>
      );
    case "buttons":
      return (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {card.items?.map((b) =>
            b.whatsapp ? (
              whatsapp && (
                <a key="wa" href={whatsapp} target="_blank" rel="noopener noreferrer" className="inline-flex rounded-lg bg-[#22A35A] px-3 py-1.5 text-xs font-bold text-white">
                  {b.label}
                </a>
              )
            ) : b.href ? (
              <Go key={b.label} href={b.href} onGo={onGo} className={pill.replace("mt-2 ", "")}>
                {b.label}
              </Go>
            ) : (
              <button key={b.label} onClick={() => onAsk(b.ask ?? b.label ?? "")} className="rounded-full border border-[var(--site-action)]/30 bg-white px-3 py-1.5 text-xs font-semibold text-[var(--site-action)] hover:bg-[#EAF3FC]">
                {b.label}
              </button>
            ),
          )}
        </div>
      );
    case "source":
      return card.href ? (
        <Go href={card.href} onGo={onGo} className="mt-1.5 block text-[11px] font-semibold text-[var(--site-action)] hover:underline">
          {card.label}
        </Go>
      ) : null;
  }
  return null;
}

"use client";

// Tarjetas de integraciones con filtro por categoría (SCR-WEB-73). El estado va como texto, no solo color.

import Link from "next/link";
import { useState } from "react";
import { Icon } from "@/components/ui/Icon";
import { assetUrl, type Integration } from "@/lib/site";

const CATEGORIES: Record<string, string> = {
  messaging: "Mensajería",
  lms: "Capacitación (LMS)",
  payments: "Pagos",
  crm: "CRM",
  calendar: "Calendario",
  email: "Correo",
  storage: "Almacenamiento",
  ai: "Inteligencia artificial",
  analytics: "Métricas",
};
const STATUS: Record<Integration["availability"], [string, string]> = {
  available: ["Disponible", "bg-[#22A35A]/12 text-[#167A42]"],
  beta: ["Beta", "bg-[#F4B90F]/20 text-[#6B5200]"],
  planned: ["Planificada", "bg-[var(--site-surface)] text-[var(--site-text-2)]"],
};

export function IntegrationsBrowser({ items }: { items: Integration[] }) {
  const [cat, setCat] = useState("");
  const cats = [...new Set(items.map((i) => i.category))];
  const list = items.filter((i) => !cat || i.category === cat);

  if (items.length === 0)
    return (
      <div className="site-card mx-auto max-w-xl p-8 text-center text-[var(--site-text-2)]">
        <Icon name="refresh" size={28} className="mx-auto mb-3" />
        Pronto publicaremos aquí las integraciones de EscudoSST.
      </div>
    );

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap gap-2" role="group" aria-label="Filtrar por categoría">
        {["", ...cats].map((c) => (
          <button
            key={c || "all"}
            type="button"
            aria-pressed={cat === c}
            onClick={() => setCat(c)}
            className={`rounded-full border px-4 py-2 text-sm font-semibold transition ${cat === c ? "border-[var(--site-navy)] bg-[var(--site-navy)] text-white" : "border-[var(--site-border)] bg-white text-[var(--site-text)] hover:border-[var(--site-action)]"}`}
          >
            {c ? CATEGORIES[c] : "Todas"}
          </button>
        ))}
      </div>
      <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {list.map((i) => {
          const logo = assetUrl(i.logo_file_id);
          const external = !!i.docs_url?.startsWith("http");
          return (
            <li key={i.slug} className="site-card flex flex-col gap-3 p-5">
              <div className="flex items-center gap-3">
                <span className="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-xl border border-[var(--site-border)] bg-white">
                  {logo ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={logo} alt={i.logo_alt_text ?? i.name} className="h-full w-full object-contain p-1.5" />
                  ) : (
                    <span className="font-display text-lg font-bold text-[var(--site-navy-title)]">{i.name.charAt(0)}</span>
                  )}
                </span>
                <div className="min-w-0">
                  <h2 className="font-display font-bold text-[var(--site-navy-title)]">{i.name}</h2>
                  <span className="text-xs text-[var(--site-text-2)]">{CATEGORIES[i.category]}</span>
                </div>
              </div>
              {i.description && <p className="text-[15px] text-[var(--site-text-2)]">{i.description}</p>}
              <div className="mt-auto flex items-center justify-between gap-2 pt-1">
                <span className={`rounded-full px-3 py-1 text-xs font-bold ${STATUS[i.availability][1]}`}>{STATUS[i.availability][0]}</span>
                {i.docs_url && (
                  <Link href={i.docs_url} target={external ? "_blank" : undefined} rel={external ? "noopener" : undefined} className="text-sm font-semibold text-[var(--site-action)] hover:underline">
                    Cómo funciona
                  </Link>
                )}
              </div>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

"use client";

// Navegación de «Sitio web» en el panel: columna vertical a la izquierda en pantallas grandes (agrupada, con lo
// pendiente de cada sección) y fila deslizable arriba en tablet y celular. La usa plataforma/sitio/layout.tsx.

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useNotices } from "@/components/platform/NotificationsBell";
import { Icon } from "@/components/ui/Icon";

const GROUPS: { title: string; items: [string, string, string][] }[] = [
  {
    title: "Resumen",
    items: [["/plataforma/sitio/metricas", "Resumen y métricas", "grid"]],
  },
  {
    title: "Ajustes",
    items: [
      ["/plataforma/sitio", "Contacto y WhatsApp", "globe"],
      ["/plataforma/sitio/marca", "Logo y menú", "tag"],
      ["/plataforma/sitio/politicas", "Políticas", "file"],
      ["/plataforma/sitio/confianza", "Subencargados", "lock"],
      ["/plataforma/sitio/estado", "Estado del servicio", "shield"],
      ["/plataforma/sitio/seo", "SEO y redirecciones", "search"],
    ],
  },
  {
    title: "Bandejas",
    items: [
      ["/plataforma/sitio/solicitudes", "Solicitudes", "clock"],
      ["/plataforma/sitio/cotizaciones", "Cotizaciones", "tag"],
      ["/plataforma/sitio/agenda", "Agenda de demos", "clock"],
      ["/plataforma/sitio/interesados", "Interesados", "people"],
      ["/plataforma/sitio/partners", "Partners y referidos", "building"],
      ["/plataforma/sitio/crm", "Conexión con el CRM", "refresh"],
      ["/plataforma/sitio/boletin", "Boletín", "mail"],
      ["/plataforma/sitio/reclamos", "Libro de Reclamaciones", "history"],
    ],
  },
  {
    title: "Contenido",
    items: [
      ["/plataforma/sitio/paginas", "Páginas", "pen"],
      ["/plataforma/sitio/contenido", "Preguntas y testimonios", "heart"],
      ["/plataforma/sitio/casos", "Casos de éxito", "star"],
      ["/plataforma/sitio/recursos", "Recursos descargables", "file"],
      ["/plataforma/sitio/calculadora", "Calculadora de multas", "tag"],
      ["/plataforma/sitio/autodiagnostico", "Autodiagnóstico", "check"],
      ["/plataforma/sitio/ayuda", "Centro de ayuda", "help"],
      ["/plataforma/sitio/chatbot", "Chatbot", "help"],
      ["/plataforma/sitio/medios", "Biblioteca de medios", "eye"],
    ],
  },
];

/** La pestaña queda marcada también en sus subpáginas (ej. el editor de una página o de un caso). */
const active = (pathname: string, href: string) => pathname === href || (href !== "/plataforma/sitio" && pathname.startsWith(href + "/"));

export function SiteSideNav() {
  const pathname = usePathname();
  const { items } = useNotices();
  const all = GROUPS.flatMap((g) => g.items);

  // Pendientes por sección (los mismos avisos de la campana). Rojo si hay reclamos vencidos.
  const pending = (href: string) => (items ?? []).filter((n) => n.href === href).reduce((a, n) => a + n.count, 0);
  const urgent = (href: string) => (items ?? []).some((n) => n.href === href && n.tone === "bad");

  return (
    <>
      {/* Tablet y celular: fila deslizable */}
      <nav aria-label="Secciones del sitio web" className="flex w-full gap-1 overflow-x-auto rounded-xl border border-line bg-surface p-1 shadow-sm [scrollbar-width:thin] xl:hidden">
        {all.map(([href, label, icon]) => (
          <Link
            key={href}
            href={href}
            aria-current={active(pathname, href) ? "page" : undefined}
            className={`inline-flex shrink-0 items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors ${active(pathname, href) ? "bg-brand text-white shadow-sm" : "text-muted hover:bg-bg hover:text-fg"}`}
          >
            <Icon name={icon} size={15} />
            {label}
            {pending(href) > 0 && <span className={`rounded-full px-1.5 text-[11px] font-bold ${active(pathname, href) ? "bg-white/25" : urgent(href) ? "bg-bad text-white" : "bg-brand-soft text-brand-strong"}`}>{pending(href)}</span>}
          </Link>
        ))}
      </nav>

      {/* Escritorio: columna vertical */}
      <nav aria-label="Secciones del sitio web" className="hidden overflow-hidden rounded-2xl border border-line bg-surface shadow-sm xl:block">
        <div className="relative overflow-hidden bg-gradient-to-br from-side to-brand px-4 py-3.5 text-white">
          <div className="absolute -right-6 -top-8 h-24 w-24 rounded-full bg-white/10" aria-hidden="true" />
          <p className="relative text-[11px] font-semibold uppercase tracking-wider text-white/70">Panel del sitio</p>
          <a href="/" target="_blank" rel="noopener" className="relative mt-1 inline-flex items-center gap-1.5 text-sm font-semibold hover:underline">
            <Icon name="eye" size={15} /> Ver el sitio público ↗
          </a>
        </div>
        <div className="space-y-3 p-2">
          {GROUPS.map((g) => (
            <div key={g.title}>
              <p className="px-2.5 pb-1 pt-1 text-[10.5px] font-bold uppercase tracking-[0.12em] text-muted">{g.title}</p>
              <ul className="space-y-px">
                {g.items.map(([href, label, icon]) => {
                  const on = active(pathname, href);
                  const n = pending(href);
                  return (
                    <li key={href}>
                      <Link
                        href={href}
                        aria-current={on ? "page" : undefined}
                        className={`group relative flex items-center gap-2.5 rounded-lg py-1.5 pl-1.5 pr-2 text-[13.5px] transition-colors ${on ? "bg-brand-soft font-semibold text-brand-strong" : "text-fg/80 hover:bg-bg hover:text-fg"}`}
                      >
                        {on && <span className="absolute -left-2 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-brand" aria-hidden="true" />}
                        <span className={`grid h-7 w-7 shrink-0 place-items-center rounded-md transition-colors ${on ? "bg-brand text-white shadow-sm shadow-brand/40" : "bg-bg text-muted group-hover:bg-brand-soft group-hover:text-brand-strong"}`}>
                          <Icon name={icon} size={15} />
                        </span>
                        <span className="flex-1 truncate">{label}</span>
                        {n > 0 && (
                          <span className={`grid h-5 min-w-5 place-items-center rounded-full px-1.5 text-[11px] font-bold ${urgent(href) ? "bg-bad text-white" : "bg-brand text-white"}`} title={`${n} pendiente${n === 1 ? "" : "s"}`}>
                            {n}
                          </span>
                        )}
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </div>
          ))}
        </div>
      </nav>
    </>
  );
}

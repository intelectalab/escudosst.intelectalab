"use client";

// Submenú de «Sitio web» en el panel (como en el prototipo). Las secciones de próximas partes aparecen con candado.

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Icon } from "@/components/ui/Icon";

const TABS: [string, string, string, boolean][] = [
  ["/plataforma/sitio", "Contacto y WhatsApp", "globe", true],
  ["/plataforma/sitio/marca", "Logo y menú", "star", true],
  ["/plataforma/sitio/politicas", "Políticas", "file", true],
  ["/plataforma/sitio/solicitudes", "Solicitudes", "clock", true],
  ["/plataforma/sitio/interesados", "Interesados", "people", true],
  ["/plataforma/sitio/contenido", "Preguntas y testimonios", "heart", true],
  ["/plataforma/sitio/reclamos", "Libro de Reclamaciones", "history", true],
  ["#", "Páginas y SEO", "pen", false],
];

export function SitePanelTabs() {
  const pathname = usePathname();
  return (
    <nav aria-label="Secciones del sitio web" className="flex w-full gap-1 overflow-x-auto rounded-xl border border-line bg-surface p-1 shadow-sm">
      {TABS.map(([href, label, icon, ready]) =>
        ready ? (
          <Link
            key={label}
            href={href}
            aria-current={pathname === href ? "page" : undefined}
            className={`inline-flex shrink-0 items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold transition-colors ${pathname === href ? "bg-brand text-white shadow-sm" : "text-muted hover:bg-bg hover:text-fg"}`}
          >
            <Icon name={icon} size={15} />
            {label}
          </Link>
        ) : (
          <span key={label} title="Próximamente" className="inline-flex shrink-0 cursor-not-allowed items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold text-muted/60">
            <Icon name={icon} size={15} />
            {label}
            <Icon name="lock" size={12} />
          </span>
        ),
      )}
    </nav>
  );
}

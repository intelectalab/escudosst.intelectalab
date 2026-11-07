"use client";

// Cabecera del sitio público: logo, menú (con «Productos» desplegable), «Iniciar sesión» y CTA.
// En el celular, menú en cajón. Si la persona ya tiene sesión, muestra «Ir a mi panel».

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";
import { SiteLogo } from "@/components/site/SiteLogo";
import { Icon } from "@/components/ui/Icon";
import { session } from "@/lib/session";

type Props = { trialHref: string; demoHref: string; blogUrl: string | null; menu?: { label: string; url: string }[]; logoUrl?: string | null; brandName?: string };

// Menú por defecto si la API no responde (el dueño lo edita en Sitio web › Marca y menú).
const DEFAULT_MENU = [
  { label: "Cómo funciona", url: "/como-funciona" },
  { label: "Planes", url: "/planes" },
  { label: "Contacto", url: "/demo?tipo=contacto" },
];

const PRODUCTS = [
  {
    href: "/producto",
    title: "Producto y módulos",
    text: "Todo lo que hace EscudoSST, por área",
    icon: "grid",
  },
  {
    href: "/complementos",
    title: "Complementos",
    text: "IA, app offline, paquetes por sector y más",
    icon: "box",
  },
  {
    href: "/planes#comparativa",
    title: "Comparativa por plan",
    text: "Qué incluye cada plan, módulo por módulo",
    icon: "check",
  },
];

export function SiteHeader({ trialHref, demoHref, blogUrl, menu, logoUrl = null, brandName = "EscudoSST" }: Props) {
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [products, setProducts] = useState(false);
  const [logged, setLogged] = useState(false);
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => setLogged(!!session.getToken()), []);
  useEffect(() => {
    setOpen(false);
    setProducts(false);
  }, [pathname]);
  useEffect(() => {
    const on = () => setScrolled(window.scrollY > 8);
    on();
    window.addEventListener("scroll", on, { passive: true });
    return () => window.removeEventListener("scroll", on);
  }, []);

  const items = menu ?? DEFAULT_MENU;
  // El blog se agrega solo si hay enlace configurado y no está ya en el menú.
  const links = [
    ...items.map((i) => ({ href: i.url, label: i.label })),
    ...(blogUrl && !items.some((i) => i.url === blogUrl) ? [{ href: blogUrl, label: "Blog" }] : []),
  ];
  const external = (href: string) => href.startsWith("http");

  return (
    <>
      <header
        className={`sticky top-0 z-40 border-b bg-white/90 backdrop-blur transition-shadow ${scrolled ? "border-[var(--site-border)] shadow-[0_4px_20px_-8px_rgba(13,59,110,.18)]" : "border-transparent"}`}
      >
        <div className="mx-auto flex h-16 max-w-[1360px] items-center justify-between gap-4 px-4 lg:h-[72px] lg:px-8">
          <Link href="/" aria-label={`${brandName}, inicio`}>
            <SiteLogo src={logoUrl} alt={brandName} />
          </Link>

          <nav
            aria-label="Principal"
            className="hidden items-center gap-0.5 lg:flex"
          >
            <div className="relative" onMouseLeave={() => setProducts(false)}>
              <button
                type="button"
                aria-expanded={products}
                onClick={() => setProducts((v) => !v)}
                onMouseEnter={() => setProducts(true)}
                className="inline-flex items-center gap-1 whitespace-nowrap rounded-lg px-3 py-2 text-[15px] font-semibold text-[var(--site-navy)] hover:bg-[var(--site-bg)]"
              >
                Productos{" "}
                <Icon
                  name="chevron"
                  size={14}
                  className={`transition-transform ${products ? "-rotate-90" : "rotate-90"}`}
                />
              </button>
              {products && (
                <div className="absolute left-0 top-full w-80 pt-2">
                  <div className="site-card overflow-hidden p-2">
                    {PRODUCTS.map((p) => (
                      <Link
                        key={p.href}
                        href={p.href}
                        className="flex gap-3 rounded-lg p-3 hover:bg-[var(--site-surface)]"
                      >
                        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[var(--site-surface)] text-[var(--site-action)]">
                          <Icon name={p.icon} size={18} />
                        </span>
                        <span>
                          <span className="block text-sm font-bold text-[var(--site-navy-title)]">
                            {p.title}
                          </span>
                          <span className="block text-xs text-[var(--site-text-2)]">
                            {p.text}
                          </span>
                        </span>
                      </Link>
                    ))}
                  </div>
                </div>
              )}
            </div>
            {links.map((l) => (
              <Link
                key={l.label}
                href={l.href}
                target={external(l.href) ? "_blank" : undefined}
                rel={external(l.href) ? "noopener" : undefined}
                className={`whitespace-nowrap rounded-lg px-3 py-2 text-[15px] font-semibold hover:bg-[var(--site-bg)] ${pathname === l.href ? "text-[var(--site-action)]" : "text-[var(--site-navy)]"}`}
              >
                {l.label}
              </Link>
            ))}
          </nav>

          <div className="hidden items-center gap-2 lg:flex">
            <Link
              href={logged ? "/select-company" : "/login"}
              className="whitespace-nowrap rounded-lg px-3 py-2 text-[15px] font-semibold text-[var(--site-navy)] hover:bg-[var(--site-bg)]"
            >
              {logged ? "Ir a mi panel" : "Iniciar sesión"}
            </Link>
            <a
              href={demoHref}
              data-track="cabecera-demo"
              className="site-btn site-btn-outline hidden min-h-[42px] px-4 py-2 text-sm xl:inline-flex"
            >
              Agenda una demo
            </a>
            <a
              href={trialHref}
              data-track="cabecera-prueba"
              className="site-btn site-btn-primary min-h-[42px] px-4 py-2 text-sm"
            >
              Prueba gratis
            </a>
          </div>

          <button
            type="button"
            onClick={() => setOpen(true)}
            aria-label="Abrir menú"
            className="grid h-11 w-11 place-items-center rounded-lg border border-[var(--site-border)] text-[var(--site-navy)] lg:hidden"
          >
            <svg
              width="20"
              height="20"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              aria-hidden="true"
            >
              <path d="M4 7h16M4 12h16M4 17h16" />
            </svg>
          </button>
        </div>
      </header>
      {open && (
        <div
          className="fixed inset-0 z-50 lg:hidden"
          role="dialog"
          aria-modal="true"
          aria-label="Menú"
        >
          <button
            className="absolute inset-0 bg-[rgba(13,59,110,.45)] backdrop-blur-[4px]"
            aria-label="Cerrar menú"
            onClick={() => setOpen(false)}
          />
          <div className="absolute inset-y-0 right-0 flex w-[86%] max-w-sm flex-col bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-[var(--site-border)] p-4">
              <SiteLogo src={logoUrl} alt={brandName} />
              <button
                onClick={() => setOpen(false)}
                aria-label="Cerrar menú"
                className="grid h-11 w-11 place-items-center rounded-lg text-[var(--site-navy)]"
              >
                <Icon name="close" size={20} />
              </button>
            </div>
            <nav
              className="flex-1 space-y-1 overflow-y-auto p-4"
              aria-label="Menú del celular"
            >
              <p className="px-3 pb-1 text-xs font-bold uppercase tracking-wider text-[var(--site-text-2)]">
                Productos
              </p>
              {PRODUCTS.map((p) => (
                <Link
                  key={p.href}
                  href={p.href}
                  className="flex items-center gap-3 rounded-lg px-3 py-3 font-semibold text-[var(--site-navy)] hover:bg-[var(--site-bg)]"
                >
                  <Icon
                    name={p.icon}
                    size={18}
                    className="text-[var(--site-action)]"
                  />
                  {p.title}
                </Link>
              ))}
              <div className="my-2 border-t border-[var(--site-border)]" />
              {links.map((l) => (
                <Link
                  key={l.label}
                  href={l.href}
                  className="block rounded-lg px-3 py-3 font-semibold text-[var(--site-navy)] hover:bg-[var(--site-bg)]"
                >
                  {l.label}
                </Link>
              ))}
            </nav>
            <div className="space-y-2 border-t border-[var(--site-border)] p-4">
              <a href={trialHref} data-track="menu-movil-prueba" className="site-btn site-btn-primary w-full">
                Prueba gratis
              </a>
              <a href={demoHref} data-track="menu-movil-demo" className="site-btn site-btn-outline w-full">
                Agenda una demo
              </a>
              <Link
                href={logged ? "/select-company" : "/login"}
                className="site-btn w-full text-[var(--site-navy)]"
              >
                {logged ? "Ir a mi panel" : "Iniciar sesión"}
              </Link>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

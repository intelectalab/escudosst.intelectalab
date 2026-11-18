"use client";

// Movimiento del sitio público en todas las páginas, sin tocar cada una:
//  - Al bajar, los bloques de cada sección aparecen (suben, entran de costado o con un leve acercamiento) y las tarjetas
//    de una misma grilla aparecen una tras otra.
//  - Las imágenes y maquetas del producto ([data-motion="art"] o los marcos con sombra grande) se inclinan un poco
//    siguiendo el mouse y flotan suave.
// Seguro por defecto: lo que ya está en pantalla al cargar no se oculta, y un respaldo por tiempo muestra todo aunque el
// navegador no avise el desplazamiento. Con «reducir movimiento» del sistema no se anima nada.

import { usePathname } from "next/navigation";
import { useEffect } from "react";

const SELECTOR = [
  "main section h2",
  "main section > div > p",
  "main section .grid > *",
  "main section [data-reveal]",
  "main section img",
  "main section [aria-hidden='true'].rounded-2xl",
].join(",");

export function SiteMotion() {
  const pathname = usePathname();

  useEffect(() => {
    if (typeof window === "undefined" || window.matchMedia("(prefers-reduced-motion: reduce)").matches || !("IntersectionObserver" in window)) return;
    const root = document.querySelector("main#contenido");
    if (!root) return;
    const fold = window.innerHeight;
    const els = Array.from(root.querySelectorAll<HTMLElement>(SELECTOR)).filter((el) => {
      if (el.closest(".site-reveal, [data-no-motion], form, [role='dialog'], header, footer") || el.dataset.motionDone) return false;
      // No animar dentro de algo que ya se anima (evita dobles movimientos).
      if (el.parentElement?.closest("[data-motion-on]")) return false;
      return el.getBoundingClientRect().top > fold * 0.9;
    });
    const timers: number[] = [];
    const io = new IntersectionObserver((entries) => {
      for (const e of entries) {
        if (!e.isIntersecting) continue;
        const el = e.target as HTMLElement;
        el.classList.add("is-shown");
        io.unobserve(el);
      }
    }, { rootMargin: "0px 0px -6% 0px" });
    els.forEach((el) => {
      el.dataset.motionDone = "1";
      el.dataset.motionOn = "1";
      const siblings = el.parentElement ? Array.from(el.parentElement.children).filter((c) => (c as HTMLElement).dataset?.motionOn) : [];
      const idx = Math.max(0, siblings.indexOf(el));
      const isGridItem = el.parentElement?.classList.contains("grid");
      const isArt = el.tagName === "IMG" || el.getAttribute("aria-hidden") === "true";
      // Variante: tarjetas suben en cascada; imágenes entran con acercamiento; párrafos y títulos suben.
      el.classList.add("site-motion", isArt ? "site-motion-zoom" : isGridItem && idx % 2 === 1 ? "site-motion-right" : isGridItem ? "site-motion-left" : "site-motion-up");
      if (isGridItem) el.style.transitionDelay = `${Math.min(idx, 6) * 90}ms`;
      io.observe(el);
    });
    timers.push(window.setTimeout(() => els.forEach((el) => el.classList.add("is-shown")), 4000));

    // Inclinación de las maquetas con el mouse.
    const arts = Array.from(root.querySelectorAll<HTMLElement>("[data-motion='art'], main section [aria-hidden='true'].rounded-2xl"));
    const handlers: [HTMLElement, (e: MouseEvent) => void, () => void][] = [];
    if (window.matchMedia("(pointer: fine)").matches) {
      arts.forEach((el) => {
        el.classList.add("site-tilt");
        const move = (e: MouseEvent) => {
          const r = el.getBoundingClientRect();
          const x = (e.clientX - r.left) / r.width - 0.5;
          const y = (e.clientY - r.top) / r.height - 0.5;
          el.style.setProperty("--tilt-x", `${(-y * 5).toFixed(2)}deg`);
          el.style.setProperty("--tilt-y", `${(x * 7).toFixed(2)}deg`);
        };
        const leave = () => { el.style.setProperty("--tilt-x", "0deg"); el.style.setProperty("--tilt-y", "0deg"); };
        el.addEventListener("mousemove", move);
        el.addEventListener("mouseleave", leave);
        handlers.push([el, move, leave]);
      });
    }
    return () => {
      io.disconnect();
      timers.forEach((t) => window.clearTimeout(t));
      handlers.forEach(([el, m, l]) => { el.removeEventListener("mousemove", m); el.removeEventListener("mouseleave", l); });
    };
  }, [pathname]);

  return null;
}

"use client";

// Aparición suave al bajar por la página: el contenido sube unos píxeles y se hace visible al entrar en pantalla.
// Seguro por defecto: se ve todo desde el inicio; solo se oculta (para animarlo) lo que está más abajo de la
// pantalla al cargar, y un respaldo por tiempo lo muestra igual si el navegador no avisa el desplazamiento.
// Con «reducir movimiento» activado en el sistema, el CSS no anima nada.

import { useEffect, useRef, useState } from "react";

type State = "idle" | "waiting" | "shown";

export function Reveal({ children, delay = 0, className = "" }: { children: React.ReactNode; delay?: number; className?: string }) {
  const ref = useRef<HTMLDivElement>(null);
  const [state, setState] = useState<State>("idle");

  useEffect(() => {
    const el = ref.current;
    if (!el || !("IntersectionObserver" in window) || el.getBoundingClientRect().top < window.innerHeight) return;
    setState("waiting");
    const show = () => setState("shown");
    const io = new IntersectionObserver(([e]) => e.isIntersecting && (show(), io.disconnect()), { rootMargin: "0px 0px -8% 0px" });
    io.observe(el);
    const fallback = window.setTimeout(show, 2500);
    return () => {
      io.disconnect();
      window.clearTimeout(fallback);
    };
  }, []);

  const cls = state === "waiting" ? "site-reveal" : state === "shown" ? "site-reveal is-shown" : "";
  return (
    <div ref={ref} className={`${cls} ${className}`} style={state === "shown" ? { transitionDelay: `${delay}ms` } : undefined}>
      {children}
    </div>
  );
}

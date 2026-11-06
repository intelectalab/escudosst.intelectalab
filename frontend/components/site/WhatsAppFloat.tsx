"use client";

// Botón flotante de WhatsApp (SCR-WEB-13): visible en todas las páginas del sitio si el dueño configuró el número.
// En el celular queda a la izquierda del borde para no tapar los botones principales.
// Una página por bloques puede traer su propio mensaje (<PageWhatsAppMessage>): se lee al cambiar de página.

import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";

export function WhatsAppIcon({ size = 26 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 32 32" aria-hidden="true">
      <path
        fill="currentColor"
        d="M16 3C8.8 3 3 8.7 3 15.8c0 2.3.6 4.5 1.8 6.4L3 29l7-1.8c1.8 1 3.9 1.5 6 1.5 7.2 0 13-5.7 13-12.8S23.2 3 16 3zm0 23.4c-1.9 0-3.8-.5-5.4-1.5l-.4-.2-4.1 1.1 1.1-4-.3-.4a10.4 10.4 0 0 1-1.6-5.6C5.3 10 10.1 5.3 16 5.3s10.7 4.7 10.7 10.5S21.9 26.4 16 26.4zm5.9-7.8c-.3-.2-1.9-.9-2.2-1-.3-.1-.5-.2-.7.2l-1 1.2c-.2.2-.4.2-.7.1a8.8 8.8 0 0 1-4.4-3.8c-.3-.6.3-.5.9-1.7.1-.2 0-.4 0-.6l-1-2.4c-.3-.6-.5-.5-.7-.5h-.6c-.2 0-.6.1-.9.4-.3.3-1.2 1.1-1.2 2.7s1.2 3.2 1.4 3.4c.2.2 2.4 3.6 5.8 5 2.1.9 3 1 4 .8.7-.1 1.9-.8 2.2-1.5.3-.7.3-1.4.2-1.5-.1-.2-.3-.3-.6-.4z"
      />
    </svg>
  );
}

/** Marca invisible con el mensaje de WhatsApp propio de la página. */
export function PageWhatsAppMessage({ message }: { message: string | null }) {
  return message ? <span hidden data-wa-message={message} /> : null;
}

export function WhatsAppFloat({ number, message }: { number: string | null; message: string }) {
  const [hover, setHover] = useState(false);
  const [pageMessage, setPageMessage] = useState<string | null>(null);
  const path = usePathname();
  useEffect(() => {
    setPageMessage(document.querySelector("[data-wa-message]")?.getAttribute("data-wa-message") ?? null);
  }, [path]);
  if (!number) return null;
  const href = `https://wa.me/${number}?text=${encodeURIComponent(pageMessage || message)}`;
  return (
    <a
      href={href}
      target="_blank"
      rel="noopener"
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      aria-label="Escríbenos por WhatsApp"
      className="wa-float fixed bottom-5 right-5 z-40 flex items-center gap-2 rounded-full bg-[#22A35A] p-3.5 text-white shadow-[0_10px_28px_-6px_rgba(34,163,90,.65)] transition-transform hover:-translate-y-0.5 sm:bottom-6 sm:right-6"
    >
      <WhatsAppIcon />
      <span className={`overflow-hidden whitespace-nowrap text-sm font-bold transition-all duration-200 ${hover ? "max-w-40 pr-1.5" : "max-w-0"}`}>Escríbenos</span>
    </a>
  );
}

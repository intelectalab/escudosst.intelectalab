"use client";

import { useEffect, useRef } from "react";
import { Icon } from "./Icon";

/** Ventana modal accesible: cierra con Esc o clic fuera, y enfoca su contenido al abrir. */
export function Modal({
  title,
  subtitle,
  icon,
  onClose,
  children,
  footer,
  size = "md",
  z = 50,
}: {
  title: string;
  subtitle?: string;
  icon?: string;
  onClose: () => void;
  children: React.ReactNode;
  footer?: React.ReactNode;
  size?: "sm" | "md" | "lg";
  z?: number;
}) {
  const ref = useRef<HTMLDivElement>(null);

  // onClose puede cambiar en cada render (funciones en línea): se guarda en una referencia para que
  // el efecto corra solo al abrir. Si dependiera de onClose, cada tecla devolvería el foco a la ventana
  // y el campo que se está escribiendo lo perdería.
  const closeRef = useRef(onClose);
  closeRef.current = onClose;

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && closeRef.current();
    document.addEventListener("keydown", onKey);
    // Enfoca la ventana solo al abrir, y solo si el foco no está ya en un campo de ella (p. ej. con autoFocus).
    if (!ref.current?.contains(document.activeElement)) ref.current?.focus();
    return () => document.removeEventListener("keydown", onKey);
  }, []);

  const width = { sm: "max-w-md", md: "max-w-2xl", lg: "max-w-4xl" }[size];

  return (
    <div className="fixed inset-0 flex items-end sm:items-center justify-center sm:p-4" style={{ zIndex: z }} role="dialog" aria-modal="true" aria-label={title}>
      <button className="absolute inset-0 bg-[#06101f]/50 backdrop-blur-[1px]" aria-label="Cerrar" onClick={onClose} tabIndex={-1} />
      <div ref={ref} tabIndex={-1} className={`relative flex max-h-[94vh] w-full ${width} flex-col rounded-t-2xl sm:rounded-2xl bg-surface shadow-2xl outline-none`}>
        <div className="modal-head flex items-start justify-between gap-4 border-b border-line px-4 py-4 sm:rounded-t-2xl sm:px-6">
          <div className="flex min-w-0 items-center gap-3">
            {icon && (
              <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-brand text-white shadow-[0_6px_16px_-6px_var(--brand)]">
                <Icon name={icon} size={20} />
              </span>
            )}
            <div className="min-w-0">
              <h2 className="text-lg font-semibold leading-tight">{title}</h2>
              {subtitle && <p className="mt-0.5 text-sm text-muted">{subtitle}</p>}
            </div>
          </div>
          <button onClick={onClose} className="rounded-lg p-1.5 text-muted hover:bg-bg hover:text-fg" aria-label="Cerrar">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
              <path d="M6 6l12 12M18 6 6 18" />
            </svg>
          </button>
        </div>
        <div className="modal-body flex-1 overflow-y-auto px-4 py-5 sm:px-6 sm:py-6">{children}</div>
        {footer && (
          <div className="flex flex-col-reverse gap-2 border-t border-line bg-bg/60 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-end sm:rounded-b-2xl sm:px-6 [&>*]:w-full sm:[&>*]:w-auto">
            {footer}
          </div>
        )}
      </div>
    </div>
  );
}

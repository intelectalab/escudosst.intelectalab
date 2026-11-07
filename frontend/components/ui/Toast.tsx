"use client";

import { useEffect } from "react";

/** Aviso breve de éxito o error, abajo a la derecha. */
export function Toast({ message, tone = "ok", onClose }: { message: string; tone?: "ok" | "bad"; onClose: () => void }) {
  useEffect(() => {
    const t = setTimeout(onClose, 5000);
    return () => clearTimeout(t);
  }, [message, onClose]);

  return (
    <div
      role="status"
      className={`fixed bottom-6 right-6 z-[70] flex max-w-sm items-start gap-3 rounded-xl px-4 py-3 text-sm text-white shadow-xl ${tone === "ok" ? "bg-side" : "bg-bad"}`}
    >
      <span className="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-white/20 text-xs" aria-hidden="true">
        {tone === "ok" ? "✓" : "!"}
      </span>
      <span>{message}</span>
    </div>
  );
}

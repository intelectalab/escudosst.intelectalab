"use client";

// Texto íntegro de una reseña con «Leer más» (se expande; nunca se recorta el sentido).

import { useState } from "react";

export function ReviewText({ text }: { text: string }) {
  const [open, setOpen] = useState(false);
  const long = text.length > 220;
  return (
    <div className="text-[15px] leading-relaxed text-[var(--site-text)]">
      <p className={`whitespace-pre-line ${!open && long ? "line-clamp-5" : ""}`}>{text}</p>
      {long && (
        <button type="button" onClick={() => setOpen(!open)} className="mt-1 text-sm font-semibold text-[var(--site-action)]">
          {open ? "Ver menos" : "Leer más"}
        </button>
      )}
    </div>
  );
}

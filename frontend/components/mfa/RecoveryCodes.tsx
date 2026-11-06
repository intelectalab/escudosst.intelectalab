"use client";

import { useState } from "react";

/** Muestra los códigos de recuperación una sola vez, con copiar y descargar. */
export function RecoveryCodes({ codes, onDone, doneLabel = "Ya los guardé, continuar" }: { codes: string[]; onDone: () => void; doneLabel?: string }) {
  const [saved, setSaved] = useState(false);
  const [copied, setCopied] = useState(false);
  const text = codes.join("\n");

  function download() {
    const blob = new Blob([`Códigos de recuperación de EscudoSST\nCada código sirve una sola vez.\n\n${text}\n`], { type: "text/plain" });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "escudosst-codigos-de-recuperacion.txt";
    a.click();
    URL.revokeObjectURL(a.href);
  }

  return (
    <div className="space-y-4">
      <div className="rounded-lg bg-amber-soft px-4 py-3 text-sm text-amber-ink">
        Guarda estos códigos en un lugar seguro. Te sirven para entrar si pierdes tu celular. <b>Cada uno se usa una sola vez y no los volveremos a mostrar.</b>
      </div>
      <ul className="grid grid-cols-2 gap-2 rounded-lg border border-line bg-bg p-4 font-mono text-lg">
        {codes.map((c) => (
          <li key={c} className="text-center">
            {c}
          </li>
        ))}
      </ul>
      <div className="flex gap-2">
        <button
          type="button"
          onClick={() => navigator.clipboard?.writeText(text).then(() => setCopied(true))}
          className="flex-1 rounded-lg border border-line px-3 py-2 hover:bg-bg"
        >
          {copied ? "Copiados ✓" : "Copiar"}
        </button>
        <button type="button" onClick={download} className="flex-1 rounded-lg border border-line px-3 py-2 hover:bg-bg">
          Descargar
        </button>
      </div>
      <label className="flex items-start gap-2 text-sm">
        <input type="checkbox" checked={saved} onChange={(e) => setSaved(e.target.checked)} className="mt-1" />
        Guardé mis códigos de recuperación en un lugar seguro.
      </label>
      <button
        type="button"
        disabled={!saved}
        onClick={onDone}
        className="w-full rounded-lg bg-brand py-3 font-semibold text-white hover:bg-brand-strong disabled:opacity-50"
      >
        {doneLabel}
      </button>
    </div>
  );
}

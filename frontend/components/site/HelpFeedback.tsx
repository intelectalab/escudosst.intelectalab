"use client";

// «¿Te sirvió?» (RN-WEB-45): voto sí/no y comentario opcional, sin pedir correo. Una sola vez por artículo y navegador.

import { FormEvent, useEffect, useState } from "react";
import { Icon } from "@/components/ui/Icon";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export function HelpFeedback({ slug, compact = false }: { slug: string; compact?: boolean }) {
  const storeKey = `escudo.help.${slug}`;
  const [vote, setVote] = useState<boolean | null>(null);
  const [comment, setComment] = useState("");
  const [state, setState] = useState<"ask" | "comment" | "sending" | "done" | "error">("ask");

  useEffect(() => {
    if (localStorage.getItem(storeKey)) setState("done");
  }, [storeKey]);

  async function send(helpful: boolean, text: string | null) {
    setState("sending");
    try {
      const res = await fetch(`${API}/api/v1/public/help/articles/${slug}/feedback`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json" },
        body: JSON.stringify({ was_helpful: helpful, comment: text, key: crypto.randomUUID() }),
      });
      if (!res.ok) throw new Error();
      localStorage.setItem(storeKey, "1");
      setState("done");
    } catch {
      setState("error");
    }
  }

  function choose(helpful: boolean) {
    setVote(helpful);
    setState("comment");
  }

  function submit(e: FormEvent) {
    e.preventDefault();
    if (vote !== null) send(vote, comment.trim() || null);
  }

  const box = compact ? "rounded-xl border border-line bg-bg p-3" : "site-card p-5 sm:p-6";

  if (state === "done") {
    return (
      <div className={`${box} flex items-center gap-2 text-sm font-semibold text-[var(--site-green,#22a35a)]`} role="status">
        <Icon name="check" size={18} /> ¡Gracias! Tu opinión nos ayuda a mejorar la ayuda.
      </div>
    );
  }

  return (
    <div className={box}>
      <p className={compact ? "text-sm font-semibold" : "font-display text-lg font-bold text-[var(--site-navy-title)]"}>¿Te sirvió este artículo?</p>
      <div className="mt-3 flex flex-wrap gap-2">
        {[
          [true, "Sí, me sirvió", "check"],
          [false, "No del todo", "close"],
        ].map(([v, label, icon]) => (
          <button
            key={String(v)}
            type="button"
            onClick={() => choose(v as boolean)}
            aria-pressed={vote === v}
            className={`inline-flex min-h-11 items-center gap-2 rounded-xl border px-4 text-sm font-semibold transition ${
              vote === v ? (v ? "border-[#22a35a] bg-[#E8F7EE] text-[#17703e]" : "border-[#E2453C] bg-[#FDECEB] text-[#a3261f]") : "border-[rgba(13,59,110,0.15)] bg-white hover:border-[#1b6fb7]"
            }`}
          >
            <Icon name={icon as string} size={16} /> {label as string}
          </button>
        ))}
      </div>
      {(state === "comment" || state === "sending" || state === "error") && (
        <form onSubmit={submit} className="mt-3 space-y-2">
          <label className="block text-sm">
            <span className="text-[var(--site-text-2,#636d7a)]">{vote ? "¿Algo que agregarías? (opcional)" : "¿Qué faltó o qué no se entendió? (opcional)"}</span>
            <textarea
              value={comment}
              onChange={(e) => setComment(e.target.value)}
              maxLength={1000}
              rows={3}
              className="mt-1 w-full rounded-xl border border-[rgba(13,59,110,0.15)] bg-white px-3 py-2 text-sm outline-none focus:border-[#1b6fb7] focus:ring-2 focus:ring-[#1b6fb7]/20"
            />
          </label>
          <p className="text-xs text-[var(--site-text-2,#636d7a)]">No escribas datos personales ni de tu empresa. No te pediremos tu correo.</p>
          {state === "error" && <p className="text-sm font-medium text-[#E2453C]">No se pudo enviar. Inténtalo de nuevo.</p>}
          <button type="submit" disabled={state === "sending"} className="site-btn site-btn-primary min-h-11 disabled:opacity-60">
            {state === "sending" ? "Enviando…" : "Enviar opinión"}
          </button>
        </form>
      )}
    </div>
  );
}
